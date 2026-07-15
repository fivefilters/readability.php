<?php

declare(strict_types=1);

namespace fivefilters\Readability\Test;

use fivefilters\Readability\Article;
use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;
use fivefilters\Readability\Readerable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReadabilityTest extends TestCase
{
    /**
     * The same URL Mozilla's test harness supplies to jsdom; the expected
     * files have relative URLs resolved against it.
     */
    private const string TEST_URL = 'http://fakehost/test/page.html';

    public static function getSamplePages(): \Generator
    {
        $root = __DIR__ . '/test-pages';
        foreach (scandir($root) as $dir) {
            if ($dir[0] === '.') {
                continue;
            }
            yield $dir => [new TestPage(
                $dir,
                trim(file_get_contents("{$root}/{$dir}/source.html")),
                trim(file_get_contents("{$root}/{$dir}/expected.html")),
                json_decode(file_get_contents("{$root}/{$dir}/expected-metadata.json"), true),
            )];
        }
    }

    #[DataProvider('getSamplePages')]
    public function testContent(TestPage $testPage): void
    {
        $article = $this->parse($testPage->source);
        $difference = DomCompare::compare($testPage->expectedContent, $article->content);
        if ($difference !== null) {
            $this->outputChanges($testPage->slug, $article);
        }
        $this->assertNull($difference, "Content mismatch: {$difference}");
    }

    #[DataProvider('getSamplePages')]
    public function testMetadata(TestPage $testPage): void
    {
        $article = $this->parse($testPage->source);
        $expected = $testPage->expectedMetadata;

        // A couple of Mozilla fixtures omit keys entirely (their harness
        // compares undefined to undefined); treat missing as null.
        $this->assertSame($expected['title'], $article->title, 'title');
        $this->assertSame($expected['byline'] ?? null, $article->byline, 'byline');
        $this->assertSame($expected['excerpt'] ?? null, $article->excerpt, 'excerpt');
        $this->assertSame($expected['siteName'] ?? null, $article->siteName, 'siteName');
        if (!empty($expected['dir'])) {
            $this->assertSame($expected['dir'], $article->dir, 'dir');
        }
        if (!empty($expected['lang'])) {
            $this->assertSame($expected['lang'], $article->lang, 'lang');
        }
        if (!empty($expected['publishedTime'])) {
            $this->assertSame($expected['publishedTime'], $article->publishedTime, 'publishedTime');
        }
    }

    #[DataProvider('getSamplePages')]
    public function testReaderable(TestPage $testPage): void
    {
        if (!array_key_exists('readerable', $testPage->expectedMetadata)) {
            $this->markTestSkipped('No readerable key for this page.');
        }
        $document = \Dom\HTMLDocument::createFromString($testPage->source, LIBXML_NOERROR);
        $this->assertSame($testPage->expectedMetadata['readerable'], Readerable::isProbablyReaderable($document));
    }

    public function testEmptyInputThrows(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('No HTML content provided.');
        new Readability()->parse('');
    }

    public function testOversizedDocumentThrows(): void
    {
        $this->expectException(ParseException::class);
        // html, head, body, div — parsing produces a full document
        $this->expectExceptionMessage('Aborting parsing document; 4 elements found');
        new Readability(new Configuration(maxElemsToParse: 1))->parse('<html><div>yo</div></html>');
    }

    public function testNoContentReturnsMetadataOnlyArticle(): void
    {
        // A document with rich metadata but no extractable content: parse()
        // must return an Article carrying what had been extracted before
        // content detection failed, with the content-derived properties null
        // (PHP-specific; Readability.js returns a bare null).
        $html = <<<HTML
        <html lang="fr"><head>
        <title>Site Name - The Article Title</title>
        <meta property="og:title" content="The Article Title" />
        <meta property="og:site_name" content="Site Name" />
        <meta property="og:description" content="A short description." />
        <meta property="article:published_time" content="2026-01-02T03:04:05Z" />
        <meta property="og:image" content="https://example.com/lead.jpg" />
        <meta name="author" content="Jane Doe" />
        </head><body></body></html>
        HTML;

        $article = new Readability()->parse($html);

        $this->assertFalse($article->hasContent());
        $this->assertNull($article->content);
        $this->assertNull($article->textContent);
        $this->assertNull($article->length);
        $this->assertNull($article->contentElement);
        $this->assertSame('', (string) $article);

        $this->assertSame('The Article Title', $article->title);
        $this->assertSame('Jane Doe', $article->byline);
        $this->assertSame('fr', $article->lang);
        $this->assertSame('A short description.', $article->excerpt);
        $this->assertSame('Site Name', $article->siteName);
        $this->assertSame('2026-01-02T03:04:05Z', $article->publishedTime);
        $this->assertSame('https://example.com/lead.jpg', $article->image);
        $this->assertSame(['https://example.com/lead.jpg'], $article->images);
        $this->assertNull($article->dir);
    }

    public function testArticleWithContentReportsHasContent(): void
    {
        $article = new Readability()->parse(
            '<html><body><article><p>' . str_repeat('Long enough paragraph text. ', 30) . '</p></article></body></html>'
        );

        $this->assertTrue($article->hasContent());
        $this->assertNotNull($article->contentElement);
        $this->assertGreaterThan(0, $article->length);
    }

    public function testCustomAllowedVideoRegex(): void
    {
        $source = '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Nunc mollis leo lacus, vitae semper nisl ullamcorper ut.</p>'
            . '<iframe src="https://mycustomdomain.com/some-embeds"></iframe>';
        $article = new Readability(new Configuration(
            charThreshold: 20,
            allowedVideoRegex: '/.*mycustomdomain.com.*/',
        ))->parse($source);
        $this->assertStringContainsString('<iframe src="https://mycustomdomain.com/some-embeds">', $article->content);
    }

    public function testKeepClasses(): void
    {
        $source = '<div class="wrapper"><p class="lead">Lorem ipsum dolor sit amet, consectetur adipiscing elit. Nunc mollis leo lacus, vitae semper nisl ullamcorper ut.</p></div>';
        $kept = new Readability(new Configuration(charThreshold: 20, keepClasses: true))->parse($source);
        $this->assertStringContainsString('class="lead"', $kept->content);
        $stripped = new Readability(new Configuration(charThreshold: 20))->parse($source);
        $this->assertStringNotContainsString('class="lead"', $stripped->content);
        $preserved = new Readability(new Configuration(charThreshold: 20, classesToPreserve: ['lead']))->parse($source);
        $this->assertStringContainsString('class="lead"', $preserved->content);
    }

    private function parse(string $source): Article
    {
        // Mirror Mozilla's jsdom test path: the source is UTF-8 text (jsdom
        // receives a decoded string, so meta charsets in the fixtures must not
        // trigger re-decoding), and comments are removed before parsing.
        $document = \Dom\HTMLDocument::createFromString($source, LIBXML_NOERROR, 'UTF-8');
        $this->removeCommentNodesRecursively($document);

        $readability = new Readability(new Configuration(
            classesToPreserve: ['caption'],
            fixRelativeURLs: true,
            originalURL: self::TEST_URL,
        ));

        return $readability->parseDocument($document);
    }

    private function removeCommentNodesRecursively(\Dom\Node $node): void
    {
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $child = $node->childNodes->item($i);
            if ($child->nodeType === XML_COMMENT_NODE) {
                $node->removeChild($child);
            } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                $this->removeCommentNodesRecursively($child);
            }
        }
    }

    /**
     * Write actual output for a failing page to test/changed/<slug>/ when the
     * output-changes env var is set, for review or golden-file regeneration.
     */
    private function outputChanges(string $slug, Article $article): void
    {
        if (!getenv('output-changes')) {
            return;
        }
        $dir = __DIR__ . '/changed/' . $slug;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents("{$dir}/expected.html", $article->content . "\n");
        file_put_contents("{$dir}/expected-metadata.json", json_encode([
            'title' => $article->title,
            'byline' => $article->byline,
            'dir' => $article->dir,
            'lang' => $article->lang,
            'excerpt' => $article->excerpt,
            'siteName' => $article->siteName,
            'publishedTime' => $article->publishedTime,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        if (getenv('output-diff')) {
            $expectedFile = __DIR__ . "/test-pages/{$slug}/expected.html";
            $diff = shell_exec('diff -u ' . escapeshellarg($expectedFile) . ' ' . escapeshellarg("{$dir}/expected.html"));
            file_put_contents("{$dir}/diff.txt", (string) $diff);
        }
    }
}
