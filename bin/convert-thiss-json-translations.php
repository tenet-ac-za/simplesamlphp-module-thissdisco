#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Simple, probably someone hacky script to convert the thiss-js JSON translations
 * into PO files for use with gettext and SimpleSAMLphp. This is really intended
 * as a one-off script to convert the existing translations, and not something
 * that will be run regularly. It's kept here to allow future maintainers to understand
 * the process and potentially adapt it for future needs.
 */

$dest = $_SERVER['argv'][1] ?? dirname(__DIR__) . '/locales';
$src = $_SERVER['argv'][2] ?? '.';

if (is_file($src . '/en.json')) {
    $baseTranslations = json_decode(file_get_contents($src . '/en.json'), true);
} else {
    exit("English base translations file (en.json) not found in " . $src . "\n");
}
unset($baseTranslations['@metadata']);
asort($baseTranslations, SORT_NATURAL | SORT_FLAG_CASE);

foreach (glob($src . '/*.json') as $filename) {
    $language = basename($filename, ".json");
    $podir = $dest . '/' . $language . '/LC_MESSAGES';
    if (!is_dir($podir)) {
        mkdir($podir, 0777, true);
    }
    if (file_exists($podir . '/thissdisco.po')) {
        echo ">> Language $language already exists, skipping\n";
        continue;
    }
    echo ">> Converting $language\n";
    $translations = json_decode(file_get_contents($filename), true);

    $newPo = [
        implode("\n", [
            'msgid ""',
            'msgstr ""',
            '"Content-Transfer-Encoding: 8bit\n"',
            '"Content-Type: text/plain; charset=UTF-8\n"',
            '"Language: ' . $language . '\n"',
            '"MIME-Version: 1.0\n"',
            '"Project-Id-Version: SimpleSAMLphp\n"',
            '"X-Domain: thissdisco\n"',
        ]),
    ];

    foreach ($baseTranslations as $key => $value) {
        if ($key === '@metadata') {
            continue;
        }

        $newPo[] = sprintf(
            "#: %s\nmsgid \"%s\"\nmsgstr \"%s\"",
            $key,
            $baseTranslations[$key],
            $translations[$key] ?? '',
        );
    }

    file_put_contents(
        $podir . '/thissdisco.po',
        implode("\n\n", $newPo),
    );
}
