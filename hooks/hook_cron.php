<?php

declare(strict_types=1);

use SimpleSAML\Assert\Assert;
use SimpleSAML\Configuration;
use SimpleSAML\Logger;
use SimpleSAML\Module\thissdisco\Controller\MDQ;

/**
 * Hook to run a cron job.
 *
 * @param array &$croninfo  Output
 */
function thissdisco_hook_cron(array &$croninfo): void
{
    Assert::keyExists($croninfo, 'summary');
    Assert::keyExists($croninfo, 'tag');

    $moduleConfig = Configuration::getConfig('module_thissdisco.php');
    $crontags = $moduleConfig->getOptionalArrayizeString('crontags', []);
    if (!in_array($croninfo['tag'], $crontags)) {
        return;
    }
    $cachetype = $moduleConfig->getOptionalString('cachetype', 'phpfiles');
    if ($cachetype == 'none') {
        Logger::debug('cron [thissdisco]: ignoring cache warmup for none cachetype.');
        return;
    }

    Logger::info('cron [thissdisco]: Running cache warmup in cron tag [' . $croninfo['tag'] . '] ');

    $config = Configuration::getInstance();
    $mdq = new MDQ($config);
    try {
        $result = $mdq->cacheWarmup();
        if ($result < 1) {
            throw new \Exception('no metadata found to cache');
        }
    } catch (\Exception $e) {
        $croninfo['summary'][] = 'Error during thissdisco MDQ cache warmup: ' . $e->getMessage();
    }
}
