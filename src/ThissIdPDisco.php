<?php

declare(strict_types=1);

namespace SimpleSAML\Module\thissdisco;

use SimpleSAML\Configuration;
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
    private Request $request;

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
        $t->send();
    }
}
