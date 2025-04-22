<?php

declare(strict_types=1);

namespace SimpleSAML\Module\thissdisco\Controller;

use Exception;
use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\Module;
use SimpleSAML\Session;
use SimpleSAML\Module\thissdisco\ThissIdPDisco;
use SimpleSAML\XHTML\Template;
use Symfony\Component\HttpFoundation\{Request, Response, StreamedResponse};

class ThissDisco
{
    /** @var \SimpleSAML\Configuration The configuration for the module */
    private Configuration $moduleConfig;

    public function __construct(
        protected Configuration $config,
    ) {
        if (!isset($this->config)) {
            $this->config = Configuration::getInstance();
        }
        $this->moduleConfig = Configuration::getConfig('module_thissdisco.php');
    }

    public function __invoke(Request $request): Response
    {
        return $this->main($request);
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request The current request.
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function main(Request $request): StreamedResponse
    {
        try {
            $discoHandler = new ThissIdPDisco(
                ['saml20-idp-remote'],
                'thissiodisco',
            );
        } catch (Exception $exception) {
            // An error here should be caused by invalid query parameters
            throw new Error\Error('DISCOPARAMS', $exception);
        }

        try {
            $response = new StreamedResponse([$discoHandler, 'handleRequest']);
        } catch (Exception $exception) {
            // An error here should be caused by metadata
            throw new Error\Error('METADATA', $exception);
        }
        /*
         * attempt to fix up the content security policy header to allow the persistence service
         * must happen here raher than in the StreamedResponse or else they get merged not replaced
         */
        $headers = $this->config->getOptionalArray('headers.security', Configuration::DEFAULT_SECURITY_HEADERS);
        $persistence_url = $this->moduleConfig->getOptionalString('persistence_url', null);
        if (isset($headers['Content-Security-Policy']) && $persistence_url !== null) {
            $csp = $headers['Content-Security-Policy'];
            if (str_contains($csp, 'child-src')) {
                $csp = preg_replace(
                    '/(^|;\s+)child-src/',
                    "\\1child-src 'self' " . $persistence_url,
                    $csp,
                );
            } else {
                $csp .= "; child-src 'self' " . $persistence_url;
            }
            /* if we allow images from the persistence service we need to add https: and data: */
            if (str_contains($csp, 'img-src')) {
                $csp = preg_replace(
                    '/(^|;\s+)img-src/',
                    "\\1img-src 'self' https: data:",
                    $csp,
                );
            } else {
                $csp .= "; img-src 'self' https: data:";
            }
            $response->headers->set('Content-Security-Policy', $csp);
        }
        return $response;
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request The current request.
     * @return \SimpleSAML\XHTML\Template
     */
    public function discoconfjs(Request $request): Template
    {
        $session = Session::getSessionFromRequest();
        $requestParams = $session->getData(ThissIdPDisco::class, 'requestParms');

        $mdqUrl = $this->moduleConfig->getOptionalString('mdq_url', Module::getModuleURL('thissdisco/entities/'));
        $search_url = $this->moduleConfig->getOptionalString(
            'search_url',
            $this->moduleConfig->getOptionalString('mdq_url', Module::getModuleURL('thissdisco/entities')),
        );
        $persistence_url = $this->moduleConfig->getOptionalString('persistence_url', Module::getModuleURL('thissdisco/persistence'));
        $persistence_context = $this->moduleConfig->getOptionalString('persistence_context', self::class);
        $learn_more_url = $this->moduleConfig->getOptionalString('learn_more_url', null);

        $t = new Template($this->config, 'thissdisco:discoconfjs.twig');
        $t->headers->set('Content-Type', 'text/javascript');
        $t->headers->set('Vary', 'Accept-Encoding, Cookie');

        $t->data['spEntityId'] = $requestParams['spEntityId'];
        $t->data['mdq_url'] = $mdqUrl;
        $t->data['search_url'] = $search_url;
        $t->data['persistence_url'] = $persistence_url;
        $t->data['persistence_context'] = $persistence_context;
        $t->data['learn_more_url'] = $learn_more_url;
        $t->data['trustProfile'] = $request->query->get('trustProfile') ?? null;
        return $t;
    }
}
