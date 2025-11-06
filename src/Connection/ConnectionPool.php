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

    public function __construct(ConnectionFactory $factory, array $config)
    {
        $this->factory = $factory;
        $this->availableConnections = new SplQueue;

        $poolConfig = $config['pool'] ?? [];
        $this->maxConnections = $poolConfig['max_connections'] ?? 10;
        $this->minConnections = $poolConfig['min_connections'] ?? 2;
        $this->healthCheckEnabled = $poolConfig['health_check_enabled'] ?? true;
        $this->healthCheckInterval = $poolConfig['health_check_interval'] ?? 30; // seconds

        // Initialize minimum connections
        $this->initializePool();
    }

    /**
     * Get a connection from the pool
     *
     * @throws ConnectionException
     */
    public function getConnection(): AMQPConnection
    {
        $this->performHealthCheckIfNeeded();

        // Try to get an available connection
        if (! $this->availableConnections->isEmpty()) {
            $connection = $this->availableConnections->dequeue();

            // Verify connection is still alive
            if ($this->factory->isConnectionAlive($connection)) {
                $connectionId = spl_object_id($connection);
                $this->activeConnections[$connectionId] = $connection;

                return $connection;
            }
            // Connection is dead, remove it and create a new one
            $this->currentConnections--;

        }

        // Create new connection if under limit
        if ($this->currentConnections < $this->maxConnections) {
            $connection = $this->createNewConnection();
            $connectionId = spl_object_id($connection);
            $this->activeConnections[$connectionId] = $connection;

            return $connection;
        }

        throw new ConnectionException(
            sprintf(
                'Connection pool exhausted. Maximum connections (%d) reached.',
                $this->maxConnections
            )
        );
    }

    /**
     * Return a connection to the pool
     */
    public function releaseConnection(AMQPConnection $connection): void
    {
        $connectionId = spl_object_id($connection);

        if (! isset($this->activeConnections[$connectionId])) {
            return;
        }

        unset($this->activeConnections[$connectionId]);

        // Check if connection is still alive before returning to pool
        if ($this->factory->isConnectionAlive($connection)) {
            $this->availableConnections->enqueue($connection);
        } else {
            // Connection is dead, don't return to pool
            $this->currentConnections--;
        }
    }

    /**
     * Close a specific connection and remove from pool
     */
    public function closeConnection(AMQPConnection $connection): void
    {
        $connectionId = spl_object_id($connection);

        // Remove from active connections
        if (isset($this->activeConnections[$connectionId])) {
            unset($this->activeConnections[$connectionId]);
        }

        // Remove from available connections if present
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

    /**
     * Close all connections and clean up the pool
     */
    public function closeAll(): void
    {

        // Close active connections
        foreach ($this->activeConnections as $connection) {
            $this->factory->closeConnection($connection);
        }
        $this->activeConnections = [];

        // Close available connections
        while (! $this->availableConnections->isEmpty()) {
            $connection = $this->availableConnections->dequeue();
            $this->factory->closeConnection($connection);
        }

        $this->currentConnections = 0;
    }

    /**
     * Get pool statistics
     */
    public function getStats(): array
    {
        return [
            'max_connections' => $this->maxConnections,
            'min_connections' => $this->minConnections,
            'current_connections' => $this->currentConnections,
            'active_connections' => count($this->activeConnections),
            'available_connections' => $this->availableConnections->count(),
            'health_check_enabled' => $this->healthCheckEnabled,
            'last_health_check' => $this->lastHealthCheck,
        ];
    }

    /**
     * Initialize the pool with minimum connections
     */
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

    /**
     * Create a new connection and increment counter
     */
    private function createNewConnection(): AMQPConnection
    {
        $connection = $this->factory->createConnection();
        $this->currentConnections++;

        return $connection;
    }

    /**
     * Perform health check on available connections if needed
     */
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

    /**
     * Check health of all available connections
     */
    private function performHealthCheck(): void
    {
        $healthyConnections = new SplQueue;
        $removedCount = 0;

        while (! $this->availableConnections->isEmpty()) {
            $connection = $this->availableConnections->dequeue();

            if ($this->factory->isConnectionAlive($connection)) {
                $healthyConnections->enqueue($connection);
            } else {
                $this->factory->closeConnection($connection);
                $this->currentConnections--;
                $removedCount++;
            }
        }

        $this->availableConnections = $healthyConnections;

        if ($removedCount > 0) {
        }
    }
}
