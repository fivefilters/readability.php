<?php

declare(strict_types=1);

namespace fivefilters\Readability;

/**
 * Options for Readability.
 *
 * Defaults match the option defaults of Readability.js v0.6.0.
 */
final class Configuration
{
    public function __construct(
        /** Whether to output debug messages via error_log() (JS: debug). */
        public readonly bool $debug = false,
        /**
         * PHP-specific: an optional PSR-3 logger. When set, Readability's debug
         * messages are sent to it (independently of the debug flag, which only
         * governs error_log()). Reinstated from readability.php 3.x.
         */
        public readonly ?\Psr\Log\LoggerInterface $logger = null,
        /** The maximum number of elements to parse; 0 means no limit (JS: maxElemsToParse). */
        public readonly int $maxElemsToParse = 0,
        /** The number of top candidates to consider when analysing how tight the competition is among candidates (JS: nbTopCandidates). */
        public readonly int $nbTopCandidates = 5,
        /** The number of characters an article must have in order to return a result (JS: charThreshold). */
        public readonly int $charThreshold = 500,
        /** Classes to preserve on HTML elements when keepClasses is false (JS: classesToPreserve). @var list<string> */
        public readonly array $classesToPreserve = [],
        /** Whether to preserve all classes on HTML elements (JS: keepClasses). */
        public readonly bool $keepClasses = false,
        /** Whether to skip JSON-LD metadata extraction (JS: disableJSONLD). */
        public readonly bool $disableJSONLD = false,
        /** PCRE pattern for video URLs allowed to remain embedded; null uses the built-in default (JS: allowedVideoRegex). */
        public readonly ?string $allowedVideoRegex = null,
        /** Number added to the base link density threshold during shadiness checks (JS: linkDensityModifier). */
        public readonly float $linkDensityModifier = 0.0,
        /**
         * PHP-specific: unlike a browser there is no live document with a base URI,
         * so relative URL fixing is opt-in and needs the document's URL supplied.
         */
        public readonly bool $fixRelativeURLs = false,
        public readonly ?string $originalURL = null,
        /**
         * PHP-specific: keep an inline byline (e.g. a "By Jane Doe" element) in
         * the article content instead of removing it. The byline is still
         * extracted into Article::$byline either way. Readability.js always
         * removes it; readability.php 3.x kept it unless articleByline was set.
         */
        public readonly bool $keepInlineByline = false,
        /** Internal flag toggles carried over from the previous major version (always on in JS). */
        public readonly bool $stripUnlikelyCandidates = true,
        public readonly bool $weightClasses = true,
        public readonly bool $cleanConditionally = true,
    ) {
    }

    /**
     * Build a Configuration from an options array keyed by property name,
     * e.g. ['fixRelativeURLs' => true, 'originalURL' => 'https://example.com/'].
     */
    public static function fromArray(array $options): self
    {
        return new self(...$options);
    }
}
