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
     * Render the discovery service.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request The current request.
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function main(Request $request): StreamedResponse
    {
        try {
            $discoHandler = new ThissIdPDisco(
                $request,
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
         * attempt to fix up the content security policy header to allow the MDQ & persistence services
         * must happen here raher than in the StreamedResponse or else they get merged not replaced
         */
        $headers = $this->config->getOptionalArray('headers.security', Configuration::DEFAULT_SECURITY_HEADERS);
        $mdq = $this->moduleConfig->getOptionalArray('mdq', []);
        $persistence = $this->moduleConfig->getOptionalArray('persistence', []);
        if (
            isset($headers['Content-Security-Policy'])
            && (!empty($persistence) || !empty($mdq))
        ) {
            $csp = $headers['Content-Security-Policy'];
            /* the mdq service needs to connect */
            if (isset($mdq['lookup_base'])) {
                $connect_src = $mdq['lookup_base'];
                if (isset($mdq['search']) && $mdq['search'] !== $mdq['lookup_base']) {
                    $connect_src .= ' ' . $mdq['search'];
                }
                if (str_contains($csp, 'connect-src')) {
                    $csp = preg_replace(
                        '/(^|;\s+)connect-src/',
                        "\\1connect-src 'self' " . $connect_src,
                        $csp,
                    );
                } else {
                    $csp .= "; connect-src 'self' " . $connect_src;
                }
            }
            /* the persistance service is an iframe child of the disco service */
            if (isset($persistence['url'])) {
                if (str_contains($csp, 'child-src')) {
                    $csp = preg_replace(
                        '/(^|;\s+)child-src/',
                        "\\1child-src 'self' " . $persistence['url'],
                        $csp,
                    );
                } else {
                    $csp .= "; child-src 'self' " . $persistence['url'];
                }
            }
            /* if we allow images from the persistence service we need to add https: and data: */
            if (isset($persistence['csp_images']) && $persistence['csp_images'] !== false) {
                $imgsrc = $persistence['csp_images'] === true ? 'https: data:' : $persistence['csp_images'];
                if (str_contains($csp, 'img-src')) {
                    $csp = preg_replace(
                        '/(^|;\s+)img-src/',
                        "\\1img-src 'self' " . $imgsrc,
                        $csp,
                    );
                } else {
                    $csp .= "; img-src 'self' " . $imgsrc;
                }
            }
            $response->headers->set('Content-Security-Policy', $csp);
        }
        return $response;
    }

    /**
     * Retrieve paramaters from the session (originally from the request) and
     * return them as a javascript file for inclusion. That avoids having to
     * render javascript in the template and gets around CSP issues.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request The current request.
     * @return \SimpleSAML\XHTML\Template
     */
    public function discoconfjs(Request $request): Template
    {
        $session = Session::getSessionFromRequest();
        $requestParams = $session->getData(ThissIdPDisco::class, 'requestParms');

        $mdq = $this->moduleConfig->getOptionalArray('mdq', []);
        $mdq_url = $mdq['lookup_base'] ?? Module::getModuleURL('thissdisco/entities/');
        $search_url = $mdq['search'] ?? $mdq['lookup_base'] ?? Module::getModuleURL('thissdisco/entities');

        $persistence = $this->moduleConfig->getOptionalArray('persistence', []);
        $persistence_url = $persistence['url'] ?? Module::getModuleURL('thissdisco/persistence');
        $persistence_context = $persistence['context'] ?? self::class;

        $learn_more_url = $this->moduleConfig->getOptionalString('learn_more_url', null);
        $trustProfile = $this->moduleConfig->getOptionalString('trustProfile', null);

        $t = new Template($this->config, 'thissdisco:discoconfjs.twig');
        $t->headers->set('Content-Type', 'text/javascript');
        $t->headers->set('Vary', 'Accept-Encoding, Cookie');

        $t->data['spEntityId'] = $requestParams['spEntityId'];
        $t->data['mdq_url'] = $mdq_url;
        $t->data['search_url'] = $search_url;
        $t->data['persistence_url'] = $persistence_url;
        $t->data['persistence_context'] = $persistence_context;
        $t->data['learn_more_url'] = $learn_more_url;
        $t->data['trustProfile'] = $trustProfile
            ?? $requestParams['trustProfile']
            ?? $request->query->get('trustProfile')
            ?? null;
        return $t;
    }
}
