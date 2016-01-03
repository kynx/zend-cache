<?php
/**
 * Zend Framework (http://framework.zend.com/).
 *
 * @link      http://github.com/zendframework/zend-cache for the canonical source repository
 * @copyright Copyright (c) 2016 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */
namespace Zend\Cache\Psr;

use DateTime;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Zend\Cache\Exception;
use Zend\Cache\Storage\ClearByNamespaceInterface;
use Zend\Cache\Storage\FlushableInterface;
use Zend\Cache\Storage\StorageInterface;

/**
 * PSR-6 cache adapter
 *
 * @link https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-6-cache.md
 * @link https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-6-cache-meta.md
 */
class CacheItemPoolAdapter implements CacheItemPoolInterface
{
    /**
     * @var StorageInterface
     */
    private $storage;

    /**
     * @var CacheItemInterface[]
     */
    private $deferred = [];

    /**
     * Constructor.
     *
     * PSR-6 requires that all implementing libraries support TTL so the given storage adapter must also support static
     * TTL or an exception will be raised. Currently the following adapters do *not* support static TTL: Dba,
     * Filesystem, Memory, Session and Redis < v2.
     *
     * @param StorageInterface $storage
     *
     * @throws CacheException
     */
    public function __construct(StorageInterface $storage)
    {
        // all current adapters implement this
        if (!$storage instanceof FlushableInterface) {
            throw new CacheException(sprintf(
                'Storage %s does not implement %s',
                get_class($storage),
                FlushableInterface::class
            ));
        }

        // we've got to be able to set per-item TTL on write
        $capabilities = $storage->getCapabilities();
        if (!($capabilities->getStaticTtl() && $capabilities->getMinTtl())) {
            throw new CacheException(sprintf(
                'Storage %s does not support static TTL',
                get_class($storage)
            ));
        }

        $this->storage = $storage;
    }

    /**
     * Destructor.
     *
     * Saves any deferred items that have not been committed
     */
    public function __destruct()
    {
        $this->commit();
    }

    /**
     * {@inheritdoc}
     */
    public function getItem($key)
    {
        $items = $this->getItems([$key]);

        return array_pop($items);
    }

    /**
     * {@inheritdoc}
     */
    public function getItems(array $keys = [])
    {
        $this->validateKeys($keys);

        // check deferred items first
        $items = array_intersect_key($this->deferred, array_flip($keys));
        $keys = array_diff($keys, array_keys($items));

        if (count($keys)) {
            try {
                $cacheItems = $this->storage->getItems($keys);
            } catch (Exception\InvalidArgumentException $e) {
                throw new InvalidArgumentException($e->getMessage(), $e->getCode(), $e);
            } catch (Exception\ExceptionInterface $e) {
                $cacheItems = [];
            }

            foreach ($cacheItems as $key => $value) {
                $items[$key] = new CacheItem($key, $value, true);
            }

            // Return empty items for any keys that where not found
            foreach (array_diff($keys, array_keys($cacheItems)) as $key) {
                $items[$key] = new CacheItem($key, null, false);
            }
        }

        return $items;
    }

    /**
     * {@inheritdoc}
     */
    public function hasItem($key)
    {
        $this->validateKey($key);

        // check deferred items first
        $hasItem = isset($this->deferred[$key]);

        if (!$hasItem) {
            try {
                $hasItem = $this->storage->hasItem($key);
            } catch (Exception\InvalidArgumentException $e) {
                throw new InvalidArgumentException($e->getMessage(), $e->getCode(), $e);
            } catch (Exception\ExceptionInterface $e) {
                $hasItem = false;
            }
        }

        return $hasItem;
    }

    /**
     * {@inheritdoc}
     *
     * If the storage adapter supports namespaces and one has been set, only that namespace is cleared; otherwise
     * entire cache is flushed.
     */
    public function clear()
    {
        $this->deferred = [];

        try {
            $namespace = $this->storage->getOptions()->getNamespace();
            if ($this->storage instanceof ClearByNamespaceInterface && $namespace) {
                $cleared = $this->storage->clearByNamespace($namespace);
            } else {
                $cleared = $this->storage->flush();
            }
        } catch (Exception\ExceptionInterface $e) {
            $cleared = false;
        }

        return $cleared;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItem($key)
    {
        return $this->deleteItems([$key]);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItems(array $keys)
    {
        $this->validateKeys($keys);

        $deleted = true;

        // remove deferred items first
        $this->deferred = array_diff_key($this->deferred, array_flip($keys));

        try {
            $this->storage->removeItems($keys);
        } catch (Exception\InvalidArgumentException $e) {
            throw new InvalidArgumentException($e->getMessage(), $e->getCode(), $e);
        } catch (Exception\ExceptionInterface $e) {
            $deleted = false;
        }

        return $deleted;
    }

    /**
     * {@inheritdoc}
     */
    public function save(CacheItemInterface $item)
    {
        if (!$item instanceof CacheItem) {
            throw new InvalidArgumentException('$item must be an instance of ' . CacheItem::class);
        }

        $this->validateKey($item->getKey());

        $saved = true;
        $options = $this->storage->getOptions();
        $ttl = $options->getTtl();
        try {
            $expiration = $item->getExpiration();
            if ($expiration instanceof DateTime) {
                $interval = $expiration->diff(new DateTime(), true);
                $options->setTtl($interval->format('%s'));
            }

            $saved = $this->storage->setItem($item->getKey(), $item->get());
        } catch (Exception\InvalidArgumentException $e) {
            throw new InvalidArgumentException($e->getMessage(), $e->getCode(), $e);
        } catch (Exception\ExceptionInterface $e) {
            $saved = false;
        } finally {
            $options->setTtl($ttl);
        }

        return $saved;
    }

    /**
     * {@inheritdoc}
     */
    public function saveDeferred(CacheItemInterface $item)
    {
        if (!$item instanceof CacheItem) {
            throw new InvalidArgumentException('$item must be an instance of ' . CacheItem::class);
        }

        $key = $item->getKey();
        $this->validateKey($key);

        // deferred items should always be a 'hit'
        $item->setIsHit(true);
        $this->deferred[$key] = $item;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        $notSaved = [];

        foreach ($this->deferred as &$item) {
            if (! $this->save($item)) {
                $notSaved[] = $item;
            }
        }
        $this->deferred = $notSaved;

        return empty($this->deferred);
    }

    private function validateKey($key)
    {
        if (!is_string($key) || preg_match('#[{}()/\\\\@:]#', $key)) {
            throw new InvalidArgumentException(sprintf(
                "Key must be a string and not contain '{}()/\\@:'; '%s' given",
                is_string($key) ? $key : gettype($key))
            );
        }
    }

    private function validateKeys($keys)
    {
        foreach ($keys as $key) {
            $this->validateKey($key);
        }
    }
}
