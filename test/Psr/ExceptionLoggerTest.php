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
use Prophecy\Argument;
use Psr\Log\LoggerInterface;
use Zend\Cache\Exception;
use Zend\Cache\Psr\ExceptionLogger;
use Zend\Cache\Storage\Adapter\AbstractAdapter;
use Zend\Cache\Storage\ExceptionEvent;
use Zend\Cache\Storage\Plugin\ExceptionHandler;
use Zend\EventManager\EventManager;

class ExceptionLoggerTest extends TestCase
{
    /**
     * @var AbstractAdapter
     */
    private $storage;
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function setUp()
    {
        $eventManager = new EventManager();
        $storage = $this->prophesize(AbstractAdapter::class);
        $storage->getEventManager()
            ->willReturn($eventManager);
        $storage->addPlugin(Argument::type(ExceptionHandler::class))
            ->will(function ($args) use ($eventManager) {
                $args[0]->attach($eventManager);
            });
        $this->storage = $storage->reveal();

        $logger = $this->prophesize(LoggerInterface::class);
        $logger->error(Argument::containingString('[CACHE]'))->shouldBeCalled();
        $logger->debug(Argument::type('string'))->shouldBeCalled();
        $this->logger = $logger->reveal();
    }

    public function testLogException()
    {
        $exceptionLogger = new ExceptionLogger($this->storage, $this->logger);
        $exceptionLogger->logException(new Exception\RuntimeException("Test exception"));
    }

    public function testEventManagerLogsException()
    {
        new ExceptionLogger($this->storage, $this->logger);
        $result = false;
        $event = new ExceptionEvent(
            'getItem.exception',
            $this->storage,
            new \ArrayObject(),
            $result,
            new Exception\RuntimeException("Test exception")
        );
        $this->storage->getEventManager()->triggerEvent($event);
    }
}
