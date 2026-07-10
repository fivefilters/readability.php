<?php

declare(strict_types=1);

namespace fivefilters\Readability\Test;

/**
 * Structural DOM comparison of two HTML strings, a port of the traverseDOM /
 * inOrderTraverse / htmlTransform comparison in Mozilla's test-readability.js.
 *
 * Compares element names, ids, classes, attributes (XML-valid names only) and
 * whitespace-collapsed text, node by node in document order. This makes
 * Mozilla's expected.html files usable directly, despite serializer
 * differences between this library and the JS tooling.
 */
final class DomCompare
{
    /**
     * @return string|null a description of the first difference, or null if the trees match
     */
    public static function compare(string $expectedHtml, string $actualHtml): ?string
    {
        $expectedDoc = \Dom\HTMLDocument::createFromString($expectedHtml, LIBXML_NOERROR);
        $actualDoc = \Dom\HTMLDocument::createFromString($actualHtml, LIBXML_NOERROR);

        $expectedNode = $expectedDoc->documentElement;
        $actualNode = $actualDoc->documentElement;

        while ($actualNode || $expectedNode) {
            if (!$actualNode || !$expectedNode) {
                return sprintf(
                    "Should have a node from both DOMs: actual %s, expected %s",
                    self::nodeStr($actualNode),
                    self::nodeStr($expectedNode)
                );
            }

            $actualDesc = self::nodeStr($actualNode);
            $expectedDesc = self::nodeStr($expectedNode);
            if ($actualDesc !== $expectedDesc) {
                return sprintf("Node mismatch at %s: actual %s, expected %s", self::path($actualNode), $actualDesc, $expectedDesc);
            }

            if ($actualNode->nodeType === XML_TEXT_NODE) {
                $actualText = self::htmlTransform($actualNode->textContent);
                $expectedText = self::htmlTransform($expectedNode->textContent);
                if ($actualText !== $expectedText) {
                    return sprintf("Text mismatch at %s: actual %s, expected %s", self::path($actualNode), var_export($actualText, true), var_export($expectedText, true));
                }
            } elseif ($actualNode->nodeType === XML_ELEMENT_NODE) {
                $actualAttributes = self::attributesForNode($actualNode);
                $expectedAttributes = self::attributesForNode($expectedNode);
                if ($actualAttributes !== $expectedAttributes) {
                    return sprintf(
                        "Attribute mismatch at %s: actual (%s), expected (%s)",
                        self::path($actualNode),
                        implode(', ', $actualAttributes),
                        implode(', ', $expectedAttributes)
                    );
                }
            }

            $actualNode = self::inOrderIgnoreEmptyTextNodes($actualNode);
            $expectedNode = self::inOrderIgnoreEmptyTextNodes($expectedNode);
        }

        return null;
    }

    private static function inOrderTraverse(\Dom\Node $fromNode): ?\Dom\Node
    {
        if ($fromNode->firstChild) {
            return $fromNode->firstChild;
        }
        while ($fromNode && !$fromNode->nextSibling) {
            $fromNode = $fromNode->parentNode;
        }
        return $fromNode?->nextSibling;
    }

    private static function inOrderIgnoreEmptyTextNodes(\Dom\Node $fromNode): ?\Dom\Node
    {
        do {
            $fromNode = self::inOrderTraverse($fromNode);
        } while ($fromNode && $fromNode->nodeType === XML_TEXT_NODE && trim($fromNode->textContent) === '');
        return $fromNode;
    }

    /** Collapse subsequent whitespace like HTML. Trimming compensates for the js-beautify pass in Mozilla's harness. */
    private static function htmlTransform(string $str): string
    {
        return trim(preg_replace('/\s+/u', ' ', $str) ?? $str);
    }

    private static function nodeStr(?\Dom\Node $node): string
    {
        if (!$node) {
            return '(no node)';
        }
        if ($node->nodeType === XML_TEXT_NODE) {
            return '#text(' . self::htmlTransform($node->textContent) . ')';
        }
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return 'some other node type: ' . $node->nodeType . ' with data ' . $node->textContent;
        }
        \assert($node instanceof \Dom\Element);
        $rv = $node->localName;
        if ($node->id) {
            $rv .= '#' . $node->id;
        }
        if ($node->className) {
            $rv .= '.(' . $node->className . ')';
        }
        return $rv;
    }

    /** @return list<string> name=value pairs for XML-valid attribute names, sorted for order-insensitive comparison */
    private static function attributesForNode(\Dom\Element $node): array
    {
        $result = [];
        foreach ($node->attributes as $attr) {
            if (preg_match('/^[A-Za-z_:][A-Za-z0-9._:\-]*$/', $attr->name)) {
                $result[] = $attr->name . '=' . $attr->value;
            }
        }
        sort($result);
        return $result;
    }

    private static function path(\Dom\Node $node): string
    {
        $parts = [];
        for ($n = $node; $n && $n->nodeType === XML_ELEMENT_NODE || $n instanceof \Dom\Text; $n = $n->parentNode) {
            $parts[] = self::nodeStr($n);
            if ($n instanceof \Dom\Element && $n->id) {
                break;
            }
        }
        return implode(' < ', $parts);
    }
}
