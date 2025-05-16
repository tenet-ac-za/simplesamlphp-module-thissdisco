<?php

declare(strict_types=1);

namespace SimpleSAML\Module\thissdisco;

use SimpleSAML\Assert;
use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\Logger;
use Symfony\Component\Cache\Adapter\{ArrayAdapter,FilesystemAdapter,MemcachedAdapter};
use Symfony\Component\Cache\Adapter\{NullAdapter,PdoAdapter,PhpFilesAdapter,RedisAdapter};

/**
 * Caching for MDQ
 *
 * This provides a simpler interface than PSR-6, but a more complete/complex one than Symfony's
 * Cache Contracts -- because we need to be able to get() without set()ing, and has() is useful.
 */
class MDQCache
{
    /** @var \Symfony\Component\Cache\Adapter\AdapterInterface */
    private $cache;

    public function __construct(
        protected Configuration $config,
        protected Configuration $moduleConfig,
    ) {
        $cachetype = $this->moduleConfig->getOptionalString('cachetype', 'phpfiles');
        $namespace = 'thissdisco+mdq';
        $cachelength = $this->moduleConfig->getOptionalInteger('cachelength', 0);
        $cachedir = $this->moduleConfig->getOptionalValue(
            'cachedir',
            $this->config->getOptionalString('cachedir', null),
        );

        switch ($cachetype) {
            case 'array':
                if ($cachedir !== 'phpunit') {
                    Logger::warning('cachetype array is really only for testing');
                }
                $max = $cachelength > 0 ? $cachelength : 3600;
                $this->cache = new ArrayAdapter($max, false, $max);
                break;

            case 'memcache':
                Assert\Assert::isAnyOf(
                    $cachedir,
                    ['string', 'array'],
                    'cachedir should be a memcache DSN or array of DSNs',
                    Error\ConfigurationError::class,
                );
                $dsntest = $cachedir[0] ?? $cachedir;
                Assert\Assert::startsWith(
                    $dsntest,
                    'memcached://',
                    'Memcached DSNs start memcached://',
                    Error\ConfigurationError::class,
                );
                $prefix = $this->config->getOptionalString('memcache_store.prefix', 'simpleSAMLphp');
                $client = MemcachedAdapter::createConnection($cachedir);
                $this->cache = new MemcachedAdapter($client, $prefix . $namespace, $cachelength);
                break;

            case 'pdo':
                Assert\Assert::string($cachedir, 'cachedir must be a PDO DSN', Error\ConfigurationError::class);
                $this->cache = new PdoAdapter($cachedir, $namespace, $cachelength);
                break;

            case 'phpfiles':
                Assert\Assert::string($cachedir, 'cachedir must be a directory', Error\ConfigurationError::class);
                Assert\Assert::directory(
                    $cachedir,
                    'cachedir directory does not exist',
                    Error\ConfigurationError::class,
                );
                $opcache = false;
                if (function_exists('opcache_get_status')) {
                    $opcache = (opcache_get_status())['opcache_enabled'] ?? false;
                }
                if ($opcache) {
                    $this->cache = new PhpFilesAdapter($namespace, $cachelength, $cachedir);
                    break;
                }
                Logger::warning('opcache.enable must be set for phpfiles, failing to filesystem');
                // fall through to filesystem

            case 'filesystem':
                Assert\Assert::string($cachedir, 'cachedir must be a directory', Error\ConfigurationError::class);
                Assert\Assert::directory(
                    $cachedir,
                    'cachedir directory does not exist',
                    Error\ConfigurationError::class,
                );
                $this->cache = new FilesystemAdapter($namespace, $cachelength, $cachedir);
                break;

            case 'redis':
                Assert\Assert::string($cachedir, 'cachedir must be a redis DSN', Error\ConfigurationError::class);
                Assert\Assert::startsWith(
                    $cachedir,
                    'redis',
                    'Redis DSNs start redis:// or rediss://',
                    Error\ConfigurationError::class,
                );
                $prefix = $this->config->getOptionalString('store.redis.prefix', 'simpleSAMLphp');
                $client = RedisAdapter::createConnection($cachedir);
                $this->cache = new RedisAdapter($client, $prefix . $namespace, $cachelength);
                break;

            case 'none':
                $this->cache = new NullAdapter();
                break;

            default:
                throw new Error\ConfigurationError(
                    'cachetype must be one of {none,array,filesystem,phpfiles,redis,memcache,pdo}',
                    'module_thissdisco.php',
                    ['cachedir' => $cachedir],
                );
        }
    }

    /**
     * getter
     *
     * Like the Contracts get() with an optional default value.
     *
     * @param string $key The key to retrieve
     * @param mixed $default Default value, defaulting to null for compatibility with Contracts
     * @return mixed The cached result (or $default)
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $cached = $this->cache->getItem($key);
        if ($cached->isHit()) {
            return $cached->get();
        }
        return $default;
    }

    /**
     * setter
     *
     * @param string $key The key to set
     * @param mixed $value The value to cache
     * @param ?int $expiresAfter Second to expire after, null for default
     * @return bool
     */
    public function set(string $key, mixed $value, ?int $expiresAfter = null): bool
    {
        $cached = $this->cache->getItem($key);
        if ($expiresAfter !== null) {
            $cached->expiresAfter($expiresAfter);
        }
        $cached->set($value);
        return $this->cache->save($cached);
    }

    /**
     * key exists in cache
     *
     * @param string $key The key to check
     * @return bool
     */
    public function has(string $key): bool
    {
        $cached = $this->cache->getItem($key);
        return $cached->isHit();
    }

    /**
     * remove a key from cache
     *
     * @param string $key The key to delete
     * @return bool
     */
    public function delete(string $key): bool
    {
        return $this->cache->deleteItem($key);
    }

    /**
     * clear the cache (mainly for unit testing)
     *
     * @return bool
     */
    public function clear(): bool
    {
        return $this->cache->clear();
    }
}
