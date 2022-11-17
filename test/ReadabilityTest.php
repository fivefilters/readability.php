<?php

namespace fivefilters\Readability\Test;

use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;

ini_set('memory_limit','1024M');

/**
 * Class ReadabilityTest.
 */
class ReadabilityTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Test that Readability parses the HTML correctly and matches the expected result.
     *
     * @dataProvider getSamplePages
     *
     * @param TestPage $testPage
     *
     * @throws ParseException
     */
    public function testReadabilityParsesHTML(TestPage $testPage)
    {
        $options = ['OriginalURL' => 'http://fakehost/test/test.html',
            'FixRelativeURLs' => true,
            'SubstituteEntities' => true,
            'ArticleByLine' => true
        ];

        $configuration = new Configuration(array_merge($testPage->getConfiguration(), $options));

        $readability = new Readability($configuration);
        $readability->parse($testPage->getSourceHTML());

        // Let's (crudely) remove whitespace between tags here to simplify comparison.
        // This isn't used for output.
        $from = ['/\>[^\S ]+/s', '/[^\S ]+\</s', '/(\s)+/s', '/> </s'];
        $to   = ['>',            '<',            '\\1',      '><'];
        $expected_no_whitespace = preg_replace($from, $to, $testPage->getExpectedHTML());
        $readability_no_whitespace = preg_replace($from, $to, $readability->getContent());

        if (getenv('output-changes') && $expected_no_whitespace !== $readability_no_whitespace) {
            @mkdir(__DIR__.'/changed/'.$testPage->getSlug());
            $new_expected = __DIR__.'/changed/'.$testPage->getSlug().'/expected.html';
            $old_expected = __DIR__.'/test-pages/'.$testPage->getSlug().'/expected.html';
            //file_put_contents(__DIR__.'/changed/'.$testPage->getSlug().'/readability.html', $readability_no_whitespace);
            //file_put_contents(__DIR__.'/changed/'.$testPage->getSlug().'/expected-current.html', $expected_no_whitespace);
            file_put_contents($new_expected, $readability->getContent());
            if (getenv('output-diff')) {
                file_put_contents(__DIR__.'/changed/'.$testPage->getSlug().'/diff-expected.txt', shell_exec(sprintf('diff -u -d %s %s', $old_expected, $new_expected)));
            }

        }

        $this->assertSame($expected_no_whitespace, $readability_no_whitespace, 'Parsed text does not match the expected one.');

        //$this->assertSame($testPage->getExpectedHTML(), $readability->getContent(), 'Parsed text does not match the expected one.');
        //$this->assertXmlStringEqualsXmlString($testPage->getExpectedHTML(), $readability->getContent(), 'Parsed text does not match the expected one.');
    }

    /**
     * Test that Readability parses the HTML correctly and matches the expected result.
     *
     * @dataProvider getSamplePages
     *
     * @param TestPage $testPage
     *
     * @throws ParseException
     */
    public function testReadabilityParsesMetadata(TestPage $testPage)
    {
        $options = ['OriginalURL' => 'http://fakehost/test/test.html',
            'FixRelativeURLs' => true,
            'SubstituteEntities' => true,
            'ArticleByLine' => true
        ];

        $configuration = new Configuration(array_merge($testPage->getConfiguration(), $options));

        $readability = new Readability($configuration);
        $readability->parse($testPage->getSourceHTML());

        $metadata = [
            'Author' => $readability->getAuthor(),
            'Direction' => $readability->getDirection(),
            'Excerpt' => $readability->getExcerpt(),
            'Image' => $readability->getImage(),
            'Title' => $readability->getTitle(),
            'SiteName' => $readability->getSiteName()
        ];

        if (getenv('output-changes') && (array)$testPage->getExpectedMetadata() !== $metadata) {
            @mkdir(__DIR__.'/changed/'.$testPage->getSlug());
            $new_expected = __DIR__.'/changed/'.$testPage->getSlug().'/expected-metadata.json';
            $old_expected = __DIR__.'/test-pages/'.$testPage->getSlug().'/expected-metadata.json';
            //file_put_contents(__DIR__.'/changed/'.$testPage->getSlug().'/expected-metadata-current.json', json_encode($testPage->getExpectedMetadata(), JSON_PRETTY_PRINT));
            file_put_contents($new_expected, json_encode((object)$metadata, JSON_PRETTY_PRINT));
            if (getenv('output-diff')) {
                file_put_contents(__DIR__.'/changed/'.$testPage->getSlug().'/diff-expected-metadata.txt', shell_exec(sprintf('diff -u -d %s %s', $old_expected, $new_expected)));
            }
        }

        $this->assertSame($testPage->getExpectedMetadata()->Author, $readability->getAuthor(), 'Parsed Author does not match expected value.');
        $this->assertSame($testPage->getExpectedMetadata()->Direction, $readability->getDirection(), 'Parsed Direction does not match expected value.');
        $this->assertSame($testPage->getExpectedMetadata()->Excerpt, $readability->getExcerpt(), 'Parsed Excerpt does not match expected value.');
        $this->assertSame($testPage->getExpectedMetadata()->Image, $readability->getImage(), 'Parsed Image does not match expected value.');
        $this->assertSame($testPage->getExpectedMetadata()->Title, $readability->getTitle(), 'Parsed Title does not match expected value.');
    }

    /**
     * Test that Readability returns all the expected images from the test page.
     *
     * @param TestPage $testPage
     * @dataProvider getSamplePages
     *
     * @throws ParseException
     */
    public function testHTMLParserParsesImages(TestPage $testPage)
    {
        $options = ['OriginalURL' => 'http://fakehost/test/test.html',
            'fixRelativeURLs' => true,
            'substituteEntities' => true,
        ];

        $configuration = new Configuration(array_merge($testPage->getConfiguration(), $options));

        $readability = new Readability($configuration);
        $readability->parse($testPage->getSourceHTML());

        if (getenv('output-changes') && $testPage->getExpectedImages() !== array_values($readability->getImages())) {
            @mkdir(__DIR__.'/changed/'.$testPage->getSlug());
            $new_expected = __DIR__.'/changed/'.$testPage->getSlug().'/expected-images.json';
            $old_expected = __DIR__.'/test-pages/'.$testPage->getSlug().'/expected-images.json';
            //file_put_contents(__DIR__.'/changed/'.$testPage->getSlug().'/expected-images-current.json', json_encode($testPage->getExpectedImages(), JSON_PRETTY_PRINT));
            file_put_contents($new_expected, json_encode(array_values($readability->getImages()), JSON_PRETTY_PRINT));
            if (getenv('output-diff')) {
                file_put_contents(__DIR__.'/changed/'.$testPage->getSlug().'/diff-expected-images.txt', shell_exec(sprintf('diff -u -d %s %s', $old_expected, $new_expected)));
            }
        }

        $this->assertSame($testPage->getExpectedImages(), array_values($readability->getImages()));
    }

    /**
     * Main data provider.
     *
     * @return \Generator
     */
    public function getSamplePages()
    {
        $path = pathinfo(__FILE__, PATHINFO_DIRNAME) . DIRECTORY_SEPARATOR . 'test-pages';
        $testPages = scandir($path);

        foreach (array_slice($testPages, 2) as $testPage) {
            $testCasePath = $path . DIRECTORY_SEPARATOR . $testPage . DIRECTORY_SEPARATOR;

            $slug = $testPage;
            $source = file_get_contents($testCasePath . 'source.html');
            $expectedHTML = file_exists($testCasePath . 'expected.html') ? file_get_contents($testCasePath . 'expected.html') : '';
            $expectedImages = file_exists($testCasePath . 'expected-images.json') ? json_decode(file_get_contents($testCasePath . 'expected-images.json'), true) : [];
            $expectedMetadata = file_exists($testCasePath . 'expected-metadata.json') ? json_decode(file_get_contents($testCasePath . 'expected-metadata.json')) : (object)[];
            $configuration = file_exists($testCasePath . 'config.json') ? json_decode(file_get_contents($testCasePath . 'config.json'), true) : [];

            yield $testPage => [new TestPage($slug, $configuration, $source, $expectedHTML, $expectedImages, $expectedMetadata)];
        }
    }

    /**
     * Test that Readability throws an exception with malformed HTML.
     *
     * @throws ParseException
     */
    public function testReadabilityThrowsExceptionWithMalformedHTML()
    {
        $parser = new Readability(new Configuration());
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Invalid or incomplete HTML.');
        $parser->parse('<html>');
    }

    /**
     * Test that Readability throws an exception with incomplete or short HTML.
     *
     * @throws ParseException
     */
    public function testReadabilityThrowsExceptionWithUnparseableHTML()
    {
        $parser = new Readability(new Configuration());
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Could not parse text.');
        $parser->parse('<html><body><p></p></body></html>');
    }

    /**
     * Test that the Readability object has no content as soon as it is instantiated.
     */
    public function testReadabilityCallGetContentWithNoContent()
    {
        $parser = new Readability(new Configuration());
        $this->assertNull($parser->getContent());
    }
}
