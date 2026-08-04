<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Doubles;

use AMQPChannel;
use AMQPConnection;
use iamfarhad\LaravelRabbitMQ\Connection\ChannelPool;
use iamfarhad\LaravelRabbitMQ\Connection\ConnectionPool;

/**
 * ChannelPool with channel creation redirected at a test-supplied factory.
 *
 * @see TestableRabbitQueue for why this exists instead of `overload:` mocking.
 */
class TestableChannelPool extends ChannelPool
{
    /** @var (callable(AMQPConnection): AMQPChannel)|null */
    private $channelFactory = null;

    public int $channelsCreated = 0;

    /**
     * @param  callable(AMQPConnection): AMQPChannel  $factory
     */
    public function useChannelFactory(callable $factory): static
    {
        $this->channelFactory = $factory;

        return $this;
    }

    protected function newAmqpChannel(AMQPConnection $connection): AMQPChannel
    {
        $this->channelsCreated++;

        if ($this->channelFactory !== null) {
            return ($this->channelFactory)($connection);
        }

        // Default to a permissive double so tests that only care about pool
        // bookkeeping need no wiring at all. Tests asserting channel
        // interactions install their own factory instead.
        $channel = \Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('isConnected')->andReturn(true);
        $channel->shouldReceive('getConnection')->andReturn($connection);
        $channel->shouldReceive('close')->andReturnNull();

        return $channel;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function make(ConnectionPool $connectionPool, array $config = []): static
    {
        return new static($connectionPool, $config);
    }
}
