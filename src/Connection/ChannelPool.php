<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Connection;

use AMQPChannel;
use AMQPChannelException;
use iamfarhad\LaravelRabbitMQ\Exceptions\QueueException;
use SplQueue;

class ChannelPool
{
    private ConnectionPool $connectionPool;

    private SplQueue $availableChannels;

    private array $activeChannels = [];

    private array $channelConnections = []; // Track which connection each channel belongs to

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
        if (isset($this->channelConnections[$channelId])) {
            unset($this->channelConnections[$channelId]);
        }

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
     * Create a new channel
     *
     * @throws QueueException
     */
    private function createNewChannel(): AMQPChannel
    {
        try {
            $connection = $this->connectionPool->getConnection();
            $channel = new AMQPChannel($connection);

            $channelId = spl_object_id($channel);
            $this->activeChannels[$channelId] = $channel;
            $this->channelConnections[$channelId] = $connection;
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

        // Return connection to pool if we have a reference
        if (isset($this->channelConnections[$channelId])) {
            $connection = $this->channelConnections[$channelId];
            $this->connectionPool->releaseConnection($connection);
            unset($this->channelConnections[$channelId]);
        }

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
