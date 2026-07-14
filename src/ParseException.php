<?php

declare(strict_types=1);

namespace fivefilters\Readability;

/**
 * Thrown when no article can be produced. Readability.js returns null in the
 * cases where this library throws (and throws a plain Error for the
 * maxElemsToParse guard).
 *
 * PHP-specific: whatever had already been extracted by the time parsing
 * failed is preserved on the exception. Readability.js discards this
 * information when it returns null, but the title and document metadata are
 * gathered before content detection runs, so callers here can still use them
 * (e.g. to label a "could not extract content" result). Every property is
 * null when parsing failed before that value was reached.
 */
final class ParseException extends \Exception
{
    private function __construct(
        string $message,
        /** Article title, as Article::$title would have reported it. */
        public readonly ?string $title = null,
        /** Author metadata, if found. */
        public readonly ?string $byline = null,
        /** Content direction ("ltr"/"rtl"), if found. */
        public readonly ?string $dir = null,
        /** Content language, if found. */
        public readonly ?string $lang = null,
        /** Article description from metadata (no content, so no first-paragraph fallback). */
        public readonly ?string $excerpt = null,
        /** Name of the site, if found. */
        public readonly ?string $siteName = null,
        /** Published time, if found. */
        public readonly ?string $publishedTime = null,
        /** The lead image URL, if found. */
        public readonly ?string $image = null,
    ) {
        parent::__construct($message);
    }

    public static function emptyInput(): self
    {
        return new self('No HTML content provided.');
    }

    public static function tooManyElements(int $count, int $max): self
    {
        return new self(sprintf('Aborting parsing document; %d elements found, max is %d.', $count, $max));
    }

    public static function noContent(
        ?string $title = null,
        ?string $byline = null,
        ?string $dir = null,
        ?string $lang = null,
        ?string $excerpt = null,
        ?string $siteName = null,
        ?string $publishedTime = null,
        ?string $image = null,
    ): self {
        return new self(
            'Could not parse text.',
            title: $title,
            byline: $byline,
            dir: $dir,
            lang: $lang,
            excerpt: $excerpt,
            siteName: $siteName,
            publishedTime: $publishedTime,
            image: $image,
        );
    }
}
