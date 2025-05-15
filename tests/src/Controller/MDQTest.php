<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\thissdisco\Controller;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SimpleSAML\Configuration;
use SimpleSAML\Metadata\MetaDataStorageHandler;
use SimpleSAML\Module\thissdisco\Controller;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};

/**
 * @covers \SimpleSAML\Module\thissdisco\Controller\MDQ
 */
final class MDQTest extends TestCase
{
    /** @var \SimpleSAML\Configuration */
    protected Configuration $config;

    /** @var \SimpleSAML\Configuration */
    protected Configuration $moduleConfig;

    /** @var \SimpleSAML\Configuration */
    protected Configuration $asConfig;

    /** @var \SimpleSAML\Module\thissdisco\Controller\MDQ */
    protected Controller\MDQ $controller;

    protected function setUp(): void
    {
        MetaDataStorageHandler::clearInternalState();
        Configuration::clearInternalState();

        $this->moduleConfig = Configuration::loadFromArray(
            [],
            '[ARRAY]',
            'simplesaml',
        );
        Configuration::setPreLoadedConfig($this->moduleConfig, 'module_thissdisco.php');

        $this->asConfig = Configuration::loadFromArray(
            [
                'example=saml' => [
                    'saml:SP',
                    'entityID' => 'https://myapp.example.org',
                    'EntityAttributes' => [
                        'https://refeds.org/entity-selection-profile' => [
                            // phpcs:ignore
                            'eyJwcm9maWxlcyI6eyJzaXJ0ZmkiOnsiZW50aXRpZXMiOlt7ImluY2x1ZGUiOnRydWUsIm1hdGNoIjoiYXNzdXJhbmNlX2NlcnRpZmljYXRpb24iLCJzZWxlY3QiOiJodHRwczovL3JlZmVkcy5vcmcvc2lydGZpIn1dLCJzdHJpY3QiOnRydWV9fX0=',
                        ],
                    ],
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
            ],
            '[ARRAY]',
            'simplesaml',
        );
        Configuration::setPreLoadedConfig($this->config, 'config.php');

        $this->controller = new Controller\MDQ($this->config);
    }

    /** @param array<string, string> $parameters */
    protected function createRequest(array $parameters = []): Request
    {
        $request = Request::create(
            '/thissdisco/entities/',
            'GET',
            $parameters,
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        );
        return $request;
    }

    public function testEntities(): void
    {
        $request = $this->createRequest();

        $response = $this->controller->mdq($request, null);
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertIsString($response->getContent());
        $this->assertJson($response->getContent());
        $decoded = @json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertIsList($decoded);
        $this->assertCount(3, $decoded);
    }

    public function testEntitityId(): void
    {
        $request = $this->createRequest();

        $response = $this->controller->mdq($request, 'https://example.org/idp');
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertIsString($response->getContent());
        $this->assertJson($response->getContent());
        $decoded = @json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('entity_id', $decoded);
        $this->assertEquals('https://example.org/idp', $decoded['entity_id']);
    }

    public function testEntitityHash(): void
    {
        $request = $this->createRequest();

        $response = $this->controller->mdq($request, '{SHA1}a6697b13dcebd5398d2d2d21465ca5a518ba2853');
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertIsString($response->getContent());
        $this->assertJson($response->getContent());
        $decoded = @json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('entity_id', $decoded);
        $this->assertEquals('https://example.org/idp', $decoded['entity_id']);
    }

    public function testEntitityNonexistent(): void
    {
        $request = $this->createRequest();

        $response = $this->controller->mdq($request, 'nonexistent');
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertIsString($response->getContent());
        $this->assertJson($response->getContent());
        $decoded = @json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertIsList($decoded);
        $this->assertCount(0, $decoded);
    }

    public function testEntitiesSearch(): void
    {
        $request = $this->createRequest(['q' => 'another']);

        $response = $this->controller->mdq($request, null);
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertIsString($response->getContent());
        $this->assertJson($response->getContent());
        $decoded = @json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertIsList($decoded);
        $this->assertArrayNotHasKey('entity_id', $decoded);
        $this->assertCount(2, $decoded);
        $this->assertArrayHasKey('entity_id', $decoded[0]);
        $this->assertEquals('https://example.org/idp', $decoded[0]['entity_id']);
        $this->assertEquals('https://example.com/idp', $decoded[1]['entity_id']);
    }

    public function testEntitiesFilter(): void
    {
        $request = $this->createRequest(['entity_filter' => 'sp']);

        $response = $this->controller->mdq($request, null);
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertIsString($response->getContent());
        $this->assertJson($response->getContent());
        $decoded = @json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertIsList($decoded);
        $this->assertArrayNotHasKey('entity_id', $decoded);
        $this->assertCount(2, $decoded);
        $this->assertArrayHasKey('entity_id', $decoded[0]);
        $this->assertEquals('https://myapp.example.org', $decoded[0]['entity_id']);
        $this->assertEquals('https://example.org/sp', $decoded[1]['entity_id']);
    }

    public function testEntitiesTrustProfile(): void
    {
        $request = $this->createRequest([
            'trustProfile' => 'sirtfi',
            'entityID' => 'https://myapp.example.org',
        ]);

        $response = $this->controller->mdq($request, null);
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertIsString($response->getContent());
        $this->assertJson($response->getContent());
        $decoded = @json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertIsList($decoded);
        $this->assertArrayNotHasKey('entity_id', $decoded);
        $this->assertCount(1, $decoded);
        $this->assertArrayHasKey('entity_id', $decoded[0]);
        $this->assertEquals('https://example.com/idp', $decoded[0]['entity_id']);
    }

    public function testEntitiesInvalidTrustProfile(): void
    {
        $request = $this->createRequest([
            'trustProfile' => 'nonexistent',
            'entityID' => 'https://myapp.example.org',
        ]);

        $response = $this->controller->mdq($request, null);
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertIsString($response->getContent());
        $this->assertJson($response->getContent());
        $decoded = @json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertIsList($decoded);
        $this->assertArrayNotHasKey('entity_id', $decoded);
        $this->assertCount(3, $decoded);
        $this->assertArrayHasKey('entity_id', $decoded[0]);
    }

    public function testEntityTrustProfile(): void
    {
        $request = $this->createRequest([
            'trustProfile' => 'sirtfi',
            'entityID' => 'https://myapp.example.org',
        ]);

        $response = $this->controller->mdq($request, 'https://example.com/idp');
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertIsString($response->getContent());
        $this->assertJson($response->getContent());
        $decoded = @json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('entity_id', $decoded);
    }

    public function testEntityTrustProfileNotMatch(): void
    {
        $request = $this->createRequest([
            'trustProfile' => 'sirtfi',
            'entityID' => 'https://myapp.example.org',
        ]);

        $response = $this->controller->mdq($request, 'https://example.org/idp');
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertIsString($response->getContent());
        $this->assertJson($response->getContent());
        $decoded = @json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertIsList($decoded);
        $this->assertArrayNotHasKey('entity_id', $decoded);
        $this->assertCount(0, $decoded);
    }

    /* non public methods */

    /** @covers \SimpleSAML\Module\thissdisco\Controller\MDQ::getSelectionProfiles */
    public function testgetSelectionProfiles(): void
    {
        $m = new ReflectionMethod(Controller\MDQ::class, 'getSelectionProfiles');
        $m->setAccessible(true);
        $result = $m->invoke(
            $this->controller,
            [
                'entityid' => 'https://example.com/getselectionprofiles',
                'EntityAttributes' => [
                    'https://refeds.org/entity-selection-profile' => [
                        // phpcs:ignore
                        'eyJwcm9maWxlcyI6eyJzaXJ0ZmkiOnsiZW50aXRpZXMiOlt7ImluY2x1ZGUiOnRydWUsIm1hdGNoIjoiYXNzdXJhbmNlX2NlcnRpZmljYXRpb24iLCJzZWxlY3QiOiJodHRwczovL3JlZmVkcy5vcmcvc2lydGZpIn1dLCJzdHJpY3QiOnRydWV9fX0=',
                    ],
                ],
            ],
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('profiles', $result);
        $this->assertArrayHasKey('sirtfi', $result['profiles']);
    }

    /** @covers \SimpleSAML\Module\thissdisco\Controller\MDQ::filterLangs */
    public function testfilterLangs(): void
    {
        $m = new ReflectionMethod(Controller\MDQ::class, 'filterLangs');
        $m->setAccessible(true);
        $result = $m->invoke(
            $this->controller,
            [
                'en' => 'English',
                'af' => 'Afrikaans',
            ],
        );

        $this->assertIsString($result);
        $this->assertEquals('Afrikaans', $result);
    }

    /** @covers \SimpleSAML\Module\thissdisco\Controller\MDQ::filterLangs */
    public function testfilterLangsDefault(): void
    {
        $m = new ReflectionMethod(Controller\MDQ::class, 'filterLangs');
        $m->setAccessible(true);
        $result = $m->invoke(
            $this->controller,
            [
                'en' => 'English',
                'nl' => 'Dutch',
            ],
        );

        $this->assertIsString($result);
        $this->assertEquals('English', $result);
    }

    /** @covers \SimpleSAML\Module\thissdisco\Controller\MDQ::filterLangs */
    public function testfilterLangsFallback(): void
    {
        $m = new ReflectionMethod(Controller\MDQ::class, 'filterLangs');
        $m->setAccessible(true);
        $result = $m->invoke(
            $this->controller,
            [
                'xh' => 'Xhosa',
                'nl' => 'Dutch',
            ],
        );

        $this->assertIsString($result);
        $this->assertEquals('Xhosa', $result);
    }

    /** @covers \SimpleSAML\Module\thissdisco\Controller\MDQ::entityAsDiscoJSON */
    public function testentityAsDiscoJSON(): void
    {
        $m = new ReflectionMethod(Controller\MDQ::class, 'entityAsDiscoJSON');
        $m->setAccessible(true);
        $result = $m->invoke(
            $this->controller,
            [
                'entityid' => 'https://example.com/entityasdiscojson',
                'name' => ['en' => 'Example IdP', 'af' => 'Voorbeeld IdP'],
                'description' => ['en' => 'Example IdP', 'nl' => 'Voorbeeld IdP'],
                'metadata-set' => 'saml20-idp-remote',
                'EntityAttributes' => [
                    'http://macedir.org/entity-category' => [
                        'http://refeds.org/category/hide-from-discovery',
                    ],
                ],
            ],
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertEquals('Voorbeeld IdP', $result['title']);
        $this->assertArrayHasKey('descr', $result);
        $this->assertEquals('Example IdP', $result['descr']);
        $this->assertArrayHasKey('hidden', $result);
        $this->assertEquals('true', $result['hidden']);
    }
}
