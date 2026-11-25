<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Connection;

use AMQPChannel;
use AMQPConnection;
use iamfarhad\LaravelRabbitMQ\Connection\ConnectionPool;
use iamfarhad\LaravelRabbitMQ\Connection\PoolManager;
use iamfarhad\LaravelRabbitMQ\Tests\Doubles\TestableChannelPool;
use iamfarhad\LaravelRabbitMQ\Tests\Doubles\TestableConnectionFactory;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Mockery;

class PoolManagerUnitTest extends UnitTestCase
{
    public function testDelegatesAllOperationsAcrossPools(): void
    {
        $config = [
            'hosts' => ['host' => '127.0.0.1'],
            'pool' => [
                'max_connections' => 1,
                'min_connections' => 0,
                'lazy' => true,
                'health_check_enabled' => false,
            ],
        ];

        $connection = Mockery::mock(AMQPConnection::class);
        $connection->shouldReceive('connect')->andReturnNull();
        $connection->shouldReceive('isConnected')->andReturn(true);
        $connection->shouldReceive('disconnect')->andReturnNull();

        $factory = (new TestableConnectionFactory($config))
            ->useConnectionFactory(fn (): AMQPConnection => $connection);

        $connectionPool = new ConnectionPool($factory, $config);

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('isConnected')->andReturn(true);
        $channel->shouldReceive('getConnection')->andReturn($connection);
        $channel->shouldReceive('close')->andReturnNull();

        $channelPool = TestableChannelPool::make($connectionPool, $config)
            ->useChannelFactory(fn (): AMQPChannel => $channel);

        $manager = new PoolManager($config, $factory, $connectionPool, $channelPool);

        $this->assertSame($factory, $manager->getConnectionFactory());
        $this->assertSame($connectionPool, $manager->getConnectionPool());
        $this->assertSame($channelPool, $manager->getChannelPool());
        $this->assertTrue($manager->isHealthy());

        $acquiredChannel = $manager->getChannel();
        $this->assertSame($channel, $acquiredChannel);
        $this->assertFalse($manager->isHealthy());

        $manager->markChannelDirty($acquiredChannel);
        $manager->releaseChannel($acquiredChannel);
        $this->assertTrue($manager->isHealthy());

        $acquiredConn = $manager->getConnection();
        $this->assertSame($connection, $acquiredConn);
        $manager->releaseConnection($acquiredConn);

        $acquiredChannel2 = $manager->getChannel();
        $manager->closeChannel($acquiredChannel2);

        $acquiredConn2 = $manager->getConnection();
        $manager->closeConnection($acquiredConn2);

        $stats = $manager->getStats();
        $this->assertSame(1, $stats['config']['max_connections']);
        $this->assertSame(0, $stats['connection_pool']['current_connections']);

        $manager->closeAll();
        $this->assertSame(0, $manager->getStats()['channel_pool']['current_channels']);
    }
}
