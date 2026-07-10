<?php

declare(strict_types=1);

namespace fivefilters\Readability;

/**
 * WHATWG URL Standard parsing, matching what Readability.js gets from the
 * browser's `new URL()`. Uses PHP 8.5's native Uri\WhatWg\Url when available,
 * falling back to rowbot/url (a WHATWG-compliant userland parser) on PHP 8.4.
 *
 * @internal
 */
final class Url
{
    private static ?bool $useNative = null;

    /**
     * Resolves $uri against $base per the WHATWG URL Standard and returns the
     * serialized URL — the equivalent of `new URL(uri, base).href`. Returns
     * null where JS would throw a TypeError (invalid input, relative $uri
     * with a null or unparseable $base).
     */
    public static function resolve(string $uri, ?string $base = null): ?string
    {
        self::$useNative ??= class_exists(\Uri\WhatWg\Url::class);

        if (self::$useNative) {
            try {
                $baseUrl = $base === null ? null : new \Uri\WhatWg\Url($base);
                return new \Uri\WhatWg\Url($uri, $baseUrl)->toAsciiString();
            } catch (\Uri\WhatWg\InvalidUrlException) {
                return null;
            }
        }

        try {
            return new \Rowbot\URL\URL($uri, $base)->href;
        } catch (\Rowbot\URL\Exception\TypeError) {
            return null;
        }
    }

    /** Whether $str parses as an absolute WHATWG URL — JS `new URL(str)` succeeding. */
    public static function isValid(string $str): bool
    {
        return self::resolve($str) !== null;
    }
}
