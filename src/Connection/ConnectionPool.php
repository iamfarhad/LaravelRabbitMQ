<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Connection;

use AMQPConnection;
use iamfarhad\LaravelRabbitMQ\Exceptions\ConnectionException;
use SplQueue;

class ConnectionPool
{
    private ConnectionFactory $factory;

    private SplQueue $availableConnections;

    private array $activeConnections = [];

    private int $maxConnections;

    private int $minConnections;

    private int $currentConnections = 0;

    private bool $healthCheckEnabled;

    private int $healthCheckInterval;

    private int $lastHealthCheck = 0;

    private bool $lazy;

    public function __construct(ConnectionFactory $factory, array $config)
    {
        $this->factory = $factory;
        $this->availableConnections = new SplQueue;

        $poolConfig = $config['pool'] ?? [];
        $hostConfig = $this->firstHostConfig($config['hosts'] ?? []);
        $this->maxConnections = $poolConfig['max_connections'] ?? 10;
        $this->minConnections = $poolConfig['min_connections'] ?? 2;
        $this->lazy = (bool) ($poolConfig['lazy'] ?? $hostConfig['lazy'] ?? false);
        $this->healthCheckEnabled = $poolConfig['health_check_enabled'] ?? true;
        $this->healthCheckInterval = $poolConfig['health_check_interval'] ?? 30;

        if (! $this->lazy) {
            $this->initializePool();
        }
    }

    public function getConnection(): AMQPConnection
    {
        $this->performHealthCheckIfNeeded();

        // Reuse the first healthy pooled connection, dropping dead ones along
        // the way (a broker restart can kill every pooled connection at once).
        while (! $this->availableConnections->isEmpty()) {
            $connection = $this->availableConnections->dequeue();

            if ($this->factory->isConnectionAlive($connection)) {
                $connectionId = spl_object_id($connection);
                $this->activeConnections[$connectionId] = $connection;

                return $connection;
            }

            $this->factory->closeConnection($connection);
            $this->currentConnections--;
        }

        if ($this->currentConnections < $this->maxConnections) {
            $connection = $this->createNewConnection();
            $connectionId = spl_object_id($connection);
            $this->activeConnections[$connectionId] = $connection;

            return $connection;
        }

        throw new ConnectionException(sprintf('Connection pool exhausted. Maximum connections (%d) reached.', $this->maxConnections));
    }

    public function releaseConnection(AMQPConnection $connection): void
    {
        $connectionId = spl_object_id($connection);

        if (! isset($this->activeConnections[$connectionId])) {
            return;
        }

        unset($this->activeConnections[$connectionId]);

        if ($this->factory->isConnectionAlive($connection)) {
            $this->availableConnections->enqueue($connection);
        } else {
            $this->currentConnections--;
        }
    }

    public function closeConnection(AMQPConnection $connection): void
    {
        $connectionId = spl_object_id($connection);

        if (isset($this->activeConnections[$connectionId])) {
            unset($this->activeConnections[$connectionId]);
        }

        $tempQueue = new SplQueue;
        while (! $this->availableConnections->isEmpty()) {
            $conn = $this->availableConnections->dequeue();
            if (spl_object_id($conn) !== $connectionId) {
                $tempQueue->enqueue($conn);
            }
        }
        $this->availableConnections = $tempQueue;

        $this->factory->closeConnection($connection);
        $this->currentConnections--;
    }

    public function closeAll(): void
    {
        foreach ($this->activeConnections as $connection) {
            $this->factory->closeConnection($connection);
        }
        $this->activeConnections = [];

        while (! $this->availableConnections->isEmpty()) {
            $connection = $this->availableConnections->dequeue();
            $this->factory->closeConnection($connection);
        }

        $this->currentConnections = 0;
    }

    public function getStats(): array
    {
        return [
            'max_connections' => $this->maxConnections,
            'min_connections' => $this->minConnections,
            'current_connections' => $this->currentConnections,
            'active_connections' => count($this->activeConnections),
            'available_connections' => $this->availableConnections->count(),
            'lazy' => $this->lazy,
            'health_check_enabled' => $this->healthCheckEnabled,
            'last_health_check' => $this->lastHealthCheck,
        ];
    }

    private function initializePool(): void
    {
        for ($i = 0; $i < $this->minConnections; $i++) {
            try {
                $connection = $this->createNewConnection();
                $this->availableConnections->enqueue($connection);
            } catch (ConnectionException $e) {
                break;
            }
        }
    }

    private function createNewConnection(): AMQPConnection
    {
        $connection = $this->factory->createConnection();
        $this->currentConnections++;

        return $connection;
    }

    private function performHealthCheckIfNeeded(): void
    {
        if (! $this->healthCheckEnabled) {
            return;
        }

        $now = time();
        if ($now - $this->lastHealthCheck < $this->healthCheckInterval) {
            return;
        }

        $this->lastHealthCheck = $now;
        $this->performHealthCheck();
    }

    private function performHealthCheck(): void
    {
        $healthyConnections = new SplQueue;

        while (! $this->availableConnections->isEmpty()) {
            $connection = $this->availableConnections->dequeue();

            if ($this->factory->isConnectionAlive($connection)) {
                $healthyConnections->enqueue($connection);
            } else {
                $this->factory->closeConnection($connection);
                $this->currentConnections--;
            }
        }

        $this->availableConnections = $healthyConnections;
    }

    private function firstHostConfig(array $hosts): array
    {
        if ($hosts === []) {
            return [];
        }

        if (array_is_list($hosts) && isset($hosts[0]) && is_array($hosts[0])) {
            return $hosts[0];
        }

        return $hosts;
    }
}
