<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\thissdisco\Controller;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\Metadata\MetaDataStorageHandler;
use SimpleSAML\Module\thissdisco\Controller;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};

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
            ['cachetype' => 'array', 'cachedir' => 'phpunit'],
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
        $this->assertCount(4, $decoded);
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
        $this->assertFalse($response->isSuccessful());
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
        $this->AssertIsArray($decoded[0]);
        $this->assertArrayHasKey('entity_id', $decoded[0]);
        $this->assertEquals('https://example.org/idp', $decoded[0]['entity_id']);
        $this->AssertIsArray($decoded[1]);
        $this->assertArrayHasKey('entity_id', $decoded[1]);
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
        $this->AssertIsArray($decoded[0]);
        $this->assertArrayHasKey('entity_id', $decoded[0]);
        $this->assertEquals('https://myapp.example.org', $decoded[0]['entity_id']);
        $this->AssertIsArray($decoded[1]);
        $this->assertArrayHasKey('entity_id', $decoded[1]);
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
        $this->AssertIsArray($decoded[0]);
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
        $this->assertCount(4, $decoded);
        $this->AssertIsArray($decoded[0]);
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
        $this->assertFalse($response->isSuccessful());
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
        $this->assertIsArray($result['profiles']);
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

    /**
     * @covers \SimpleSAML\Module\thissdisco\Controller\MDQ::getTransformedFromEntityId
     */
    public function testgetTransformedFromEntityId(): void
    {
        $m = new ReflectionMethod(Controller\MDQ::class, 'getTransformedFromEntityId');
        $m->setAccessible(true);

        /* something in metadata */
        $result = $m->invoke($this->controller, 'https://example.org/idp', 'sha1');
        $this->assertEquals('{SHA1}a6697b13dcebd5398d2d2d21465ca5a518ba2853', $result);
        /* something not in metadata cached for testgetEntityIdFromTransformed() */
        $result = $m->invoke($this->controller, 'https://example.com/sha1');
        $this->assertEquals('{SHA1}3313d728609120d53ffce1f56b62965015351b86', $result);
        /* different algorithm */
        $result = $m->invoke($this->controller, 'https://example.com/sha256', 'sha256');
        $this->assertEquals('{SHA256}a10c7261a94cbf6c81720a9ab7c95381f2c4d60d1962a00c0a7d78b662d1635a', $result);
    }

    public function testgetTransformedFromEntityIdInvalidHash(): void
    {
        $m = new ReflectionMethod(Controller\MDQ::class, 'getTransformedFromEntityId');
        $m->setAccessible(true);

        $this->expectException(Error\BadRequest::class);
        $this->expectExceptionMessage('Invalid hash algorithm: {invalid}');
        $result = $m->invoke($this->controller, 'https://example.com/invalid', 'invalid');
    }

    /**
     * @covers \SimpleSAML\Module\thissdisco\Controller\MDQ::getEntityIdFromTransformed
     */
    public function testgetEntityIdFromTransformed(): void
    {
        $m = new ReflectionMethod(Controller\MDQ::class, 'getEntityIdFromTransformed');
        $m->setAccessible(true);

        /* something cached and in metadata */
        $result = $m->invoke($this->controller, '{SHA1}a6697b13dcebd5398d2d2d21465ca5a518ba2853');
        $this->assertEquals('https://example.org/idp', $result);
        /* something we've not cached but exists in metadata */
        $result = $m->invoke($this->controller, '{SHA1}742b8fe0b74155f31b3c1eda9af1fcebb332f2ee');
        $this->assertEquals('https://example.ac.za/idp', $result);
        /* something we've not cached but does not exist in metadata */
        $result = $m->invoke($this->controller, '{SHA1}c0db10cc2ba093017eb91a54949dc0df9006a643');
        $this->assertEquals(null, $result);
        /* robustness, check normalisation */
        $result = $m->invoke($this->controller, '{sha1}a6697b13dcebd5398d2d2d21465ca5a518ba2853');
        $this->assertEquals('https://example.org/idp', $result);
    }

    /**
     * @covers \SimpleSAML\Module\thissdisco\Controller\MDQ::getEntityIdFromTransformed
     */
    public function testgetTransformedFromEntityIdNotTransformed(): void
    {
        $m = new ReflectionMethod(Controller\MDQ::class, 'getEntityIdFromTransformed');
        $m->setAccessible(true);

        /* a non-transformed version */
        $result = $m->invoke($this->controller, 'https://example.com/sha256');
        $this->assertEquals('https://example.com/sha256', $result);
    }
}
