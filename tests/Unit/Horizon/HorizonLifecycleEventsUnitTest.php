<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Horizon;

use AMQPChannel;
use AMQPConnection;
use AMQPEnvelope;
use AMQPExchange;
use AMQPQueue;
use iamfarhad\LaravelRabbitMQ\Connection\PoolManager;
use iamfarhad\LaravelRabbitMQ\Connectors\RabbitMQConnector;
use iamfarhad\LaravelRabbitMQ\Consumer;
use iamfarhad\LaravelRabbitMQ\Horizon\HorizonRabbitQueue;
use iamfarhad\LaravelRabbitMQ\Horizon\Listeners\RabbitMQFailedEvent;
use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\WorkerOptions;
use Laravel\Horizon\Events\JobDeleted;
use Laravel\Horizon\Events\JobFailed as HorizonJobFailed;
use Laravel\Horizon\Events\JobPending;
use Laravel\Horizon\Events\JobPushed;
use Laravel\Horizon\Events\JobReleased;
use Laravel\Horizon\Events\JobReserved;
use Laravel\Horizon\JobPayload;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class HorizonLifecycleEventsUnitTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::defineHorizonStubs();

        $container = new Container;
        $container->instance('config', new ConfigRepository([
            'queue' => ['connections' => ['rabbitmq' => ['worker' => 'horizon']]],
        ]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        RabbitMQConnector::resetPoolManager();
        Container::setInstance(null);
        Mockery::close();
        parent::tearDown();
    }

    private static function defineHorizonStubs(): void
    {
        if (class_exists(JobPayload::class, false)) {
            return;
        }

        eval(<<<'PHP'
namespace Laravel\Horizon;

class JobPayload
{
    public string $value;

    public array $decoded = [];

    public function __construct(string $value)
    {
        $this->value = $value;
        $this->decoded = json_decode($value, true) ?: [];
    }

    public function prepare($job = null): self
    {
        $this->decoded['horizon_prepared'] = ($this->decoded['horizon_prepared'] ?? 0) + 1;
        $this->value = json_encode($this->decoded);

        return $this;
    }
}

namespace Laravel\Horizon\Events;

abstract class HorizonEvent
{
    public $connectionName;

    public $queue;

    public function connection($name): static
    {
        $this->connectionName = $name;

        return $this;
    }

    public function queue($queue): static
    {
        $this->queue = $queue;

        return $this;
    }
}

class JobPending extends HorizonEvent
{
    public function __construct(public $payload) {}
}

class JobPushed extends HorizonEvent
{
    public function __construct(public $payload) {}
}

class JobReserved extends HorizonEvent
{
    public function __construct(public $payload) {}
}

class JobReleased extends HorizonEvent
{
    public function __construct(public $payload, public $delay = 0) {}
}

class JobDeleted extends HorizonEvent
{
    public function __construct(public $job, public $payload) {}
}

class JobFailed extends HorizonEvent
{
    public function __construct(public $exception, public $job, public $payload) {}
}
PHP);
    }

    public function testConnectorSelectsHorizonAndFullLifecycleDispatchesEveryHorizonEvent(): void
    {
        $container = Container::getInstance();
        $dispatcher = new Dispatcher($container);
        $container->instance(DispatcherContract::class, $dispatcher);

        $dispatched = [];
        foreach ([JobPending::class, JobPushed::class, JobReserved::class, JobReleased::class, JobDeleted::class, HorizonJobFailed::class] as $eventClass) {
            $dispatcher->listen($eventClass, function (object $event) use (&$dispatched): void {
                $dispatched[] = get_class($event);
            });
        }

        $connector = new RabbitMQConnector($dispatcher);
        $connected = $connector->connect([
            'name' => 'rabbitmq',
            'worker' => 'horizon',
            'hosts' => ['host' => '127.0.0.1'],
            'pool' => ['lazy' => true, 'min_connections' => 0],
        ]);
        $this->assertInstanceOf(HorizonRabbitQueue::class, $connected);

        $conn = Mockery::mock(AMQPConnection::class);
        $conn->shouldReceive('isConnected')->andReturn(true);

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('isConnected')->andReturn(true);
        $channel->shouldReceive('getConnection')->andReturn($conn);

        $poolManager = Mockery::mock(PoolManager::class);
        $poolManager->shouldReceive('getChannel')->andReturn($channel);

        $publishedPayloads = [];
        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getBody')->andReturnUsing(function () use (&$publishedPayloads): string {
            return $publishedPayloads[0];
        });
        $envelope->shouldReceive('getCorrelationId')->andReturn('h-1');
        $envelope->shouldReceive('getHeaders')->andReturn([]);
        $envelope->shouldReceive('getDeliveryTag')->andReturn(1);

        $amqpQueue = Mockery::mock(AMQPQueue::class);
        $amqpQueue->shouldReceive('setName');
        $amqpQueue->shouldReceive('setFlags');
        $amqpQueue->shouldReceive('getFlags')->andReturn(2);
        $amqpQueue->shouldReceive('setArguments');
        $amqpQueue->shouldReceive('declareQueue')->andReturn(0);
        $amqpQueue->shouldReceive('get')->andReturn($envelope);
        $amqpQueue->shouldReceive('ack')->twice()->with(1);

        $amqpExchange = Mockery::mock(AMQPExchange::class);
        $amqpExchange->shouldReceive('setName');
        $amqpExchange->shouldReceive('publish')->andReturnUsing(function (string $payload) use (&$publishedPayloads): void {
            $publishedPayloads[] = $payload;
        });

        $queue = new class($poolManager, 'orders', $amqpQueue, $amqpExchange) extends HorizonRabbitQueue
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

        $queue->pushRaw('{"id":"h-1"}', 'orders');
        $job = $queue->pop('orders');
        $this->assertInstanceOf(RabbitMQJob::class, $job);

        // release() must fire JobReleased (not JobPending/JobPushed) and keep horizon_prepared=1.
        $job->release(0);
        $decodedReleased = json_decode($publishedPayloads[1], true);
        $this->assertSame(1, $decodedReleased['horizon_prepared']);

        // delete() on HorizonRabbitQueue fires JobDeleted via deleteReserved().
        $job2 = $queue->pop('orders');
        $this->assertInstanceOf(RabbitMQJob::class, $job2);
        $job2->delete();

        // RabbitMQFailedEvent translates Laravel JobFailed into Horizon JobFailed.
        (new RabbitMQFailedEvent($dispatcher))->handle(
            new JobFailed('rabbitmq', $job2, new \RuntimeException('boom'))
        );

        $this->assertSame([
            JobPending::class,
            JobPushed::class,
            JobReserved::class,
            JobReleased::class,
            JobReserved::class,
            JobDeleted::class,
            HorizonJobFailed::class,
        ], $dispatched);
    }

    public function testConsumerDispatchesJobReservedWhenUsingHorizonRabbitQueue(): void
    {
        $container = Container::getInstance();
        $dispatcher = new Dispatcher($container);
        $container->instance(DispatcherContract::class, $dispatcher);

        $reserved = [];
        $dispatcher->listen(JobReserved::class, function (JobReserved $event) use (&$reserved): void {
            $reserved[] = $event;
        });

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

        $horizonQueue = new class($poolManager, 'orders', $driverQueue) extends HorizonRabbitQueue
        {
            public function __construct(
                PoolManager $poolManager,
                string $defaultQueue,
                private AMQPQueue $queueDouble,
            ) {
                parent::__construct($poolManager, $defaultQueue);
            }

            protected function newAmqpQueue(AMQPChannel $channel): AMQPQueue
            {
                return $this->queueDouble;
            }
        };
        $horizonQueue->setContainer($container);

        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getBody')->andReturn('{"id":"c-1"}');
        $envelope->shouldReceive('getCorrelationId')->andReturn('c-1');
        $envelope->shouldReceive('getHeaders')->andReturn([]);

        $consumerQueue = Mockery::mock(AMQPQueue::class);
        $consumerQueue->shouldReceive('setName')->once()->with('orders');
        $consumerQueue->shouldReceive('get')->once()->with(AMQP_NOPARAM)->andReturn($envelope);

        $manager = Mockery::mock(QueueManager::class);
        $manager->shouldReceive('connection')->with('rabbitmq')->andReturn($horizonQueue);

        $consumer = new class($manager, $dispatcher, Mockery::mock(ExceptionHandler::class), fn (): bool => false, $consumerQueue) extends Consumer
        {
            public function __construct(
                QueueManager $manager,
                DispatcherContract $events,
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

            public function process($connectionName, $job, WorkerOptions $options): void
            {
                $this->shouldQuit = true;
            }
        };
        $consumer->setContainer($container);
        $consumer->setCache(new CacheRepository(new ArrayStore));

        $options = new WorkerOptions;
        $options->sleep = 0;

        $consumer->daemon('rabbitmq', 'orders', $options);

        $this->assertCount(1, $reserved);
        $this->assertSame('orders', $reserved[0]->queue);
        $this->assertSame('rabbitmq', $reserved[0]->connectionName);
    }

    public function testHorizonReleaseSupportsDateTimeInterfaceDateIntervalAndNonZeroIntDelays(): void
    {
        $container = Container::getInstance();
        $dispatcher = new Dispatcher($container);
        $container->instance(DispatcherContract::class, $dispatcher);

        $releasedEvents = [];
        $dispatcher->listen(JobReleased::class, function (JobReleased $event) use (&$releasedEvents): void {
            $releasedEvents[] = $event;
        });

        $conn = Mockery::mock(AMQPConnection::class);
        $conn->shouldReceive('isConnected')->andReturn(true);

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('isConnected')->andReturn(true);
        $channel->shouldReceive('getConnection')->andReturn($conn);

        $poolManager = Mockery::mock(PoolManager::class);
        $poolManager->shouldReceive('getChannel')->andReturn($channel);

        $declaredQueues = [];
        $amqpQueue = Mockery::mock(AMQPQueue::class);
        $amqpQueue->shouldReceive('setName')->andReturnUsing(function (string $name) use (&$declaredQueues): void {
            $declaredQueues[] = $name;
        });
        $amqpQueue->shouldReceive('setFlags');
        $amqpQueue->shouldReceive('getFlags')->andReturn(2);
        $amqpQueue->shouldReceive('setArguments');
        $amqpQueue->shouldReceive('declareQueue')->andReturn(0);
        $amqpQueue->shouldReceive('ack')->times(3)->with(1);

        $publishedTargets = [];
        $amqpExchange = Mockery::mock(AMQPExchange::class);
        $amqpExchange->shouldReceive('setName');
        $amqpExchange->shouldReceive('publish')->andReturnUsing(
            function (string $payload, string $routingKey) use (&$publishedTargets): void {
                $publishedTargets[] = $routingKey;
            }
        );

        $queue = new class($poolManager, 'orders', $amqpQueue, $amqpExchange) extends HorizonRabbitQueue
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

        $makeJob = function () use ($container, $queue): RabbitMQJob {
            $envelope = Mockery::mock(AMQPEnvelope::class);
            $envelope->shouldReceive('getBody')->andReturn('{"id":"h-delay","horizon_prepared":1}');
            $envelope->shouldReceive('getCorrelationId')->andReturn('h-delay');
            $envelope->shouldReceive('getHeaders')->andReturn([]);
            $envelope->shouldReceive('getDeliveryTag')->andReturn(1);

            return new RabbitMQJob($container, $queue, $envelope, 'rabbitmq', 'orders');
        };

        $makeJob()->release(new \DateTimeImmutable('+120 seconds'));
        $makeJob()->release(new \DateInterval('PT45S'));
        $makeJob()->release(15);

        $this->assertCount(3, $releasedEvents);
        $this->assertGreaterThanOrEqual(115, $releasedEvents[0]->delay);
        $this->assertGreaterThanOrEqual(40, $releasedEvents[1]->delay);
        $this->assertSame(15, $releasedEvents[2]->delay);

        // All three releases must route to delay queues, not immediate 'orders'.
        $this->assertCount(3, $publishedTargets);
        foreach ($publishedTargets as $target) {
            $this->assertStringStartsWith('orders.delay.', $target);
        }
    }
}
