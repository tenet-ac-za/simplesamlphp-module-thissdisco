<?php

declare(strict_types=1);

use SimpleSAML\Assert\Assert;
use SimpleSAML\Configuration;
use SimpleSAML\Error;
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

    try {
        $cronconfig = Configuration::getConfig('module_cron.php');
    } catch(Error\ConfigurationError $e) {
        return;
    }

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
        if ($croninfo['tag'] === 'phpunit' || $cronconfig->getOptionalBoolean('debug_message', true)) {
            $croninfo['summary'][] = sprintf(
                '[thissdisco]: warmed up the MDQ cache with %d transformed identifiers',
                $result,
            );
        }
    } catch (\Exception $e) {
        $croninfo['summary'][] = 'Error during thissdisco MDQ cache warmup: ' . $e->getMessage();
    }
}
