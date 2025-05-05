<?php

declare(strict_types=1);

namespace SimpleSAML\Module\thissdisco\Controller;

use SimpleSAML\Configuration;
use Symfony\Component\HttpFoundation\{Request, Response};

/**
 * Placeholder controller for a non-existent persistence service
 * @package SimpleSAMLphp
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
        if (!isset($this->config)) {
            $this->config = Configuration::getInstance();
        }
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
        $response->headers->set('Content-Security-Policy', "default-src 'self'; style-src 'self' 'nonce-$nonce';");
        $response->setContent(
            '<html><head><meta charset="utf-8">' .
            '<style nonce="' . $nonce . '">' .
            'input[disabled]{outline: 1px solid #da4932; accent-color: #ca452e;}' .
            'input:disabled {outline: 1px solid #da4932; accent-color: #ca452e;}' .
            '</style></head><body>' .
            '<input type="checkbox" id="ps-checkbox-adv" class="checkbox-in-iframe" ' .
            'disabled="disabled" checked="checked">' .
            '</body></html>',
        );
        return $response;
    }
}
