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

    /**
     * @var array<string, PoolManager>
     */
    private static array $poolManagers = [];

    public function __construct(private Dispatcher $dispatcher) {}

    /**
     * @throws ConnectionException
     */
    public function connect(array $config = []): Queue
    {
        $poolKey = self::poolCacheKey($config);

        if (! isset(self::$poolManagers[$poolKey])) {
            self::$poolManagers[$poolKey] = new PoolManager($config);
        }

        $defaultQueue = $config['queue'] ?? 'default';
        $options = $config['options'] ?? [];
        $dispatchAfterCommit = (bool) ($config['after_commit'] ?? false);
        $connectionName = (string) ($config['name'] ?? 'rabbitmq');

        $rabbitQueue = $this->makeQueue(
            self::$poolManagers[$poolKey],
            $defaultQueue,
            $options,
            $dispatchAfterCommit,
            $connectionName,
            $config
        );

        $this->registerCleanupListeners();
        $this->registerHorizonListeners($rabbitQueue);

        return $rabbitQueue;
    }

    /**
     * Build a stable cache key from the connection-affecting portion of the config,
     * so distinct named connections (different hosts/pool settings) never share a
     * pool, while identical configs can still reuse one.
     */
    private static function poolCacheKey(array $config): string
    {
        return md5(serialize([
            $config['hosts'] ?? [],
            $config['pool'] ?? [],
            $config['transport'] ?? null,
            $config['protocol'] ?? null,
        ]));
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

    public static function getPoolManager(?string $connectionName = null): ?PoolManager
    {
        if (self::$poolManagers === []) {
            return null;
        }

        if ($connectionName === null && count(self::$poolManagers) === 1) {
            return reset(self::$poolManagers);
        }

        $connectionName ??= (string) config('queue.default', 'rabbitmq');
        $config = (array) config("queue.connections.{$connectionName}", []);

        return self::$poolManagers[self::poolCacheKey($config)]
            ?? (count(self::$poolManagers) === 1 ? reset(self::$poolManagers) : null);
    }

    public static function resetPoolManager(): void
    {
        foreach (self::$poolManagers as $poolManager) {
            $poolManager->closeAll();
        }

        self::$poolManagers = [];
    }
}
