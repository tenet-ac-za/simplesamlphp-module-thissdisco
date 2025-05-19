<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\thissdisco;

use PHPUnit\Framework\TestCase;
use SimpleSAML\Configuration;
use SimpleSAML\Metadata\MetaDataStorageHandler;

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols
require_once(dirname(__FILE__, 3) . '/hooks/hook_cron.php');
// phpcs:enable

final class HookCronTest extends TestCase
{
    /** @var \SimpleSAML\Configuration */
    protected Configuration $config;

    /** @var \SimpleSAML\Configuration */
    protected Configuration $moduleConfig;

    /** @var \SimpleSAML\Configuration */
    protected Configuration $asConfig;

    protected function setUp(): void
    {
        MetaDataStorageHandler::clearInternalState();
        Configuration::clearInternalState();

        $this->moduleConfig = Configuration::loadFromArray(
            ['cachetype' => 'array', 'cachedir' => 'phpunit', 'crontags' => 'phpunit'],
            '[ARRAY]',
            'simplesaml',
        );
        Configuration::setPreLoadedConfig($this->moduleConfig, 'module_thissdisco.php');

        $this->asConfig = Configuration::loadFromArray(
            [],
            '[ARRAY]',
            'simplesaml',
        );
        Configuration::setPreLoadedConfig($this->asConfig, 'authsources.php');

        $this->config = Configuration::loadFromArray(
            [
                'module.enable' => ['thissdisco' => true, 'saml' => true,],
                'language.default' => 'af',
                'metadata.sources' => [
                    ['type' => 'flatfile', 'directory' => dirname(__FILE__, 2) . '/test-metadata'],
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
    }
}
