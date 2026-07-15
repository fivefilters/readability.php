<?php

declare(strict_types=1);

namespace fivefilters\Readability;

/**
 * Thrown when parsing cannot be attempted: empty input, or a document
 * exceeding the maxElemsToParse guard (where Readability.js throws an Error).
 *
 * Finding no article content is not an exception: parse() then returns a
 * metadata-only Article — see Article::hasContent().
 */
final class ParseException extends \Exception
{
    public static function emptyInput(): self
    {
        return new self('No HTML content provided.');
    }

    public static function tooManyElements(int $count, int $max): self
    {
        return new self(sprintf('Aborting parsing document; %d elements found, max is %d.', $count, $max));
    }
}
