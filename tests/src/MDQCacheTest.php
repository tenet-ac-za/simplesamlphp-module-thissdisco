<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\thissdisco;

use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\Module\thissdisco\MDQCache;
use SimpleSAML\TestUtils\ClearStateTestCase;

/**
 * @covers \SimpleSAML\Module\thissdisco\MDQCache
 */
final class MDQCacheTest extends ClearStateTestCase
{
    /** @var \SimpleSAML\Configuration */
    protected Configuration $config;

    /** @var \SimpleSAML\Configuration */
    protected Configuration $asConfig;


    protected function setUp(): void
    {
        parent::setUp();
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
        $this->assertTrue($result, 'set(a)');
        $result = $cache->has('a');
        $this->assertTrue($result, 'has(a)');
        $result = $cache->get('a');
        $this->assertIsString($result, 'get(a).type');
        $this->assertEquals('value', $result, 'get(a).value');

        $result = $cache->has('b');
        $this->assertFalse($result, 'has(b) [nonexistant]');
        $result = $cache->get('b');
        $this->assertEquals(null, $result, 'get(b) [nonexistant]');
        $result = $cache->get('b', 'default');
        $this->assertIsString($result, 'get(b).type [default]');
        $this->assertEquals('default', $result, 'get(b).value [default]');
        $result = $cache->has('b');
        $this->assertFalse($result, 'has(b) [nonexistant, post-get]');

        $result = $cache->delete('a');
        $this->assertTrue($result, 'delete(a)');
        $result = $cache->has('a');
        $this->assertFalse($result, 'has(a) [post-delete]');
        $result = $cache->get('a');
        $this->assertEquals(null, $result, 'get(a) [post-delete]');

        $result = $cache->delete('b');
        $this->assertTrue($result, 'delete(b) [nonexistent]');

        $result = $cache->prune();
        $this->assertTrue($result, 'prune()');
    }


    public function testConstructFilesPathNotSet(): void
    {
        $moduleConfig = Configuration::loadFromArray(
            ['cachetype' => 'filesystem',],
            '[ARRAY]',
            'simplesaml',
        );
        Configuration::setPreLoadedConfig($moduleConfig, 'module_thissdisco.php');
        $cache = new MDQCache($this->config, $moduleConfig);
        $this->assertInstanceOf(MDQCache::class, $cache, 'prune()');
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
        $this->expectExceptionMessageMatches(
            '/Redis DSNs start redis|The configuration is invalid: Redis extension/',
        );
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
