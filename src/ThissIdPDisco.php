<?php

declare(strict_types=1);

namespace SimpleSAML\Module\thissdisco;

use SimpleSAML\Assert;
use SimpleSAML\Auth;
use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\Logger;
use SimpleSAML\Module;
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
     * @param ?array $spmd SP metatata
     * @return ?string trust profile name
     */
    private function getTrustProfile(?array $spmd): ?string
    {
        $trustProfile = $this->request->get('trustProfile', null);
        if ($trustProfile === null) {
            if (is_array($spmd) && array_key_exists('thissdisco.trustProfile', $spmd)) {
                $trustProfile = $spmd['thissdisco.trustProfile'];
            }
        }
        Logger::debug(sprintf(
            'idpDisco.%s: trust profile for %s%s is %s',
            $this->instance,
            $spmd['entityid'],
            $spmd['entityid'] != $this->spEntityId ? ' [via ' . $this->spEntityId . ']' : '',
            $trustProfile ?? '[none]',
        ));
        return $trustProfile;
    }

    public function handleRequest(): void
    {
        $this->start();
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
                'trustProfile' => $this->request->get('trustProfile'),
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

        /* get information about the thiss config */
        $mdq = $this->moduleConfig->getOptionalArray('mdq', []);
        $mdq_url = $mdq['lookup_base'] ?? Module::getModuleURL('thissdisco/entities/');
        $search_url = $mdq['search'] ?? $mdq['lookup_base'] ?? Module::getModuleURL('thissdisco/entities');

        $persistence = $this->moduleConfig->getOptionalArray('persistence', []);
        $persistence_url = $persistence['url'] ?? Module::getModuleURL('thissdisco/persistence');
        $persistence_context = $persistence['context'] ?? self::class;

        $learn_more_url = $this->moduleConfig->getOptionalString('learn_more_url', null);

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

        try {
            $spmd = $this->metadata->getMetaData($this->spEntityId, 'saml20-sp-remote');
            if (
                $this->moduleConfig->getOptionalBoolean('useunsafereturn', false)
                && $this->request->query->has('return')
            ) {
                /**
                 * As with discopower, this attempts to let protocol bridges retrieve the SP metadata
                 * from the other side of the protocol bridge by retrieving the state.
                 * Because the disco is not explicitly passed the state ID, we can use a crude hack to
                 * infer it from the return parameter. This should be relatively safe because we're not
                 * going to trust it for anything other than finding the `thissdisco.trustProfile` elements,
                 * and because the SP could bypass all of this anyway by specifying a known IdP in scoping.
                 */
                parse_str(parse_url($this->request->query->get('return'), PHP_URL_QUERY), $returnState);
                if (array_key_exists('AuthID', $returnState)) {
                    /* first preference is to get it from the state */
                    $state = Auth\State::loadState($returnState['AuthID'], 'saml:sp:sso', true);
                    if ($state && array_key_exists('SPMetadata', $state)) {
                        $spmd = $state['SPMetadata'];
                        $this->log(sprintf(
                            'Updated SP metadata from %s to %s via state',
                            $this->spEntityId,
                            $spmd['entityid'],
                        ));
                    }
                    /*
                    elseif (
                        preg_match('/[?&]spentityid=([^&]*)/', $returnState['AuthID'], $matches)
                        && isset($matches[1])
                    ) {
                        // if the session fails, we could get it from the spentityid
                        // param in the return. But that is more vulnerable to tampering,
                        // so this code is commented out.
                        $spentityid = urldecode($matches[1]);
                        $spmd = $this->metadata->getMetaData($spentityid, 'saml20-sp-remote');
                        $this->log(sprintf(
                            'Updated SP metadata from %s to %s via spentityid',
                            $this->spEntityId,
                            $spentityid,
                        ));
                    }
                    */
                }
            }
        } catch (Error\MetadataNotFound | Error\NoState $e) {
            // ignore
        } finally {
            $trustProfile = $this->getTrustProfile($spmd ?? null);
            $originalEntityId = $spmd['entityid'] ?? $this->spEntityId;
        }

        /* save them for thissdisco.js */
        $this->session->setData(
            self::class,
            'thissParms',
            [
                'mdq_url' => $mdq_url,
                'search_url' => $search_url,
                'persistence_url' => $persistence_url,
                'persistence_context ' => $persistence_context,
                'learn_more_url' => $learn_more_url,
                'trustProfile' => $trustProfile,
                'discovery_response_warning' => $discovery_response_warning,
                'discovery_response_warning_url' => $discovery_response_warning_url,
                'originEntityId' => $originalEntityId,
            ],
        );

        /* and then make them available here too */
        $t->data['mdq_url'] = $mdq_url;
        $t->data['search_url'] = $search_url;
        $t->data['persistence_url'] = $persistence_url;
        $t->data['persistence_context'] = $persistence_context;
        $t->data['learn_more_url'] = $learn_more_url;
        $t->data['trustProfile'] = $trustProfile;
        $t->data['discovery_response_warning'] = $discovery_response_warning ? 'true' : 'false';
        $t->data['discovery_response_warning_url'] = $discovery_response_warning_url;
        /* these two will be the same unless we are via a protocol bridge */
        $t->data['spEntityId'] = $this->spEntityId;
        $t->data['originEntityId'] = $originalEntityId;
        /* As a theme designer, it always irritates me when modules have metadata
         * but don't make it available for use in a theme. We have it here, so ... */
        $t->data['source'] = $spmd ?? [];

        /* add the basic disco params */
        $t->data['return'] = $this->returnURL;
        $t->data['returnIDParam'] = $this->returnIdParam;
        $t->data['entityID'] = $this->spEntityId;
        $httpUtils = new Utils\HTTP();
        $t->data['urlpattern'] = $httpUtils->getSelfURLNoQuery();

        $t->send();
    }
}
