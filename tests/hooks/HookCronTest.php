<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\thissdisco;

use SimpleSAML\Configuration;
use SimpleSAML\TestUtils\ClearStateTestCase;

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols
require_once(dirname(__FILE__, 3) . '/hooks/hook_cron.php');
// phpcs:enable

final class HookCronTest extends ClearStateTestCase
{
    /** @var \SimpleSAML\Configuration */
    protected Configuration $config;

    /** @var \SimpleSAML\Configuration */
    protected Configuration $moduleConfig;


    protected function setUp(): void
    {
        parent::setUp();
        $empty = Configuration::loadFromArray([], '[ARRAY]', 'simplesaml',);
        Configuration::setPreLoadedConfig($empty, 'module_cron.php');
        Configuration::setPreLoadedConfig($empty, 'authsources.php');

        $this->moduleConfig = Configuration::loadFromArray(
            ['cachetype' => 'array', 'cachedir' => 'phpunit', 'crontags' => 'phpunit'],
            '[ARRAY]',
            'simplesaml',
        );
        Configuration::setPreLoadedConfig($this->moduleConfig, 'module_thissdisco.php');

        $this->config = Configuration::loadFromArray(
            [
                'module.enable' => ['thissdisco' => true, 'saml' => true, 'cron' => 'true'],
                'language.default' => 'af',
                'metadata.sources' => [
                    ['type' => 'flatfile', 'directory' => dirname(__FILE__, 1) . '/test-metadata'],
                ],
            ],
            '[ARRAY]',
            'simplesaml',
        );
        Configuration::setPreLoadedConfig($this->config, 'config.php');
    }


    public function testHookCronExists(): void
    {
        $hook = function_exists('thissdisco_hook_cron');
        $this->assertTrue($hook);
    }


    public function testHookCron(): void
    {
        $croninfo = ['tag' => 'phpunit','summary' => []];
        thissdisco_hook_cron($croninfo);
        $this->assertIsArray($croninfo['summary']);
        $this->assertIsList($croninfo['summary']);
        $this->assertCount(1, $croninfo['summary']);
        $this->assertIsString($croninfo['summary'][0]);
        $this->assertMatchesRegularExpression(
            '/warmed up the MDQ cache with|no metadata found to cache/',
            $croninfo['summary'][0],
        );
    }
}
