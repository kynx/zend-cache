<?php
/**
 * Zend Framework (http://framework.zend.com/).
 *
 * @link      http://github.com/zendframework/zend-cache for the canonical source repository
 * @copyright Copyright (c) 2016 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */
namespace Zend\Cache\Psr;

use Psr\Log\LoggerInterface;
use Zend\Cache\Exception\LogicException;
use Zend\Cache\Storage\Adapter\AbstractAdapter;
use Zend\Cache\Storage\Plugin\ExceptionHandler;
use Zend\Cache\StorageFactory;

/**
 * Class for logging exceptions thrown by storage backend to a PSR-3 compatible logger
 *
 * From the PSR-6 mandates that exceptions thrown by the storage backend be suppressed and specifies that
 * implementations SHOULD provide a mechanism for logging them or otherwise notifying the administrator.
 *
 * Exceptions are logged at level 'error', with stack trace logged at level 'debug'. Note that caught exceptions are
 * re-thrown so the CacheItemPoolAdapter can generate appropriate return values.
 *
 * @link https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-6-cache.md#error-handling
 */
final class ExceptionLogger
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Constructor.
     * @param AbstractAdapter $storage
     * @param LoggerInterface $logger
     * @throws LogicException           Thrown if adapter already has ExceptionHandler plugin
     */
    public function __construct(AbstractAdapter $storage, LoggerInterface $logger)
    {
        $this->logger = $logger;

        $plugin = StorageFactory::pluginFactory(ExceptionHandler::class, [
            'exception_callback' => [&$this, 'logException'],
            'throw_exceptions' => true
        ]);
        $storage->addPlugin($plugin);
    }

    /**
     * Logs exception thrown by storage back end
     * @param \Exception $exception
     */
    public function logException(\Exception $exception)
    {
        $message = sprintf(
            '[CACHE] %s:%s %s "%s"',
            $exception->getFile(),
            $exception->getLine(),
            $exception->getCode(),
            $exception->getMessage()
        );
        $this->logger->error($message);
        $this->logger->debug($exception->getTraceAsString());
    }
}
