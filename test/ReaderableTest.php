<?php

declare(strict_types=1);

namespace fivefilters\Readability\Test;

use fivefilters\Readability\Readerable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Readerable::isProbablyReaderable, mirroring Mozilla's
 * test/test-isProbablyReaderable.js. The corpus-wide check (every test page
 * against the "readerable" key in its expected-metadata.json) lives in
 * ReadabilityTest::testReaderable.
 */
class ReaderableTest extends TestCase
{
    private static function makeDoc(string $source): \Dom\HTMLDocument
    {
        return \Dom\HTMLDocument::createFromString($source, LIBXML_NOERROR);
    }

    /** "hello there " repeated: 1× = 11 chars, 11× = 132, 12× = 144, 50× = 600. */
    private static function makeParagraphDoc(int $repeat): \Dom\HTMLDocument
    {
        return self::makeDoc('<html><p id="main">' . str_repeat('hello there ', $repeat) . '</p></html>');
    }

    public function testOnlyLargeDocumentsAreReaderableWithDefaultOptions(): void
    {
        $this->assertFalse(Readerable::isProbablyReaderable(self::makeParagraphDoc(1)), 'very small doc'); // score: 0
        $this->assertFalse(Readerable::isProbablyReaderable(self::makeParagraphDoc(11)), 'small doc'); // score: 0
        $this->assertFalse(Readerable::isProbablyReaderable(self::makeParagraphDoc(12)), 'large doc'); // score: ~1.7
        $this->assertTrue(Readerable::isProbablyReaderable(self::makeParagraphDoc(50)), 'very large doc'); // score: ~21.4
    }

    public function testSmallAndLargeDocumentsAreReaderableWithLowerMinContentLength(): void
    {
        $this->assertFalse(Readerable::isProbablyReaderable(self::makeParagraphDoc(1), minScore: 0, minContentLength: 120), 'very small doc');
        $this->assertTrue(Readerable::isProbablyReaderable(self::makeParagraphDoc(11), minScore: 0, minContentLength: 120), 'small doc');
        $this->assertTrue(Readerable::isProbablyReaderable(self::makeParagraphDoc(12), minScore: 0, minContentLength: 120), 'large doc');
        $this->assertTrue(Readerable::isProbablyReaderable(self::makeParagraphDoc(50), minScore: 0, minContentLength: 120), 'very large doc');
    }

    public function testOnlyLargestDocumentIsReaderableWithHigherMinContentLength(): void
    {
        $this->assertFalse(Readerable::isProbablyReaderable(self::makeParagraphDoc(1), minScore: 0, minContentLength: 200), 'very small doc');
        $this->assertFalse(Readerable::isProbablyReaderable(self::makeParagraphDoc(11), minScore: 0, minContentLength: 200), 'small doc');
        $this->assertFalse(Readerable::isProbablyReaderable(self::makeParagraphDoc(12), minScore: 0, minContentLength: 200), 'large doc');
        $this->assertTrue(Readerable::isProbablyReaderable(self::makeParagraphDoc(50), minScore: 0, minContentLength: 200), 'very large doc');
    }

    public function testSmallAndLargeDocumentsAreReaderableWithLowerMinScore(): void
    {
        $this->assertFalse(Readerable::isProbablyReaderable(self::makeParagraphDoc(1), minScore: 4, minContentLength: 0), 'very small doc'); // score: ~3.3
        $this->assertTrue(Readerable::isProbablyReaderable(self::makeParagraphDoc(11), minScore: 4, minContentLength: 0), 'small doc'); // score: ~11.4
        $this->assertTrue(Readerable::isProbablyReaderable(self::makeParagraphDoc(12), minScore: 4, minContentLength: 0), 'large doc'); // score: ~11.9
        $this->assertTrue(Readerable::isProbablyReaderable(self::makeParagraphDoc(50), minScore: 4, minContentLength: 0), 'very large doc'); // score: ~24.4
    }

    public function testOnlyLargeDocumentsAreReaderableWithHigherMinScore(): void
    {
        $this->assertFalse(Readerable::isProbablyReaderable(self::makeParagraphDoc(1), minScore: 11.5, minContentLength: 0), 'very small doc'); // score: ~3.3
        $this->assertFalse(Readerable::isProbablyReaderable(self::makeParagraphDoc(11), minScore: 11.5, minContentLength: 0), 'small doc'); // score: ~11.4
        $this->assertTrue(Readerable::isProbablyReaderable(self::makeParagraphDoc(12), minScore: 11.5, minContentLength: 0), 'large doc'); // score: ~11.9
        $this->assertTrue(Readerable::isProbablyReaderable(self::makeParagraphDoc(50), minScore: 11.5, minContentLength: 0), 'very large doc'); // score: ~24.4
    }

    public function testUsesProvidedVisibilityCheckerNotReaderable(): void
    {
        $called = false;
        $result = Readerable::isProbablyReaderable(self::makeParagraphDoc(50), visibilityChecker: function () use (&$called): bool {
            $called = true;
            return false;
        });
        $this->assertFalse($result);
        $this->assertTrue($called);
    }

    public function testUsesProvidedVisibilityCheckerReaderable(): void
    {
        $hiddenDoc = self::makeDoc('<html><p id="main" style="display: none">' . str_repeat('hello there ', 50) . '</p></html>');
        $this->assertFalse(Readerable::isProbablyReaderable($hiddenDoc), 'hidden with default checker');

        $called = false;
        $result = Readerable::isProbablyReaderable($hiddenDoc, visibilityChecker: function () use (&$called): bool {
            $called = true;
            return true;
        });
        $this->assertTrue($result, 'hidden with always-visible checker');
        $this->assertTrue($called);
    }

    /** PHP-specific convenience: an HTML string is accepted in place of a document. */
    public function testAcceptsHtmlString(): void
    {
        $this->assertTrue(Readerable::isProbablyReaderable('<html><p>' . str_repeat('hello there ', 50) . '</p></html>'));
        $this->assertFalse(Readerable::isProbablyReaderable('<html><p>hello there</p></html>'));
    }
}
