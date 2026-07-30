<?php

declare(strict_types=1);

namespace fivefilters\Readability;

/**
 * The result of a parse.
 *
 * Mirrors the object returned by Readability.js parse(), with one PHP-specific
 * extension: where Readability.js returns null when it finds no article
 * content — discarding the title and metadata it had already extracted — this
 * library still returns an Article carrying that metadata, with the
 * content-derived properties (content, textContent, length, contentElement)
 * set to null. Use hasContent() to tell the two apart.
 *
 * The content-derived properties (content, textContent, length, images) are
 * computed from contentElement on first access and cached, so callers that
 * only need the DOM tree never pay for serializing it. Mutating
 * contentElement after reading one of them will not be reflected in
 * subsequent reads.
 */
final class Article implements \JsonSerializable
{
    /** HTML string of the processed article content; null when no content was found. */
    public ?string $content {
        get {
            if ($this->contentElement === null) {
                return null;
            }
            return $this->contentCache ??= $this->contentElement->innerHTML;
        }
    }

    /** Text content of the article, with all the HTML tags removed; null when no content was found. */
    public ?string $textContent {
        get {
            if ($this->contentElement === null) {
                return null;
            }
            return $this->textContentCache ??= $this->contentElement->textContent;
        }
    }

    /** Length of the article's text content, in characters (Unicode code points); null when no content was found. */
    public ?int $length {
        get {
            if ($this->contentElement === null) {
                return null;
            }
            return $this->lengthCache ??= mb_strlen($this->textContent);
        }
    }

    /**
     * All image URLs found for the article: the lead image (if any)
     * followed by every <img> in the content, de-duplicated. PHP-specific.
     * Content srcs are absolute when fixRelativeURLs is enabled.
     *
     * @var list<string>
     */
    public array $images {
        get => $this->imagesCache ??= $this->collectImages();
    }

    private ?string $contentCache = null;
    private ?string $textContentCache = null;
    private ?int $lengthCache = null;
    /** @var ?list<string> */
    private ?array $imagesCache = null;

    public function __construct(
        /** Article title (empty string if none was found). */
        public readonly string $title,
        /** Author metadata, if found. */
        public readonly ?string $byline,
        /** Content direction ("ltr"/"rtl"), if found. */
        public readonly ?string $dir,
        /** Content language, if found. */
        public readonly ?string $lang,
        /** Article description, or short excerpt from the content. */
        public readonly ?string $excerpt,
        /** Name of the site, if found. */
        public readonly ?string $siteName,
        /** Published time, if found. */
        public readonly ?string $publishedTime,
        /**
         * The lead image URL (from og:image/twitter:image, or a
         * <link rel="img_src">), if found. PHP-specific; not part of
         * Readability.js. Absolute when fixRelativeURLs is enabled.
         */
        public readonly ?string $image,
        /** The article content as a DOM element, for callers who want to keep working on the tree; null when no content was found. */
        public readonly ?\Dom\Element $contentElement,
    ) {
    }

    /**
     * Whether article content was found. When false — the case where
     * Readability.js returns null — only the title and metadata properties
     * are populated; content, textContent, length and contentElement are null.
     */
    public function hasContent(): bool
    {
        return $this->contentElement !== null;
    }

    public function __toString(): string
    {
        return $this->content ?? '';
    }

    /**
     * json_encode() and var_dump() only see real properties, not virtual
     * (hooked) ones, which would silently drop content, textContent, length
     * and images. These two methods spell out the full property list, in the
     * order the eager implementation used, so the output is unchanged. Note
     * that both therefore trigger the serialization the lazy properties
     * otherwise avoid.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'title' => $this->title,
            'byline' => $this->byline,
            'dir' => $this->dir,
            'lang' => $this->lang,
            'content' => $this->content,
            'textContent' => $this->textContent,
            'length' => $this->length,
            'excerpt' => $this->excerpt,
            'siteName' => $this->siteName,
            'publishedTime' => $this->publishedTime,
            'image' => $this->image,
            'images' => $this->images,
            'contentElement' => $this->contentElement,
        ];
    }

    /** @return list<string> */
    private function collectImages(): array
    {
        $urls = [];
        if ($this->image !== null) {
            $urls[] = $this->image;
        }
        if ($this->contentElement !== null) {
            foreach ($this->contentElement->querySelectorAll('img') as $img) {
                $src = (string) $img->getAttribute('src');
                if ($src !== '') {
                    $urls[] = $src;
                }
            }
        }
        return array_values(array_unique($urls));
    }
}
