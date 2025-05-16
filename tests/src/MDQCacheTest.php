<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\thissdisco;

use PHPUnit\Framework\TestCase;
use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\Metadata\MetaDataStorageHandler;
use SimpleSAML\Module\thissdisco\MDQCache;

/**
 * @covers \SimpleSAML\Module\thissdisco\MDQCache
 */
final class MDQCacheTest extends TestCase
{
    /** @var \SimpleSAML\Configuration */
    protected Configuration $config;

    /** @var \SimpleSAML\Configuration */
    protected Configuration $asConfig;

    protected function setUp(): void
    {
        MetaDataStorageHandler::clearInternalState();
        Configuration::clearInternalState();

        $this->asConfig = Configuration::loadFromArray(
            [],
            '[ARRAY]',
            'simplesaml',
        );
        Configuration::setPreLoadedConfig($this->asConfig, 'authsources.php');

        $this->config = Configuration::loadFromArray(
            [
                'module.enable' => ['thissdisco' => true],
                'metadata.sources' => [
                    ['type' => 'flatfile', 'directory' => dirname(__FILE__) . '/test-metadata'],
                ],
            ],
            '[ARRAY]',
            'simplesaml',
        );
        Configuration::setPreLoadedConfig($this->config, 'config.php');
    }

    public function testConstruct(): void
    {
        $moduleConfig = Configuration::loadFromArray(
            ['cachetype' => 'array', 'cachedir' => 'phpunit'],
            '[ARRAY]',
            'simplesaml',
        );
        Configuration::setPreLoadedConfig($moduleConfig, 'module_thissdisco.php');
        $cache = new MDQCache($this->config, $moduleConfig);
        $this->assertInstanceOf(MDQCache::class, $cache);
    }

    public function testConstructInvalidDriver(): void
    {
        $moduleConfig = Configuration::loadFromArray(
            ['cachetype' => 'unknown', 'cachedir' => 'phpunit'],
            '[ARRAY]',
            'simplesaml',
        );
        Configuration::setPreLoadedConfig($moduleConfig, 'module_thissdisco.php');
        $this->expectException(Error\ConfigurationError::class);
        $this->expectExceptionMessageMatches('/cachetype must be one of/');
        $cache = new MDQCache($this->config, $moduleConfig);
    }

    public function testConstructArrayDriver(): void
    {
        $moduleConfig = Configuration::loadFromArray(
            ['cachetype' => 'array', 'cachedir' => 'phpunit'],
            '[ARRAY]',
            'simplesaml',
        );
        Configuration::setPreLoadedConfig($moduleConfig, 'module_thissdisco.php');
        $cache = new MDQCache($this->config, $moduleConfig);

        $result = $cache->set('a', 'value');
        $this->assertTrue($result);
        $result = $cache->has('a');
        $this->assertTrue($result);
        $result = $cache->get('a');
        $this->assertIsString($result);
        $this->assertEquals('value', $result);

        $result = $cache->has('b');
        $this->assertFalse($result);
        $result = $cache->get('b');
        $this->assertEquals(null, $result);
        $result = $cache->get('b', 'default');
        $this->assertIsString($result);
        $this->assertEquals('default', $result);
        $result = $cache->has('b');
        $this->assertFalse($result);

        $result = $cache->delete('a');
        $this->assertTrue($result);
        $result = $cache->has('a');
        $this->assertFalse($result);
        $result = $cache->get('a');
        $this->assertEquals(null, $result);

        $result = $cache->delete('b');
        $this->assertTrue($result);
    }

    public function testConstructFilesPathNotSet(): void
    {
        $moduleConfig = Configuration::loadFromArray(
            ['cachetype' => 'filesystem',],
            '[ARRAY]',
            'simplesaml',
        );
        Configuration::setPreLoadedConfig($moduleConfig, 'module_thissdisco.php');
        $this->expectException(Error\ConfigurationError::class);
        $this->expectExceptionMessageMatches('/cachedir must be a directory/');
        $cache = new MDQCache($this->config, $moduleConfig);
    }

    public function testConstructFilesInvalidPath(): void
    {
        $moduleConfig = Configuration::loadFromArray(
            ['cachetype' => 'filesystem', 'cachedir' => 'phpunit/nonexistant'],
            '[ARRAY]',
            'simplesaml',
        );
        Configuration::setPreLoadedConfig($moduleConfig, 'module_thissdisco.php');
        $this->expectException(Error\ConfigurationError::class);
        $this->expectExceptionMessageMatches('/cachedir directory does not exist/');
        $cache = new MDQCache($this->config, $moduleConfig);
    }

    public function testConstructRedisInvalidDSN(): void
    {
        $moduleConfig = Configuration::loadFromArray(
            ['cachetype' => 'redis', 'cachedir' => 'phpunit://nonexistant'],
            '[ARRAY]',
            'simplesaml',
        );
        Configuration::setPreLoadedConfig($moduleConfig, 'module_thissdisco.php');
        $this->expectException(Error\ConfigurationError::class);
        $this->expectExceptionMessageMatches('/Redis DSNs start redis/');
        $cache = new MDQCache($this->config, $moduleConfig);
    }

    public function testConstructRedis(): void
    {
        $moduleConfig = Configuration::loadFromArray(
            ['cachetype' => 'redis', 'cachedir' => 'redis://nonexistant'],
            '[ARRAY]',
            'simplesaml',
        );
        Configuration::setPreLoadedConfig($moduleConfig, 'module_thissdisco.php');
        $this->expectExceptionMessageMatches(
            '/Redis connection failed|Cannot find the "redis" extension|predis\/predis/',
        );
        $cache = new MDQCache($this->config, $moduleConfig);
    }
}
