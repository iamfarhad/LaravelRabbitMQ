<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Connection;

use AMQPChannel;
use AMQPChannelException;
use AMQPConnection;
use iamfarhad\LaravelRabbitMQ\Exceptions\QueueException;
use SplQueue;

class ChannelPool
{
    private ConnectionPool $connectionPool;

    private SplQueue $availableChannels;

    private array $activeChannels = [];

    private array $channelConnections = []; // Track which connection each channel belongs to

    private array $connectionChannelCounts = []; // Track how many channels are bound to each connection

    private ?AMQPConnection $currentConnection = null; // Connection new channels are multiplexed onto

    private int $maxChannelsPerConnection;

    private int $currentChannels = 0;

    private bool $healthCheckEnabled;

    private int $healthCheckInterval;

    private int $lastHealthCheck = 0;

    public function __construct(ConnectionPool $connectionPool, array $config)
    {
        $this->connectionPool = $connectionPool;
        $this->availableChannels = new SplQueue;

        $poolConfig = $config['pool'] ?? [];
        $this->maxChannelsPerConnection = $poolConfig['max_channels_per_connection'] ?? 100;
        $this->healthCheckEnabled = $poolConfig['health_check_enabled'] ?? true;
        $this->healthCheckInterval = $poolConfig['health_check_interval'] ?? 30; // seconds
    }

    /**
     * Get a channel from the pool
     *
     * @throws QueueException
     */
    public function getChannel(): AMQPChannel
    {
        $this->performHealthCheckIfNeeded();

        // Try to get an available channel
        if (! $this->availableChannels->isEmpty()) {
            $channel = $this->availableChannels->dequeue();

            // Verify channel is still open
            if ($this->isChannelOpen($channel)) {
                $channelId = spl_object_id($channel);
                $this->activeChannels[$channelId] = $channel;

                return $channel;
            }
            // Channel is closed, remove it and create a new one
            $this->removeDeadChannel($channel);

        }

        // Create new channel
        return $this->createNewChannel();
    }

    /**
     * Return a channel to the pool
     */
    public function releaseChannel(AMQPChannel $channel): void
    {
        $channelId = spl_object_id($channel);

        if (! isset($this->activeChannels[$channelId])) {
            return;
        }

        unset($this->activeChannels[$channelId]);

        // Check if channel is still open before returning to pool
        if ($this->isChannelOpen($channel)) {
            $this->availableChannels->enqueue($channel);
        } else {
            // Channel is closed, don't return to pool
            $this->removeDeadChannel($channel);
        }
    }

    /**
     * Close a specific channel and remove from pool
     */
    public function closeChannel(AMQPChannel $channel): void
    {
        $channelId = spl_object_id($channel);

        // Remove from active channels
        if (isset($this->activeChannels[$channelId])) {
            unset($this->activeChannels[$channelId]);
        }

        // Remove from available channels if present
        $tempQueue = new SplQueue;
        while (! $this->availableChannels->isEmpty()) {
            $ch = $this->availableChannels->dequeue();
            if (spl_object_id($ch) !== $channelId) {
                $tempQueue->enqueue($ch);
            }
        }
        $this->availableChannels = $tempQueue;

        // Close the channel safely
        $this->safeCloseChannel($channel);

        // Clean up tracking
        $this->unbindChannelFromConnection($channelId);

        $this->currentChannels--;
    }

    /**
     * Close all channels and clean up the pool
     */
    public function closeAll(): void
    {

        // Close active channels
        foreach ($this->activeChannels as $channel) {
            $this->safeCloseChannel($channel);
        }
        $this->activeChannels = [];

        // Close available channels
        while (! $this->availableChannels->isEmpty()) {
            $channel = $this->availableChannels->dequeue();
            $this->safeCloseChannel($channel);
        }

        $this->channelConnections = [];
        $this->connectionChannelCounts = [];
        $this->currentConnection = null;
        $this->currentChannels = 0;
    }

    /**
     * Get pool statistics
     */
    public function getStats(): array
    {
        return [
            'max_channels_per_connection' => $this->maxChannelsPerConnection,
            'current_channels' => $this->currentChannels,
            'active_channels' => count($this->activeChannels),
            'available_channels' => $this->availableChannels->count(),
            'health_check_enabled' => $this->healthCheckEnabled,
            'last_health_check' => $this->lastHealthCheck,
        ];
    }

    /**
     * Create a new channel, multiplexing it onto the current connection
     * until max_channels_per_connection is reached before requesting
     * another connection from the connection pool.
     *
     * @throws QueueException
     */
    private function createNewChannel(): AMQPChannel
    {
        try {
            $connection = $this->acquireConnectionForNewChannel();
            $channel = new AMQPChannel($connection);

            $channelId = spl_object_id($channel);
            $this->activeChannels[$channelId] = $channel;
            $this->bindChannelToConnection($channelId, $connection);
            $this->currentChannels++;

            return $channel;
        } catch (AMQPChannelException $e) {
            throw new QueueException(
                'Failed to create AMQP channel: '.$e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Reuse the current connection while it has spare channel capacity;
     * otherwise obtain a fresh one from the connection pool.
     */
    private function acquireConnectionForNewChannel(): AMQPConnection
    {
        if ($this->currentConnection !== null) {
            $connectionId = spl_object_id($this->currentConnection);
            $count = $this->connectionChannelCounts[$connectionId] ?? 0;

            if ($count < $this->maxChannelsPerConnection) {
                return $this->currentConnection;
            }
        }

        $connection = $this->connectionPool->getConnection();
        $this->currentConnection = $connection;

        return $connection;
    }

    /**
     * Track that a channel is bound to a connection, so the connection is
     * only released back to the connection pool once every channel sharing
     * it has been removed.
     */
    private function bindChannelToConnection(int $channelId, AMQPConnection $connection): void
    {
        $connectionId = spl_object_id($connection);
        $this->channelConnections[$channelId] = $connection;
        $this->connectionChannelCounts[$connectionId] = ($this->connectionChannelCounts[$connectionId] ?? 0) + 1;
    }

    /**
     * Undo bindChannelToConnection(): only releases the connection back to
     * the connection pool once no channel is left referencing it.
     */
    private function unbindChannelFromConnection(int $channelId): void
    {
        if (! isset($this->channelConnections[$channelId])) {
            return;
        }

        $connection = $this->channelConnections[$channelId];
        $connectionId = spl_object_id($connection);
        unset($this->channelConnections[$channelId]);

        $remaining = max(0, ($this->connectionChannelCounts[$connectionId] ?? 1) - 1);

        if ($remaining > 0) {
            $this->connectionChannelCounts[$connectionId] = $remaining;

            return;
        }

        unset($this->connectionChannelCounts[$connectionId]);
        $this->connectionPool->releaseConnection($connection);

        if ($this->currentConnection !== null && spl_object_id($this->currentConnection) === $connectionId) {
            $this->currentConnection = null;
        }
    }

    /**
     * Check if channel is open and usable
     */
    private function isChannelOpen(AMQPChannel $channel): bool
    {
        try {
            // Try to get channel ID - this will fail if channel is closed
            $channel->getChannelId();

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Safely close a channel
     */
    private function safeCloseChannel(AMQPChannel $channel): void
    {
        try {
            if ($this->isChannelOpen($channel)) {
                $channel->close();
            }
        } catch (\Exception $e) {
        }
    }

    /**
     * Remove a dead channel and its connection reference
     */
    private function removeDeadChannel(AMQPChannel $channel): void
    {
        $channelId = spl_object_id($channel);

        // Only releases the connection back to the pool once no other
        // channel sharing it remains (see unbindChannelFromConnection()).
        $this->unbindChannelFromConnection($channelId);

        $this->currentChannels--;
    }

    /**
     * Perform health check on available channels if needed
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
     * Check health of all available channels
     */
    private function performHealthCheck(): void
    {
        $healthyChannels = new SplQueue;
        $removedCount = 0;

        while (! $this->availableChannels->isEmpty()) {
            $channel = $this->availableChannels->dequeue();

            if ($this->isChannelOpen($channel)) {
                $healthyChannels->enqueue($channel);
            } else {
                $this->removeDeadChannel($channel);
                $this->safeCloseChannel($channel);
                $removedCount++;
            }
        }

        $this->availableChannels = $healthyChannels;

        if ($removedCount > 0) {
        }
    }
}
