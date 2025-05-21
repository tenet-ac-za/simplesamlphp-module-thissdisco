<?php

declare(strict_types=1);

namespace SimpleSAML\Module\thissdisco;

use SimpleSAML\Assert;
use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\Logger;
use SimpleSAML\Utils;
use SimpleSAML\XHTML\IdPDisco;
use SimpleSAML\XHTML\Template;
use Symfony\Component\HttpFoundation\Request;

/**
 * A subclass to extending the built-in SimpleSAML\XHTML\IdPDisco class.
 *
 * Provides additonal features needed by this module:
 *  - It caches the requestParms in the session so that they can be rendered
 *    in the thissdisco.js output (thus working around CSP restrictons).
 *  - It changes the default template to a module-specific one.
 *
 * @property \SimpleSAML\Configuration $config
 * @property \SimpleSAML\Session $session
 */
class ThissIdPDisco extends IdPDisco
{
    /** @var \SimpleSAML\Configuration The configuration for the module */
    private Configuration $moduleConfig;

    /** @var \Symfony\Component\HttpFoundation\Request The current request */
    protected Request $request;

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request The current request.
     * @param array  $metadataSets Array with metadata sets we find remote entities in.
     * @param string $instance The name of this instance of the discovery service.
     */
    public function __construct(Request $request, array $metadataSets, string $instance)
    {
        $this->moduleConfig = Configuration::getConfig('module_thissdisco.php');
        $this->request = $request;
        parent::__construct($metadataSets, $instance);
    }

    /**
     * Get an appropriate trust profile
     *
     * Order of precedence is:
     *  - profile name given in the query params to the discovery service
     *  - profile name from the appropriate SP's metadata
     *  - any global profile from module_thissdisco.php
     *  - no profile (null)
     *
     * @return ?string trust profile name
     */
    private function getTrustProfile(): ?string
    {
        $trustProfile = $this->request->get('trustProfile', null);
        if ($trustProfile === null) {
            $trustProfile = $this->moduleConfig->getOptionalString('trustProfile', null);
            try {
                $spmd = $this->metadata->getMetaData($this->spEntityId, 'saml20-sp-remote');
                if ($spmd && array_key_exists('thissdisco.trustProfile', $spmd)) {
                    $trustProfile = $spmd['thissdisco.trustProfile'];
                }
            } catch (Error\MetadataNotFound) {
                // ignore the error
            }
        }
        Logger::debug(sprintf(
            'idpDisco.%s: trust profile for %s is %s',
            $this->instance,
            $this->spEntityId,
            $trustProfile ?? '[none]',
        ));
        return $trustProfile;
    }

    public function handleRequest(): void
    {
        $this->start();

        $trustProfile = $this->getTrustProfile();
        $this->session->setData(
            self::class,
            'requestParms',
            [
                'spEntityId' => $this->spEntityId,
                'returnIDParam' => $this->returnIdParam,
                'return' => $this->returnURL,
                'isPassive ' => $this->isPassive,
                'setIdPentityID' => $this->setIdPentityID,
                'scopedIDPList' => $this->scopedIDPList,
                'trustProfile' => $trustProfile,
            ],
        );

        $t = new Template($this->config, 'thissdisco:disco.twig');
        $basetemplate = $this->moduleConfig->getOptionalValueValidate(
            'basetemplate',
            ['simplesamlphp', 'thissio', 'seamlessaccess'],
            'simplesamlphp',
        );
        if ($basetemplate === 'simplesamlphp') {
            $t->data['base_template'] = 'base.twig';
        } else {
            $t->data['base_template'] = '@thissdisco/usethissio.twig';
        }

        $discovery_response_warning = $this->moduleConfig->getOptionalValue('discovery_response_warning', false);
        if (!is_bool($discovery_response_warning)) {
            Assert\Assert::validURL(
                $discovery_response_warning,
                'discovery_response_warning should be true/false or a URL',
                Error\ConfigurationError::class,
            );
            $discovery_response_warning_url = $discovery_response_warning;
            $discovery_response_warning = true;
        } else {
            // use.thiss.io / seamlessaccess default URL
            $discovery_response_warning_url = 'https://seamlessaccess.atlassian.net/wiki/x/B4C_Vw';
        }
        $t->data['discovery_response_warning'] = $discovery_response_warning;
        $t->data['discovery_response_warning_url'] = $discovery_response_warning_url;
        $t->data['trustProfile'] = $trustProfile;

        /* add the basic disco params */
        $t->data['return'] = $this->returnURL;
        $t->data['returnIDParam'] = $this->returnIdParam;
        $t->data['entityID'] = $this->spEntityId;
        $httpUtils = new Utils\HTTP();
        $t->data['urlpattern'] = $httpUtils->getSelfURLNoQuery();

        $t->send();
    }
}
