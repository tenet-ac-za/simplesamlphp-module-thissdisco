<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\thissdisco\Controller;

use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\Module\thissdisco\Controller;
use SimpleSAML\Session;
use SimpleSAML\TestUtils\ClearStateTestCase;
use SimpleSAML\XHTML\Template;
use Symfony\Component\HttpFoundation\{Request, StreamedResponse};

/**
 * @covers \SimpleSAML\Module\thissdisco\Controller\ThissDisco
 */
final class ThissDiscoTest extends ClearStateTestCase
{
    /** @var \SimpleSAML\Configuration */
    protected Configuration $config;

    /** @var \SimpleSAML\Configuration */
    protected Configuration $moduleConfig;

    /** @var \SimpleSAML\Configuration */
    protected Configuration $asConfig;

    /** @var \SimpleSAML\Module\thissdisco\Controller\ThissDisco */
    protected Controller\ThissDisco $controller;

    protected function setUp(): void
    {
        parent::setUp();
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
                    ['type' => 'flatfile', 'directory' => dirname(__FILE__, 2) . '/test-metadata'],
                ],
                'trusted.url.domains' => ['localhost', 'example.com',],
            ],
            '[ARRAY]',
            'simplesaml',
        );
        Configuration::setPreLoadedConfig($this->config, 'config.php');

        $this->controller = new Controller\ThissDisco($this->config);
    }

    public function testDiscoNoParams(): void
    {
        $request = Request::create('/thissdisco/disco', 'GET', []);
        // In SSP 2.3.x, IdPDisco still uses $_GET rather than the request
        $request->overrideGlobals();

        $this->expectException(Error\Error::class);
        $this->expectExceptionMessageMatches('/DISCOPARAMS/');
        $this->controller->main($request);
    }

    public function testDisco(): void
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

        $response = $this->controller->main($request);
        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertTrue($response->headers->has('content-security-policy'));
        $this->assertIsString($response->headers->get('content-security-policy', ''));
        $this->assertMatchesRegularExpression(
            '/child-src [^;]*use.thiss.io/',
            $response->headers->get('content-security-policy', ''),
        );
    }

    public function testThissDiscoJsNoSession(): void
    {
        $request = Request::create('/thissdisco/thissdisco.js', 'GET',);
        $request->overrideGlobals();
        $this->expectException(Error\Exception::class);
        $this->expectExceptionMessage('Could not get request parameters from session');

        $response = $this->controller->thissdiscojs($request);
    }

    public function testThissDiscoJs(): void
    {
        $session = Session::getSessionFromRequest();
        $session->setData(
            \SimpleSAML\Module\thissdisco\ThissIdPDisco::class,
            'requestParms',
            [
                'spEntityId' => 'https://myapp.example.org',
                'trustProfile' => 'sirtfi',
            ],
        );
        $session->setData(
            \SimpleSAML\Module\thissdisco\ThissIdPDisco::class,
            'thissParms',
            [
                'mdq_url' => 'mdq_url',
                'search_url' => 'search_url',
                'persistence_url' => 'persistence_url',
                'persistence_context ' => 'persistence_context',
                'learn_more_url' => 'learn_more_url',
                'trustProfile' => 'trustProfile',
                'discovery_response_warning' => true,
                'discovery_response_warning_url' => 'discovery_response_warning_url',
                'originEntityId' => 'https://myapp.example.org',
            ],
        );
        $request = Request::create('/thissdisco/thissdisco.js', 'GET',);
        $request->overrideGlobals();

        $response = $this->controller->thissdiscojs($request);
        $this->assertInstanceOf(Template::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertEquals('text/javascript', $response->headers->get('Content-Type'));
        $this->assertIsArray($response->data);
        $this->assertArrayHasKey('persistence_url', $response->data);
        $this->assertEquals('persistence_url', $response->data['persistence_url']);
        $this->assertArrayHasKey('mdq_url', $response->data);
        $this->assertIsString($response->data['mdq_url']);
        $this->assertEquals('mdq_url', $response->data['mdq_url']);
        $this->assertArrayHasKey('spEntityId', $response->data);
        $this->assertIsString($response->data['spEntityId']);
        $this->assertEquals('https://myapp.example.org', $response->data['spEntityId']);
        $this->assertIsString($response->data['originEntityId']);
        $this->assertEquals('https://myapp.example.org', $response->data['originEntityId']);
        $this->assertArrayHasKey('trustProfile', $response->data);
        $this->assertIsString($response->data['trustProfile']);
        $this->assertEquals('trustProfile', $response->data['trustProfile']);
    }
}
