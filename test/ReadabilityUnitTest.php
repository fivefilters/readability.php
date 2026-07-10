<?php

declare(strict_types=1);

namespace fivefilters\Readability\Test;

use fivefilters\Readability\Configuration;
use fivefilters\Readability\Readability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the behavior of the pure helper methods against values verified with
 * Readability.js. The methods are private (they are implementation details
 * mirroring the JS prototype), so they are invoked through reflection.
 */
class ReadabilityUnitTest extends TestCase
{
    private static function invoke(string $method, mixed ...$args): mixed
    {
        $readability = new Readability(new Configuration(
            fixRelativeURLs: true,
            originalURL: 'http://fakehost/test/page.html',
        ));
        $reflection = new \ReflectionClass($readability);
        // toAbsoluteURI reads the base URI resolved by parseDocument; set it directly.
        foreach (['baseURI' => 'http://fakehost/test/page.html', 'documentURI' => 'http://fakehost/test/page.html'] as $property => $value) {
            $reflection->getProperty($property)->setValue($readability, $value);
        }
        return $reflection->getMethod($method)->invoke($readability, ...$args);
    }

    #[DataProvider('textSimilarityProvider')]
    public function testTextSimilarity(string $a, string $b, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, self::invoke('textSimilarity', $a, $b), 0.001);
    }

    public static function textSimilarityProvider(): array
    {
        return [
            'identical' => ['Some Title', 'Some Title', 1.0],
            'disjoint' => ['Some Title', 'Other Words', 0.0],
            'case and punctuation insensitive' => ['some-title!', 'Some Title', 1.0],
            'partial overlap' => ['One Two Three', 'One Two Four', 1 - 4 / 12],
            'empty' => ['', 'Some Title', 0.0],
        ];
    }

    #[DataProvider('unescapeHtmlEntitiesProvider')]
    public function testUnescapeHtmlEntities(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, self::invoke('unescapeHtmlEntities', $input));
    }

    public static function unescapeHtmlEntitiesProvider(): array
    {
        return [
            'named' => ['&lt;b&gt; &amp; &quot;quoted&quot; &apos;', '<b> & "quoted" \''],
            'decimal' => ['&#65;&#66;', 'AB'],
            'hex' => ['&#x41;&#x42;', 'AB'],
            'astral' => ['&#x1F600;', "\u{1F600}"],
            'out of range becomes replacement char' => ['&#x110000;', "\u{FFFD}"],
            'surrogate becomes replacement char' => ['&#xD800;', "\u{FFFD}"],
            'null byte becomes replacement char' => ['&#0;', "\u{FFFD}"],
            'other entities untouched' => ['&nbsp;&copy;', '&nbsp;&copy;'],
            'null passes through' => [null, null],
            'empty passes through' => ['', ''],
        ];
    }

    #[DataProvider('toAbsoluteURIProvider')]
    public function testToAbsoluteURI(string $uri, string $expected): void
    {
        $this->assertSame($expected, self::invoke('toAbsoluteURI', $uri));
    }

    public static function toAbsoluteURIProvider(): array
    {
        return [
            'relative path' => ['foo/bar.html', 'http://fakehost/test/foo/bar.html'],
            'root-relative' => ['/foo.html', 'http://fakehost/foo.html'],
            'protocol-relative' => ['//cdn.example.com/x.png', 'http://cdn.example.com/x.png'],
            'hash left alone when base matches document' => ['#fn1', '#fn1'],
            'absolute untouched' => ['https://example.com/a', 'https://example.com/a'],
            'empty path gets a slash (WHATWG)' => ['https://example.com', 'https://example.com/'],
            'empty path before query gets a slash (WHATWG)' => ['https://example.com?a=1', 'https://example.com/?a=1'],
            'whitespace stripped (WHATWG)' => ["https://example.com/a.html\n  ", 'https://example.com/a.html'],
            'space encoded (WHATWG)' => ['foo bar.html', 'http://fakehost/test/foo%20bar.html'],
            'zero-width junk resolves as relative (WHATWG)' => ["\u{200B}https://example.com/x", 'http://fakehost/test/%E2%80%8Bhttps://example.com/x'],
            'data URI untouched' => ["data:image/svg+xml;utf8,<svg viewBox='0 0 24 24'></svg>", "data:image/svg+xml;utf8,<svg viewBox='0 0 24 24'></svg>"],
            'blob URI untouched' => ['blob:http://example.com/uuid', 'blob:http://example.com/uuid'],
        ];
    }

    #[DataProvider('isValidBylineProvider')]
    public function testIsValidByline(string $html, bool $expected): void
    {
        $document = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $node = $document->body->firstElementChild;
        $matchString = $node->className . ' ' . $node->id;
        $this->assertSame($expected, self::invoke('isValidByline', $node, $matchString));
    }

    public static function isValidBylineProvider(): array
    {
        return [
            'rel author' => ['<span rel="author">Jane Doe</span>', true],
            'itemprop author' => ['<span itemprop="author name">Jane Doe</span>', true],
            'byline class' => ['<div class="byline">Jane Doe</div>', true],
            'author class' => ['<div class="author-name">Jane Doe</div>', true],
            'no byline signal' => ['<div class="content">Jane Doe</div>', false],
            'empty text' => ['<div class="byline">   </div>', false],
            'too long' => ['<div class="byline">' . str_repeat('name ', 25) . '</div>', false],
        ];
    }

    #[DataProvider('getRowAndColumnCountProvider')]
    public function testGetRowAndColumnCount(string $html, int $rows, int $columns): void
    {
        $document = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $table = $document->querySelector('table');
        $this->assertSame(['rows' => $rows, 'columns' => $columns], self::invoke('getRowAndColumnCount', $table));
    }

    public static function getRowAndColumnCountProvider(): array
    {
        return [
            'simple 2x2' => ['<table><tr><td>a</td><td>b</td></tr><tr><td>c</td><td>d</td></tr></table>', 2, 2],
            'colspan' => ['<table><tr><td colspan="3">a</td></tr><tr><td>b</td><td>c</td></tr></table>', 2, 3],
            'rowspan' => ['<table><tr rowspan="2"><td>a</td></tr></table>', 2, 1],
            'invalid spans count as one' => ['<table><tr rowspan="x"><td colspan="y">a</td></tr></table>', 1, 1],
            'empty table' => ['<table></table>', 0, 0],
        ];
    }
}
