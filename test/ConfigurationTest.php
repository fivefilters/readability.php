<?php

namespace fivefilters\Readability\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use fivefilters\Readability\Configuration;
use Monolog\Handler\NullHandler;
use Monolog\Logger;

/**
 * Class ConfigurationTest.
 */
class ConfigurationTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Test constructor sets parameters
     */
    #[DataProvider('getParams')]
    public function testConfigurationConstructorSetsParameters(array $params): void
    {
        $config = new Configuration($params);
        $this->doEqualsAsserts($config, $params);
    }

    /**
     * Test invalid parameter is not in config
     */
    #[DataProvider('getParams')]
    public function testInvalidParameterIsNotInConfig(array $params): void
    {
        $config = new Configuration($params);
        $this->assertArrayNotHasKey('invalidParameter', $config->toArray(), 'Invalid param key is not present in config');
    }

    /**
     * Check if the config getters are correct
     */
    private function doEqualsAsserts(Configuration $config, array $options): void
    {
        $this->assertEquals($options['maxTopCandidates'], $config->getMaxTopCandidates());
        $this->assertEquals($options['charThreshold'], $config->getCharThreshold());
        $this->assertEquals($options['articleByline'], $config->getArticleByline());
        $this->assertEquals($options['stripUnlikelyCandidates'], $config->getStripUnlikelyCandidates());
        $this->assertEquals($options['cleanConditionally'], $config->getCleanConditionally());
        $this->assertEquals($options['weightClasses'], $config->getWeightClasses());
        $this->assertEquals($options['fixRelativeURLs'], $config->getFixRelativeURLs());
        $this->assertEquals($options['substituteEntities'], $config->getSubstituteEntities());
        $this->assertEquals($options['normalizeEntities'], $config->getNormalizeEntities());
        $this->assertEquals($options['originalURL'], $config->getOriginalURL());
        $this->assertEquals($options['summonCthulhu'], $config->getSummonCthulhu());
    }

    /**
     * Data provider
     */
    public static function getParams(): array
    {
        return [[
            'params' => [
                'maxTopCandidates' => 3,
                'wordThreshold' => 500,
                'charThreshold' => 500,
                'articleByline' => true,
                'stripUnlikelyCandidates' => false,
                'cleanConditionally' => false,
                'weightClasses' => false,
                'fixRelativeURLs' => true,
                'substituteEntities' => true,
                'normalizeEntities' => true,
                'originalURL' => 'my.original.url',
                'summonCthulhu' => 'my.original.url',
                'invalidParameter' => 'invalidParameterValue'
            ]
        ]];
    }

    /**
     * Test if a logger interface can be injected and retrieved from the Configuration object.
     */
    public function testLoggerCanBeInjected(): void
    {
        $configuration = new Configuration();
        $log = new Logger('Readability');
        $log->pushHandler(new NullHandler());

        $configuration->setLogger($log);

        $this->assertSame($log, $configuration->getLogger());
    }
}
