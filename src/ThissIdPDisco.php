<?php

declare(strict_types=1);

namespace SimpleSAML\Module\thissdisco;

use SimpleSAML\Configuration;
use SimpleSAML\XHTML\IdPDisco;
use SimpleSAML\XHTML\Template;

class ThissIdPDisco extends IdPDisco
{
    /** @var \SimpleSAML\Configuration The configuration for the module */
    private Configuration $moduleConfig;

    /**
     * @param array  $metadataSets Array with metadata sets we find remote entities in.
     * @param string $instance The name of this instance of the discovery service.
     */
    public function __construct(array $metadataSets, string $instance)
    {
        $this->moduleConfig = Configuration::getConfig('module_thissdisco.php');
        parent::__construct($metadataSets, $instance);
    }

    public function handleRequest(): void
    {
        $this->start();

        /** @var \SimpleSAML\Session $this->session */
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
            ],
        );

        $t = new Template($this->config, 'thissdisco:disco.twig');
        $basetemplate = $this->moduleConfig->getOptionalValueValidate('basetemplate', ['simplesamlphp', 'thissio', 'seamlessaccess'], 'simplesamlphp');
        if ($basetemplate === 'simplesamlphp') {
            $t->data['base_template'] = 'base.twig';
        } else {
            $t->data['base_template'] = '@thissdisco/usethissio.twig';
        }
        $t->send();
    }
}