<?php

namespace fivefilters\Readability\Nodes;

use fivefilters\Readability\Nodes\DOM;
use fivefilters\Readability\Nodes\DOM\Element;
use fivefilters\Readability\Nodes\DOM\Node;
use fivefilters\Readability\Nodes\DOM\Text;
use fivefilters\Readability\Nodes\DOM\Comment;
use fivefilters\Readability\Nodes\DOM\NodeList;

/**
 * Class NodeUtility.
 */
class NodeUtility
{
    /**
     * Collection of regexps to check the node usability.
     *
     * @var array
     */
    public static $regexps = [
        'unlikelyCandidates' => '/-ad-|ai2html|banner|breadcrumbs|combx|comment|community|cover-wrap|disqus|extra|footer|gdpr|header|legends|menu|related|remark|replies|rss|shoutbox|sidebar|skyscraper|social|sponsor|supplemental|ad-break|agegate|pagination|pager|popup|yom-remote/i',
        'okMaybeItsACandidate' => '/and|article|body|column|content|main|shadow/i',
        'extraneous' => '/print|archive|comment|discuss|e[\-]?mail|share|reply|all|login|sign|single|utility/i',
        'byline' => '/byline|author|dateline|writtenby|p-author/i',
        'replaceFonts' => '/<(\/?)font[^>]*>/i',
        'normalize' => '/\s{2,}/',
        'videos' => '/\/\/(www\.)?((dailymotion|youtube|youtube-nocookie|player\.vimeo|v\.qq)\.com|(archive|upload\.wikimedia)\.org|player\.twitch\.tv)/i',
        'shareElements' => '/(\b|_)(share|sharedaddy)(\b|_)/i',
        'nextLink' => '/(next|weiter|continue|>([^\|]|$)|»([^\|]|$))/i',
        'prevLink' => '/(prev|earl|old|new|<|«)/i',
        'tokenize' => '/\W+/',
        'whitespace' => '/^\s*$/',
        'hasContent' => '/\S$/',
        'positive' => '/article|body|content|entry|hentry|h-entry|main|page|pagination|post|text|blog|story/i',
        'negative' => '/-ad-|hidden|^hid$| hid$| hid |^hid |banner|combx|comment|com-|contact|foot|footer|footnote|gdpr|masthead|media|meta|outbrain|promo|related|scroll|share|shoutbox|sidebar|skyscraper|sponsor|shopping|tags|tool|widget/i',
        // \x{00A0} is the unicode version of &nbsp;
        'onlyWhitespace' => '/\x{00A0}|\s+/u',
        'hashUrl' => '/^#.+/',
        'srcsetUrl' => '/(\S+)(\s+[\d.]+[xw])?(\s*(?:,|$))/',
        'b64DataUrl' => '/^data:\s*([^\s;,]+)\s*;\s*base64\s*,/i',
        // See: https://schema.org/Article
        'jsonLdArticleTypes' => '/^Article|AdvertiserContentArticle|NewsArticle|AnalysisNewsArticle|AskPublicNewsArticle|BackgroundNewsArticle|OpinionNewsArticle|ReportageNewsArticle|ReviewNewsArticle|Report|SatiricalArticle|ScholarlyArticle|MedicalScholarlyArticle|SocialMediaPosting|BlogPosting|LiveBlogPosting|DiscussionForumPosting|TechArticle|APIReference$/'

    ];

    /**
     * Finds the next node, starting from the given node, and ignoring
     * whitespace in between. If the given node is an element, the same node is
     * returned.
     *
     * Imported from the Element class on league\html-to-markdown.
     */
    public static function nextNode(Node|Comment|Text|Element|null $node): Node|Comment|Text|Element|null
    {
        $next = $node;
        while ($next
            && $next->nodeType !== XML_ELEMENT_NODE
            && $next->isWhitespace()) {
            $next = $next->nextSibling;
        }

        return $next;
    }

    /**
     * Not in the DOM spec, but PHP 8.4 introduced rename() for DOM\Element and DOM\Attr
     */
    public static function setNodeTag(Element $element, string $newName): void
    {
        $element->rename($element->namespaceURI, $newName);
    }

    /**
     * Removes the current node and returns the next node to be parsed (child, sibling or parent).
     */
    public static function removeAndGetNext(Node|Comment|Text|Element $node): Node|Comment|Text|Element|null
    {
        $nextNode = self::getNextNode($node, true);
        $node->parentNode->removeChild($node);

        return $nextNode;
    }

    /**
     * Remove the selected node.
     */
    public static function removeNode(Node|Comment|Text|Element $node): void
    {
        $parent = $node->parentNode;
        if ($parent) {
            $parent->removeChild($node);
        }
    }

    /**
     * Returns the next node. First checks for children (if the flag allows it), then for siblings, and finally
     * for parents.
     */
    public static function getNextNode(Node|Comment|Text|Element|\Dom\HtmlDocument $originalNode, bool $ignoreSelfAndKids = false): Node|Comment|Text|Element|\Dom\HtmlDocument|null
    {
        /*
         * Traverse the DOM from node to node, starting at the node passed in.
         * Pass true for the second parameter to indicate this node itself
         * (and its kids) are going away, and we want the next node over.
         *
         * Calling this in a loop will traverse the DOM depth-first.
         */

        // First check for kids if those aren't being ignored
        if (!$ignoreSelfAndKids && $originalNode->firstChild) {
            return $originalNode->firstChild;
        }

        // Then for siblings...
        if ($originalNode->nextSibling) {
            return $originalNode->nextSibling;
        }

        // And finally, move up the parent chain *and* find a sibling
        // (because this is depth-first traversal, we will have already
        // seen the parent nodes themselves).
        do {
            $originalNode = $originalNode->parentNode;
        } while ($originalNode && !$originalNode->nextSibling);

        return ($originalNode) ? $originalNode->nextSibling : $originalNode;
    }

    /**
     * Remove all empty DOMNodes from DOMNodeLists.
     */
    public static function filterTextNodes(\Dom\NodeList $list): NodeList
    {
        $newList = new NodeList();
        foreach ($list as $node) {
            if ($node->nodeType !== XML_TEXT_NODE || mb_trim($node->nodeValue) !== '') {
                $newList->add($node);
            }
        }

        return $newList;
    }

    public static function registerReadabilityNodeClasses(\DOM\HtmlDocument $dom): void
    {
        $dom->registerNodeClass('DOM\HtmlElement', DOM\Element::class);
        $dom->registerNodeClass('DOM\Attr', DOM\Attr::class);
        $dom->registerNodeClass('DOM\CdataSection', DOM\CdataSection::class);
        $dom->registerNodeClass('DOM\CharacterData', DOM\CharacterData::class);
        $dom->registerNodeClass('DOM\Comment', DOM\Comment::class);
        //$dom->registerNodeClass('DOM\HtmlDocument', DOM\HtmlDocument::class);
        $dom->registerNodeClass('DOM\DocumentFragment', DOM\DocumentFragment::class);
        $dom->registerNodeClass('DOM\DocumentType', DOM\DocumentType::class);
        $dom->registerNodeClass('DOM\Element', DOM\Element::class);
        $dom->registerNodeClass('DOM\Entity', DOM\Entity::class);
        $dom->registerNodeClass('DOM\EntityReference', DOM\EntityReference::class);
        $dom->registerNodeClass('DOM\Node', DOM\Node::class);
        $dom->registerNodeClass('DOM\Notation', DOM\Notation::class);
        $dom->registerNodeClass('DOM\ProcessingInstruction', DOM\ProcessingInstruction::class);
        $dom->registerNodeClass('DOM\Text', DOM\Text::class);
    }
}
