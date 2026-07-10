<?php

declare(strict_types=1);

namespace fivefilters\Readability\Test;

use fivefilters\Readability\Configuration;
use PHPUnit\Framework\TestCase;

class ConfigurationTest extends TestCase
{
    /** Defaults must match Readability.js 0.6.0 option defaults. */
    public function testDefaults(): void
    {
        $configuration = new Configuration();
        $this->assertFalse($configuration->debug);
        $this->assertSame(0, $configuration->maxElemsToParse);
        $this->assertSame(5, $configuration->nbTopCandidates);
        $this->assertSame(500, $configuration->charThreshold);
        $this->assertSame([], $configuration->classesToPreserve);
        $this->assertFalse($configuration->keepClasses);
        $this->assertFalse($configuration->disableJSONLD);
        $this->assertNull($configuration->allowedVideoRegex);
        $this->assertSame(0.0, $configuration->linkDensityModifier);
        $this->assertFalse($configuration->fixRelativeURLs);
        $this->assertNull($configuration->originalURL);
        $this->assertTrue($configuration->stripUnlikelyCandidates);
        $this->assertTrue($configuration->weightClasses);
        $this->assertTrue($configuration->cleanConditionally);
    }

    public function testFromArray(): void
    {
        $configuration = Configuration::fromArray([
            'charThreshold' => 250,
            'fixRelativeURLs' => true,
            'originalURL' => 'https://example.com/article',
        ]);
        $this->assertSame(250, $configuration->charThreshold);
        $this->assertTrue($configuration->fixRelativeURLs);
        $this->assertSame('https://example.com/article', $configuration->originalURL);
        // Untouched options keep their defaults.
        $this->assertSame(5, $configuration->nbTopCandidates);
    }

    public function testFromArrayRejectsUnknownOptions(): void
    {
        $this->expectException(\Error::class);
        Configuration::fromArray(['noSuchOption' => true]);
    }
}
