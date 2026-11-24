<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Horizon;

use AMQPChannel;
use AMQPConnection;
use AMQPEnvelope;
use AMQPExchange;
use AMQPQueue;
use iamfarhad\LaravelRabbitMQ\Connection\PoolManager;
use iamfarhad\LaravelRabbitMQ\Exceptions\SettlementException;
use iamfarhad\LaravelRabbitMQ\Horizon\HorizonRabbitQueue;
use iamfarhad\LaravelRabbitMQ\Horizon\Listeners\RabbitMQFailedEvent;
use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Events\JobFailed;
use Mockery;

class HorizonIntegrationUnitTest extends UnitTestCase
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

    public function testReadyNowPushLaterPopAndDeleteReserved(): void
    {
        $conn = Mockery::mock(AMQPConnection::class);
        $conn->shouldReceive('isConnected')->andReturn(true);

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('isConnected')->andReturn(true);
        $channel->shouldReceive('getConnection')->andReturn($conn);

        $poolManager = Mockery::mock(PoolManager::class);
        $poolManager->shouldReceive('getChannel')->andReturn($channel);

        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getBody')->andReturn('{"id":"1"}');
        $envelope->shouldReceive('getCorrelationId')->andReturn('1');
        $envelope->shouldReceive('getHeaders')->andReturn([]);
        $envelope->shouldReceive('getDeliveryTag')->andReturn(9);

        $amqpQueue = Mockery::mock(AMQPQueue::class);
        $amqpQueue->shouldReceive('setName');
        $amqpQueue->shouldReceive('setFlags');
        $amqpQueue->shouldReceive('getFlags')->andReturn(2);
        $amqpQueue->shouldReceive('setArguments');
        $amqpQueue->shouldReceive('declareQueue')->andReturn(4);
        $amqpQueue->shouldReceive('get')->andReturn($envelope);
        $amqpQueue->shouldReceive('ack')->once()->with(9);

        $amqpExchange = Mockery::mock(AMQPExchange::class);
        $amqpExchange->shouldReceive('setName');
        $amqpExchange->shouldReceive('publish');

        $dispatcher = Mockery::mock(Dispatcher::class);
        $container = Container::getInstance();
        $container->instance(Dispatcher::class, $dispatcher);

        $queue = new class($poolManager, 'default', $amqpQueue, $amqpExchange) extends HorizonRabbitQueue
        {
            public function __construct(
                PoolManager $poolManager,
                string $defaultQueue,
                private AMQPQueue $queueDouble,
                private AMQPExchange $exchangeDouble,
            ) {
                parent::__construct($poolManager, $defaultQueue);
            }

            protected function newAmqpQueue(AMQPChannel $channel): AMQPQueue
            {
                return $this->queueDouble;
            }

            protected function newAmqpExchange(AMQPChannel $channel): AMQPExchange
            {
                return $this->exchangeDouble;
            }
        };
        $queue->setContainer($container);

        $this->assertSame(4, $queue->readyNow('orders'));
        $queue->push(fn () => null, '', 'orders');
        $queue->later(0, fn () => null, '', 'orders');

        $popped = $queue->pop('orders');
        $this->assertInstanceOf(RabbitMQJob::class, $popped);

        // Non-RabbitMQJob is ignored.
        $queue->deleteReserved('orders', Mockery::mock(JobContract::class));

        // Unsettled RabbitMQJob is deleted (acked).
        $queue->deleteReserved('orders', $popped);
        $this->assertTrue($popped->isDeleted());
    }

    public function testDeleteReservedDoesNotMaskSettlementFailure(): void
    {
        $poolManager = Mockery::mock(PoolManager::class);
        $queue = new HorizonRabbitQueue($poolManager, 'default');

        $job = Mockery::mock(RabbitMQJob::class);
        $job->shouldReceive('isDeletedOrReleased')->once()->andReturn(false);
        $job->shouldReceive('acknowledgeDeletion')->once()->andThrow(SettlementException::channelUnusable('ack', 'orders'));
        $job->shouldNotReceive('getRawBody');

        $this->expectException(SettlementException::class);

        $queue->deleteReserved('orders', $job);
    }

    public function testFailedEventListenerIgnoresNonRabbitMqJobs(): void
    {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldNotReceive('dispatch');

        $listener = new RabbitMQFailedEvent($dispatcher);
        $listener->handle(new JobFailed('sync', Mockery::mock(JobContract::class), new \RuntimeException('err')));

        $this->assertTrue(true);
    }
}
