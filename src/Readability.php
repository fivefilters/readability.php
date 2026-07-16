<?php

declare(strict_types=1);

namespace fivefilters\Readability;

/**
 * A port of Mozilla's Readability.js, v0.6.0, on PHP's native DOM API
 * (\Dom\HTMLDocument, PHP >= 8.4).
 *
 * Private methods mirror the prototype methods of Readability.js in name
 * (without the underscore prefix) and in order, to keep future upstream syncs
 * a mechanical diff exercise. Deviations needed for PHP are noted inline.
 */
final class Readability
{
    private const int FLAG_STRIP_UNLIKELYS = 0x1;
    private const int FLAG_WEIGHT_CLASSES = 0x2;
    private const int FLAG_CLEAN_CONDITIONALLY = 0x4;

    /** The default number of chars an article must have in order to return a result. */
    private const int DEFAULT_CHAR_THRESHOLD = 500;

    /** Element tags to score by default. */
    private const array DEFAULT_TAGS_TO_SCORE = ['SECTION', 'H2', 'H3', 'H4', 'H5', 'H6', 'P', 'TD', 'PRE'];

    private const array UNLIKELY_ROLES = ['menu', 'menubar', 'complementary', 'navigation', 'alert', 'alertdialog', 'dialog'];

    private const array DIV_TO_P_ELEMS = ['BLOCKQUOTE', 'DL', 'DIV', 'IMG', 'OL', 'P', 'PRE', 'TABLE', 'UL'];

    private const array ALTER_TO_DIV_EXCEPTIONS = ['DIV', 'ARTICLE', 'SECTION', 'P', 'OL', 'UL'];

    private const array PRESENTATIONAL_ATTRIBUTES = ['align', 'background', 'bgcolor', 'border', 'cellpadding', 'cellspacing', 'frame', 'hspace', 'rules', 'style', 'valign', 'vspace'];

    private const array DEPRECATED_SIZE_ATTRIBUTE_ELEMS = ['TABLE', 'TH', 'TD', 'HR', 'PRE'];

    /**
     * The commented out elements qualify as phrasing content but tend to be
     * removed by readability when put into paragraphs, so we ignore them here.
     */
    private const array PHRASING_ELEMS = [
        // 'CANVAS', 'IFRAME', 'SVG', 'VIDEO',
        'ABBR', 'AUDIO', 'B', 'BDO', 'BR', 'BUTTON', 'CITE', 'CODE', 'DATA',
        'DATALIST', 'DFN', 'EM', 'EMBED', 'I', 'IMG', 'INPUT', 'KBD', 'LABEL',
        'MARK', 'MATH', 'METER', 'NOSCRIPT', 'OBJECT', 'OUTPUT', 'PROGRESS', 'Q',
        'RUBY', 'SAMP', 'SCRIPT', 'SELECT', 'SMALL', 'SPAN', 'STRONG', 'SUB',
        'SUP', 'TEXTAREA', 'TIME', 'VAR', 'WBR',
    ];

    /** These are the classes that readability sets itself. */
    private const array CLASSES_TO_PRESERVE = ['page'];

    /** These are the list of HTML entities that need to be escaped. */
    private const array HTML_ESCAPE_MAP = ['lt' => '<', 'gt' => '>', 'amp' => '&', 'quot' => '"', 'apos' => "'"];

    /** Title separators used by getArticleTitle (JS: titleSeparators). */
    private const string TITLE_SEPARATORS = '[\\\\\\/|\-–—>»]';

    /** Meta tag patterns used by getArticleMetadata; property is a space-separated list of values, name is a single value. */
    private const string PROPERTY_PATTERN = '/\s*(article|dc|dcterm|og|twitter)\s*:\s*(author|creator|description|published_time|title|site_name)\s*/i';
    private const string NAME_PATTERN = '/^\s*(?:(dc|dcterm|og|twitter|parsely|weibo:(article|webpage))\s*[-\.:]\s*)?(author|creator|pub-date|description|title|site_name)\s*$/i';

    private \Dom\HTMLDocument $doc;
    private int $flags = 0;
    private ?string $articleTitle = null;
    private ?string $articleByline = null;
    private ?string $articleDir = null;
    private ?string $articleLang = null;
    private ?string $articleSiteName = null;
    /** @var list<array{articleContent: \Dom\Element, textLength: int}> */
    private array $attempts = [];
    /** @var array<string, ?string> */
    private array $metadata = [];
    /** @var non-empty-string */
    private string $allowedVideoRegex = RegExps::VIDEOS;

    /**
     * Per-parse scoring state. JS stores these on the nodes themselves
     * (node.readability.contentScore, table._readabilityDataTable); the PHP
     * DOM offers no expando properties, so they live in object maps instead.
     * SplObjectStorage (not WeakMap) because the strong references keep the
     * PHP node wrappers alive, which is what makes node identity stable.
     *
     * @var \SplObjectStorage<\Dom\Element, float>
     */
    private \SplObjectStorage $scores;
    /** @var \SplObjectStorage<\Dom\Element, bool> */
    private \SplObjectStorage $dataTables;

    /** Base URL handling: JS reads these from the live document. */
    private ?string $baseURI = null;
    private ?string $documentURI = null;

    private readonly Configuration $configuration;

    /**
     * Options can be passed directly as named arguments — the PHP equivalent
     * of Readability.js's options argument — e.g.
     * new Readability(fixRelativeURLs: true, charThreshold: 20).
     * See Configuration for the available options and their defaults.
     * A pre-built Configuration is accepted too, for options built up
     * separately or shared between instances.
     *
     * @param mixed ...$options Configuration options as named arguments
     */
    public function __construct(?Configuration $configuration = null, mixed ...$options)
    {
        if ($configuration !== null && $options !== []) {
            throw new \InvalidArgumentException('Pass either a Configuration object or options as named arguments, not both.');
        }
        $this->configuration = $configuration ?? Configuration::fromArray($options);
        $this->doc = \Dom\HTMLDocument::createEmpty();
        $this->scores = new \SplObjectStorage();
        $this->dataTables = new \SplObjectStorage();
    }

    /**
     * Parse an HTML string and return the article. When no article content
     * is found (where Readability.js returns null), the returned Article
     * carries the extracted title and metadata with null content — see
     * Article::hasContent().
     *
     * @throws ParseException when the input is empty or the document exceeds
     *                        maxElemsToParse
     */
    public function parse(\Dom\HTMLDocument|string $document): Article
    {
        if (is_string($document)) {
            if (trim($document) === '') {
                throw ParseException::emptyInput();
            }
            $document = \Dom\HTMLDocument::createFromString($document, LIBXML_NOERROR);
        }

        $this->doc = $document;
        $this->scores = new \SplObjectStorage();
        $this->dataTables = new \SplObjectStorage();
        $this->attempts = [];
        $this->metadata = [];
        $this->articleTitle = null;
        $this->articleByline = null;
        $this->articleDir = null;
        $this->articleLang = null;
        $this->articleSiteName = null;
        $this->allowedVideoRegex = ($this->configuration->allowedVideoRegex === null || $this->configuration->allowedVideoRegex === '')
            ? RegExps::VIDEOS
            : $this->configuration->allowedVideoRegex;
        $this->flags = ($this->configuration->stripUnlikelyCandidates ? self::FLAG_STRIP_UNLIKELYS : 0)
            | ($this->configuration->weightClasses ? self::FLAG_WEIGHT_CLASSES : 0)
            | ($this->configuration->cleanConditionally ? self::FLAG_CLEAN_CONDITIONALLY : 0);
        $this->resolveBaseURI();

        try {
            // Avoid parsing too large documents, as per configuration option
            if ($this->configuration->maxElemsToParse > 0) {
                $numTags = $document->getElementsByTagName('*')->length;
                if ($numTags > $this->configuration->maxElemsToParse) {
                    throw ParseException::tooManyElements($numTags, $this->configuration->maxElemsToParse);
                }
            }

            // Unwrap image from noscript
            $this->unwrapNoscriptImages($document);

            // Extract JSON-LD metadata before removing scripts
            $jsonLd = $this->configuration->disableJSONLD ? [] : $this->getJSONLD($document);

            // Remove script tags from the document.
            $this->removeScripts($document);

            $this->prepDocument();

            $metadata = $this->getArticleMetadata($jsonLd);
            $this->metadata = $metadata;
            $this->articleTitle = $metadata['title'];

            // PHP-specific: capture the lead image before grabArticle mutates
            // the tree (the source meta/link tags live in <head>). Content
            // <img> src attributes are made absolute by postProcessContent
            // (when fixRelativeURLs is on); the lead image comes from <head>,
            // so absolutize it the same way here.
            $leadImage = $this->getLeadImageUrl();
            if ($leadImage !== null && $this->configuration->fixRelativeURLs && $this->baseURI !== null) {
                $leadImage = $this->toAbsoluteURI($leadImage);
            }

            $articleContent = $this->grabArticle();
            if (!$articleContent) {
                // PHP-specific: where Readability.js returns a bare null and
                // discards the title and metadata it had already extracted,
                // return a metadata-only Article (content fields are null;
                // see Article::hasContent()).
                return new Article(
                    title: $this->articleTitle ?? '',
                    byline: self::pick($metadata['byline'], $this->articleByline),
                    dir: $this->articleDir,
                    lang: self::pick($this->articleLang),
                    content: null,
                    textContent: null,
                    length: null,
                    excerpt: self::pick($metadata['excerpt']),
                    siteName: self::pick($metadata['siteName'], $this->articleSiteName),
                    publishedTime: self::pick($metadata['publishedTime']),
                    image: $leadImage,
                    images: $leadImage !== null ? [$leadImage] : [],
                    contentElement: null,
                );
            }

            $this->log('Grabbed:', fn (): string => $articleContent->innerHTML);

            $this->postProcessContent($articleContent);

            // If we haven't found an excerpt in the article's metadata, use the article's
            // first paragraph as the excerpt. This is used for displaying a preview of
            // the article's content.
            if (self::pick($metadata['excerpt']) === null) {
                $firstParagraph = $articleContent->querySelector('p');
                if ($firstParagraph) {
                    $metadata['excerpt'] = self::jsTrim($firstParagraph->textContent);
                }
            }

            $textContent = $articleContent->textContent;

            $images = $this->collectImages($articleContent, $leadImage);

            return new Article(
                title: $this->articleTitle ?? '',
                byline: self::pick($metadata['byline'], $this->articleByline),
                dir: $this->articleDir,
                lang: self::pick($this->articleLang),
                content: $articleContent->innerHTML,
                textContent: $textContent,
                length: mb_strlen($textContent),
                excerpt: self::pick($metadata['excerpt']),
                siteName: self::pick($metadata['siteName'], $this->articleSiteName),
                publishedTime: self::pick($metadata['publishedTime']),
                image: $leadImage,
                images: $images,
                contentElement: $articleContent,
            );
        } finally {
            // Release the per-parse node maps (and the nodes they keep alive).
            $this->scores = new \SplObjectStorage();
            $this->dataTables = new \SplObjectStorage();
            $this->doc = \Dom\HTMLDocument::createEmpty();
        }
    }

    /**
     * Determine the base URI for relative URL resolution. JS reads
     * doc.baseURI/doc.documentURI off the live document; here the document
     * URL comes from Configuration::originalURL, combined with any
     * <base href> in the document, per the same rules a browser applies.
     */
    private function resolveBaseURI(): void
    {
        $this->documentURI = $this->configuration->originalURL;
        $this->baseURI = $this->documentURI;

        $baseHref = $this->doc->querySelector('base[href]')?->getAttribute('href');
        if ($baseHref !== null && trim($baseHref) !== '') {
            if ($this->documentURI !== null) {
                // An unparseable base href leaves documentURI as the base,
                // as in a browser.
                $this->baseURI = Url::resolve($baseHref, $this->documentURI) ?? $this->documentURI;
            } else {
                // No document URL: an absolute base href can stand on its own.
                $this->baseURI = Url::resolve($baseHref);
            }
        }
    }

    /**
     * Run any post-process modifications to article content as necessary.
     *
     * Mirrors _postProcessContent.
     */
    private function postProcessContent(\Dom\Element $articleContent): void
    {
        // Neutralize javascript: links and (opt-in) convert relative uris to
        // absolute ones. The javascript: handling always runs — it matches
        // Readability.js, needs no base URL, and is a defense-in-depth measure.
        // Absolutizing relative uris is opt-in (PHP has no live document to
        // take a base URL from) and is gated inside fixRelativeUris().
        $this->fixRelativeUris($articleContent);

        $this->simplifyNestedElements($articleContent);

        if (!$this->configuration->keepClasses) {
            // Remove classes.
            $this->cleanClasses($articleContent);
        }
    }

    /**
     * Iterates over a list of nodes, calls `filterFn` for each node and removes
     * the node if the function returned `true`. If function is not passed,
     * removes all the nodes in the list.
     *
     * Mirrors _removeNodes.
     *
     * @param list<\Dom\Element> $nodes
     * @param callable(\Dom\Element): bool|null $filterFn
     */
    private function removeNodes(array $nodes, ?callable $filterFn = null): void
    {
        for ($i = count($nodes) - 1; $i >= 0; $i--) {
            $node = $nodes[$i];
            if ($node->parentNode) {
                if (!$filterFn || $filterFn($node)) {
                    $node->parentNode->removeChild($node);
                }
            }
        }
    }

    /**
     * Iterates over a list of nodes, and calls setNodeTag for each node.
     *
     * Mirrors _replaceNodeTags.
     *
     * @param list<\Dom\Element> $nodes
     */
    private function replaceNodeTags(array $nodes, string $newTagName): void
    {
        foreach ($nodes as $node) {
            $this->setNodeTag($node, $newTagName);
        }
    }

    /**
     * Mirrors _getAllNodesWithTag. querySelectorAll returns a static snapshot
     * in the PHP DOM, which is exactly what the algorithm needs: this is the
     * only element query path used while mutating, never a live collection.
     *
     * @param list<string> $tagNames
     * @return list<\Dom\Element>
     */
    private function getAllNodesWithTag(\Dom\Element|\Dom\Document $node, array $tagNames): array
    {
        return iterator_to_array($node->querySelectorAll(implode(',', $tagNames)), false);
    }

    /**
     * Removes the class="" attribute from every element in the given subtree,
     * except those that match CLASSES_TO_PRESERVE and the classesToPreserve
     * option.
     *
     * Mirrors _cleanClasses.
     */
    private function cleanClasses(\Dom\Element $node): void
    {
        $classesToPreserve = [...self::CLASSES_TO_PRESERVE, ...$this->configuration->classesToPreserve];
        $className = implode(' ', array_filter(
            preg_split('/\s+/', $node->getAttribute('class') ?? '') ?: [],
            fn (string $class): bool => in_array($class, $classesToPreserve, true)
        ));

        if ($className !== '') {
            $node->setAttribute('class', $className);
        } else {
            $node->removeAttribute('class');
        }

        for ($node = $node->firstElementChild; $node; $node = $node->nextElementSibling) {
            $this->cleanClasses($node);
        }
    }

    /**
     * Tests whether a string is an (absolute) URL.
     *
     * Mirrors _isUrl (JS: new URL(str) succeeds).
     */
    private function isUrl(string $str): bool
    {
        return Url::isValid($str);
    }

    /**
     * Converts each <a> and <img> uri in the given element to an absolute URI,
     * ignoring #ref URIs.
     *
     * Mirrors _fixRelativeUris.
     */
    private function fixRelativeUris(\Dom\Element $articleContent): void
    {
        // Converting relative uris to absolute ones is opt-in and needs a base
        // URI. Neutralizing javascript: links, however, always runs — it is a
        // safety measure that needs no base URL.
        $absolutize = $this->configuration->fixRelativeURLs && $this->baseURI !== null;

        $links = $this->getAllNodesWithTag($articleContent, ['a']);
        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            if ($href) {
                // Remove links with javascript: URIs, since
                // they won't work after scripts have been removed from the page.
                if (str_starts_with($href, 'javascript:')) {
                    // if the link only contains simple text content, it can be converted to a text node
                    if ($link->childNodes->length === 1 && $link->firstChild->nodeType === XML_TEXT_NODE) {
                        $text = $this->doc->createTextNode($link->textContent);
                        $link->parentNode->replaceChild($text, $link);
                    } else {
                        // if the link has multiple children, they should all be preserved
                        $container = $this->doc->createElement('span');
                        while ($link->firstChild) {
                            $container->appendChild($link->firstChild);
                        }
                        $link->parentNode->replaceChild($container, $link);
                    }
                } elseif ($absolutize) {
                    $link->setAttribute('href', $this->toAbsoluteURI($href));
                }
            }
        }

        if (!$absolutize) {
            return;
        }

        $medias = $this->getAllNodesWithTag($articleContent, ['img', 'picture', 'figure', 'video', 'audio', 'source']);
        foreach ($medias as $media) {
            $src = $media->getAttribute('src');
            $poster = $media->getAttribute('poster');
            $srcset = $media->getAttribute('srcset');

            if ($src) {
                $media->setAttribute('src', $this->toAbsoluteURI($src));
            }

            if ($poster) {
                $media->setAttribute('poster', $this->toAbsoluteURI($poster));
            }

            if ($srcset) {
                $newSrcset = preg_replace_callback(
                    RegExps::SRCSET_URL,
                    fn (array $m): string => $this->toAbsoluteURI($m[1]) . ($m[2] ?? '') . $m[3],
                    $srcset
                );
                $media->setAttribute('srcset', $newSrcset ?? $srcset);
            }
        }
    }

    /**
     * PHP-specific (not in Readability.js): the page's lead/main image URL,
     * from an og:image or twitter:image meta tag, falling back to a
     * <link rel="img_src"> / <link rel="image_src">. Reinstated from
     * readability.php 3.x.
     */
    private function getLeadImageUrl(): ?string
    {
        foreach ($this->getAllNodesWithTag($this->doc, ['meta']) as $meta) {
            $property = strtolower(self::jsTrim($meta->getAttribute('property') ?? $meta->getAttribute('name') ?? ''));
            if (($property === 'og:image' || $property === 'twitter:image') && ($content = (string) $meta->getAttribute('content')) !== '') {
                return $content;
            }
        }
        foreach ($this->getAllNodesWithTag($this->doc, ['link']) as $link) {
            $rel = $link->getAttribute('rel');
            if (($rel === 'img_src' || $rel === 'image_src') && ($href = (string) $link->getAttribute('href')) !== '') {
                return $href;
            }
        }
        return null;
    }

    /**
     * PHP-specific: the list of image URLs for the article — the lead image
     * (if any) followed by every content <img> src, de-duplicated. Content
     * srcs are already absolute here when fixRelativeURLs is enabled (see
     * fixRelativeUris); the lead image is absolutized by the caller.
     *
     * @return list<string>
     */
    private function collectImages(\Dom\Element $content, ?string $leadImage): array
    {
        $urls = [];
        if ($leadImage !== null) {
            $urls[] = $leadImage;
        }
        foreach ($this->getAllNodesWithTag($content, ['img']) as $img) {
            $src = (string) $img->getAttribute('src');
            if ($src !== '') {
                $urls[] = $src;
            }
        }
        return array_values(array_unique($urls));
    }

    /** The toAbsoluteURI closure inside _fixRelativeUris. */
    private function toAbsoluteURI(string $uri): string
    {
        // Leave hash links alone if the base URI matches the document URI:
        if ($this->baseURI === $this->documentURI && str_starts_with($uri, '#')) {
            return $uri;
        }

        // Otherwise, resolve against base URI; if something went wrong,
        // just return the original:
        return Url::resolve($uri, $this->baseURI) ?? $uri;
    }

    /** Mirrors _simplifyNestedElements. */
    private function simplifyNestedElements(\Dom\Element $articleContent): void
    {
        $node = $articleContent;

        while ($node) {
            if (
                $node->parentNode
                && in_array($node->tagName, ['DIV', 'SECTION'], true)
                && !($node->id && str_starts_with($node->id, 'readability'))
            ) {
                if ($this->isElementWithoutContent($node)) {
                    $node = $this->removeAndGetNext($node);
                    continue;
                } elseif (
                    $this->hasSingleTagInsideElement($node, 'DIV')
                    || $this->hasSingleTagInsideElement($node, 'SECTION')
                ) {
                    $child = $node->firstElementChild;
                    foreach ($node->attributes as $attribute) {
                        try {
                            $child->setAttribute($attribute->name, $attribute->value);
                        } catch (\DOMException) {
                            // Skip attributes with names the DOM API rejects (upstream #918).
                        }
                    }
                    $node->parentNode->replaceChild($child, $node);
                    $node = $child;
                    continue;
                }
            }

            $node = $this->getNextNode($node);
        }
    }

    /**
     * Get the article title.
     *
     * Mirrors _getArticleTitle.
     */
    private function getArticleTitle(): string
    {
        $doc = $this->doc;
        $curTitle = $origTitle = self::jsTrim($doc->title);

        $titleHadHierarchicalSeparators = false;
        $wordCount = fn (string $str): int => count(preg_split('/\s+/u', $str) ?: []);

        // If there's a separator in the title, first remove the final part
        if (preg_match('/\s' . self::TITLE_SEPARATORS . '\s/u', $curTitle)) {
            $titleHadHierarchicalSeparators = (bool) preg_match('/\s[\\\\\/>»]\s/u', $curTitle);
            preg_match_all('/\s' . self::TITLE_SEPARATORS . '\s/ui', $origTitle, $allSeparators, PREG_OFFSET_CAPTURE);
            $lastSeparator = end($allSeparators[0]);
            if ($lastSeparator !== false) {
                $curTitle = substr($origTitle, 0, $lastSeparator[1]);
            }

            // If the resulting title is too short, remove the first part instead:
            if ($wordCount($curTitle) < 3) {
                $curTitle = preg_replace('/^[^\\\\\/|\-–—>»]*' . self::TITLE_SEPARATORS . '/ui', '', $origTitle) ?? '';
            }
        } elseif (str_contains($curTitle, ': ')) {
            // Check if we have an heading containing this exact string, so we
            // could assume it's the full title.
            $headings = $this->getAllNodesWithTag($doc, ['h1', 'h2']);
            $trimmedTitle = self::jsTrim($curTitle);
            $match = false;
            foreach ($headings as $heading) {
                if (self::jsTrim($heading->textContent) === $trimmedTitle) {
                    $match = true;
                    break;
                }
            }

            // If we don't, let's extract the title out of the original title string.
            if (!$match) {
                // (int) casts: the str_contains above guarantees a colon exists.
                $curTitle = substr($origTitle, (int) strrpos($origTitle, ':') + 1);

                // If the title is now too short, try the first colon instead:
                if ($wordCount($curTitle) < 3) {
                    $curTitle = substr($origTitle, (int) strpos($origTitle, ':') + 1);
                    // But if we have too many words before the colon there's something weird
                    // with the titles and the H tags so let's just use the original title instead
                } elseif ($wordCount(substr($origTitle, 0, (int) strpos($origTitle, ':'))) > 5) {
                    $curTitle = $origTitle;
                }
            }
        } elseif (mb_strlen($curTitle) > 150 || mb_strlen($curTitle) < 15) {
            $hOnes = $this->getAllNodesWithTag($doc, ['h1']);

            if (count($hOnes) === 1) {
                $curTitle = $this->getInnerText($hOnes[0]);
            }
        }

        $curTitle = preg_replace(RegExps::NORMALIZE, ' ', self::jsTrim($curTitle)) ?? '';
        // If we now have 4 words or fewer as our title, and either no
        // 'hierarchical' separators (\, /, > or ») were found in the original
        // title or we decreased the number of words by more than 1 word, use
        // the original title.
        $curTitleWordCount = $wordCount($curTitle);
        if (
            $curTitleWordCount <= 4
            && (!$titleHadHierarchicalSeparators
                || $curTitleWordCount != $wordCount(preg_replace('/\s' . self::TITLE_SEPARATORS . '\s/u', '', $origTitle) ?? '') - 1)
        ) {
            $curTitle = $origTitle;
        }

        return $curTitle;
    }

    /**
     * Prepare the HTML document for readability to scrape it. This includes
     * things like stripping javascript, CSS, and handling terrible markup.
     *
     * Mirrors _prepDocument.
     */
    private function prepDocument(): void
    {
        $doc = $this->doc;

        // Remove all style tags in head
        $this->removeNodes($this->getAllNodesWithTag($doc, ['style']));

        if ($doc->body) {
            $this->replaceBrs($doc->body);
        }

        $this->replaceNodeTags($this->getAllNodesWithTag($doc, ['font']), 'SPAN');
    }

    /**
     * Finds the next node, starting from the given node, and ignoring
     * whitespace in between. If the given node is an element, the same node is
     * returned.
     *
     * Mirrors _nextNode.
     */
    private function nextNode(?\Dom\Node $node): ?\Dom\Node
    {
        $next = $node;
        while (
            $next
            && $next->nodeType !== XML_ELEMENT_NODE
            && preg_match(RegExps::WHITESPACE, $next->textContent)
        ) {
            $next = $next->nextSibling;
        }
        return $next;
    }

    /**
     * Replaces 2 or more successive <br> elements with a single <p>.
     * Whitespace between <br> elements are ignored. For example:
     *   <div>foo<br>bar<br> <br><br>abc</div>
     * will become:
     *   <div>foo<br>bar<p>abc</p></div>
     *
     * Mirrors _replaceBrs.
     */
    private function replaceBrs(\Dom\Element $elem): void
    {
        foreach ($this->getAllNodesWithTag($elem, ['br']) as $br) {
            if (!$br->parentNode) {
                continue;
            }
            $next = $br->nextSibling;

            // Whether 2 or more <br> elements have been found and replaced with a
            // <p> block.
            $replaced = false;

            // If we find a <br> chain, remove the <br>s until we hit another node
            // or non-whitespace. This leaves behind the first <br> in the chain
            // (which will be replaced with a <p> later).
            while (($next = $this->nextNode($next)) && $next instanceof \Dom\Element && $next->tagName === 'BR') {
                $replaced = true;
                $brSibling = $next->nextSibling;
                $next->remove();
                $next = $brSibling;
            }

            // If we removed a <br> chain, replace the remaining <br> with a <p>. Add
            // all sibling nodes as children of the <p> until we hit another <br>
            // chain.
            if ($replaced) {
                $p = $this->doc->createElement('p');
                $br->parentNode->replaceChild($p, $br);

                $next = $p->nextSibling;
                while ($next) {
                    // If we've hit another <br><br>, we're done adding children to this <p>.
                    if ($next instanceof \Dom\Element && $next->tagName === 'BR') {
                        $nextElem = $this->nextNode($next->nextSibling);
                        if ($nextElem instanceof \Dom\Element && $nextElem->tagName === 'BR') {
                            break;
                        }
                    }

                    if (!$this->isPhrasingContent($next)) {
                        break;
                    }

                    // Otherwise, make this node a child of the new <p>.
                    $sibling = $next->nextSibling;
                    $p->appendChild($next);
                    $next = $sibling;
                }

                while ($p->lastChild && $this->isWhitespace($p->lastChild)) {
                    $p->removeChild($p->lastChild);
                }

                if ($p->parentNode instanceof \Dom\Element && $p->parentNode->tagName === 'P') {
                    $this->setNodeTag($p->parentNode, 'DIV');
                }
            }
        }
    }

    /** Mirrors _setNodeTag. */
    private function setNodeTag(\Dom\Element $node, string $tag): \Dom\Element
    {
        $this->log('setNodeTag', $node, $tag);

        $replacement = $node->ownerDocument->createElement($tag);
        while ($node->firstChild) {
            $replacement->appendChild($node->firstChild);
        }
        $node->parentNode->replaceChild($replacement, $node);
        if ($this->scores->offsetExists($node)) {
            $this->scores[$replacement] = $this->scores[$node];
        }

        foreach ($node->attributes as $attribute) {
            try {
                $replacement->setAttribute($attribute->name, $attribute->value);
            } catch (\DOMException) {
                // Skip attributes with names the DOM API rejects (upstream #918).
            }
        }
        return $replacement;
    }

    /**
     * Prepare the article node for display. Clean out any inline styles,
     * iframes, forms, strip extraneous <p> tags, etc.
     *
     * Mirrors _prepArticle.
     */
    private function prepArticle(\Dom\Element $articleContent): void
    {
        $this->cleanStyles($articleContent);

        // Check for data tables before we continue, to avoid removing items in
        // those tables, which will often be isolated even though they're
        // visually linked to other content-ful elements (text, images, etc.).
        $this->markDataTables($articleContent);

        $this->fixLazyImages($articleContent);

        // Clean out junk from the article content
        $this->cleanConditionally($articleContent, 'form');
        $this->cleanConditionally($articleContent, 'fieldset');
        $this->clean($articleContent, 'object');
        $this->clean($articleContent, 'embed');
        $this->clean($articleContent, 'footer');
        $this->clean($articleContent, 'link');
        $this->clean($articleContent, 'aside');

        // Clean out elements with little content that have "share" in their id/class combinations from final top candidates,
        // which means we don't remove the top candidates even they have "share".

        $shareElementThreshold = self::DEFAULT_CHAR_THRESHOLD;

        foreach ($this->children($articleContent) as $topCandidate) {
            $this->cleanMatchedNodes($topCandidate, fn (\Dom\Element $node, string $matchString): bool =>
                preg_match(RegExps::SHARE_ELEMENTS, $matchString)
                && mb_strlen($node->textContent) < $shareElementThreshold);
        }

        $this->clean($articleContent, 'iframe');
        $this->clean($articleContent, 'input');
        $this->clean($articleContent, 'textarea');
        $this->clean($articleContent, 'select');
        $this->clean($articleContent, 'button');
        $this->cleanHeaders($articleContent);

        // Do these last as the previous stuff may have removed junk
        // that will affect these
        $this->cleanConditionally($articleContent, 'table');
        $this->cleanConditionally($articleContent, 'ul');
        $this->cleanConditionally($articleContent, 'div');

        // replace H1 with H2 as H1 should be only title that is displayed separately
        $this->replaceNodeTags($this->getAllNodesWithTag($articleContent, ['h1']), 'h2');

        // Remove extra paragraphs
        $this->removeNodes($this->getAllNodesWithTag($articleContent, ['p']), function (\Dom\Element $paragraph): bool {
            // At this point, nasty iframes have been removed; only embedded video
            // ones remain.
            $contentElementCount = count($this->getAllNodesWithTag($paragraph, ['img', 'embed', 'object', 'iframe']));
            return $contentElementCount === 0 && $this->getInnerText($paragraph, false) === '';
        });

        foreach ($this->getAllNodesWithTag($articleContent, ['br']) as $br) {
            $next = $this->nextNode($br->nextSibling);
            if ($next instanceof \Dom\Element && $next->tagName === 'P') {
                $br->remove();
            }
        }

        // Remove single-cell tables
        foreach ($this->getAllNodesWithTag($articleContent, ['table']) as $table) {
            $tbody = $this->hasSingleTagInsideElement($table, 'TBODY') ? $table->firstElementChild : $table;
            if ($this->hasSingleTagInsideElement($tbody, 'TR')) {
                $row = $tbody->firstElementChild;
                if ($this->hasSingleTagInsideElement($row, 'TD')) {
                    $cell = $row->firstElementChild;
                    $everyPhrasing = true;
                    foreach ($cell->childNodes as $child) {
                        if (!$this->isPhrasingContent($child)) {
                            $everyPhrasing = false;
                            break;
                        }
                    }
                    $cell = $this->setNodeTag($cell, $everyPhrasing ? 'P' : 'DIV');
                    $table->parentNode->replaceChild($cell, $table);
                }
            }
        }
    }

    /**
     * Initialize a node with the readability score. Also checks the
     * className/id for special names to add to its score.
     *
     * Mirrors _initializeNode (JS: node.readability = {contentScore}).
     */
    private function initializeNode(\Dom\Element $node): void
    {
        $contentScore = match ($node->tagName) {
            'DIV' => 5,
            'PRE', 'TD', 'BLOCKQUOTE' => 3,
            'ADDRESS', 'OL', 'UL', 'DL', 'DD', 'DT', 'LI', 'FORM' => -3,
            'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'TH' => -5,
            default => 0,
        };

        $this->scores[$node] = (float) ($contentScore + $this->getClassWeight($node));
    }

    /** Mirrors _removeAndGetNext. */
    private function removeAndGetNext(\Dom\Element $node): ?\Dom\Element
    {
        $nextNode = $this->getNextNode($node, true);
        $node->remove();
        return $nextNode;
    }

    /**
     * Traverse the DOM from node to node, starting at the node passed in.
     * Pass true for the second parameter to indicate this node itself
     * (and its kids) are going away, and we want the next node over.
     *
     * Calling this in a loop will traverse the DOM depth-first.
     *
     * Mirrors _getNextNode.
     */
    private function getNextNode(\Dom\Element $node, bool $ignoreSelfAndKids = false): ?\Dom\Element
    {
        // First check for kids if those aren't being ignored
        if (!$ignoreSelfAndKids && $node->firstElementChild) {
            return $node->firstElementChild;
        }
        // Then for siblings...
        if ($node->nextElementSibling) {
            return $node->nextElementSibling;
        }
        // And finally, move up the parent chain *and* find a sibling
        // (because this is depth-first traversal, we will have already
        // seen the parent nodes themselves).
        do {
            $node = $node->parentNode;
        } while ($node instanceof \Dom\Element && !$node->nextElementSibling);
        return $node instanceof \Dom\Element ? $node->nextElementSibling : null;
    }

    /**
     * Compares second text to first one. 1 = same text, 0 = completely
     * different text. Works the way that it splits both texts into words and
     * then finds words that are unique in second text; the result is given by
     * the lower length of unique parts.
     *
     * Mirrors _textSimilarity.
     */
    private function textSimilarity(string $textA, string $textB): float
    {
        $tokensA = array_values(array_filter(preg_split(RegExps::TOKENIZE, mb_strtolower($textA)) ?: [], fn (string $t): bool => $t !== ''));
        $tokensB = array_values(array_filter(preg_split(RegExps::TOKENIZE, mb_strtolower($textB)) ?: [], fn (string $t): bool => $t !== ''));
        if (!$tokensA || !$tokensB) {
            return 0;
        }
        $uniqTokensB = array_filter($tokensB, fn (string $token): bool => !in_array($token, $tokensA, true));
        $distanceB = mb_strlen(implode(' ', $uniqTokensB)) / mb_strlen(implode(' ', $tokensB));
        return 1 - $distanceB;
    }

    /**
     * Checks whether an element node contains a valid byline.
     *
     * Mirrors _isValidByline.
     */
    private function isValidByline(\Dom\Element $node, string $matchString): bool
    {
        $rel = $node->getAttribute('rel');
        $itemprop = $node->getAttribute('itemprop');
        $bylineLength = mb_strlen(self::jsTrim($node->textContent));

        return ($rel === 'author'
                || ($itemprop && str_contains($itemprop, 'author'))
                || preg_match(RegExps::BYLINE, $matchString))
            && $bylineLength > 0
            && $bylineLength < 100;
    }

    /**
     * Mirrors _getNodeAncestors.
     *
     * @return list<\Dom\Node>
     */
    private function getNodeAncestors(\Dom\Node $node, int $maxDepth = 0): array
    {
        $i = 0;
        $ancestors = [];
        while ($node->parentNode) {
            $ancestors[] = $node->parentNode;
            if ($maxDepth && ++$i === $maxDepth) {
                break;
            }
            $node = $node->parentNode;
        }
        return $ancestors;
    }

    /**
     * Using a variety of metrics (content score, classname, element types),
     * find the content that is most likely to be the stuff a user wants to
     * read. Then return it wrapped up in a div.
     *
     * Mirrors _grabArticle. (The JS `page` parameter and its `isPaging` /
     * readability-content branches are vestigial — parse() never passes a
     * page — so they are not ported.)
     */
    private function grabArticle(): ?\Dom\Element
    {
        $this->log('**** grabArticle ****');
        $doc = $this->doc;
        $page = $doc->body;

        // We can't grab an article if we don't have a page!
        if (!$page) {
            $this->log('No body found in document. Abort.');
            return null;
        }

        $pageCacheHtml = $page->innerHTML;

        while (true) {
            $this->log('Starting grabArticle loop');
            $stripUnlikelyCandidates = $this->flagIsActive(self::FLAG_STRIP_UNLIKELYS);

            // First, node prepping. Trash nodes that look cruddy (like ones with the
            // class name "comment", etc), and turn divs into P tags where they have been
            // used inappropriately (as in, where they contain no other block level elements.)
            $elementsToScore = [];
            $node = $doc->documentElement;

            $shouldRemoveTitleHeader = true;

            while ($node) {
                if ($node->tagName === 'HTML') {
                    $this->articleLang = $node->getAttribute('lang');
                }

                $matchString = $node->className . ' ' . $node->id;

                if (!$this->isProbablyVisible($node)) {
                    $this->log('Removing hidden node - ' . $matchString);
                    $node = $this->removeAndGetNext($node);
                    continue;
                }

                // User is not able to see elements applied with both "aria-modal = true" and "role = dialog"
                if ($node->getAttribute('aria-modal') === 'true' && $node->getAttribute('role') === 'dialog') {
                    $node = $this->removeAndGetNext($node);
                    continue;
                }

                // If we don't have a byline yet check to see if this node is a byline; if it is store the byline and remove the node.
                if (
                    $this->articleByline === null
                    && self::pick($this->metadata['byline'] ?? null) === null
                    && $this->isValidByline($node, $matchString)
                ) {
                    // Find child node matching [itemprop="name"] and use that if it exists for a more accurate author name byline
                    $endOfSearchMarkerNode = $this->getNextNode($node, true);
                    $next = $this->getNextNode($node);
                    $itemPropNameNode = null;
                    while ($next && $next !== $endOfSearchMarkerNode) {
                        $itemprop = $next->getAttribute('itemprop');
                        if ($itemprop && str_contains($itemprop, 'name')) {
                            $itemPropNameNode = $next;
                            break;
                        } else {
                            $next = $this->getNextNode($next);
                        }
                    }
                    $this->articleByline = self::jsTrim(($itemPropNameNode ?? $node)->textContent);
                    // JS always removes the byline node; PHP can keep it in the
                    // content (the byline is still recorded above either way).
                    if (!$this->configuration->keepInlineByline) {
                        $node = $this->removeAndGetNext($node);
                        continue;
                    }
                }

                if ($shouldRemoveTitleHeader && $this->headerDuplicatesTitle($node)) {
                    $this->log('Removing header:', self::jsTrim($node->textContent), self::jsTrim($this->articleTitle ?? ''));
                    $shouldRemoveTitleHeader = false;
                    $node = $this->removeAndGetNext($node);
                    continue;
                }

                // Remove unlikely candidates
                if ($stripUnlikelyCandidates) {
                    if (
                        preg_match(RegExps::UNLIKELY_CANDIDATES, $matchString)
                        && !preg_match(RegExps::OK_MAYBE_ITS_A_CANDIDATE, $matchString)
                        && !$this->hasAncestorTag($node, 'table')
                        && !$this->hasAncestorTag($node, 'code')
                        && $node->tagName !== 'BODY'
                        && $node->tagName !== 'A'
                    ) {
                        $this->log('Removing unlikely candidate - ' . $matchString);
                        $node = $this->removeAndGetNext($node);
                        continue;
                    }

                    if (in_array($node->getAttribute('role'), self::UNLIKELY_ROLES, true)) {
                        $this->log('Removing content with role ' . $node->getAttribute('role') . ' - ' . $matchString);
                        $node = $this->removeAndGetNext($node);
                        continue;
                    }
                }

                // Remove DIV, SECTION, and HEADER nodes without any content(e.g. text, image, video, or iframe).
                if (
                    in_array($node->tagName, ['DIV', 'SECTION', 'HEADER', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6'], true)
                    && $this->isElementWithoutContent($node)
                ) {
                    $node = $this->removeAndGetNext($node);
                    continue;
                }

                if (in_array($node->tagName, self::DEFAULT_TAGS_TO_SCORE, true)) {
                    $elementsToScore[] = $node;
                }

                // Turn all divs that don't have children block level elements into p's
                if ($node->tagName === 'DIV') {
                    // Put phrasing content into paragraphs.
                    $childNode = $node->firstChild;
                    while ($childNode) {
                        $nextSibling = $childNode->nextSibling;
                        if ($this->isPhrasingContent($childNode)) {
                            $fragment = $doc->createDocumentFragment();
                            // Collect all consecutive phrasing content into a fragment.
                            do {
                                $nextSibling = $childNode->nextSibling;
                                $fragment->appendChild($childNode);
                                $childNode = $nextSibling;
                            } while ($childNode && $this->isPhrasingContent($childNode));

                            // Trim leading and trailing whitespace from the fragment.
                            while ($fragment->firstChild && $this->isWhitespace($fragment->firstChild)) {
                                $fragment->removeChild($fragment->firstChild);
                            }
                            while ($fragment->lastChild && $this->isWhitespace($fragment->lastChild)) {
                                $fragment->removeChild($fragment->lastChild);
                            }

                            // If the fragment contains anything, wrap it in a paragraph and
                            // insert it before the next non-phrasing node.
                            if ($fragment->firstChild) {
                                $p = $doc->createElement('p');
                                $p->appendChild($fragment);
                                $node->insertBefore($p, $nextSibling);
                            }
                        }
                        $childNode = $nextSibling;
                    }

                    // Sites like http://mobile.slate.com encloses each paragraph with a DIV
                    // element. DIVs with only a P element inside and no text content can be
                    // safely converted into plain P elements to avoid confusing the scoring
                    // algorithm with DIVs with are, in practice, paragraphs.
                    if ($this->hasSingleTagInsideElement($node, 'P') && $this->getLinkDensity($node) < 0.25) {
                        $newNode = $node->firstElementChild;
                        $node->parentNode->replaceChild($newNode, $node);
                        $node = $newNode;
                        $elementsToScore[] = $node;
                    } elseif (!$this->hasChildBlockElement($node)) {
                        $node = $this->setNodeTag($node, 'P');
                        $elementsToScore[] = $node;
                    }
                }
                $node = $this->getNextNode($node);
            }

            /*
             * Loop through all paragraphs, and assign a score to them based on how content-y they look.
             * Then add their score to their parent node.
             *
             * A score is determined by things like number of commas, class names, etc. Maybe eventually link density.
             */
            $candidates = [];
            foreach ($elementsToScore as $elementToScore) {
                if (!$elementToScore->parentNode instanceof \Dom\Element) {
                    continue;
                }

                // If this paragraph is less than 25 characters, don't even count it.
                $innerText = $this->getInnerText($elementToScore);
                if (mb_strlen($innerText) < 25) {
                    continue;
                }

                // Exclude nodes with no ancestor.
                $ancestors = $this->getNodeAncestors($elementToScore, 5);
                if (count($ancestors) === 0) {
                    continue;
                }

                $contentScore = 0;

                // Add a point for the paragraph itself as a base.
                $contentScore += 1;

                // Add points for any commas within this paragraph.
                $contentScore += (int) preg_match_all(RegExps::COMMAS, $innerText) + 1;

                // For every 100 characters in this paragraph, add another point. Up to 3 points.
                $contentScore += min(intdiv(mb_strlen($innerText), 100), 3);

                // Initialize and score ancestors.
                foreach ($ancestors as $level => $ancestor) {
                    if (!$ancestor instanceof \Dom\Element || !$ancestor->parentNode instanceof \Dom\Element) {
                        continue;
                    }

                    if (!$this->scores->offsetExists($ancestor)) {
                        $this->initializeNode($ancestor);
                        $candidates[] = $ancestor;
                    }

                    // Node score divider:
                    // - parent:             1 (no division)
                    // - grandparent:        2
                    // - great grandparent+: ancestor level * 3
                    if ($level === 0) {
                        $scoreDivider = 1;
                    } elseif ($level === 1) {
                        $scoreDivider = 2;
                    } else {
                        $scoreDivider = $level * 3;
                    }
                    $this->scores[$ancestor] = $this->scores[$ancestor] + $contentScore / $scoreDivider;
                }
            }

            // After we've calculated scores, loop through all of the possible
            // candidate nodes we found and find the one with the highest score.
            $topCandidates = [];
            foreach ($candidates as $candidate) {
                // Scale the final candidates score based on link density. Good content
                // should have a relatively small link density (5% or less) and be mostly
                // unaffected by this operation.
                $candidateScore = $this->scores[$candidate] * (1 - $this->getLinkDensity($candidate));
                $this->scores[$candidate] = $candidateScore;

                $this->log('Candidate:', $candidate, 'with score ' . $candidateScore);

                for ($t = 0; $t < $this->configuration->nbTopCandidates; $t++) {
                    $aTopCandidate = $topCandidates[$t] ?? null;

                    if (!$aTopCandidate || $candidateScore > $this->scores[$aTopCandidate]) {
                        array_splice($topCandidates, $t, 0, [$candidate]);
                        if (count($topCandidates) > $this->configuration->nbTopCandidates) {
                            array_pop($topCandidates);
                        }
                        break;
                    }
                }
            }

            $topCandidate = $topCandidates[0] ?? null;
            $neededToCreateTopCandidate = false;

            // If we still have no top candidate, just use the body as a last resort.
            // We also have to copy the body node so it is something we can modify.
            if ($topCandidate === null || $topCandidate->tagName === 'BODY') {
                // Move all of the page's children into topCandidate
                $topCandidate = $doc->createElement('DIV');
                $neededToCreateTopCandidate = true;
                // Move everything (not just elements, also text nodes etc.) into the container
                // so we even include text directly in the body:
                while ($page->firstChild) {
                    $this->log('Moving child out:', $page->firstChild);
                    $topCandidate->appendChild($page->firstChild);
                }

                $page->appendChild($topCandidate);

                $this->initializeNode($topCandidate);
            } else {
                // Find a better top candidate node if it contains (at least three) nodes which belong to `topCandidates` array
                // and whose scores are quite closed with current `topCandidate` node.
                $alternativeCandidateAncestors = [];
                for ($i = 1; $i < count($topCandidates); $i++) {
                    // fdiv mirrors JS division: x/0 is INF (>= 0.75), 0/0 is NAN (not >= 0.75)
                    if (fdiv((float) $this->scores[$topCandidates[$i]], (float) $this->scores[$topCandidate]) >= 0.75) {
                        $alternativeCandidateAncestors[] = $this->getNodeAncestors($topCandidates[$i]);
                    }
                }
                $MINIMUM_TOPCANDIDATES = 3;
                if (count($alternativeCandidateAncestors) >= $MINIMUM_TOPCANDIDATES) {
                    $parentOfTopCandidate = $topCandidate->parentNode;
                    while ($parentOfTopCandidate instanceof \Dom\Element && $parentOfTopCandidate->tagName !== 'BODY') {
                        $listsContainingThisAncestor = 0;
                        for (
                            $ancestorIndex = 0;
                            $ancestorIndex < count($alternativeCandidateAncestors) && $listsContainingThisAncestor < $MINIMUM_TOPCANDIDATES;
                            $ancestorIndex++
                        ) {
                            $listsContainingThisAncestor += (int) in_array($parentOfTopCandidate, $alternativeCandidateAncestors[$ancestorIndex], true);
                        }
                        if ($listsContainingThisAncestor >= $MINIMUM_TOPCANDIDATES) {
                            $topCandidate = $parentOfTopCandidate;
                            break;
                        }
                        $parentOfTopCandidate = $parentOfTopCandidate->parentNode;
                    }
                }
                if (!$this->scores->offsetExists($topCandidate)) {
                    $this->initializeNode($topCandidate);
                }

                // Because of our bonus system, parents of candidates might have scores
                // themselves. They get half of the node. There won't be nodes with higher
                // scores than our topCandidate, but if we see the score going *up* in the first
                // few steps up the tree, that's a decent sign that there might be more content
                // lurking in other places that we want to unify in. The sibling stuff
                // below does some of that - but only if we've looked high enough up the DOM
                // tree.
                $parentOfTopCandidate = $topCandidate->parentNode;
                $lastScore = $this->scores[$topCandidate];
                // The scores shouldn't get too low.
                $scoreThreshold = $lastScore / 3;
                while ($parentOfTopCandidate instanceof \Dom\Element && $parentOfTopCandidate->tagName !== 'BODY') {
                    if (!$this->scores->offsetExists($parentOfTopCandidate)) {
                        $parentOfTopCandidate = $parentOfTopCandidate->parentNode;
                        continue;
                    }
                    $parentScore = $this->scores[$parentOfTopCandidate];
                    if ($parentScore < $scoreThreshold) {
                        break;
                    }
                    if ($parentScore > $lastScore) {
                        // Alright! We found a better parent to use.
                        $topCandidate = $parentOfTopCandidate;
                        break;
                    }
                    $lastScore = $this->scores[$parentOfTopCandidate];
                    $parentOfTopCandidate = $parentOfTopCandidate->parentNode;
                }

                // If the top candidate is the only child, use parent instead. This will help sibling
                // joining logic when adjacent content is actually located in parent's sibling node.
                $parentOfTopCandidate = $topCandidate->parentNode;
                while (
                    $parentOfTopCandidate instanceof \Dom\Element
                    && $parentOfTopCandidate->tagName !== 'BODY'
                    && $parentOfTopCandidate->childElementCount === 1
                ) {
                    $topCandidate = $parentOfTopCandidate;
                    $parentOfTopCandidate = $topCandidate->parentNode;
                }
                if (!$this->scores->offsetExists($topCandidate)) {
                    $this->initializeNode($topCandidate);
                }
            }

            // Now that we have the top candidate, look through its siblings for content
            // that might also be related. Things like preambles, content split by ads
            // that we removed, etc.
            $articleContent = $doc->createElement('DIV');

            $siblingScoreThreshold = max(10, $this->scores[$topCandidate] * 0.2);
            // Keep potential top candidate's parent node to try to get text direction of it later.
            $parentOfTopCandidate = $topCandidate->parentNode;
            // JS iterates the live `children` collection, re-fetching and adjusting
            // indices after each removal; iterating a snapshot visits the same
            // original children exactly once.
            $siblings = $this->children($parentOfTopCandidate);

            foreach ($siblings as $sibling) {
                $append = false;

                $this->log('Looking at sibling node:', $sibling, $this->scores->offsetExists($sibling) ? 'with score ' . $this->scores[$sibling] : '');

                if ($sibling === $topCandidate) {
                    $append = true;
                } else {
                    $contentBonus = 0;

                    // Give a bonus if sibling nodes and top candidates have the example same classname
                    if ($sibling->className === $topCandidate->className && $topCandidate->className !== '') {
                        $contentBonus += $this->scores[$topCandidate] * 0.2;
                    }

                    if ($this->scores->offsetExists($sibling) && $this->scores[$sibling] + $contentBonus >= $siblingScoreThreshold) {
                        $append = true;
                    } elseif ($sibling->nodeName === 'P') {
                        $linkDensity = $this->getLinkDensity($sibling);
                        $nodeContent = $this->getInnerText($sibling);
                        $nodeLength = mb_strlen($nodeContent);

                        if ($nodeLength > 80 && $linkDensity < 0.25) {
                            $append = true;
                        } elseif (
                            $nodeLength < 80
                            && $nodeLength > 0
                            && $linkDensity === 0.0
                            && preg_match('/\.( |$)/', $nodeContent)
                        ) {
                            $append = true;
                        }
                    }
                }

                if ($append) {
                    $this->log('Appending node:', $sibling);

                    if (!in_array($sibling->nodeName, self::ALTER_TO_DIV_EXCEPTIONS, true)) {
                        // We have a node that isn't a common block level element, like a form or td tag.
                        // Turn it into a div so it doesn't get filtered out later by accident.
                        $this->log('Altering sibling:', $sibling, 'to div.');

                        $sibling = $this->setNodeTag($sibling, 'DIV');
                    }

                    $articleContent->appendChild($sibling);
                }
            }

            // So we have all of the content that we need. Now we clean it up for presentation.
            $this->prepArticle($articleContent);

            if ($neededToCreateTopCandidate) {
                // We already created a fake div thing, and there wouldn't have been any siblings left
                // for the previous loop, so there's no point trying to create a new div, and then
                // move all the children over. Just assign IDs and class names here. No need to append
                // because that already happened anyway.
                $topCandidate->setAttribute('id', 'readability-page-1');
                $topCandidate->setAttribute('class', 'page');
            } else {
                $div = $doc->createElement('DIV');
                $div->setAttribute('id', 'readability-page-1');
                $div->setAttribute('class', 'page');
                while ($articleContent->firstChild) {
                    $div->appendChild($articleContent->firstChild);
                }
                $articleContent->appendChild($div);
            }

            $parseSuccessful = true;

            // Now that we've gone through the full algorithm, check to see if
            // we got any meaningful content. If we didn't, we may need to re-run
            // grabArticle with different flags set. This gives us a higher likelihood of
            // finding the content, and the sieve approach gives us a higher likelihood of
            // finding the -right- content.
            $textLength = mb_strlen($this->getInnerText($articleContent, true));
            if ($textLength < $this->configuration->charThreshold) {
                $parseSuccessful = false;
                $page->innerHTML = $pageCacheHtml;

                $this->attempts[] = ['articleContent' => $articleContent, 'textLength' => $textLength];

                if ($this->flagIsActive(self::FLAG_STRIP_UNLIKELYS)) {
                    $this->removeFlag(self::FLAG_STRIP_UNLIKELYS);
                } elseif ($this->flagIsActive(self::FLAG_WEIGHT_CLASSES)) {
                    $this->removeFlag(self::FLAG_WEIGHT_CLASSES);
                } elseif ($this->flagIsActive(self::FLAG_CLEAN_CONDITIONALLY)) {
                    $this->removeFlag(self::FLAG_CLEAN_CONDITIONALLY);
                } else {
                    // No luck after removing flags, just return the longest text we found during the different loops
                    usort($this->attempts, fn (array $a, array $b): int => $b['textLength'] <=> $a['textLength']);

                    // But first check if we actually have something
                    if (!$this->attempts[0]['textLength']) {
                        return null;
                    }

                    $articleContent = $this->attempts[0]['articleContent'];
                    $parseSuccessful = true;
                }
            }

            if ($parseSuccessful) {
                // Find out text direction from ancestors of final top candidate.
                $ancestors = [$parentOfTopCandidate, $topCandidate, ...$this->getNodeAncestors($parentOfTopCandidate)];
                foreach ($ancestors as $ancestor) {
                    if (!$ancestor instanceof \Dom\Element) {
                        continue;
                    }
                    $articleDir = $ancestor->getAttribute('dir');
                    if ($articleDir) {
                        $this->articleDir = $articleDir;
                        break;
                    }
                }
                return $articleContent;
            }
        }
    }

    /**
     * Converts some of the common HTML entities in string to their
     * corresponding characters.
     *
     * Mirrors _unescapeHtmlEntities.
     */
    private function unescapeHtmlEntities(?string $str): ?string
    {
        if ($str === null || $str === '') {
            return $str;
        }

        $str = preg_replace_callback(
            '/&(quot|amp|apos|lt|gt);/',
            fn (array $m): string => self::HTML_ESCAPE_MAP[$m[1]],
            $str
        ) ?? $str;
        return preg_replace_callback(
            '/&#(?:x([0-9a-f]+)|([0-9]+));/i',
            function (array $m): string {
                $hex = $m[1] !== '' ? $m[1] : null;
                $num = $hex !== null ? (int) hexdec($hex) : (int) $m[2];

                // these character references are replaced by a conforming HTML parser
                if ($num === 0 || $num > 0x10FFFF || ($num >= 0xD800 && $num <= 0xDFFF)) {
                    $num = 0xFFFD;
                }

                return mb_chr($num, 'UTF-8') ?: "\u{FFFD}";
            },
            $str
        ) ?? $str;
    }

    /**
     * Try to extract metadata from JSON-LD object. For now, only Schema.org
     * objects of type Article or its subtypes are supported.
     *
     * Mirrors _getJSONLD.
     *
     * @return array with any metadata that could be extracted (possibly none)
     */
    private function getJSONLD(\Dom\HTMLDocument $doc): array
    {
        $scripts = $this->getAllNodesWithTag($doc, ['script']);

        $metadata = null;

        foreach ($scripts as $jsonLdElement) {
            if ($metadata === null && $jsonLdElement->getAttribute('type') === 'application/ld+json') {
                try {
                    // Strip CDATA markers if present
                    $raw = (string) $jsonLdElement->textContent;
                    $content = preg_replace('/^\s*<!\[CDATA\[|\]\]>\s*$/', '', $raw) ?? $raw;
                    $parsed = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

                    if (is_array($parsed) && array_is_list($parsed)) {
                        $found = null;
                        foreach ($parsed as $it) {
                            if (is_string($it['@type'] ?? null) && preg_match(RegExps::JSON_LD_ARTICLE_TYPES, $it['@type'])) {
                                $found = $it;
                                break;
                            }
                        }
                        if (!$found) {
                            continue;
                        }
                        $parsed = $found;
                    }

                    $schemaDotOrgRegex = '/^https?\:\/\/schema\.org\/?$/';
                    $context = $parsed['@context'] ?? null;
                    $matches = (is_string($context) && preg_match($schemaDotOrgRegex, $context))
                        || (is_array($context)
                            && is_string($context['@vocab'] ?? null)
                            && preg_match($schemaDotOrgRegex, $context['@vocab']));

                    if (!$matches) {
                        continue;
                    }

                    if (!isset($parsed['@type']) && is_array($parsed['@graph'] ?? null) && array_is_list($parsed['@graph'])) {
                        $found = null;
                        foreach ($parsed['@graph'] as $it) {
                            $type = $it['@type'] ?? '';
                            if (is_string($type) && preg_match(RegExps::JSON_LD_ARTICLE_TYPES, $type)) {
                                $found = $it;
                                break;
                            }
                        }
                        $parsed = $found;
                    }

                    if (
                        !$parsed
                        || !is_string($parsed['@type'] ?? null)
                        || !preg_match(RegExps::JSON_LD_ARTICLE_TYPES, $parsed['@type'])
                    ) {
                        continue;
                    }

                    $metadata = [];

                    $name = $parsed['name'] ?? null;
                    $headline = $parsed['headline'] ?? null;
                    if (is_string($name) && is_string($headline) && $name !== $headline) {
                        // we have both name and headline element in the JSON-LD. They should both be the same but some websites like aktualne.cz
                        // put their own name into "name" and the article title to "headline" which confuses Readability. So we try to check if either
                        // "name" or "headline" closely matches the html title, and if so, use that one. If not, then we use "name" by default.
                        $title = $this->getArticleTitle();
                        $nameMatches = $this->textSimilarity($name, $title) > 0.75;
                        $headlineMatches = $this->textSimilarity($headline, $title) > 0.75;

                        if ($headlineMatches && !$nameMatches) {
                            $metadata['title'] = $headline;
                        } else {
                            $metadata['title'] = $name;
                        }
                    } elseif (is_string($name)) {
                        $metadata['title'] = self::jsTrim($name);
                    } elseif (is_string($headline)) {
                        $metadata['title'] = self::jsTrim($headline);
                    }
                    if (isset($parsed['author'])) {
                        if (is_string($parsed['author']['name'] ?? null)) {
                            $metadata['byline'] = self::jsTrim($parsed['author']['name']);
                        } elseif (
                            is_array($parsed['author'])
                            && array_is_list($parsed['author'])
                            && is_string($parsed['author'][0]['name'] ?? null)
                        ) {
                            $metadata['byline'] = implode(', ', array_map(
                                fn (array $author): string => self::jsTrim($author['name']),
                                array_filter($parsed['author'], fn ($author): bool => is_array($author) && is_string($author['name'] ?? null))
                            ));
                        }
                    }
                    if (is_string($parsed['description'] ?? null)) {
                        $metadata['excerpt'] = self::jsTrim($parsed['description']);
                    }
                    if (is_string($parsed['publisher']['name'] ?? null)) {
                        $metadata['siteName'] = self::jsTrim($parsed['publisher']['name']);
                    }
                    if (is_string($parsed['datePublished'] ?? null)) {
                        $metadata['datePublished'] = self::jsTrim($parsed['datePublished']);
                    }
                } catch (\Throwable $err) {
                    $this->log($err->getMessage());
                }
            }
        }
        return $metadata ?? [];
    }

    /**
     * Attempts to get excerpt and byline metadata for the article.
     *
     * Mirrors _getArticleMetadata.
     *
     * @param array $jsonld object containing any metadata that could be extracted from JSON-LD object
     */
    private function getArticleMetadata(array $jsonld): array
    {
        $metadata = [];
        $values = [];
        $metaElements = $this->getAllNodesWithTag($this->doc, ['meta']);

        // Find description tags.
        foreach ($metaElements as $element) {
            $elementName = $element->getAttribute('name');
            $elementProperty = $element->getAttribute('property');
            $content = $element->getAttribute('content');
            if (!$content) {
                continue;
            }
            $matches = null;
            $name = null;

            if ($elementProperty) {
                if (preg_match(self::PROPERTY_PATTERN, $elementProperty, $m)) {
                    $matches = $m;
                    // Convert to lowercase, and remove any whitespace
                    // so we can match below.
                    $name = strtolower(preg_replace('/\s/', '', $m[0]) ?? $m[0]);
                    // multiple authors
                    $values[$name] = self::jsTrim($content);
                }
            }
            if (!$matches && $elementName && preg_match(self::NAME_PATTERN, $elementName)) {
                $name = $elementName;
                // Convert to lowercase, remove any whitespace, and convert dots
                // to colons so we can match below.
                $name = str_replace('.', ':', strtolower(preg_replace('/\s/', '', $name) ?? $name));
                $values[$name] = self::jsTrim($content);
            }
        }

        // get title
        $metadata['title'] = self::pick(
            $jsonld['title'] ?? null,
            $values['dc:title'] ?? null,
            $values['dcterm:title'] ?? null,
            $values['og:title'] ?? null,
            $values['weibo:article:title'] ?? null,
            $values['weibo:webpage:title'] ?? null,
            $values['title'] ?? null,
            $values['twitter:title'] ?? null,
            $values['parsely-title'] ?? null,
        );

        if ($metadata['title'] === null) {
            $metadata['title'] = $this->getArticleTitle();
        }

        $articleAuthor = isset($values['article:author']) && !$this->isUrl($values['article:author'])
            ? $values['article:author']
            : null;

        // get author
        $metadata['byline'] = self::pick(
            $jsonld['byline'] ?? null,
            $values['dc:creator'] ?? null,
            $values['dcterm:creator'] ?? null,
            $values['author'] ?? null,
            $values['parsely-author'] ?? null,
            $articleAuthor,
        );

        // get description
        $metadata['excerpt'] = self::pick(
            $jsonld['excerpt'] ?? null,
            $values['dc:description'] ?? null,
            $values['dcterm:description'] ?? null,
            $values['og:description'] ?? null,
            $values['weibo:article:description'] ?? null,
            $values['weibo:webpage:description'] ?? null,
            $values['description'] ?? null,
            $values['twitter:description'] ?? null,
        );

        // get site name
        $metadata['siteName'] = self::pick(
            $jsonld['siteName'] ?? null,
            $values['og:site_name'] ?? null,
        );

        // get article published time
        $metadata['publishedTime'] = self::pick(
            $jsonld['datePublished'] ?? null,
            $values['article:published_time'] ?? null,
            $values['parsely-pub-date'] ?? null,
        );

        // in many sites the meta value is escaped with HTML entities,
        // so here we need to unescape it
        $metadata['title'] = $this->unescapeHtmlEntities($metadata['title']);
        $metadata['byline'] = $this->unescapeHtmlEntities($metadata['byline']);
        $metadata['excerpt'] = $this->unescapeHtmlEntities($metadata['excerpt']);
        $metadata['siteName'] = $this->unescapeHtmlEntities($metadata['siteName']);
        $metadata['publishedTime'] = $this->unescapeHtmlEntities($metadata['publishedTime']);

        return $metadata;
    }

    /**
     * Check if node is image, or if node contains exactly only one image
     * whether as a direct child or as its descendants.
     *
     * Mirrors _isSingleImage.
     */
    private function isSingleImage(\Dom\Element $node): bool
    {
        while ($node) {
            if ($node->tagName === 'IMG') {
                return true;
            }
            if ($node->childElementCount !== 1 || self::jsTrim($node->textContent) !== '') {
                return false;
            }
            $node = $node->firstElementChild;
        }
        return false;
    }

    /**
     * Find all <noscript> that are located after <img> nodes, and which contain
     * only one <img> element. Replace the first image with the image from
     * inside the <noscript> tag, and remove the <noscript> tag. This improves
     * the quality of the images we use on some sites (e.g. Medium).
     *
     * Mirrors _unwrapNoscriptImages.
     */
    private function unwrapNoscriptImages(\Dom\HTMLDocument $doc): void
    {
        // Find img without source or attributes that might contains image, and remove it.
        // This is done to prevent a placeholder img is replaced by img from noscript in next step.
        $imgs = $this->getAllNodesWithTag($doc, ['img']);
        foreach ($imgs as $img) {
            $keep = false;
            foreach ($img->attributes as $attr) {
                if (in_array($attr->name, ['src', 'srcset', 'data-src', 'data-srcset'], true)) {
                    $keep = true;
                    break;
                }

                if (preg_match('/\.(jpg|jpeg|png|webp)/i', $attr->value)) {
                    $keep = true;
                    break;
                }
            }
            if (!$keep) {
                $img->remove();
            }
        }

        // Next find noscript and try to extract its image
        $noscripts = $this->getAllNodesWithTag($doc, ['noscript']);
        foreach ($noscripts as $noscript) {
            // Parse content of noscript and make sure it only contains image
            if (!$this->isSingleImage($noscript)) {
                continue;
            }
            $tmp = $doc->createElement('div');
            $tmp->innerHTML = $noscript->innerHTML;

            // If noscript has previous sibling and it only contains image,
            // replace it with noscript content. However we also keep old
            // attributes that might contains image.
            $prevElement = $noscript->previousElementSibling;
            if ($prevElement && $this->isSingleImage($prevElement)) {
                $prevImg = $prevElement;
                if ($prevImg->tagName !== 'IMG') {
                    $prevImg = $prevElement->querySelector('img');
                }

                $newImg = $tmp->querySelector('img');
                if ($prevImg === null || $newImg === null) {
                    // Unreachable: isSingleImage guarantees both. Keeps the
                    // null-safety visible to static analysis.
                    continue;
                }
                foreach ($prevImg->attributes as $attr) {
                    if ($attr->value === '') {
                        continue;
                    }

                    if ($attr->name === 'src' || $attr->name === 'srcset' || preg_match('/\.(jpg|jpeg|png|webp)/i', $attr->value)) {
                        if ($newImg->getAttribute($attr->name) === $attr->value) {
                            continue;
                        }

                        $attrName = $attr->name;
                        if ($newImg->hasAttribute($attrName)) {
                            $attrName = 'data-old-' . $attrName;
                        }

                        try {
                            $newImg->setAttribute($attrName, $attr->value);
                        } catch (\DOMException) {
                            // Skip attributes with names the DOM API rejects (upstream #918).
                        }
                    }
                }

                $noscript->parentNode->replaceChild($tmp->firstElementChild, $prevElement);
            }
        }
    }

    /**
     * Removes script tags from the document.
     *
     * Mirrors _removeScripts.
     */
    private function removeScripts(\Dom\HTMLDocument $doc): void
    {
        $this->removeNodes($this->getAllNodesWithTag($doc, ['script', 'noscript']));
    }

    /**
     * Check if this node has only whitespace and a single element with given
     * tag. Returns false if the DIV node contains non-empty text nodes or if
     * it contains no element with given tag or more than 1 element.
     *
     * Mirrors _hasSingleTagInsideElement.
     */
    private function hasSingleTagInsideElement(\Dom\Element $element, string $tag): bool
    {
        // There should be exactly 1 element child with given tag
        if ($element->childElementCount !== 1 || $element->firstElementChild->tagName !== $tag) {
            return false;
        }

        // And there should be no text nodes with real content
        foreach ($element->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE && preg_match(RegExps::HAS_CONTENT, $node->textContent)) {
                return false;
            }
        }
        return true;
    }

    /** Mirrors _isElementWithoutContent. */
    private function isElementWithoutContent(\Dom\Element $node): bool
    {
        return self::jsTrim($node->textContent) === ''
            && ($node->childElementCount === 0
                || $node->childElementCount === $node->getElementsByTagName('br')->length + $node->getElementsByTagName('hr')->length);
    }

    /**
     * Determine whether element has any children block level elements.
     *
     * Mirrors _hasChildBlockElement.
     */
    private function hasChildBlockElement(\Dom\Element $element): bool
    {
        foreach ($element->childNodes as $node) {
            if ($node instanceof \Dom\Element
                && (in_array($node->tagName, self::DIV_TO_P_ELEMS, true) || $this->hasChildBlockElement($node))
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Determine if a node qualifies as phrasing content.
     * https://developer.mozilla.org/en-US/docs/Web/Guide/HTML/Content_categories#Phrasing_content
     *
     * Mirrors _isPhrasingContent.
     */
    private function isPhrasingContent(\Dom\Node $node): bool
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return true;
        }
        if (!$node instanceof \Dom\Element) {
            return false;
        }
        if (in_array($node->tagName, self::PHRASING_ELEMS, true)) {
            return true;
        }
        if (!in_array($node->tagName, ['A', 'DEL', 'INS'], true)) {
            return false;
        }
        foreach ($node->childNodes as $child) {
            if (!$this->isPhrasingContent($child)) {
                return false;
            }
        }
        return true;
    }

    /** Mirrors _isWhitespace. */
    private function isWhitespace(\Dom\Node $node): bool
    {
        return ($node->nodeType === XML_TEXT_NODE && self::jsTrim($node->textContent) === '')
            || ($node instanceof \Dom\Element && $node->tagName === 'BR');
    }

    /**
     * Get the inner text of a node. This also strips out any excess whitespace
     * to be found.
     *
     * Mirrors _getInnerText.
     */
    private function getInnerText(\Dom\Node $e, bool $normalizeSpaces = true): string
    {
        $textContent = self::jsTrim($e->textContent);

        if ($normalizeSpaces) {
            return preg_replace(RegExps::NORMALIZE, ' ', $textContent) ?? $textContent;
        }
        return $textContent;
    }

    /**
     * Get the number of times a string s appears in the node e.
     *
     * Mirrors _getCharCount.
     */
    private function getCharCount(\Dom\Element $e, string $s = ','): int
    {
        return substr_count($this->getInnerText($e), $s);
    }

    /**
     * Remove the style attribute on every e and under.
     *
     * Mirrors _cleanStyles.
     */
    private function cleanStyles(?\Dom\Element $e): void
    {
        if (!$e || strtolower($e->tagName) === 'svg') {
            return;
        }

        // Remove `style` and deprecated presentational attributes
        foreach (self::PRESENTATIONAL_ATTRIBUTES as $presentationalAttribute) {
            $e->removeAttribute($presentationalAttribute);
        }

        if (in_array($e->tagName, self::DEPRECATED_SIZE_ATTRIBUTE_ELEMS, true)) {
            $e->removeAttribute('width');
            $e->removeAttribute('height');
        }

        $cur = $e->firstElementChild;
        while ($cur !== null) {
            $this->cleanStyles($cur);
            $cur = $cur->nextElementSibling;
        }
    }

    /**
     * Get the density of links as a percentage of the content. This is the
     * amount of text that is inside a link divided by the total text in the
     * node.
     *
     * Mirrors _getLinkDensity.
     */
    private function getLinkDensity(\Dom\Element $element): float
    {
        $textLength = mb_strlen($this->getInnerText($element));
        if ($textLength === 0) {
            return 0;
        }

        $linkLength = 0;

        foreach ($element->getElementsByTagName('a') as $linkNode) {
            $href = $linkNode->getAttribute('href');
            $coefficient = $href && preg_match(RegExps::HASH_URL, $href) ? 0.3 : 1;
            $linkLength += mb_strlen($this->getInnerText($linkNode)) * $coefficient;
        }

        return $linkLength / $textLength;
    }

    /**
     * Get an elements class/id weight. Uses regular expressions to tell if
     * this element looks good or bad.
     *
     * Mirrors _getClassWeight.
     */
    private function getClassWeight(\Dom\Element $e): int
    {
        if (!$this->flagIsActive(self::FLAG_WEIGHT_CLASSES)) {
            return 0;
        }

        $weight = 0;

        // Look for a special classname
        if ($e->className !== '') {
            if (preg_match(RegExps::NEGATIVE, $e->className)) {
                $weight -= 25;
            }

            if (preg_match(RegExps::POSITIVE, $e->className)) {
                $weight += 25;
            }
        }

        // Look for a special ID
        if ($e->id !== '') {
            if (preg_match(RegExps::NEGATIVE, $e->id)) {
                $weight -= 25;
            }

            if (preg_match(RegExps::POSITIVE, $e->id)) {
                $weight += 25;
            }
        }

        return $weight;
    }

    /**
     * Clean a node of all elements of type "tag".
     * (Unless it's a youtube/vimeo video. People love movies.)
     *
     * Mirrors _clean.
     */
    private function clean(\Dom\Element $e, string $tag): void
    {
        $isEmbed = in_array($tag, ['object', 'embed', 'iframe'], true);

        $this->removeNodes($this->getAllNodesWithTag($e, [$tag]), function (\Dom\Element $element) use ($isEmbed): bool {
            // Allow youtube and vimeo videos through as people usually want to see those.
            if ($isEmbed) {
                // First, check the elements attributes to see if any of them contain youtube or vimeo
                foreach ($element->attributes as $attr) {
                    if (preg_match($this->allowedVideoRegex, $attr->value)) {
                        return false;
                    }
                }

                // For embed with <object> tag, check inner HTML as well.
                // (Kept verbatim from Readability.js, where tagName is uppercase,
                // so this lowercase comparison never matches there either.)
                if ($element->tagName === 'object' && preg_match($this->allowedVideoRegex, $element->innerHTML)) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Check if a given node has one of its ancestor tag name matching the
     * provided one.
     *
     * Mirrors _hasAncestorTag.
     *
     * @param callable(\Dom\Element): bool|null $filterFn a filter to invoke to determine whether this node 'counts'
     */
    private function hasAncestorTag(\Dom\Element $node, string $tagName, int $maxDepth = 3, ?callable $filterFn = null): bool
    {
        $tagName = strtoupper($tagName);
        $depth = 0;
        while ($node->parentNode) {
            if ($maxDepth > 0 && $depth > $maxDepth) {
                return false;
            }
            if (
                $node->parentNode instanceof \Dom\Element
                && $node->parentNode->tagName === $tagName
                && (!$filterFn || $filterFn($node->parentNode))
            ) {
                return true;
            }
            $node = $node->parentNode;
            $depth++;
        }
        return false;
    }

    /**
     * Return an array indicating how many rows and columns this table has.
     *
     * Mirrors _getRowAndColumnCount.
     *
     * @return array{rows: int, columns: int}
     */
    private function getRowAndColumnCount(\Dom\Element $table): array
    {
        $rows = 0;
        $columns = 0;
        foreach ($table->getElementsByTagName('tr') as $tr) {
            $rowspan = (int) ($tr->getAttribute('rowspan') ?? 0);
            $rows += $rowspan ?: 1;

            // Now look for column-related info
            $columnsInThisRow = 0;
            foreach ($tr->getElementsByTagName('td') as $cell) {
                $colspan = (int) ($cell->getAttribute('colspan') ?? 0);
                $columnsInThisRow += $colspan ?: 1;
            }
            $columns = max($columns, $columnsInThisRow);
        }
        return ['rows' => $rows, 'columns' => $columns];
    }

    /**
     * Look for 'data' (as opposed to 'layout') tables, for which we use
     * similar checks as
     * https://searchfox.org/mozilla-central/rev/f82d5c549f046cb64ce5602bfd894b7ae807c8f8/accessible/generic/TableAccessible.cpp#19
     *
     * Mirrors _markDataTables (JS: table._readabilityDataTable).
     */
    private function markDataTables(\Dom\Element $root): void
    {
        foreach ($root->getElementsByTagName('table') as $table) {
            $role = $table->getAttribute('role');
            if ($role === 'presentation') {
                $this->dataTables[$table] = false;
                continue;
            }
            $datatable = $table->getAttribute('datatable');
            if ($datatable === '0') {
                $this->dataTables[$table] = false;
                continue;
            }
            $summary = $table->getAttribute('summary');
            if ($summary) {
                $this->dataTables[$table] = true;
                continue;
            }

            $caption = $table->getElementsByTagName('caption')->item(0);
            if ($caption && $caption->childNodes->length) {
                $this->dataTables[$table] = true;
                continue;
            }

            // If the table has a descendant with any of these tags, consider a data table:
            $dataTableDescendants = ['col', 'colgroup', 'tfoot', 'thead', 'th'];
            $descendantExists = fn (string $tag): bool => (bool) $table->getElementsByTagName($tag)->item(0);
            if (array_any($dataTableDescendants, $descendantExists)) {
                $this->log('Data table because found data-y descendant');
                $this->dataTables[$table] = true;
                continue;
            }

            // Nested tables indicate a layout table:
            if ($table->getElementsByTagName('table')->item(0)) {
                $this->dataTables[$table] = false;
                continue;
            }

            $sizeInfo = $this->getRowAndColumnCount($table);

            if ($sizeInfo['columns'] === 1 || $sizeInfo['rows'] === 1) {
                // single colum/row tables are commonly used for page layout purposes.
                $this->dataTables[$table] = false;
                continue;
            }

            if ($sizeInfo['rows'] >= 10 || $sizeInfo['columns'] > 4) {
                $this->dataTables[$table] = true;
                continue;
            }
            // Now just go by size entirely:
            $this->dataTables[$table] = $sizeInfo['rows'] * $sizeInfo['columns'] > 10;
        }
    }

    /** The isDataTable check used by cleanConditionally (JS: t._readabilityDataTable). */
    private function isDataTable(\Dom\Element $table): bool
    {
        return $this->dataTables->offsetExists($table) && $this->dataTables[$table];
    }

    /**
     * Convert images and figures that have properties like data-src into
     * images that can be loaded without JS.
     *
     * Mirrors _fixLazyImages.
     */
    private function fixLazyImages(\Dom\Element $root): void
    {
        foreach ($this->getAllNodesWithTag($root, ['img', 'picture', 'figure']) as $elem) {
            $src = $elem->getAttribute('src') ?? '';
            // In some sites (e.g. Kotaku), they put 1px square image as base64 data uri in the src attribute.
            // So, here we check if the data uri is too short, just might as well remove it.
            if ($src && preg_match(RegExps::B64_DATA_URL, $src, $parts)) {
                // Make sure it's not SVG, because SVG can have a meaningful image in under 133 bytes.
                if ($parts[1] === 'image/svg+xml') {
                    continue;
                }

                // Make sure this element has other attributes which contains image.
                // If it doesn't, then this src is important and shouldn't be removed.
                $srcCouldBeRemoved = false;
                foreach ($elem->attributes as $attr) {
                    if ($attr->name === 'src') {
                        continue;
                    }

                    if (preg_match('/\.(jpg|jpeg|png|webp)/i', $attr->value)) {
                        $srcCouldBeRemoved = true;
                        break;
                    }
                }

                // Here we assume if image is less than 100 bytes (or 133 after encoded to base64)
                // it will be too small, therefore it might be placeholder image.
                if ($srcCouldBeRemoved) {
                    $b64starts = strlen($parts[0]);
                    $b64length = strlen($src) - $b64starts;
                    if ($b64length < 133) {
                        $elem->removeAttribute('src');
                        $src = '';
                    }
                }
            }

            $srcset = $elem->getAttribute('srcset') ?? '';
            // The "null" check works around a jsdom bug, kept for parity (upstream jsdom#2580).
            if (($src || ($srcset && $srcset !== 'null')) && !str_contains(strtolower($elem->className), 'lazy')) {
                continue;
            }

            foreach (iterator_to_array($elem->attributes) as $attr) {
                if (in_array($attr->name, ['src', 'srcset', 'alt'], true)) {
                    continue;
                }
                $copyTo = null;
                if (preg_match('/\.(jpg|jpeg|png|webp)\s+\d/', $attr->value)) {
                    $copyTo = 'srcset';
                } elseif (preg_match('/^\s*\S+\.(jpg|jpeg|png|webp)\S*\s*$/', $attr->value)) {
                    $copyTo = 'src';
                }
                if ($copyTo) {
                    // if this is an img or picture, set the attribute directly
                    if ($elem->tagName === 'IMG' || $elem->tagName === 'PICTURE') {
                        $elem->setAttribute($copyTo, $attr->value);
                    } elseif (
                        $elem->tagName === 'FIGURE'
                        && !count($this->getAllNodesWithTag($elem, ['img', 'picture']))
                    ) {
                        // if the item is a <figure> that does not contain an image or picture,
                        // create one and place it inside the figure; see the nytimes-3 testcase for an example
                        $img = $this->doc->createElement('img');
                        $img->setAttribute($copyTo, $attr->value);
                        $elem->appendChild($img);
                    }
                }
            }
        }
    }

    /**
     * Mirrors _getTextDensity.
     *
     * @param list<string> $tags
     */
    private function getTextDensity(\Dom\Element $e, array $tags): float
    {
        $textLength = mb_strlen($this->getInnerText($e, true));
        if ($textLength === 0) {
            return 0;
        }
        $childrenLength = 0;
        $children = $this->getAllNodesWithTag($e, $tags);
        foreach ($children as $child) {
            $childrenLength += mb_strlen($this->getInnerText($child, true));
        }
        return $childrenLength / $textLength;
    }

    /**
     * Clean an element of all tags of type "tag" if they look fishy. "Fishy"
     * is an algorithm based on content length, classnames, link density,
     * number of images & embeds, etc.
     *
     * Mirrors _cleanConditionally.
     */
    private function cleanConditionally(\Dom\Element $e, string $tag): void
    {
        if (!$this->flagIsActive(self::FLAG_CLEAN_CONDITIONALLY)) {
            return;
        }

        // Gather counts for other typical elements embedded within.
        // Traverse backwards so we can remove nodes at the same time
        // without effecting the traversal.
        $this->removeNodes($this->getAllNodesWithTag($e, [$tag]), function (\Dom\Element $node) use ($tag): bool {
            $isList = $tag === 'ul' || $tag === 'ol';
            if (!$isList) {
                $listLength = 0;
                $listNodes = $this->getAllNodesWithTag($node, ['ul', 'ol']);
                foreach ($listNodes as $list) {
                    $listLength += mb_strlen($this->getInnerText($list));
                }
                $nodeTextLength = mb_strlen($this->getInnerText($node));
                $isList = $nodeTextLength > 0 && $listLength / $nodeTextLength > 0.9;
            }

            // First check if this node IS data table, in which case don't remove it.
            if ($tag === 'table' && $this->isDataTable($node)) {
                return false;
            }

            // Next check if we're inside a data table, in which case don't remove it as well.
            if ($this->hasAncestorTag($node, 'table', -1, $this->isDataTable(...))) {
                return false;
            }

            if ($this->hasAncestorTag($node, 'code')) {
                return false;
            }

            // keep element if it has a data tables
            foreach ($node->getElementsByTagName('table') as $tbl) {
                if ($this->isDataTable($tbl)) {
                    return false;
                }
            }

            $weight = $this->getClassWeight($node);

            $this->log('Cleaning Conditionally', $node);

            $contentScore = 0;

            if ($weight + $contentScore < 0) {
                return true;
            }

            if ($this->getCharCount($node, ',') < 10) {
                // If there are not very many commas, and the number of
                // non-paragraph elements is more than paragraphs or other
                // ominous signs, remove the element.
                $p = $node->getElementsByTagName('p')->length;
                $img = $node->getElementsByTagName('img')->length;
                $li = $node->getElementsByTagName('li')->length - 100;
                $input = $node->getElementsByTagName('input')->length;
                $headingDensity = $this->getTextDensity($node, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6']);

                $embedCount = 0;
                $embeds = $this->getAllNodesWithTag($node, ['object', 'embed', 'iframe']);

                foreach ($embeds as $embed) {
                    // If this embed has attribute that matches video regex, don't delete it.
                    foreach ($embed->attributes as $attr) {
                        if (preg_match($this->allowedVideoRegex, $attr->value)) {
                            return false;
                        }
                    }

                    // For embed with <object> tag, check inner HTML as well.
                    // (Kept verbatim from Readability.js, where tagName is uppercase,
                    // so this lowercase comparison never matches there either.)
                    if ($embed->tagName === 'object' && preg_match($this->allowedVideoRegex, $embed->innerHTML)) {
                        return false;
                    }

                    $embedCount++;
                }

                $innerText = $this->getInnerText($node);

                // toss any node whose inner text contains nothing but suspicious words
                if (preg_match(RegExps::AD_WORDS, $innerText) || preg_match(RegExps::LOADING_WORDS, $innerText)) {
                    return true;
                }

                $contentLength = mb_strlen($innerText);
                $linkDensity = $this->getLinkDensity($node);
                $textishTags = ['span', 'li', 'td', ...array_map(strtolower(...), self::DIV_TO_P_ELEMS)];
                $textDensity = $this->getTextDensity($node, $textishTags);
                $isFigureChild = $this->hasAncestorTag($node, 'figure');

                // apply shadiness checks, then check for exceptions
                $errs = [];
                if (!$isFigureChild && $img > 1 && $p / $img < 0.5) {
                    $errs[] = "Bad p to img ratio (img={$img}, p={$p})";
                }
                if (!$isList && $li > $p) {
                    $errs[] = "Too many li's outside of a list. (li={$li} > p={$p})";
                }
                if ($input > floor($p / 3)) {
                    $errs[] = "Too many inputs per p. (input={$input}, p={$p})";
                }
                if (
                    !$isList
                    && !$isFigureChild
                    && $headingDensity < 0.9
                    && $contentLength < 25
                    && ($img === 0 || $img > 2)
                    && $linkDensity > 0
                ) {
                    $errs[] = "Suspiciously short. (headingDensity={$headingDensity}, img={$img}, linkDensity={$linkDensity})";
                }
                if (!$isList && $weight < 25 && $linkDensity > 0.2 + $this->configuration->linkDensityModifier) {
                    $errs[] = "Low weight and a little linky. (linkDensity={$linkDensity})";
                }
                if ($weight >= 25 && $linkDensity > 0.5 + $this->configuration->linkDensityModifier) {
                    $errs[] = "High weight and mostly links. (linkDensity={$linkDensity})";
                }
                if (($embedCount === 1 && $contentLength < 75) || $embedCount > 1) {
                    $errs[] = "Suspicious embed. (embedCount={$embedCount}, contentLength={$contentLength})";
                }
                if ($img === 0 && $textDensity === 0.0) {
                    $errs[] = "No useful content. (img={$img}, textDensity={$textDensity})";
                }
                if ($errs) {
                    $this->log('Checks failed', ...$errs);
                }
                $haveToRemove = (bool) $errs;

                // Allow simple lists of images to remain in pages
                if ($isList && $haveToRemove) {
                    foreach ($this->children($node) as $child) {
                        // Don't filter in lists with li's that contain more than one child
                        if ($child->childElementCount > 1) {
                            return $haveToRemove;
                        }
                    }
                    $liCount = $node->getElementsByTagName('li')->length;
                    // Only allow the list to remain if every li contains an image
                    if ($img === $liCount) {
                        return false;
                    }
                }
                return $haveToRemove;
            }
            return false;
        });
    }

    /**
     * Clean out elements that match the specified conditions.
     *
     * Mirrors _cleanMatchedNodes.
     *
     * @param callable(\Dom\Element, string): bool $filter determines whether a node should be removed
     */
    private function cleanMatchedNodes(\Dom\Element $e, callable $filter): void
    {
        $endOfSearchMarkerNode = $this->getNextNode($e, true);
        $next = $this->getNextNode($e);
        while ($next && $next !== $endOfSearchMarkerNode) {
            if ($filter($next, $next->className . ' ' . $next->id)) {
                $next = $this->removeAndGetNext($next);
            } else {
                $next = $this->getNextNode($next);
            }
        }
    }

    /**
     * Clean out spurious headers from an Element.
     *
     * Mirrors _cleanHeaders.
     */
    private function cleanHeaders(\Dom\Element $e): void
    {
        $headingNodes = $this->getAllNodesWithTag($e, ['h1', 'h2']);
        $this->removeNodes($headingNodes, function (\Dom\Element $node): bool {
            $shouldRemove = $this->getClassWeight($node) < 0;
            if ($shouldRemove) {
                $this->log('Removing header with low class weight:', $node);
            }
            return $shouldRemove;
        });
    }

    /**
     * Check if this node is an H1 or H2 element whose content is mostly the
     * same as the article title.
     *
     * Mirrors _headerDuplicatesTitle.
     */
    private function headerDuplicatesTitle(\Dom\Element $node): bool
    {
        if ($node->tagName !== 'H1' && $node->tagName !== 'H2') {
            return false;
        }
        $heading = $this->getInnerText($node, false);
        $this->log('Evaluating similarity of header:', $heading, $this->articleTitle);
        return $this->textSimilarity($this->articleTitle ?? '', $heading) > 0.75;
    }

    /** Mirrors _flagIsActive. */
    private function flagIsActive(int $flag): bool
    {
        return ($this->flags & $flag) > 0;
    }

    /** Mirrors _removeFlag. */
    private function removeFlag(int $flag): void
    {
        $this->flags &= ~$flag;
    }

    /**
     * Mirrors _isProbablyVisible. JS checks node.style, which reflects only
     * the inline style attribute, so a match on the attribute text is
     * equivalent here.
     */
    private function isProbablyVisible(\Dom\Element $node): bool
    {
        $style = $node->getAttribute('style') ?? '';
        return !preg_match('/display\s*:\s*none/i', $style)
            && !preg_match('/visibility\s*:\s*hidden/i', $style)
            && !$node->hasAttribute('hidden')
            // check for "fallback-image" so that wikimedia math images are displayed
            && (!$node->hasAttribute('aria-hidden')
                || $node->getAttribute('aria-hidden') !== 'true'
                || str_contains($node->className, 'fallback-image'));
    }

    /**
     * Element-only child list; the PHP DOM has no `children` collection.
     *
     * @return list<\Dom\Element>
     */
    private function children(\Dom\Element|\Dom\Document|\Dom\DocumentFragment $node): array
    {
        $children = [];
        for ($child = $node->firstElementChild; $child; $child = $child->nextElementSibling) {
            $children[] = $child;
        }
        return $children;
    }

    /** Equivalent of JavaScript's String.prototype.trim (which also trims NBSP etc.). */
    private static function jsTrim(string $str): string
    {
        return preg_replace(RegExps::TRIM, '', $str) ?? $str;
    }

    /**
     * First value that is a non-empty string, or null. Stands in for the
     * `a || b || c` chains JS uses over possibly-empty metadata strings.
     */
    private static function pick(?string ...$values): ?string
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return $value;
            }
        }
        return null;
    }

    /** The this.log shim: enabled by Configuration::debug (JS: options.debug). */
    private function log(mixed ...$args): void
    {
        $logger = $this->configuration->logger;
        if (!$this->configuration->debug && $logger === null) {
            return;
        }
        $format = function (mixed $arg): string {
            // Closures defer expensive serialization (e.g. a large innerHTML)
            // until here, past the enabled check above, so nothing is built
            // when logging is off.
            if ($arg instanceof \Closure) {
                $arg = $arg();
            }
            if ($arg instanceof \Dom\Element) {
                $attrPairs = [];
                foreach ($arg->attributes as $attr) {
                    $attrPairs[] = "{$attr->name}=\"{$attr->value}\"";
                }
                return '<' . $arg->localName . ' ' . implode(' ', $attrPairs) . '>';
            }
            if ($arg instanceof \Dom\Node) {
                return "{$arg->nodeName} (\"{$arg->textContent}\")";
            }
            return is_scalar($arg) || $arg === null ? (string) $arg : var_export($arg, true);
        };
        $message = implode(' ', array_map($format, $args));
        $logger?->debug($message);
        if ($this->configuration->debug) {
            error_log('Reader: (Readability) ' . $message);
        }
    }
}
