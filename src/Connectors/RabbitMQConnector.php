<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Connectors;

use iamfarhad\LaravelRabbitMQ\Connection\PoolManager;
use iamfarhad\LaravelRabbitMQ\Exceptions\ConnectionException;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Events\WorkerStopping;

class RabbitMQConnector implements ConnectorInterface
{
    private static ?PoolManager $poolManager = null;

    public function __construct(private Dispatcher $dispatcher) {}

    /**
     * @throws ConnectionException
     */
    public function connect(array $config = []): Queue
    {
        // Get the full RabbitMQ configuration
        $rabbitConfig = config('queue.connections.rabbitmq', []);

        // Initialize pool manager if not already done (singleton pattern)
        if (self::$poolManager === null) {
            self::$poolManager = new PoolManager($rabbitConfig);
        }

        $defaultQueue = $rabbitConfig['queue'] ?? 'default';
        $options = $rabbitConfig['options'] ?? [];

        // Create RabbitQueue with pool manager
        $rabbitQueue = new RabbitQueue(
            self::$poolManager,
            $defaultQueue,
            $options,
            false, // dispatchAfterCommit
            'rabbitmq' // connectionName
        );

        // Register cleanup listener
        $this->dispatcher->listen(WorkerStopping::class, function () {
            if (self::$poolManager) {
                self::$poolManager->closeAll();
                self::$poolManager = null;
            }
        });

        return $rabbitQueue;
    }

    /**
     * Get the pool manager instance (for testing or advanced use)
     */
    public static function getPoolManager(): ?PoolManager
    {
        return self::$poolManager;
    }

    /**
     * Reset the pool manager (for testing)
     */
    public static function resetPoolManager(): void
    {
        if (self::$poolManager) {
            self::$poolManager->closeAll();
        }
        self::$poolManager = null;
    }
}
