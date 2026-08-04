<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Connection;

use AMQPChannel;
use AMQPConnection;
use AMQPException;
use iamfarhad\LaravelRabbitMQ\Exceptions\QueueException;
use SplQueue;

class ChannelPool
{
    private ConnectionPool $connectionPool;

    private SplQueue $availableChannels;

    private array $activeChannels = [];

    private array $channelConnections = []; // Track which connection each channel belongs to

    private array $connectionChannelCounts = []; // Track how many channels are bound to each connection

    /**
     * Channels carrying connection-scoped state that AMQP provides no way to
     * undo — publisher confirm mode, an open transaction, a QoS prefetch.
     * Reusing one would silently impose that state on the next borrower, so
     * they are closed on release instead of being pooled.
     *
     * @var array<int, true>
     */
    private array $dirtyChannels = [];

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

        // Cast explicitly: env() only coerces true/false/null/empty, so any
        // numeric value set in .env arrives here as a string and would be a
        // TypeError against these typed properties.
        $this->maxChannelsPerConnection = max(1, (int) ($poolConfig['max_channels_per_connection'] ?? 100));
        $this->healthCheckEnabled = (bool) ($poolConfig['health_check_enabled'] ?? true);
        $this->healthCheckInterval = max(1, (int) ($poolConfig['health_check_interval'] ?? 30)); // seconds
    }

    /**
     * Get a channel from the pool
     *
     * @throws QueueException
     */
    public function getChannel(): AMQPChannel
    {
        $this->performHealthCheckIfNeeded();

        // Reuse the first healthy pooled channel, discarding dead ones along
        // the way. When a connection dies it can orphan many pooled channels
        // at once, so keep draining instead of giving up after one.
        while (! $this->availableChannels->isEmpty()) {
            $channel = $this->availableChannels->dequeue();

            if ($this->isChannelOpen($channel)) {
                $channelId = spl_object_id($channel);
                $this->activeChannels[$channelId] = $channel;

                return $channel;
            }

            $this->removeDeadChannel($channel);
        }

        // Create new channel
        return $this->createNewChannel();
    }

    /**
     * Mark a channel as carrying state that must not leak to the next borrower.
     */
    public function markDirty(AMQPChannel $channel): void
    {
        $this->dirtyChannels[spl_object_id($channel)] = true;
    }

    public function isDirty(AMQPChannel $channel): bool
    {
        return isset($this->dirtyChannels[spl_object_id($channel)]);
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

        if (isset($this->dirtyChannels[$channelId])) {
            // Confirm mode and transaction mode cannot be switched off, and a
            // stale confirm callback would keep firing into a dead closure.
            $this->closeChannel($channel);

            return;
        }

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
        unset($this->activeChannels[$channelId], $this->dirtyChannels[$channelId]);

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

        // Clean up tracking. The channel counter is only decremented for
        // channels this pool actually created and still tracks, so repeated or
        // foreign closes can never drive it negative.
        $this->unbindChannelFromConnection($channelId);
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
        $this->dirtyChannels = [];
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
            'dirty_channels' => count($this->dirtyChannels),
            'health_check_enabled' => $this->healthCheckEnabled,
            'health_check_interval' => $this->healthCheckInterval,
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

            try {
                $channel = $this->newAmqpChannel($connection);
            } catch (AMQPException $e) {
                // The connection died between the liveness check and channel
                // creation; discard it and retry once on a fresh connection.
                $this->discardConnection($connection);
                $connection = $this->acquireConnectionForNewChannel();
                $channel = $this->newAmqpChannel($connection);
            }

            $channelId = spl_object_id($channel);
            $this->activeChannels[$channelId] = $channel;
            $this->bindChannelToConnection($channelId, $connection);
            $this->currentChannels++;

            return $channel;
        } catch (AMQPException $e) {
            throw new QueueException(
                'Failed to create AMQP channel: '.$e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * ext-amqp object-creation seam; see RabbitQueue for the rationale.
     */
    protected function newAmqpChannel(AMQPConnection $connection): AMQPChannel
    {
        return new AMQPChannel($connection);
    }

    /**
     * Reuse the current connection while it is alive and has spare channel
     * capacity; otherwise obtain a fresh one from the connection pool. A dead
     * current connection is dropped so new channels are never multiplexed
     * onto a connection the broker has already closed.
     */
    private function acquireConnectionForNewChannel(): AMQPConnection
    {
        if ($this->currentConnection !== null) {
            if (! $this->isConnectionUsable($this->currentConnection)) {
                $this->currentConnection = null;
            } else {
                $connectionId = spl_object_id($this->currentConnection);
                $count = $this->connectionChannelCounts[$connectionId] ?? 0;

                if ($count < $this->maxChannelsPerConnection) {
                    return $this->currentConnection;
                }
            }
        }

        $connection = $this->connectionPool->getConnection();
        $this->currentConnection = $connection;

        return $connection;
    }

    private function isConnectionUsable(AMQPConnection $connection): bool
    {
        try {
            return $connection->isConnected();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Stop multiplexing onto a connection and hand it back to the connection
     * pool, which detects dead connections on release and drops them.
     */
    private function discardConnection(AMQPConnection $connection): void
    {
        if ($this->currentConnection !== null
            && spl_object_id($this->currentConnection) === spl_object_id($connection)) {
            $this->currentConnection = null;
        }

        $this->connectionPool->releaseConnection($connection);
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

        $this->currentChannels = max(0, $this->currentChannels - 1);

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
     * Check if channel is open and usable.
     *
     * AMQPChannel::isConnected() reflects the same internal flag ext-amqp
     * verifies before every channel operation, so this is the only check
     * that actually detects channels orphaned by a dead connection
     * (broker restart, missed heartbeats, idle disconnect). getChannelId()
     * must NOT be used here: it returns a stored id without touching the
     * connection and reports dead channels as healthy.
     */
    private function isChannelOpen(AMQPChannel $channel): bool
    {
        try {
            return $channel->isConnected() && $channel->getConnection()->isConnected();
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

        unset($this->dirtyChannels[$channelId]);

        // Only releases the connection back to the pool once no other
        // channel sharing it remains, and only decrements the channel counter
        // for a channel this pool is still tracking (see
        // unbindChannelFromConnection()).
        $this->unbindChannelFromConnection($channelId);
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

        while (! $this->availableChannels->isEmpty()) {
            $channel = $this->availableChannels->dequeue();

            if ($this->isChannelOpen($channel)) {
                $healthyChannels->enqueue($channel);
            } else {
                $this->removeDeadChannel($channel);
                $this->safeCloseChannel($channel);
            }
        }

        $this->availableChannels = $healthyChannels;
    }
}
