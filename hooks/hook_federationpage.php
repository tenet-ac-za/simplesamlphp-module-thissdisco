<?php

declare(strict_types=1);

use PHPUnit\Event\TestRunner\Configured;
use SimpleSAML\Configuration;
use SimpleSAML\Locale\Translate;
use SimpleSAML\Module;
use SimpleSAML\Module\thissdisco\Controller\MDQ;
use SimpleSAML\XHTML\Template;

/**
 * Hook to add the metarefresh module to the config page.
 *
 * @param \SimpleSAML\XHTML\Template &$template The template that we should alter in this hook.
 */
function thissdisco_hook_federationpage(Template &$template): void
{
    $mdq = new MDQ(Configuration::getInstance());

    /* display the SHA1 hash for hosted entities */
    foreach ($template->data['entries']['hosted'] as $key => &$set) {
        if (
            !isset($set['metadata-index'])
            || $set['metadata-index'] = $key
        ) {
            $set['metadata-index'] = $mdq->getTransformedFromEntityId($set['entityid']);
        }
    }

    $template->data['links'][] = [
        'href' => Module::getModuleURL('thissdisco/entities', ['debug' => 1]),
        'text' => Translate::noop('ThissDisco JSON MDQ Endpoint'),
    ];

    $template->getLocalization()->addModuleDomain('thissdisco');
}
