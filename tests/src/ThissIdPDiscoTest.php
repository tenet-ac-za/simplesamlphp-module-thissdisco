<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\thissdisco;

use PHPUnit\Framework\TestCase;
use Exception;
use SimpleSAML\Configuration;
use SimpleSAML\Metadata\MetaDataStorageHandler;
use SimpleSAML\Module\thissdisco\ThissIdPDisco;
use Symfony\Component\HttpFoundation\{Request, Response};

/**
 * @covers \SimpleSAML\Module\thissdisco\ThissIdPDisco
 */
final class ThissIdPDiscoTest extends TestCase
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
            [
                'persistence' => [
                    'url' => 'https://use.thiss.io/ps/',
                    'context' => 'thiss.io',
                ],
            ],
            '[ARRAY]',
            'simplesaml',
        );
        Configuration::setPreLoadedConfig($this->moduleConfig, 'module_thissdisco.php');

        $this->asConfig = Configuration::loadFromArray(
            [
                'example=saml' => [
                    'saml:SP',
                    'entityID' => 'https://myapp.example.org',
                ],
            ],
            '[ARRAY]',
            'simplesaml',
        );
        Configuration::setPreLoadedConfig($this->asConfig, 'authsources.php');

        $this->config = Configuration::loadFromArray(
            [
                'module.enable' => ['thissdisco' => true, 'saml' => true,],
                'language.default' => 'af',
                'metadata.sources' => [
                    ['type' => 'flatfile', 'directory' => dirname(__FILE__) . '/test-metadata'],
                ],
                'trusted.url.domains' => ['localhost', 'example.com',],
            ],
            '[ARRAY]',
            'simplesaml',
        );
        Configuration::setPreLoadedConfig($this->config, 'config.php');
    }

    public function testDiscoNoParams(): void
    {
        $request = Request::create('/thissdisco/disco', 'GET', []);
        // In SSP 2.3.x, IdPDisco still uses $_GET rather than the request
        $request->overrideGlobals();

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Missing parameter/');
        $thissidpdisco = new ThissIdPDisco($request, ['saml20-idp-remote'], 'thissiodisco',);
    }

    public function testDiscoConstruct(): void
    {
        $request = Request::create(
            '/thissdisco/disco',
            'GET',
            [
                'entityID' => 'https://myapp.example.org',
                'return' => 'https://example.com/return',
            ],
        );
        $request->overrideGlobals();

        $thissidpdisco = new ThissIdPDisco($request, ['saml20-idp-remote'], 'thissiodisco',);
        $this->assertInstanceOf(ThissIdPDisco::class, $thissidpdisco);
    }

    public function testDiscoHandler(): void
    {
        $request = Request::create(
            '/thissdisco/disco',
            'GET',
            [
                'entityID' => 'https://myapp.example.org',
                'return' => 'https://example.com/return',
            ],
        );
        $request->overrideGlobals();
        $thissidpdisco = new ThissIdPDisco($request, ['saml20-idp-remote'], 'thissiodisco',);

        $this->expectOutputRegex('/Find Your Institution/');
        $thissidpdisco->handleRequest();
    }
}
