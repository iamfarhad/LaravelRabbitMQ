<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit;

use AMQPChannel;
use AMQPChannelException;
use AMQPConnection;
use AMQPConnectionException;
use AMQPEnvelope;
use AMQPExchange;
use AMQPExchangeException;
use AMQPQueue;
use AMQPQueueException;
use Exception;
use iamfarhad\LaravelRabbitMQ\Connection\PoolManager;
use iamfarhad\LaravelRabbitMQ\Facades\RabbitMQ;
use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use iamfarhad\LaravelRabbitMQ\LaravelRabbitQueueServiceProvider;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use iamfarhad\LaravelRabbitMQ\Support\ExchangeManager;
use iamfarhad\LaravelRabbitMQ\Support\ExponentialBackoff;
use iamfarhad\LaravelRabbitMQ\Support\RpcClient;
use iamfarhad\LaravelRabbitMQ\Support\TransactionManager;
use iamfarhad\LaravelRabbitMQ\Tests\Doubles\TestableRabbitQueue;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Mockery;

class RabbitQueueFullCoverageTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container;
        $container->instance('config', new ConfigRepository([
            'queue' => ['connections' => ['rabbitmq' => []]],
        ]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        Mockery::close();
        parent::tearDown();
    }

    private function poolManager(?AMQPChannel $channel = null): PoolManager
    {
        $conn = Mockery::mock(AMQPConnection::class);
        $conn->shouldReceive('isConnected')->andReturn(true);

        $channel ??= Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('isConnected')->andReturn(true);
        $channel->shouldReceive('getConnection')->andReturn($conn);

        $poolManager = Mockery::mock(PoolManager::class);
        $poolManager->shouldReceive('getChannel')->andReturn($channel);
        $poolManager->shouldReceive('releaseChannel');
        $poolManager->shouldReceive('markChannelDirty');

        return $poolManager;
    }

    public function testSizeMetricsAndQueueExists(): void
    {
        $amqpQueue = Mockery::mock(AMQPQueue::class);
        $amqpQueue->shouldReceive('setName');
        $amqpQueue->shouldReceive('setFlags');
        $amqpQueue->shouldReceive('declareQueue')->andReturn(
            5,
            5,
            true,
        );

        $queue = TestableRabbitQueue::make($this->poolManager(), 'orders')
            ->useQueueFactory(fn (): AMQPQueue => $amqpQueue);

        $this->assertSame(5, $queue->size());
        $this->assertSame(5, $queue->pendingSize());
        $this->assertSame(0, $queue->delayedSize());
        $this->assertSame(0, $queue->reservedSize());
        $this->assertNull($queue->creationTimeOfOldestPendingJob());
        $this->assertTrue($queue->queueExists('orders'));
    }

    public function testSizeAndQueueExistsHandle404AndRethrow403(): void
    {
        $amqpQueue = Mockery::mock(AMQPQueue::class);
        $amqpQueue->shouldReceive('setName');
        $amqpQueue->shouldReceive('setFlags');
        $amqpQueue->shouldReceive('declareQueue')->andReturnUsing(function () {
            static $call = 0;
            $call++;
            if ($call <= 2) {
                throw new AMQPQueueException('NOT_FOUND', 404);
            }

            throw new AMQPChannelException('ACCESS_REFUSED', 403);
        });

        $queue = TestableRabbitQueue::make($this->poolManager(), 'orders')
            ->useQueueFactory(fn (): AMQPQueue => $amqpQueue);

        $this->assertSame(0, $queue->size());
        $this->assertFalse($queue->queueExists('missing'));

        $this->expectException(AMQPChannelException::class);
        $this->expectExceptionCode(403);
        $queue->queueExists('forbidden');
    }

    public function testPopReturnsJobHandlesDeadChannelAndLostConnection(): void
    {
        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getBody')->andReturn('{"id":"job-1"}');
        $envelope->shouldReceive('getCorrelationId')->andReturn('job-1');
        $envelope->shouldReceive('getHeaders')->andReturn([]);
        $envelope->shouldReceive('getDeliveryTag')->andReturn(5);

        $amqpQueue = Mockery::mock(AMQPQueue::class);
        $amqpQueue->shouldReceive('setName');
        $amqpQueue->shouldReceive('setFlags');
        $amqpQueue->shouldReceive('getFlags')->andReturn(2);
        $amqpQueue->shouldReceive('setArguments');
        $amqpQueue->shouldReceive('declareQueue')->andReturn(0);
        $amqpQueue->shouldReceive('reject')->once()->with(5, AMQP_REQUEUE);

        $getCalls = 0;
        $amqpQueue->shouldReceive('get')->andReturnUsing(function () use (&$getCalls, $envelope) {
            $getCalls++;
            if ($getCalls === 1) {
                return $envelope;
            }
            if ($getCalls === 2) {
                throw new AMQPConnectionException('reset by peer');
            }
            if ($getCalls === 3) {
                return null;
            }

            throw new AMQPConnectionException('still dead');
        });

        $queue = TestableRabbitQueue::make($this->poolManager(), 'orders')
            ->useQueueFactory(fn (): AMQPQueue => $amqpQueue);
        $queue->setContainer(Container::getInstance());

        $job = $queue->pop();
        $this->assertInstanceOf(RabbitMQJob::class, $job);

        // close() rejects unsettled $rabbitMQJob and releases channel.
        $queue->close();

        // First AMQPConnectionException retries once and succeeds with null.
        $this->assertNull($queue->pop());

        // Double AMQPConnectionException surfaces 'Lost connection: ...'.
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Lost connection: still dead');
        $queue->pop();
    }

    public function testPurgeDeleteTopologyMismatchAndBindings(): void
    {
        Container::getInstance()->make('config')->set('queue.connections.rabbitmq.queues.orders', [
            'bindings' => [
                'not-an-array',
                ['exchange' => ''],
                ['exchange' => 'events', 'exchange_type' => 'topic', 'routing_key' => 'order.*'],
            ],
        ]);

        $log = Mockery::mock();
        $log->shouldReceive('warning')->twice();
        Container::getInstance()->instance('log', $log);

        $amqpQueue = Mockery::mock(AMQPQueue::class);
        $amqpQueue->shouldReceive('setName');
        $amqpQueue->shouldReceive('setFlags');
        $amqpQueue->shouldReceive('getFlags')->andReturn(2);
        $amqpQueue->shouldReceive('setArguments');
        $amqpQueue->shouldReceive('bind')->once()->with('events', 'order.*', []);
        $amqpQueue->shouldReceive('purge')->andReturn(7, 0);
        $amqpQueue->shouldReceive('delete')->andReturn(0);

        $declareCalls = 0;
        $amqpQueue->shouldReceive('declareQueue')->andReturnUsing(function () use (&$declareCalls): int {
            if (++$declareCalls === 2) {
                throw new AMQPQueueException('PRECONDITION_FAILED', 406);
            }

            return 0;
        });

        $exDeclareCalls = 0;
        $amqpExchange = Mockery::mock(AMQPExchange::class);
        $amqpExchange->shouldReceive('setName');
        $amqpExchange->shouldReceive('setType');
        $amqpExchange->shouldReceive('setFlags');
        $amqpExchange->shouldReceive('publish');
        $amqpExchange->shouldReceive('declareExchange')->andReturnUsing(function () use (&$exDeclareCalls): void {
            if (++$exDeclareCalls === 2) {
                throw new AMQPExchangeException('PRECONDITION_FAILED', 406);
            }
        });

        $queue = TestableRabbitQueue::make($this->poolManager(), 'orders')
            ->useQueueFactory(fn (): AMQPQueue => $amqpQueue)
            ->useExchangeFactory(fn (): AMQPExchange => $amqpExchange);
        $queue->setContainer(Container::getInstance());

        $queue->pushRaw('{"id":"1"}', 'orders');
        $this->assertSame(7, $queue->purgeQueue('orders'));
        $this->assertSame(0, $queue->deleteQueue('orders'));

        // Auto-delete queue 406 mismatch + exchange 406 mismatch both log warnings.
        $queue->declareQueue('orders', true, true);
        (new \ReflectionMethod($queue, 'declareExchange'))->invoke($queue, 'events', AMQP_EX_TYPE_DIRECT);

        // Declare advanced queue with lazy, priority, dead-letter, additional args.
        $queue->declareAdvancedQueue('adv', true, false, true, 10, [
            'exchange' => 'dlx',
            'routing_key' => 'adv.dlq',
            'ttl' => 5000,
        ], ['x-custom' => 'val']);

        $this->assertTrue(true);
    }

    public function testPurgeAndDeleteHandle404AndRethrowOtherErrors(): void
    {
        $amqpQueue = Mockery::mock(AMQPQueue::class);
        $amqpQueue->shouldReceive('setName');
        $amqpQueue->shouldReceive('purge')->andThrow(new AMQPQueueException('NOT_FOUND', 404));
        $amqpQueue->shouldReceive('delete')->andThrow(new AMQPChannelException('ACCESS_REFUSED', 403));

        $queue = TestableRabbitQueue::make($this->poolManager(), 'orders')
            ->useQueueFactory(fn (): AMQPQueue => $amqpQueue);

        $this->assertNull($queue->purgeQueue('missing'));

        $this->expectException(AMQPChannelException::class);
        $this->expectExceptionCode(403);
        $queue->deleteQueue('forbidden');
    }

    public function testHelperAccessorsTransactionRpcSetupDlxAndValidation(): void
    {
        Container::getInstance()->make('config')->set('queue.connections.rabbitmq', [
            'rpc' => ['enabled' => true],
            'transactions' => ['enabled' => true],
            'publisher_confirms' => ['enabled' => false],
            'dead_letter' => ['enabled' => true, 'exchange' => 'dlx', 'ttl' => 1000],
            'delayed_message' => ['plugin_enabled' => false],
        ]);

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('startTransaction')->once();
        $channel->shouldReceive('commitTransaction')->once();

        $amqpQueue = Mockery::mock(AMQPQueue::class);
        $amqpQueue->shouldReceive('setName');
        $amqpQueue->shouldReceive('setFlags');
        $amqpQueue->shouldReceive('getFlags')->andReturn(2);
        $amqpQueue->shouldReceive('setArguments');
        $amqpQueue->shouldReceive('declareQueue')->andReturn(0);

        $amqpExchange = Mockery::mock(AMQPExchange::class);
        $amqpExchange->shouldReceive('setName');
        $amqpExchange->shouldReceive('publish');

        $queue = TestableRabbitQueue::make($this->poolManager($channel), 'orders')
            ->useQueueFactory(fn (): AMQPQueue => $amqpQueue)
            ->useExchangeFactory(fn (): AMQPExchange => $amqpExchange);

        $queue->setOptions(['custom' => true]);
        $this->assertSame($channel, $queue->getAmqpChannel());
        $this->assertInstanceOf(ExponentialBackoff::class, $queue->getBackoff());
        $this->assertInstanceOf(ExchangeManager::class, $queue->getExchangeManager());
        $this->assertInstanceOf(TransactionManager::class, $queue->getTransactionManager());
        $this->assertSame('abc', $queue->correlationIdFor('{"id":"abc"}'));
        $this->assertSame('abc', $queue->createMessage('{"id":"abc"}'));
        $this->assertTrue($queue->publishToExchange('events', '{"id":"p1"}', 'rk', ['x' => '1']));
        $this->assertNotNull($queue->publishDelayed('orders', '{"id":"d1"}', 5));

        $this->assertSame('tx-ok', $queue->transaction(fn () => 'tx-ok'));

        $exchangeManager = Mockery::mock(ExchangeManager::class);
        $exchangeManager->shouldReceive('setupDeadLetterExchange')->once();
        (new \ReflectionProperty(RabbitQueue::class, 'exchangeManager'))->setValue($queue, $exchangeManager);
        $queue->setupDeadLetterExchange('orders');

        $rpcClient = Mockery::mock(RpcClient::class);
        $rpcClient->shouldReceive('call')->once()->with('rpc.q', '{}', [])->andReturn('pong');
        (new \ReflectionProperty(RabbitQueue::class, 'rpcClient'))->setValue($queue, $rpcClient);
        $this->assertSame('pong', $queue->rpcCall('rpc.q', '{}'));

        // Verify disabled/conflict guards.
        Container::getInstance()->make('config')->set('queue.connections.rabbitmq.rpc.enabled', false);
        try {
            $queue->rpcCall('rpc.q', '{}');
            $this->fail('Expected RPC disabled exception.');
        } catch (Exception $e) {
            $this->assertStringContainsString('RPC is not enabled', $e->getMessage());
        }

        Container::getInstance()->make('config')->set('queue.connections.rabbitmq.publisher_confirms.enabled', true);
        try {
            $queue->transaction(fn () => null);
            $this->fail('Expected transaction/confirms conflict exception.');
        } catch (Exception $e) {
            $this->assertStringContainsString('AMQP transactions cannot be used', $e->getMessage());
        }

        Container::getInstance()->make('config')->set('queue.connections.rabbitmq.transactions.enabled', false);
        try {
            $queue->transaction(fn () => null);
            $this->fail('Expected transactions disabled exception.');
        } catch (Exception $e) {
            $this->assertStringContainsString('Transactions are not enabled', $e->getMessage());
        }

        Container::getInstance()->make('config')->set('queue.connections.rabbitmq.options.queue.job', \stdClass::class);
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('must extend');
        $queue->getJobClass();
    }

    public function testFacadeAccessorMatchesServiceProviderBinding(): void
    {
        $method = new \ReflectionMethod(RabbitMQ::class, 'getFacadeAccessor');
        $this->assertSame(LaravelRabbitQueueServiceProvider::QUEUE_BINDING, $method->invoke(null));
    }
}
