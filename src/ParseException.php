<?php

declare(strict_types=1);

namespace fivefilters\Readability;

/**
 * Thrown when no article can be produced. Readability.js returns null in the
 * cases where this library throws (and throws a plain Error for the
 * maxElemsToParse guard).
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

    public static function noContent(): self
    {
        return new self('Could not parse text.');
    }
}
