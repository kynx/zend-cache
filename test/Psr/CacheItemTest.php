<?php
/**
 * Zend Framework (http://framework.zend.com/).
 *
 * @link      http://github.com/zendframework/zend-cache for the canonical source repository
 * @copyright Copyright (c) 2016 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */
namespace ZendTest\Cache\Psr;

use PHPUnit_Framework_TestCase as TestCase;
use Zend\Cache\Psr\CacheItem;

class CacheItemTest extends TestCase
{
    public function testConstructorIsHit()
    {
        $item = new CacheItem('key', 'value', true);
        $this->assertEquals('key', $item->getKey());
        $this->assertEquals('value', $item->get());
        $this->assertTrue($item->isHit());
    }

    public function testConstructorIsNotHit()
    {
        $item = new CacheItem('key', 'value', false);
        $this->assertEquals('key', $item->getKey());
        $this->assertNull($item->get());
        $this->assertFalse($item->isHit());
    }

    public function testSet()
    {
        $item = new CacheItem('key', 'value', true);
        $return = $item->set('value2');
        $this->assertEquals($item, $return);
        $this->assertEquals('value2', $item->get());
    }

    public function testExpireAtDateTime()
    {
        $item = new CacheItem('key', 'value', true);
        $dateTime = new \DateTime();
        $return = $item->expiresAt($dateTime);
        $this->assertEquals($item, $return);
        $this->assertEquals($dateTime, $item->getExpiration());
    }

    public function testExpireAtNull()
    {
        $item = new CacheItem('key', 'value', true);
        $return = $item->expiresAt(null);
        $this->assertEquals($item, $return);

        $this->assertNull($item->getExpiration());
    }

    /**
     * @expectedException \Zend\Cache\Psr\InvalidArgumentException
     */
    public function testExpireAtInvalidThrowsException()
    {
        $item = new CacheItem('key', 'value', true);
        $item->expiresAt('foo');
    }

    public function testExpiresAfterInt()
    {
        $item = new CacheItem('key', 'value', true);
        $return = $item->expiresAfter(3600);
        $this->assertEquals($item, $return);

        $expiration = $item->getExpiration();
        $this->assertNotNull($expiration);
        /* @var \DateTime $expiration */
        $interval = $expiration->diff(new \DateTime(), true);
        $this->assertEquals(1, $interval->h);
    }

    public function testExpiresAfterInterval()
    {
        $item = new CacheItem('key', 'value', true);
        $interval = new \DateInterval('PT1H');
        $return = $item->expiresAfter($interval);
        $this->assertEquals($item, $return);

        $expiration = $item->getExpiration();
        $this->assertNotNull($expiration);
        /* @var \DateTime $expiration */
        $interval = $expiration->diff(new \DateTime(), true);
        $this->assertEquals(1, $interval->h);
    }

    /**
     * @expectedException \Zend\Cache\Psr\InvalidArgumentException
     */
    public function testExpiresAfterInvalidThrowsException()
    {
        $item = new CacheItem('key', 'value', true);
        $item->expiresAfter([]);
    }
}
