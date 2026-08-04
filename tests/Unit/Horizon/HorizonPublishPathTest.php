<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Horizon;

use AMQPChannel;
use AMQPConnection;
use iamfarhad\LaravelRabbitMQ\Connection\PoolManager;
use iamfarhad\LaravelRabbitMQ\Horizon\HorizonRabbitQueue;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Mockery;

/**
 * laterRaw() used to publish through $this->pushRaw() when the delay resolved to
 * zero. On the Horizon subclass that re-entered the pushRaw() override, wrapping
 * the payload in a Horizon envelope twice and dispatching JobPending/JobPushed a
 * second time for the same job.
 */
class HorizonPublishPathTest extends UnitTestCase
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
        parent::tearDown();
    }

    private function poolManager(): PoolManager
    {
        $connection = Mockery::mock(AMQPConnection::class);
        $connection->shouldReceive('isConnected')->andReturn(true);

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('isConnected')->andReturn(true);
        $channel->shouldReceive('getConnection')->andReturn($connection);

        $poolManager = Mockery::mock(PoolManager::class);
        $poolManager->shouldReceive('getChannel')->andReturn($channel);
        $poolManager->shouldReceive('markChannelDirty')->andReturnNull();

        return $poolManager;
    }

    /**
     * @return array{0: \AMQPQueue, 1: \AMQPExchange}
     */
    private function amqpDoubles(): array
    {
        $amqpQueue = Mockery::mock(\AMQPQueue::class);
        $amqpQueue->shouldReceive('setName');
        $amqpQueue->shouldReceive('setFlags');
        $amqpQueue->shouldReceive('getFlags')->andReturn(2);
        $amqpQueue->shouldReceive('setArguments');
        $amqpQueue->shouldReceive('declareQueue')->andReturn(0);
        $amqpQueue->shouldReceive('bind');

        $amqpExchange = Mockery::mock(\AMQPExchange::class);
        $amqpExchange->shouldReceive('setName');
        $amqpExchange->shouldReceive('setType');
        $amqpExchange->shouldReceive('setFlags');
        $amqpExchange->shouldReceive('declareExchange');
        $amqpExchange->shouldReceive('publish');

        return [$amqpQueue, $amqpExchange];
    }

    /**
     * Horizon queue that counts pushRaw() entries and creates its ext-amqp
     * objects from the supplied doubles.
     */
    private function countingHorizonQueue(\AMQPQueue $amqpQueue, \AMQPExchange $amqpExchange): HorizonRabbitQueue
    {
        return new class($this->poolManager(), 'default', $amqpQueue, $amqpExchange) extends HorizonRabbitQueue
        {
            public int $pushRawCalls = 0;

            public function __construct(
                PoolManager $poolManager,
                string $defaultQueue,
                private \AMQPQueue $queueDouble,
                private \AMQPExchange $exchangeDouble
            ) {
                parent::__construct($poolManager, $defaultQueue);
            }

            public function pushRaw($payload, $queue = null, array $options = []): ?string
            {
                $this->pushRawCalls++;

                return parent::pushRaw($payload, $queue, $options);
            }

            protected function newAmqpQueue(AMQPChannel $channel): \AMQPQueue
            {
                return $this->queueDouble;
            }

            protected function newAmqpExchange(AMQPChannel $channel): \AMQPExchange
            {
                return $this->exchangeDouble;
            }
        };
    }

    public function testZeroDelayDoesNotReEnterThePushRawOverride(): void
    {
        [$amqpQueue, $amqpExchange] = $this->amqpDoubles();

        $queue = $this->countingHorizonQueue($amqpQueue, $amqpExchange);

        $queue->laterRaw(0, '{"id":"a"}', 'orders');

        $this->assertSame(
            0,
            $queue->pushRawCalls,
            'laterRaw() must publish directly, not through the subclass pushRaw() hook.'
        );
    }

    public function testDirectPushRawStillGoesThroughTheOverride(): void
    {
        [$amqpQueue, $amqpExchange] = $this->amqpDoubles();

        $queue = $this->countingHorizonQueue($amqpQueue, $amqpExchange);

        $queue->pushRaw('{"id":"a"}', 'orders');

        $this->assertSame(1, $queue->pushRawCalls);
    }

    /**
     * The job behind a payload is only known for the publish it belongs to; a
     * leftover reference would tag an unrelated later raw push with it.
     */
    public function testLastPushedJobIsNotRetainedAfterPublishing(): void
    {
        [$amqpQueue, $amqpExchange] = $this->amqpDoubles();

        $queue = $this->countingHorizonQueue($amqpQueue, $amqpExchange);
        $queue->pushRaw('{"id":"a"}', 'orders');

        $property = new \ReflectionProperty(HorizonRabbitQueue::class, 'lastPushed');

        $this->assertNull($property->getValue($queue));
    }
}
