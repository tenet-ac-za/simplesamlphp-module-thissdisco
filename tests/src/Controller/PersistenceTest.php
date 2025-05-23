<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\thissdisco\Controller;

use PHPUnit\Framework\TestCase;
use SimpleSAML\Configuration;
use SimpleSAML\Metadata\MetaDataStorageHandler;
use SimpleSAML\Module\thissdisco\Controller;
use Symfony\Component\HttpFoundation\{Request, Response};

/**
 * @covers \SimpleSAML\Module\thissdisco\Controller\Persistence
 */
final class PersistenceTest extends TestCase
{
    /** @var \SimpleSAML\Configuration */
    protected Configuration $config;

    /** @var \SimpleSAML\Module\thissdisco\Controller\Persistence */
    protected Controller\Persistence $controller;

    protected function setUp(): void
    {
        MetaDataStorageHandler::clearInternalState();
        Configuration::clearInternalState();
        $this->config = Configuration::loadFromArray(
            [
                'module.enable' => [ 'thissdisco' => true, ],
                'store.type' => 'phpsession',
                'session.phpsession.cookiename' => 'PHPUnitSession',
                'session.cookie.name' => 'PHPUnitSession',
            ],
            '[ARRAY]',
            'simplesaml',
        );
        Configuration::setPreLoadedConfig($this->config, 'config.php');
        $this->controller = new Controller\Persistence($this->config);
    }

    public function testPersistence(): void
    {
        $request = Request::create(
            '/thissdisco/persistence',
            'GET',
        );

        $response = $this->controller->persistence($request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertFalse($response->isSuccessful());
        $this->assertEquals(Response::HTTP_NOT_IMPLEMENTED, $response->getStatusCode());
    }
}
