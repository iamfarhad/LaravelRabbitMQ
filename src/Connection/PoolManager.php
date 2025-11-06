<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Connection;

use AMQPChannel;
use AMQPConnection;
use iamfarhad\LaravelRabbitMQ\Exceptions\ConnectionException;
use iamfarhad\LaravelRabbitMQ\Exceptions\QueueException;

class PoolManager
{
    private ConnectionFactory $connectionFactory;

    private ConnectionPool $connectionPool;

    private ChannelPool $channelPool;

    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->connectionFactory = new ConnectionFactory($config);
        $this->connectionPool = new ConnectionPool($this->connectionFactory, $config);
        $this->channelPool = new ChannelPool($this->connectionPool, $config);
    }

    /**
     * Get a channel from the pool (recommended method)
     *
     * @throws QueueException
     */
    public function getChannel(): AMQPChannel
    {
        return $this->channelPool->getChannel();
    }

    /**
     * Return a channel to the pool
     */
    public function releaseChannel(AMQPChannel $channel): void
    {
        $this->channelPool->releaseChannel($channel);
    }

    /**
     * Get a connection from the pool (for advanced use cases)
     *
     * @throws ConnectionException
     */
    public function getConnection(): AMQPConnection
    {
        return $this->connectionPool->getConnection();
    }

    /**
     * Return a connection to the pool
     */
    public function releaseConnection(AMQPConnection $connection): void
    {
        $this->connectionPool->releaseConnection($connection);
    }

    /**
     * Close a specific channel
     */
    public function closeChannel(AMQPChannel $channel): void
    {
        $this->channelPool->closeChannel($channel);
    }

    /**
     * Close a specific connection
     */
    public function closeConnection(AMQPConnection $connection): void
    {
        $this->connectionPool->closeConnection($connection);
    }

    /**
     * Close all pools and connections
     */
    public function closeAll(): void
    {
        $this->channelPool->closeAll();
        $this->connectionPool->closeAll();
    }

    /**
     * Get comprehensive pool statistics
     */
    public function getStats(): array
    {
        return [
            'connection_pool' => $this->connectionPool->getStats(),
            'channel_pool' => $this->channelPool->getStats(),
            'config' => [
                'max_connections' => $this->config['pool']['max_connections'] ?? 10,
                'min_connections' => $this->config['pool']['min_connections'] ?? 2,
                'max_channels_per_connection' => $this->config['pool']['max_channels_per_connection'] ?? 100,
                'max_retries' => $this->config['pool']['max_retries'] ?? 3,
                'retry_delay' => $this->config['pool']['retry_delay'] ?? 1000,
                'health_check_enabled' => $this->config['pool']['health_check_enabled'] ?? true,
                'health_check_interval' => $this->config['pool']['health_check_interval'] ?? 30,
            ],
        ];
    }

    /**
     * Check if pools are healthy
     */
    public function isHealthy(): bool
    {
        $stats = $this->getStats();

        // Check if we have at least minimum connections
        $connectionStats = $stats['connection_pool'];
        $minConnections = $stats['config']['min_connections'];

        if ($connectionStats['current_connections'] < $minConnections) {
            return false;
        }

        return true;
    }

    /**
     * Get the connection factory (for testing or advanced use)
     */
    public function getConnectionFactory(): ConnectionFactory
    {
        return $this->connectionFactory;
    }

    /**
     * Get the connection pool (for testing or advanced use)
     */
    public function getConnectionPool(): ConnectionPool
    {
        return $this->connectionPool;
    }

    /**
     * Get the channel pool (for testing or advanced use)
     */
    public function getChannelPool(): ChannelPool
    {
        return $this->channelPool;
    }
}
