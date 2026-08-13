<?php

/**
 * Sync npm-asset packages to the public assets directory.
 * This is a simple local replacement for the oomphinc/composer-installers-extender plugin
 * which was abandoned.
 */

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$installedJson = $projectRoot . DIRECTORY_SEPARATOR
    . 'vendor' . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR
    . 'installed.json';
$targetRoot = $projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR
    . 'assets' . DIRECTORY_SEPARATOR . 'npm-asset';
$packageRules = loadPackageRules($projectRoot);

if (!is_file($installedJson)) {
    fwrite(STDOUT, "[sync-npm-assets] Composer metadata not found, skipping: {$installedJson}" . PHP_EOL);
    exit(0);
}

if (!is_dir($targetRoot) && !mkdir($targetRoot, 0777, true) && !is_dir($targetRoot)) {
    fwrite(STDERR, "[sync-npm-assets] Failed to create target directory: {$targetRoot}" . PHP_EOL);
    exit(1);
}

$installedRaw = file_get_contents($installedJson);
if ($installedRaw === false) {
    fwrite(STDERR, "[sync-npm-assets] Failed to read Composer metadata: {$installedJson}" . PHP_EOL);
    exit(1);
}

$installed = json_decode($installedRaw, true);
if (!is_array($installed)) {
    fwrite(STDERR, "[sync-npm-assets] Invalid JSON in Composer metadata: {$installedJson}" . PHP_EOL);
    exit(1);
}

$packages = [];
if (isset($installed['packages']) && is_array($installed['packages'])) {
    $packages = $installed['packages'];
} elseif (array_is_list($installed)) {
    $packages = $installed;
}

$copied = 0;
$found = 0;

foreach ($packages as $package) {
    if (!is_array($package)) {
        continue;
    }

    $name = (string) ($package['name'] ?? '');
    $type = (string) ($package['type'] ?? '');
    $installPathRel = (string) ($package['install-path'] ?? '');

    if ($name === '' || $installPathRel === '') {
        continue;
    }

    $isNpmAsset = $type === 'npm-asset' || str_starts_with($name, 'npm-asset/');
    if (!$isNpmAsset) {
        continue;
    }

    $found++;

    $nameParts = explode('/', $name, 2);
    $packageDirName = $nameParts[1] ?? $nameParts[0];
    $sourcePath = realpath(dirname($installedJson) . DIRECTORY_SEPARATOR . $installPathRel);
    $targetPath = $targetRoot . DIRECTORY_SEPARATOR . $packageDirName;
    $rule = $packageRules[$packageDirName] ?? [];

    if ($sourcePath === false || !is_dir($sourcePath)) {
        fwrite(STDOUT, "[sync-npm-assets] Package path not found, skipping {$name}: {$installPathRel}" . PHP_EOL);
        continue;
    }

    if (!syncPackage($sourcePath, $targetPath, $rule)) {
        fwrite(STDERR, "[sync-npm-assets] Failed to copy {$sourcePath} to {$targetPath}" . PHP_EOL);
        exit(1);
    }

    $copied++;
}

if ($found === 0) {
    fwrite(
        STDOUT,
        '[sync-npm-assets] No npm-asset packages are installed; this is expected when Composer ' .
        'is run with --no-dev because npm-asset packages are only updated during development.' . PHP_EOL,
    );
    exit(0);
}

fwrite(
    STDOUT,
    "[sync-npm-assets] Synced {$copied} npm-asset package directories to {$targetRoot}"
    . PHP_EOL,
);

exit(0);

function copyTree(string $source, string $target): bool
{
    if (!is_dir($target) && !mkdir($target, 0777, true) && !is_dir($target)) {
        return false;
    }

    $entries = scandir($source);
    if ($entries === false) {
        return false;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $sourcePath = $source . DIRECTORY_SEPARATOR . $entry;
        $targetPath = $target . DIRECTORY_SEPARATOR . $entry;

        if (is_dir($sourcePath)) {
            if (!copyTree($sourcePath, $targetPath)) {
                return false;
            }
            continue;
        }

        if (!copy($sourcePath, $targetPath)) {
            return false;
        }
    }

    return true;
}

function loadPackageRules(string $projectRoot): array
{
    $composerJson = $projectRoot . DIRECTORY_SEPARATOR . 'composer.json';
    if (!is_file($composerJson)) {
        return [];
    }

    $composerRaw = file_get_contents($composerJson);
    if ($composerRaw === false) {
        return [];
    }

    $composer = json_decode($composerRaw, true);
    if (!is_array($composer)) {
        return [];
    }

    $rules = $composer['extra']['sync-npm-assets-rules'] ?? [];
    if (!is_array($rules)) {
        return [];
    }

    $normalized = [];
    foreach ($rules as $packageName => $rule) {
        if (!is_string($packageName) || $packageName === '' || !is_array($rule)) {
            continue;
        }

        $include = $rule['include'] ?? null;
        if ($include !== null && !is_array($include)) {
            continue;
        }

        $normalized[$packageName] = $rule;
    }

    return $normalized;
}

function syncPackage(string $sourcePath, string $targetPath, array $rule): bool
{
    $preserveGitignore = null;
    $targetGitignore = $targetPath . DIRECTORY_SEPARATOR . '.gitignore';
    if (is_file($targetGitignore)) {
        $preserveGitignore = file_get_contents($targetGitignore);
        if ($preserveGitignore === false) {
            return false;
        }
    }

    if (is_link($targetPath) || is_file($targetPath)) {
        if (!unlink($targetPath)) {
            return false;
        }
    }

    if (is_dir($targetPath) && !deleteTree($targetPath)) {
        return false;
    }

    if (!is_dir($targetPath) && !mkdir($targetPath, 0777, true) && !is_dir($targetPath)) {
        return false;
    }

    $includePaths = $rule['include'] ?? null;
    if (is_array($includePaths)) {
        foreach ($includePaths as $relativePath) {
            if (!is_string($relativePath) || $relativePath === '') {
                continue;
            }

            $sourceEntry = $sourcePath . DIRECTORY_SEPARATOR
                . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
            if (!file_exists($sourceEntry)) {
                continue;
            }

            $targetEntry = $targetPath . DIRECTORY_SEPARATOR
                . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
            if (!copyEntry($sourceEntry, $targetEntry)) {
                return false;
            }
        }
    } else {
        if (!copyTree($sourcePath, $targetPath)) {
            return false;
        }
    }

    if (
        $preserveGitignore !== null &&
        file_put_contents($targetGitignore, $preserveGitignore) === false
    ) {
        return false;
    }

    return true;
}

function copyEntry(string $source, string $target): bool
{
    if (is_dir($source)) {
        return copyTree($source, $target);
    }

    $targetDir = dirname($target);
    if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
        return false;
    }

    return copy($source, $target);
}

function deleteTree(string $path): bool
{
    $entries = scandir($path);
    if ($entries === false) {
        return false;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $current = $path . DIRECTORY_SEPARATOR . $entry;

        if (is_dir($current) && !is_link($current)) {
            if (!deleteTree($current)) {
                return false;
            }
            continue;
        }

        if (!unlink($current)) {
            return false;
        }
    }

    return rmdir($path);
}
