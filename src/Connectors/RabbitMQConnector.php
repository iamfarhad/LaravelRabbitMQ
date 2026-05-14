<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Connectors;

use iamfarhad\LaravelRabbitMQ\Connection\PoolManager;
use iamfarhad\LaravelRabbitMQ\Exceptions\ConnectionException;
use iamfarhad\LaravelRabbitMQ\Horizon\HorizonRabbitQueue;
use iamfarhad\LaravelRabbitMQ\Horizon\Listeners\RabbitMQFailedEvent;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\WorkerStopping;

class RabbitMQConnector implements ConnectorInterface
{
    private const HORIZON_JOB_PAYLOAD = 'Laravel\\Horizon\\JobPayload';

    private const HORIZON_JOB_FAILED = 'Laravel\\Horizon\\Events\\JobFailed';

    private const OCTANE_REQUEST_TERMINATED = 'Laravel\\Octane\\Events\\RequestTerminated';

    private static ?PoolManager $poolManager = null;

    public function __construct(private Dispatcher $dispatcher)
    {
    }

    /**
     * @throws ConnectionException
     */
    public function connect(array $config = []): Queue
    {
        $rabbitConfig = config('queue.connections.rabbitmq', $config);

        if (self::$poolManager === null) {
            self::$poolManager = new PoolManager($rabbitConfig);
        }

        $defaultQueue = $rabbitConfig['queue'] ?? 'default';
        $options = $rabbitConfig['options'] ?? [];
        $dispatchAfterCommit = (bool) ($rabbitConfig['after_commit'] ?? false);
        $connectionName = (string) ($rabbitConfig['name'] ?? 'rabbitmq');

        $rabbitQueue = $this->makeQueue(
            self::$poolManager,
            $defaultQueue,
            $options,
            $dispatchAfterCommit,
            $connectionName,
            $rabbitConfig
        );

        $this->registerCleanupListeners();
        $this->registerHorizonListeners($rabbitQueue);

        return $rabbitQueue;
    }

    private function makeQueue(
        PoolManager $poolManager,
        string $defaultQueue,
        array $options,
        bool $dispatchAfterCommit,
        string $connectionName,
        array $config
    ): RabbitQueue {
        if ($this->shouldUseHorizonQueue($config)) {
            return new HorizonRabbitQueue(
                $poolManager,
                $defaultQueue,
                $options,
                $dispatchAfterCommit,
                $connectionName
            );
        }

        return new RabbitQueue(
            $poolManager,
            $defaultQueue,
            $options,
            $dispatchAfterCommit,
            $connectionName
        );
    }

    private function shouldUseHorizonQueue(array $config): bool
    {
        $worker = strtolower((string) ($config['worker'] ?? $config['options']['queue']['worker'] ?? 'default'));

        return $worker === 'horizon'
            && class_exists(self::HORIZON_JOB_PAYLOAD)
            && class_exists(self::HORIZON_JOB_FAILED);
    }

    private function registerHorizonListeners(RabbitQueue $queue): void
    {
        if (! $queue instanceof HorizonRabbitQueue || ! class_exists(self::HORIZON_JOB_FAILED)) {
            return;
        }

        $this->dispatcher->listen(JobFailed::class, RabbitMQFailedEvent::class);
    }

    private function registerCleanupListeners(): void
    {
        $this->dispatcher->listen(WorkerStopping::class, fn () => self::resetPoolManager());

        if ($this->shouldResetPoolAfterOctaneRequest()) {
            $this->dispatcher->listen(self::OCTANE_REQUEST_TERMINATED, fn () => self::resetPoolManager());
        }
    }

    private function shouldResetPoolAfterOctaneRequest(): bool
    {
        return class_exists(self::OCTANE_REQUEST_TERMINATED)
            && (bool) config('queue.connections.rabbitmq.octane.reset_on_request', false);
    }

    public static function getPoolManager(): ?PoolManager
    {
        return self::$poolManager;
    }

    public static function resetPoolManager(): void
    {
        if (self::$poolManager) {
            self::$poolManager->closeAll();
        }

        self::$poolManager = null;
    }
}
