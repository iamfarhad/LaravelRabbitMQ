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

    /**
     * Collaborators are injectable so they can be substituted in tests without
     * globally replacing ext-amqp's classes. Passing nothing keeps the previous
     * behaviour of building them from the configuration.
     *
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        array $config,
        ?ConnectionFactory $connectionFactory = null,
        ?ConnectionPool $connectionPool = null,
        ?ChannelPool $channelPool = null
    ) {
        $this->config = $config;
        $this->connectionFactory = $connectionFactory ?? new ConnectionFactory($config);
        $this->connectionPool = $connectionPool ?? new ConnectionPool($this->connectionFactory, $config);
        $this->channelPool = $channelPool ?? new ChannelPool($this->connectionPool, $config);
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
     * Flag a channel as carrying state that cannot be reset (publisher confirm
     * mode, an open transaction, a QoS prefetch) so it is retired rather than
     * handed to the next borrower.
     */
    public function markChannelDirty(AMQPChannel $channel): void
    {
        $this->channelPool->markDirty($channel);
    }

    /**
     * Get a connection from the pool (for advanced use cases)
     *
     * Callers own the returned connection and MUST hand it back with
     * releaseConnection(), or the pool will run out.
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
        $poolConfig = $this->config['pool'] ?? [];

        return [
            'connection_pool' => $this->connectionPool->getStats(),
            'channel_pool' => $this->channelPool->getStats(),
            'config' => [
                'max_connections' => (int) ($poolConfig['max_connections'] ?? 10),
                'min_connections' => (int) ($poolConfig['min_connections'] ?? 2),
                'max_channels_per_connection' => (int) ($poolConfig['max_channels_per_connection'] ?? 100),
                'max_retries' => (int) ($poolConfig['max_retries'] ?? 3),
                'retry_delay' => (int) ($poolConfig['retry_delay'] ?? 1000),
                'health_check_enabled' => (bool) ($poolConfig['health_check_enabled'] ?? true),
                'health_check_interval' => (int) ($poolConfig['health_check_interval'] ?? 30),
            ],
        ];
    }

    /**
     * Whether the pool can still serve work.
     *
     * A lazy pool legitimately sits at zero connections until its first use, so
     * "fewer than min_connections" is not a fault on its own — that check used
     * to report every idle worker as unhealthy. The pool is unhealthy only when
     * it can neither hand out an idle connection nor open a new one.
     */
    public function isHealthy(): bool
    {
        $stats = $this->connectionPool->getStats();

        if ($stats['available_connections'] > 0) {
            return true;
        }

        return $stats['current_connections'] < $stats['max_connections'];
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
