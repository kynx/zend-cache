<?php

namespace ZendTest\Cache\Psr;

use Cache\IntegrationTests\CachePoolTest;
use Zend\Cache\Psr\CacheItemPoolAdapter;
use Zend\Cache\Storage\Adapter\Redis;
use Zend\Cache\Storage\Adapter\RedisOptions;

class PoolIntegrationTest extends CachePoolTest
{
    public function createCachePool()
    {
        $options = new RedisOptions(['server' => [
            'host' => '127.0.0.1',
            'port' => 6379,
        ]]);
        $client = new Redis($options);

        return new CacheItemPoolAdapter($client);
    }
}