<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\thissdisco;

use PHPUnit\Framework\TestCase;
use Exception;
use ReflectionMethod;
use ReflectionProperty;
use SimpleSAML\Configuration;
use SimpleSAML\Metadata\MetaDataStorageHandler;
use SimpleSAML\Module\thissdisco\ThissIdPDisco;
use SimpleSAML\Session;
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
        Session::clearInternalState();

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
                'trustProfile' => 'query',
            ],
        );
        $request->overrideGlobals();
        $thissidpdisco = new ThissIdPDisco($request, ['saml20-idp-remote'], 'thissiodisco',);

        /* get the session */
        $rp = new ReflectionProperty(ThissIdPDisco::class, 'session');
        $rp->setAccessible(true);
        $session = $rp->getValue($thissidpdisco);
        $this->assertInstanceOf(Session::class, $session);
        /* should not be set before handleRequest */
        $requestParms = $session->getData(ThissIdPDisco::class, 'requestParms');
        $this->assertEquals(null, $requestParms);
        $thissParms = $session->getData(ThissIdPDisco::class, 'thissParms');
        $this->assertEquals(null, $thissParms);

        /* confirm a page is rendered */
        $this->expectOutputRegex('/Find Your Institution/');
        $thissidpdisco->handleRequest();

        /* get the session after handleRequest */
        $session = $rp->getValue($thissidpdisco);
        $this->assertInstanceOf(Session::class, $session);
        /* should be set after handleRequest */
        $requestParms = $session->getData(ThissIdPDisco::class, 'requestParms');
        $this->assertIsArray($requestParms);
        $this->assertArrayHasKey('spEntityId', $requestParms);
        $this->assertEquals('https://myapp.example.org', $requestParms['spEntityId']);
        $this->assertArrayHasKey('trustProfile', $requestParms);
        $this->assertEquals('query', $requestParms['trustProfile']);
        $thissParms = $session->getData(ThissIdPDisco::class, 'thissParms');
        $this->assertIsArray($thissParms);
        $this->assertArrayHasKey('persistence_url', $thissParms);
        $this->assertEquals('https://use.thiss.io/ps/', $thissParms['persistence_url']);
        $this->assertArrayHasKey('mdq_url', $thissParms);
        $this->assertIsString($thissParms['mdq_url']);
        $this->assertStringContainsString('simplesaml/module.php/thissdisco/entities/', $thissParms['mdq_url']);
        $this->assertArrayHasKey('trustProfile', $thissParms);
        $this->assertIsString($thissParms['trustProfile']);
        $this->assertEquals('query', $thissParms['trustProfile']);
    }

    public function testTrustProfile(): void
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

        $rm = new ReflectionMethod(ThissIdPDisco::class, 'getTrustProfile');
        $rm->setAccessible(true);

        $result = $rm->invoke($thissidpdisco, null);
        $this->assertEquals(null, $result);
    }

    public function testTrustProfileFromMd(): void
    {
        $request = Request::create(
            '/thissdisco/disco',
            'GET',
            [
                'entityID' => 'https://example.org/sp',
                'return' => 'https://example.com/return',
            ],
        );
        $request->overrideGlobals();
        $thissidpdisco = new ThissIdPDisco($request, ['saml20-idp-remote'], 'thissiodisco',);
        $metadata = MetaDataStorageHandler::getMetadataHandler();
        $spmd = $metadata->getMetaData('https://example.org/sp', 'saml20-sp-remote');


        $rm = new ReflectionMethod(ThissIdPDisco::class, 'getTrustProfile');
        $rm->setAccessible(true);

        $result = $rm->invoke($thissidpdisco, $spmd);
        $this->assertEquals('dontTrustMe', $result);
    }

    public function testTrustProfileOverride(): void
    {
        $request = Request::create(
            '/thissdisco/disco',
            'GET',
            [
                'entityID' => 'https://example.org/sp',
                'return' => 'https://example.com/return',
                'trustProfile' => 'queryParam',
            ],
        );
        $request->overrideGlobals();
        $thissidpdisco = new ThissIdPDisco($request, ['saml20-idp-remote'], 'thissiodisco',);
        $metadata = MetaDataStorageHandler::getMetadataHandler();
        $spmd = $metadata->getMetaData('https://example.org/sp', 'saml20-sp-remote');

        $rm = new ReflectionMethod(ThissIdPDisco::class, 'getTrustProfile');
        $rm->setAccessible(true);

        $result = $rm->invoke($thissidpdisco, $spmd);
        $this->assertEquals('queryParam', $result);
    }
}
