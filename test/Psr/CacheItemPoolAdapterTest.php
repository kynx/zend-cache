<?php
/**
 * Zend Framework (http://framework.zend.com/).
 *
 * @link      http://github.com/zendframework/zend-cache for the canonical source repository
 * @copyright Copyright (c) 2016 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */
namespace ZendTest\Cache;

use PHPUnit_Framework_TestCase as TestCase;
use Prophecy\Argument;
use Psr\Cache\CacheItemInterface;
use Zend\Cache\Exception;
use Zend\Cache\Psr\CacheItemPoolAdapter;
use Zend\Cache\Psr\CacheItem;
use Zend\Cache\Storage\Adapter\AdapterOptions;
use Zend\Cache\Storage\ClearByNamespaceInterface;
use Zend\Cache\Storage\FlushableInterface;
use Zend\Cache\Storage\Capabilities;
use Zend\Cache\Storage\StorageInterface;

class CacheItemPoolAdapterTest extends TestCase
{
    /**
     * @expectedException \Zend\Cache\Psr\CacheException
     */
    public function testStorageNotFlushableThrowsException()
    {
        $storage = $this->prophesize(StorageInterface::class);
        $this->getAdapter($storage);
    }

    /**
     * @expectedException \Zend\Cache\Psr\CacheException
     */
    public function testStorageFalseStaticTtlThrowsException()
    {
        $storage = $this->getStorageProphesy(['staticTtl' => false]);
        $this->getAdapter($storage);
    }

    /**
     * @expectedException \Zend\Cache\Psr\CacheException
     */
    public function testStorageZeroMinTtlThrowsException()
    {
        $storage = $this->getStorageProphesy(['staticTtl' => true, 'minTtl' => 0]);
        $this->getAdapter($storage);
    }

    public function testGetNonexistentItem()
    {
        $item = $this->getAdapter()->getItem('foo');
        $this->assertInstanceOf(CacheItem::class, $item);
        $this->assertEquals('foo', $item->getKey());
        $this->assertNull($item->get());
        $this->assertFalse($item->isHit());
    }

    public function testGetDeferredItem()
    {
        $adapter = $this->getAdapter();
        $item = $adapter->getItem('foo');
        $item->set('bar');
        $adapter->saveDeferred($item);
        $item = $adapter->getItem('foo');
        $this->assertTrue($item->isHit());
        $this->assertEquals('bar', $item->get());
    }

    /**
     * @dataProvider invalidKeyProvider
     * @expectedException \Zend\Cache\Psr\InvalidArgumentException
     */
    public function testGetItemInvalidKeyThrowsException($key)
    {
        $this->getAdapter()->getItem($key);
    }

    public function testGetItemRuntimeExceptionIsMiss()
    {
        $storage = $this->getStorageProphesy();
        $storage->getItems(Argument::type('array'))
            ->willThrow(Exception\RuntimeException::class);
        $adapter = $this->getAdapter($storage);
        $item = $adapter->getItem('foo');
        $this->assertFalse($item->isHit());
    }

    /**
     * @expectedException \Zend\Cache\Psr\InvalidArgumentException
     */
    public function testGetItemInvalidArgumentExceptionRethrown()
    {
        $storage = $this->getStorageProphesy();
        $storage->getItems(Argument::type('array'))
            ->willThrow(Exception\InvalidArgumentException::class);
        $this->getAdapter($storage)->getItem('foo');
    }

    public function testGetNonexistentItems()
    {
        $keys = ['foo', 'bar'];
        $adapter = $this->getAdapter();
        $items = $adapter->getItems($keys);
        $this->assertEquals($keys, array_keys($items));
        foreach ($keys as $key) {
            $this->assertEquals($key, $items[$key]->getKey());
        }
        foreach ($items as $item) {
            $this->assertNull($item->get());
            $this->assertFalse($item->isHit());
        }
    }

    public function testGetMixedItems()
    {
        $keys = ['foo', 'bar'];
        $storage = $this->getStorageProphesy();
        $storage->getItems($keys)
            ->willReturn(['bar' => 'value']);
        $items = $this->getAdapter($storage)->getItems($keys);
        $this->assertEquals(2, count($items));
        $this->assertNull($items['foo']->get());
        $this->assertFalse($items['foo']->isHit());
        $this->assertEquals('value', $items['bar']->get());
        $this->assertTrue($items['bar']->isHit());
    }

    /**
     * @expectedException \Zend\Cache\Psr\InvalidArgumentException
     */
    public function testGetItemsInvalidKeyThrowsException()
    {
        $keys = ['ok'] + $this->getInvalidKeys();
        $this->getAdapter()->getItems($keys);
    }

    public function testGetItemsRuntimeExceptionIsMiss()
    {
        $keys = ['foo', 'bar'];
        $storage = $this->getStorageProphesy();
        $storage->getItems(Argument::type('array'))
            ->willThrow(Exception\RuntimeException::class);
        $items = $this->getAdapter($storage)->getItems($keys);
        $this->assertEquals(2, count($items));
        foreach ($keys as $key) {
            $this->assertFalse($items[$key]->isHit());
        }
    }

    /**
     * @expectedException \Zend\Cache\Psr\InvalidArgumentException
     */
    public function testGetItemsInvalidArgumentExceptionRethrown()
    {
        $storage = $this->getStorageProphesy();
        $storage->getItems(Argument::type('array'))
            ->willThrow(Exception\InvalidArgumentException::class);
        $this->getAdapter($storage)->getItems(['foo', 'bar']);
    }

    public function testSaveItem()
    {
        $adapter = $this->getAdapter();
        $item = $adapter->getItem('foo');
        $item->set('bar');
        $this->assertTrue($adapter->save($item));
        $saved = $adapter->getItem('foo');
        $this->assertEquals('bar', $saved->get());
        $this->assertTrue($saved->isHit());
    }

    public function testSaveItemWithExpiration()
    {
        $storage = $this->getStorageProphesy()->reveal();
        $adapter = new CacheItemPoolAdapter($storage);
        $item = $adapter->getItem('foo');
        $item->set('bar');
        $item->expiresAfter(3600);
        $this->assertTrue($adapter->save($item));
        $saved = $adapter->getItem('foo');
        $this->assertEquals('bar', $saved->get());
        $this->assertTrue($saved->isHit());
        // ensure original TTL not modified
        $options = $storage->getOptions();
        $this->assertEquals(0, $options->getTtl());
    }

    /**
     * @expectedException \Zend\Cache\Psr\InvalidArgumentException
     */
    public function testSaveForeignItemThrowsException()
    {
        $item = $this->prophesize(CacheItemInterface::class);
        $this->getAdapter()->save($item->reveal());
    }

    /**
     * @dataProvider invalidKeyProvider
     * @expectedException \Zend\Cache\Psr\InvalidArgumentException
     */
    public function testSaveItemInvalidKeyThrowsException($key)
    {
        $item = new CacheItem($key, 'value', true);
        $this->getAdapter()->save($item);
    }

    public function testSaveItemRuntimeExceptionReturnsFalse()
    {
        $storage = $this->getStorageProphesy();
        $storage->setItem(Argument::type('string'), Argument::any())
            ->willThrow(Exception\RuntimeException::class);
        $adapter = $this->getAdapter($storage);
        $item = $adapter->getItem('foo');
        $this->assertFalse($adapter->save($item));
    }

    /**
     * @expectedException \Zend\Cache\Psr\InvalidArgumentException
     */
    public function testSaveItemInvalidArgumentExceptionRethrown()
    {
        $storage = $this->getStorageProphesy();
        $storage->setItem(Argument::type('string'), Argument::any())
            ->willThrow(Exception\InvalidArgumentException::class);
        $adapter = $this->getAdapter($storage);
        $item = $adapter->getItem('foo');
        $adapter->save($item);
    }

    public function testHasItemReturnsTrue()
    {
        $adapter = $this->getAdapter();
        $item = $adapter->getItem('foo');
        $item->set('bar');
        $adapter->save($item);
        $this->assertTrue($adapter->hasItem('foo'));
    }

    public function testHasNonexistentItemReturnsFalse()
    {
        $this->assertFalse($this->getAdapter()->hasItem('foo'));
    }

    public function testHasDeferredItemReturnsTrue()
    {
        $adapter = $this->getAdapter();
        $item = $adapter->getItem('foo');
        $adapter->saveDeferred($item);
        $this->assertTrue($adapter->hasItem('foo'));
    }

    /**
     * @dataProvider invalidKeyProvider
     * @expectedException \Zend\Cache\Psr\InvalidArgumentException
     */
    public function testHasItemInvalidKeyThrowsException($key)
    {
        $this->getAdapter()->hasItem($key);
    }

    public function testHasItemRuntimeExceptionReturnsFalse()
    {
        $storage = $this->getStorageProphesy();
        $storage->hasItem(Argument::type('string'))
            ->willThrow(Exception\RuntimeException::class);
        $this->assertFalse($this->getAdapter($storage)->hasItem('foo'));
    }

    /**
     * @expectedException \Zend\Cache\Psr\InvalidArgumentException
     */
    public function testHasItemInvalidArgumentExceptionRethrown()
    {
        $storage = $this->getStorageProphesy();
        $storage->hasItem(Argument::type('string'))
            ->willThrow(Exception\InvalidArgumentException::class);
        $this->getAdapter($storage)->hasItem('foo');
    }

    public function testClearReturnsTrue()
    {
        $adapter = $this->getAdapter();
        $item = $adapter->getItem('foo');
        $item->set('bar');
        $adapter->save($item);
        $this->assertTrue($adapter->clear());
    }

    public function testClearEmptyReturnsTrue()
    {
        $this->assertTrue($this->getAdapter()->clear());
    }

    public function testClearDeferred()
    {
        $adapter = $this->getAdapter();
        $item = $adapter->getItem('foo');
        $adapter->saveDeferred($item);
        $adapter->clear();
        $this->assertFalse($adapter->hasItem('foo'));
    }

    public function testClearRuntimeExceptionReturnsFalse()
    {
        $storage = $this->getStorageProphesy();
        $storage->flush()
            ->willThrow(Exception\RuntimeException::class);
        $this->assertFalse($this->getAdapter($storage)->clear());
    }

    public function testClearByNamespaceReturnsTrue()
    {
        $storage = $this->getStorageProphesy(false, ['namespace' => 'zfcache']);
        $storage->clearByNamespace(Argument::any())->willReturn(true)->shouldBeCalled();
        $this->assertTrue($this->getAdapter($storage)->clear());
    }

    public function testClearByEmptyNamespaceCallsFlush()
    {
        $storage = $this->getStorageProphesy(false, ['namespace' => '']);
        $storage->flush()->willReturn(true)->shouldBeCalled();
        $this->assertTrue($this->getAdapter($storage)->clear());
    }

    public function testClearByNamespaceRuntimeExceptionReturnsFalse()
    {
        $storage = $this->getStorageProphesy(false, ['namespace' => 'zfcache']);
        $storage->clearByNamespace(Argument::any())
            ->willThrow(Exception\RuntimeException::class)
            ->shouldBeCalled();
        $this->assertFalse($this->getAdapter($storage)->clear());
    }

    public function testDeleteItemReturnsTrue()
    {
        $this->assertTrue($this->getAdapter()->deleteItem('foo'));
    }

    public function testDeleteDeferredItem()
    {
        $adapter = $this->getAdapter();
        $item = $adapter->getItem('foo');
        $adapter->saveDeferred($item);
        $adapter->deleteItem('foo');
        $this->assertFalse($adapter->hasItem('foo'));
    }

    /**
     * @dataProvider invalidKeyProvider
     * @expectedException \Zend\Cache\Psr\InvalidArgumentException
     */
    public function testDeleteItemInvalidKeyThrowsException($key)
    {
        $this->getAdapter()->deleteItem($key);
    }

    public function testDeleteItemRuntimeExceptionReturnsFalse()
    {
        $storage = $this->getStorageProphesy();
        $storage->removeItems(Argument::type('array'))
            ->willThrow(Exception\RuntimeException::class);
        $this->assertFalse($this->getAdapter($storage)->deleteItem('foo'));
    }

    /**
     * @expectedException \Zend\Cache\Psr\InvalidArgumentException
     */
    public function testDeleteItemInvalidArgumentExceptionRethrown()
    {
        $storage = $this->getStorageProphesy();
        $storage->removeItems(Argument::type('array'))
            ->willThrow(Exception\InvalidArgumentException::class);
        $this->getAdapter($storage)->deleteItem('foo');
    }

    public function testDeleteItemsReturnsTrue()
    {
        $this->assertTrue($this->getAdapter()->deleteItems(['foo', 'bar', 'baz']));
    }

    public function testDeleteDeferredItems()
    {
        $keys = ['foo', 'bar', 'baz'];
        $adapter = $this->getAdapter();
        foreach ($keys as $key) {
            $item = $adapter->getItem($key);
            $adapter->saveDeferred($item);
        }
        $keys = ['foo', 'bar'];
        $adapter->deleteItems($keys);
        foreach ($keys as $key) {
            $this->assertFalse($adapter->hasItem($key));
        }
        $this->assertTrue($adapter->hasItem('baz'));
    }

    /**
     * @expectedException \Zend\Cache\Psr\InvalidArgumentException
     */
    public function testDeleteItemsInvalidKeyThrowsException()
    {
        $keys = ['ok'] + $this->getInvalidKeys();
        $this->getAdapter()->deleteItems($keys);
    }

    public function testDeleteItemsRuntimeExceptionReturnsFalse()
    {
        $storage = $this->getStorageProphesy();
        $storage->removeItems(Argument::type('array'))
            ->willThrow(Exception\RuntimeException::class);
        $this->assertFalse($this->getAdapter($storage)->deleteItems(['foo', 'bar', 'baz']));
    }

    /**
     * @expectedException \Zend\Cache\Psr\InvalidArgumentException
     */
    public function testDeleteItemsInvalidArgumentExceptionRethrown()
    {
        $storage = $this->getStorageProphesy();
        $storage->removeItems(Argument::type('array'))
            ->willThrow(Exception\InvalidArgumentException::class);
        $this->getAdapter($storage)->deleteItems(['foo', 'bar', 'baz']);
    }

    public function testSaveDeferredReturnsTrue()
    {
        $adapter = $this->getAdapter();
        $item = $adapter->getItem('foo');
        $this->assertTrue($adapter->saveDeferred($item));
    }

    /**
     * @expectedException \Zend\Cache\Psr\InvalidArgumentException
     */
    public function testSaveDeferredForeignItemThrowsException()
    {
        $item = $this->prophesize(CacheItemInterface::class);
        $this->getAdapter()->saveDeferred($item->reveal());
    }

    public function testCommitReturnsTrue()
    {
        $adapter = $this->getAdapter();
        $item = $adapter->getItem('foo');
        $adapter->saveDeferred($item);
        $this->assertTrue($adapter->commit());
    }

    public function testCommitEmptyReturnsTrue()
    {
        $this->assertTrue($this->getAdapter()->commit());
    }

    public function testCommitRuntimeExceptionReturnsFalse()
    {
        $storage = $this->getStorageProphesy();
        $storage->setItem(Argument::type('string'), Argument::any())
            ->willThrow(Exception\RuntimeException::class);
        $adapter = $this->getAdapter($storage);
        $item = $adapter->getItem('foo');
        $adapter->saveDeferred($item);
        $this->assertFalse($adapter->commit());
    }

    public function invalidKeyProvider()
    {
        return array_map(function($v) { return [$v]; }, $this->getInvalidKeys());
    }

    private function getInvalidKeys()
    {
        return [
            'key{',
            'key}',
            'key(',
            'key)',
            'key/',
            'key\\',
            'key@',
            'key:',
            new \stdClass()
        ];
    }

    /**
     * @param Prophesy $storage
     * @return CacheItemPoolAdapter
     */
    private function getAdapter($storage = null)
    {
        if (!$storage) {
            $storage = $this->getStorageProphesy();
        }
        return new CacheItemPoolAdapter($storage->reveal());
    }

    private function getStorageProphesy($capabilities = false, $options = false)
    {
        if ($capabilities === false) {
            $capabilities = [
                'staticTtl' => true,
                'minTtl' => 1
            ];
        }
        if ($options === false) {
            $options = [];
        }

        $storage = $this->prophesize(StorageInterface::class);
        $storage->willImplement(FlushableInterface::class);
        if (array_key_exists('namespace', $options)) {
            $storage->willImplement(ClearByNamespaceInterface::class);
        }

        $storage->getCapabilities()
            ->will(function() use ($capabilities) {
                return new Capabilities(
                    $this->reveal(),
                    new \stdClass(),
                    $capabilities
                );
            });
        $storage->getOptions()
            ->will(function() use ($options) {
                $adapterOptions = new AdapterOptions($options);
                $this->getOptions()->willReturn($adapterOptions);
                return $adapterOptions;
            });

        $storage->hasItem(Argument::type('string'))
            ->willReturn(false);
        $storage->getItems(Argument::type('array'))
            ->willReturn([]);
        $storage->setItem(Argument::type('string'), Argument::any())
            ->will(function ($args) {
                static $items = [];
                $items[$args[0]] = $args[1];
                $this->getItems(Argument::type('array'))
                    ->will(function ($args) use ($items) {
                        return array_intersect_key($items, array_flip($args[0]));
                    });
                $this->hasItem($args[0])
                    ->willReturn(true);
                return true;
            });
        $storage->flush()->willReturn(true);
        $storage->removeItems(Argument::type('array'))
            ->will(function ($args) {
                return $args;
            });

        return $storage;
    }
}
