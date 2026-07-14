<?php

declare(strict_types=1);

namespace fivefilters\Readability;

/**
 * A port of Mozilla's Readability-readerable.js (v0.6.0): a quick check for
 * whether a document is likely to be readerable, without parsing the whole
 * thing.
 */
final class Readerable
{
    /**
     * Decides whether or not the document is reader-able without parsing the whole thing.
     *
     * Mirrors isProbablyReaderable.
     *
     * @param \Dom\HTMLDocument|string $document the document, or an HTML string
     * @param float $minScore the minimum cumulated 'score' used to determine if the document is readerable
     * @param int $minContentLength the minimum node content length used to decide if the document is readerable
     * @param callable(\Dom\Element): bool|null $visibilityChecker the function used to determine if a node is visible
     * @return bool Whether or not we suspect Readability::parse() will succeed at returning an article
     */
    public static function isProbablyReaderable(
        \Dom\HTMLDocument|string $document,
        float $minScore = 20,
        int $minContentLength = 140,
        ?callable $visibilityChecker = null,
    ): bool {
        if (is_string($document)) {
            $document = \Dom\HTMLDocument::createFromString($document, LIBXML_NOERROR);
        }
        $visibilityChecker ??= self::isNodeVisible(...);

        $nodes = iterator_to_array($document->querySelectorAll('p, pre, article'), false);

        // Get <div> nodes which have <br> node(s) and append them into the `nodes` variable.
        // Some articles' DOM structures might look like
        // <div>
        //   Sentences<br>
        //   <br>
        //   Sentences<br>
        // </div>
        $brNodes = $document->querySelectorAll('div > br');
        if ($brNodes->length) {
            $set = new \SplObjectStorage();
            foreach ($nodes as $node) {
                $set->offsetSet($node);
            }
            foreach ($brNodes as $node) {
                $set->offsetSet($node->parentNode);
            }
            $nodes = iterator_to_array($set, false);
        }

        $score = 0;
        foreach ($nodes as $node) {
            if (!$visibilityChecker($node)) {
                continue;
            }

            $matchString = $node->className . ' ' . $node->id;
            if (
                preg_match(RegExps::UNLIKELY_CANDIDATES, $matchString)
                && !preg_match(RegExps::OK_MAYBE_ITS_A_CANDIDATE, $matchString)
            ) {
                continue;
            }

            if ($node->matches('li p')) {
                continue;
            }

            $text = (string) $node->textContent;
            $textContentLength = mb_strlen(preg_replace(RegExps::TRIM, '', $text) ?? $text);
            if ($textContentLength < $minContentLength) {
                continue;
            }

            $score += sqrt($textContentLength - $minContentLength);

            if ($score > $minScore) {
                return true;
            }
        }
        return false;
    }

    /** Mirrors isNodeVisible in Readability-readerable.js. */
    public static function isNodeVisible(\Dom\Element $node): bool
    {
        $style = $node->getAttribute('style') ?? '';
        return !preg_match('/display\s*:\s*none/i', $style)
            && !$node->hasAttribute('hidden')
            // check for "fallback-image" so that wikimedia math images are displayed
            && (!$node->hasAttribute('aria-hidden')
                || $node->getAttribute('aria-hidden') !== 'true'
                || str_contains($node->className, 'fallback-image'));
    }
}
