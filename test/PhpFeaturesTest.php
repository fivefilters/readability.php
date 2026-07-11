<?php

declare(strict_types=1);

namespace fivefilters\Readability\Test;

use fivefilters\Readability\Configuration;
use fivefilters\Readability\Readability;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * PHP-specific features that are not part of Readability.js: image extraction,
 * the keep-inline-byline option, and PSR-3 logging. (Reinstated from 3.x.)
 */
class PhpFeaturesTest extends TestCase
{
    private const string BODY = '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam quis nostrud.</p>';

    private static function page(string $head = '', string $article = ''): string
    {
        $paragraphs = str_repeat(self::BODY, 12);
        return "<html><head><title>Test</title>{$head}</head><body><article>{$article}{$paragraphs}</article></body></html>";
    }

    public function testLeadImageFromOgImage(): void
    {
        $html = self::page('<meta property="og:image" content="https://example.com/lead.jpg">');
        $article = new Readability(new Configuration())->parse($html);
        $this->assertSame('https://example.com/lead.jpg', $article->image);
        $this->assertContains('https://example.com/lead.jpg', $article->images);
    }

    public function testLeadImageFromTwitterImage(): void
    {
        $html = self::page('<meta name="twitter:image" content="https://example.com/tw.jpg">');
        $article = new Readability(new Configuration())->parse($html);
        $this->assertSame('https://example.com/tw.jpg', $article->image);
    }

    public function testLeadImageFromLinkRel(): void
    {
        $html = self::page('<link rel="image_src" href="https://example.com/link.jpg">');
        $article = new Readability(new Configuration())->parse($html);
        $this->assertSame('https://example.com/link.jpg', $article->image);
    }

    public function testNoLeadImage(): void
    {
        $article = new Readability(new Configuration())->parse(self::page());
        $this->assertNull($article->image);
        $this->assertSame([], $article->images);
    }

    public function testImagesListCollectsContentImagesAndDeduplicates(): void
    {
        $html = self::page(
            '<meta property="og:image" content="https://example.com/lead.jpg">',
            '<img src="https://example.com/a.jpg"><img src="https://example.com/b.jpg"><img src="https://example.com/lead.jpg">'
        );
        $article = new Readability(new Configuration())->parse($html);
        // Lead image first, then content images, with the duplicate lead image collapsed.
        $this->assertSame([
            'https://example.com/lead.jpg',
            'https://example.com/a.jpg',
            'https://example.com/b.jpg',
        ], $article->images);
    }

    public function testImagesAreAbsolutizedWhenFixRelativeUrlsEnabled(): void
    {
        $html = self::page(
            '<meta property="og:image" content="/lead.jpg">',
            '<img src="pics/inline.jpg">'
        );
        $article = new Readability(new Configuration(
            fixRelativeURLs: true,
            originalURL: 'https://example.com/news/',
        ))->parse($html);
        $this->assertSame('https://example.com/lead.jpg', $article->image);
        $this->assertSame([
            'https://example.com/lead.jpg',
            'https://example.com/news/pics/inline.jpg',
        ], $article->images);
    }

    public function testInlineBylineRemovedByDefault(): void
    {
        $html = self::page('', '<p class="byline">By Jane Doe</p>');
        $article = new Readability(new Configuration())->parse($html);
        $this->assertSame('By Jane Doe', $article->byline);
        $this->assertStringNotContainsString('By Jane Doe', $article->content);
    }

    public function testInlineBylineKeptWhenConfigured(): void
    {
        $html = self::page('', '<p class="byline">By Jane Doe</p>');
        $article = new Readability(new Configuration(keepInlineByline: true))->parse($html);
        // Still recorded as metadata...
        $this->assertSame('By Jane Doe', $article->byline);
        // ...but left in the content.
        $this->assertStringContainsString('By Jane Doe', $article->content);
    }

    public function testPsrLoggerReceivesMessages(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        new Readability(new Configuration(logger: $logger))->parse(self::page());

        $this->assertNotEmpty($logger->messages, 'logger should receive debug messages even with debug=false');
    }

    public function testNoLoggingWithoutDebugOrLogger(): void
    {
        // With neither a logger nor the debug flag, log() is a no-op; this
        // parse should simply succeed without touching error_log.
        $article = new Readability(new Configuration())->parse(self::page());
        $this->assertNotSame('', $article->content);
    }
}
