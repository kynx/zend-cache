<?php
/**
 * Zend Framework (http://framework.zend.com/).
 *
 * @link      http://github.com/zendframework/zend-cache for the canonical source repository
 * @copyright Copyright (c) 2016 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */
namespace Zend\Cache\Psr;

use DateInterval;
use Psr\Cache\CacheItemInterface;

final class CacheItem implements CacheItemInterface
{
    private $key;
    private $value;
    private $isHit = false;
    private $expiration = null;

    /**
     * Constructor.
     *
     * @param string $key
     * @param mixed $value
     * @param bool $isHit
     */
    public function __construct($key, $value, $isHit)
    {
        $this->key = $key;
        $this->value = $isHit ? $value : null;
        $this->isHit = $isHit;
    }

    /**
     * {@inheritdoc}
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * {@inheritdoc}
     */
    public function get()
    {
        return $this->value;
    }

    /**
     * {@inheritdoc}
     */
    public function isHit()
    {
        return $this->isHit;
    }

    /**
     * Sets isHit value
     *
     * This function is called by CacheItemPoolAdapter::saveDeferred() and is not intended for use by other calling
     * code.
     *
     * @param boolean $isHit
     * @return $this
     */
    public function setIsHit($isHit)
    {
        $this->isHit = $isHit;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function set($value)
    {
        $this->value = $value;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function expiresAt($expiration = null)
    {
        if (! ($expiration === null || $expiration instanceof \DateTimeInterface)) {
            throw new InvalidArgumentException('$expiration must be null or an instance of DateTimeInterface');
        }

        $this->expiration = $expiration;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function expiresAfter($time)
    {
        if ($time instanceof DateInterval) {
            $interval = $time;
        } elseif ((int) $time == $time) {
            $interval = new DateInterval('PT' . $time . 'S');
        } else {
            throw new InvalidArgumentException(sprintf('Invalid $time "%s"', gettype($time)));
        }

        $this->expiration = new \DateTime();
        $this->expiration->add($interval);

        return $this;
    }

    /**
     * Returns DateTime item has been explicitly set to expire at, or NULL if not set
     *
     * For items retrieved from cache this will always be NULL. Consult the TTL from the options specified for the
     * underlying storage adapter instead.
     *
     * @return DateTime|null
     */
    public function getExpiration()
    {
        return $this->expiration;
    }
}
