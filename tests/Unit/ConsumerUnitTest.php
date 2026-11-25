<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit;

use AMQPChannel;
use AMQPConnection;
use AMQPEnvelope;
use AMQPQueue;
use AMQPQueueException;
use iamfarhad\LaravelRabbitMQ\Connection\PoolManager;
use iamfarhad\LaravelRabbitMQ\Consumer;
use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use iamfarhad\LaravelRabbitMQ\Tests\Doubles\TestableRabbitQueue;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\WorkerOptions;
use Mockery;
use RuntimeException;

class ConsumerUnitTest extends UnitTestCase
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

    public function testSetMaxPriorityAndSetConsumeModeFallback(): void
    {
        $consumer = new Consumer(
            Mockery::mock(QueueManager::class),
            Mockery::mock(Dispatcher::class),
            Mockery::mock(ExceptionHandler::class),
            fn (): bool => false
        );

        $consumer->setMaxPriority(7);
        $this->assertSame(7, $consumer->getMaxPriority());

        $consumer->setConsumeMode('invalid');
        $prop = new \ReflectionProperty(Consumer::class, 'consumeMode');
        $this->assertSame('poll', $prop->getValue($consumer));
    }

    public function testDaemonRejectsNonRabbitQueueConnection(): void
    {
        $manager = Mockery::mock(QueueManager::class);
        $manager->shouldReceive('connection')->with('sync')->andReturn(new \stdClass);

        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch');

        $consumer = new Consumer(
            $manager,
            $events,
            Mockery::mock(ExceptionHandler::class),
            fn (): bool => false
        );
        $consumer->setCache(new CacheRepository(new ArrayStore));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection must be an instance of RabbitQueue');

        $consumer->daemon('sync', 'default', new WorkerOptions);
    }

    public function testPollModeRefreshesChannelHandlesIdleAndExceptions(): void
    {
        $conn = Mockery::mock(AMQPConnection::class);
        $conn->shouldReceive('isConnected')->andReturn(true);

        $channel1 = Mockery::mock(AMQPChannel::class);
        $channel1->shouldReceive('isConnected')->andReturn(true, false);
        $channel1->shouldReceive('getConnection')->andReturn($conn);

        $channel2 = Mockery::mock(AMQPChannel::class);
        $channel2->shouldReceive('isConnected')->andReturn(true);
        $channel2->shouldReceive('getConnection')->andReturn($conn);

        $poolManager = Mockery::mock(PoolManager::class);
        $poolManager->shouldReceive('getChannel')->twice()->andReturn($channel1, $channel2);
        $poolManager->shouldReceive('releaseChannel')->once()->with($channel1);

        $driverQueue = Mockery::mock(AMQPQueue::class);
        $driverQueue->shouldReceive('setName');
        $driverQueue->shouldReceive('setFlags');
        $driverQueue->shouldReceive('declareQueue')->andReturn(0);

        $rabbitQueue = TestableRabbitQueue::make($poolManager, 'orders')
            ->useQueueFactory(fn (): AMQPQueue => $driverQueue);

        $consumerQueue = Mockery::mock(AMQPQueue::class);
        $consumerQueue->shouldReceive('setName')->twice()->with('orders');

        $pollAttempt = 0;
        $consumer = null;
        $consumerQueue->shouldReceive('get')->with(AMQP_NOPARAM)->andReturnUsing(
            function () use (&$pollAttempt, &$consumer) {
                $pollAttempt++;
                if ($pollAttempt === 1) {
                    throw new RuntimeException('transient poll error');
                }

                $consumer->shouldQuit = true;

                return false;
            }
        );

        $manager = Mockery::mock(QueueManager::class);
        $manager->shouldReceive('connection')->with('rabbitmq')->andReturn($rabbitQueue);

        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch');
        $events->shouldReceive('until')->andReturn(true);

        $reported = [];
        $exceptions = Mockery::mock(ExceptionHandler::class);
        $exceptions->shouldReceive('report')->andReturnUsing(function (\Throwable $e) use (&$reported): void {
            $reported[] = $e->getMessage();
        });

        $consumer = new class($manager, $events, $exceptions, fn (): bool => false, $consumerQueue) extends Consumer
        {
            public function __construct(
                QueueManager $manager,
                Dispatcher $events,
                ExceptionHandler $exceptions,
                callable $isDownForMaintenance,
                private AMQPQueue $queueDouble,
            ) {
                parent::__construct($manager, $events, $exceptions, $isDownForMaintenance);
            }

            protected function newAmqpQueue(AMQPChannel $channel): AMQPQueue
            {
                return $this->queueDouble;
            }
        };
        $consumer->setContainer(Container::getInstance());
        $consumer->setCache(new CacheRepository(new ArrayStore));

        $options = new WorkerOptions;
        $options->sleep = 0;

        $status = $consumer->daemon('rabbitmq', 'orders', $options);

        $this->assertSame(0, $status);
        $this->assertSame(2, $pollAttempt);
        $this->assertSame(['transient poll error'], $reported);
    }

    public function testConsumeModeFallsBackToPollWhenStopWhenEmptyRequestedAndLogsWarning(): void
    {
        $conn = Mockery::mock(AMQPConnection::class);
        $conn->shouldReceive('isConnected')->andReturn(true);

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('isConnected')->andReturn(true);
        $channel->shouldReceive('getConnection')->andReturn($conn);

        $poolManager = Mockery::mock(PoolManager::class);
        $poolManager->shouldReceive('getChannel')->andReturn($channel);

        $driverQueue = Mockery::mock(AMQPQueue::class);
        $driverQueue->shouldReceive('setName');
        $driverQueue->shouldReceive('setFlags');
        $driverQueue->shouldReceive('declareQueue')->andReturn(0);

        $rabbitQueue = TestableRabbitQueue::make($poolManager, 'orders')
            ->useQueueFactory(fn (): AMQPQueue => $driverQueue);

        $consumerQueue = Mockery::mock(AMQPQueue::class);
        $consumerQueue->shouldReceive('setName')->once()->with('orders');
        $consumerQueue->shouldReceive('get')->once()->with(AMQP_NOPARAM)->andReturn(false);

        $manager = Mockery::mock(QueueManager::class);
        $manager->shouldReceive('connection')->with('rabbitmq')->andReturn($rabbitQueue);

        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch');
        $events->shouldReceive('until')->andReturn(true);

        $log = Mockery::mock();
        $log->shouldReceive('warning')->once();
        Container::getInstance()->instance('log', $log);

        $consumer = new class($manager, $events, Mockery::mock(ExceptionHandler::class), fn (): bool => false, $consumerQueue) extends Consumer
        {
            public function __construct(
                QueueManager $manager,
                Dispatcher $events,
                ExceptionHandler $exceptions,
                callable $isDownForMaintenance,
                private AMQPQueue $queueDouble,
            ) {
                parent::__construct($manager, $events, $exceptions, $isDownForMaintenance);
            }

            protected function newAmqpQueue(AMQPChannel $channel): AMQPQueue
            {
                return $this->queueDouble;
            }
        };
        $consumer->setContainer(Container::getInstance());
        $consumer->setCache(new CacheRepository(new ArrayStore));
        $consumer->setConsumeMode('consume');

        $options = new WorkerOptions;
        $options->sleep = 0;
        $options->stopWhenEmpty = true;

        $status = $consumer->daemon('rabbitmq', 'orders', $options);

        $this->assertSame(0, $status);
    }

    public function testConsumeModeProcessesEnvelopeHandlesPauseResumeAndQueueException(): void
    {
        Container::getInstance()->make('config')->set('queue.connections.rabbitmq.options.queue.qos', [
            'prefetch_count' => 2,
            'prefetch_size' => 1024,
        ]);

        $conn = Mockery::mock(AMQPConnection::class);
        $conn->shouldReceive('isConnected')->andReturn(true);

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('isConnected')->andReturn(true);
        $channel->shouldReceive('getConnection')->andReturn($conn);
        $channel->shouldReceive('setPrefetchCount')->twice()->with(2);
        $channel->shouldReceive('setPrefetchSize')->twice()->with(1024);

        $poolManager = Mockery::mock(PoolManager::class);
        $poolManager->shouldReceive('getChannel')->andReturn($channel);
        $poolManager->shouldReceive('markChannelDirty')->twice()->with($channel);
        $poolManager->shouldReceive('releaseChannel')->atLeast()->once()->with($channel);

        $driverQueue = Mockery::mock(AMQPQueue::class);
        $driverQueue->shouldReceive('setName');
        $driverQueue->shouldReceive('setFlags');
        $driverQueue->shouldReceive('declareQueue')->andReturn(0);

        $rabbitQueue = TestableRabbitQueue::make($poolManager, 'orders')
            ->useQueueFactory(fn (): AMQPQueue => $driverQueue);

        $envelope1 = Mockery::mock(AMQPEnvelope::class);
        $envelope1->shouldReceive('getBody')->andReturn('{"id":"1"}');
        $envelope1->shouldReceive('getCorrelationId')->andReturn('1');
        $envelope1->shouldReceive('getHeaders')->andReturn([]);
        $envelope1->shouldReceive('getDeliveryTag')->andReturn(10);

        $envelope2 = Mockery::mock(AMQPEnvelope::class);
        $envelope2->shouldReceive('getBody')->andReturn('{"id":"2"}');
        $envelope2->shouldReceive('getCorrelationId')->andReturn('2');
        $envelope2->shouldReceive('getHeaders')->andReturn([]);
        $envelope2->shouldReceive('getDeliveryTag')->andReturn(11);

        $consumerQueue = Mockery::mock(AMQPQueue::class);
        $consumerQueue->shouldReceive('setName')->twice()->with('orders');
        $consumerQueue->shouldReceive('reject')->once()->with(10, AMQP_REQUEUE);

        $consumePass = 0;
        $consumerQueue->shouldReceive('consume')->twice()->andReturnUsing(
            function (callable $callback) use (&$consumePass, $envelope1, $envelope2): void {
                $consumePass++;
                if ($consumePass === 1) {
                    // First delivery while paused: requeues and exits consume() cleanly.
                    $this->assertFalse($callback($envelope1));

                    return;
                }

                // Second pass after unpausing: process envelope2, then simulate AMQPQueueException.
                $callback($envelope2);

                throw new AMQPQueueException('read timeout');
            }
        );

        $manager = Mockery::mock(QueueManager::class);
        $manager->shouldReceive('connection')->with('rabbitmq')->andReturn($rabbitQueue);

        $loopingCall = 0;
        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch');
        $events->shouldReceive('until')->andReturnUsing(function () use (&$loopingCall): bool {
            $loopingCall++;

            // 1st call: outer while(!daemonShouldRun) -> true (enters consume pass 1);
            // 2nd call: inside consume pass 1 callback -> false (requeues & pauses);
            // 3rd+ calls: pause check & consume pass 2 -> true (resumes & runs job).
            return $loopingCall !== 2;
        });

        $reported = [];
        $exceptions = Mockery::mock(ExceptionHandler::class);
        $exceptions->shouldReceive('report')->andReturnUsing(function (\Throwable $e) use (&$reported): void {
            $reported[] = $e->getMessage();
        });

        $consumer = new class($manager, $events, $exceptions, fn (): bool => false, $consumerQueue) extends Consumer
        {
            public bool $ranResetScope = false;

            /** @var list<mixed> */
            public array $processed = [];

            public function __construct(
                QueueManager $manager,
                Dispatcher $events,
                ExceptionHandler $exceptions,
                callable $isDownForMaintenance,
                private AMQPQueue $queueDouble,
            ) {
                parent::__construct($manager, $events, $exceptions, $isDownForMaintenance);
                $this->resetScope = function (): void {
                    $this->ranResetScope = true;
                };
            }

            protected function newAmqpQueue(AMQPChannel $channel): AMQPQueue
            {
                return $this->queueDouble;
            }

            public function process($connectionName, $job, WorkerOptions $options): void
            {
                $this->shouldQuit = true;
                $this->processed[] = $job;
            }
        };

        $consumer->setContainer(Container::getInstance());
        $consumer->setCache(new CacheRepository(new ArrayStore));
        $consumer->setConsumeMode('consume');
        $consumer->setConsumerTag('my-tag');

        $options = new WorkerOptions;
        $options->sleep = 0;
        $options->rest = 0;

        $status = $consumer->daemon('rabbitmq', 'orders', $options);

        $this->assertSame(0, $status);
        $this->assertTrue($consumer->ranResetScope);
        $this->assertCount(1, $consumer->processed);
        $this->assertInstanceOf(RabbitMQJob::class, $consumer->processed[0]);
        $this->assertSame(['read timeout'], $reported);
        $this->assertSame(2, $consumePass);
    }

    public function testConsumeModeRecoversFromTransientQueueExceptionWithoutExiting(): void
    {
        $conn = Mockery::mock(AMQPConnection::class);
        $conn->shouldReceive('isConnected')->andReturn(true);

        $channel1 = Mockery::mock(AMQPChannel::class);
        $channel1->shouldReceive('isConnected')->andReturn(true);
        $channel1->shouldReceive('getConnection')->andReturn($conn);
        $channel1->shouldReceive('setPrefetchCount')->once()->with(1);

        $channel2 = Mockery::mock(AMQPChannel::class);
        $channel2->shouldReceive('isConnected')->andReturn(true);
        $channel2->shouldReceive('getConnection')->andReturn($conn);
        $channel2->shouldReceive('setPrefetchCount')->once()->with(1);

        $poolManager = Mockery::mock(PoolManager::class);
        $poolManager->shouldReceive('getChannel')->twice()->andReturn($channel1, $channel2);
        $poolManager->shouldReceive('markChannelDirty')->twice();
        $poolManager->shouldReceive('releaseChannel')->once()->with($channel1);

        $driverQueue = Mockery::mock(AMQPQueue::class);
        $driverQueue->shouldReceive('setName');
        $driverQueue->shouldReceive('setFlags');
        $driverQueue->shouldReceive('declareQueue')->andReturn(0);

        $rabbitQueue = TestableRabbitQueue::make($poolManager, 'orders')
            ->useQueueFactory(fn (): AMQPQueue => $driverQueue);

        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getBody')->andReturn('{"id":"recovered"}');
        $envelope->shouldReceive('getCorrelationId')->andReturn('recovered');
        $envelope->shouldReceive('getHeaders')->andReturn([]);
        $envelope->shouldReceive('getDeliveryTag')->andReturn(1);

        $consumerQueue1 = Mockery::mock(AMQPQueue::class);
        $consumerQueue1->shouldReceive('setName')->once()->with('orders');
        $consumerQueue1->shouldReceive('consume')->once()->andThrow(new AMQPQueueException('read timeout'));

        $consumerQueue2 = Mockery::mock(AMQPQueue::class);
        $consumerQueue2->shouldReceive('setName')->once()->with('orders');
        $consumerQueue2->shouldReceive('consume')->once()->andReturnUsing(
            function (callable $callback) use ($envelope): void {
                $this->assertFalse($callback($envelope));
            }
        );

        $manager = Mockery::mock(QueueManager::class);
        $manager->shouldReceive('connection')->with('rabbitmq')->andReturn($rabbitQueue);

        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch');
        $events->shouldReceive('until')->andReturn(true);

        $reported = [];
        $exceptions = Mockery::mock(ExceptionHandler::class);
        $exceptions->shouldReceive('report')->andReturnUsing(function (\Throwable $e) use (&$reported): void {
            $reported[] = $e->getMessage();
        });

        $queueCalls = 0;
        $consumer = new class($manager, $events, $exceptions, fn (): bool => false, $consumerQueue1, $consumerQueue2, $queueCalls) extends Consumer
        {
            /** @var list<mixed> */
            public array $processed = [];

            public function __construct(
                QueueManager $manager,
                Dispatcher $events,
                ExceptionHandler $exceptions,
                callable $isDownForMaintenance,
                private AMQPQueue $queue1,
                private AMQPQueue $queue2,
                private int &$queueCalls,
            ) {
                parent::__construct($manager, $events, $exceptions, $isDownForMaintenance);
            }

            protected function newAmqpQueue(AMQPChannel $channel): AMQPQueue
            {
                $this->queueCalls++;

                return $this->queueCalls === 1 ? $this->queue1 : $this->queue2;
            }

            public function process($connectionName, $job, WorkerOptions $options): void
            {
                $this->shouldQuit = true;
                $this->processed[] = $job;
            }
        };

        $consumer->setContainer(Container::getInstance());
        $consumer->setCache(new CacheRepository(new ArrayStore));
        $consumer->setConsumeMode('consume');

        $options = new WorkerOptions;
        $options->sleep = 0;
        $options->rest = 0;

        $status = $consumer->daemon('rabbitmq', 'orders', $options);

        $this->assertSame(0, $status);
        $this->assertSame(['read timeout'], $reported);
        $this->assertCount(1, $consumer->processed);
    }
}
