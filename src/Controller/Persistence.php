<?php

declare(strict_types=1);

namespace SimpleSAML\Module\thissdisco\Controller;

use SimpleSAML\Configuration;
use SimpleSAML\Logger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Placeholder controller for a non-existent persistence service.
 *
 * @deprecated Use the persistence services provided by SeamlessAccess/thiss.io
 */
class Persistence
{
    /**
     * Controller constructor.
     *
     * It initializes the global configuration for the controllers implemented here.
     *
     * @param \SimpleSAML\Configuration $config The configuration to use by the controllers.
     */
    public function __construct(
        protected Configuration $config,
    ) {
    }


    public function __invoke(Request $request): Response
    {
        return $this->persistence($request);
    }


    /**
     * @param \Symfony\Component\HttpFoundation\Request $request The current request.
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function persistence(Request $request): Response
    {
        $nonce = hash('sha1', random_bytes(16));
        $response = new Response();
        $response->setStatusCode(Response::HTTP_NOT_IMPLEMENTED);
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self' 'nonce-$nonce';",
        );
        $response->setContent(
            '<html><head><meta charset="utf-8">' .
            '<style nonce="' . $nonce . '">' .
            'input[disabled]{outline: 1px solid #da4932; accent-color: #ca452e;}' .
            'input:disabled {outline: 1px solid #da4932; accent-color: #ca452e;}' .
            '</style></head><body>' .
            // phpcs:ignore
            '<input type="checkbox" id="ps-checkbox-adv" class="checkbox-in-iframe" disabled="disabled" title="Persistence is disabled, cannot remember you.">' .
            '</body><script nonce="' . $nonce . '">' .
            // phpcs:ignore
            'console.warn("simplesamlphp-module-thissdisco does not implement a persistence service.\nUse use.thiss.io or service.seamlessaccess.org instead.");' .
            '</script></html>',
        );
        Logger::warning(
            'simplesamlphp-module-thissdisco does not implement a persistence service.' .
            'Use use.thiss.io or service.seamlessaccess.org instead.',
        );
        return $response;
    }
}
