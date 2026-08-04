<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit;

use AMQPEnvelope;
use AMQPQueue;
use iamfarhad\LaravelRabbitMQ\Consumer;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\QueueManager;
use Mockery;
use ReflectionMethod;

/**
 * In basic.consume mode the broker hands a delivery to the callback before the
 * worker decides whether it may run. Returning without settling that delivery
 * left it unacked for the whole maintenance/pause window — and with it up to
 * prefetch_count further messages — until the connection happened to drop.
 */
class ConsumeModeSettlementTest extends UnitTestCase
{
    private function consumer(): Consumer
    {
        return new Consumer(
            Mockery::mock(QueueManager::class),
            Mockery::mock(Dispatcher::class),
            Mockery::mock(ExceptionHandler::class),
            fn (): bool => false
        );
    }

    public function testUnprocessedDeliveryIsHandedBackToTheBroker(): void
    {
        $rejected = [];

        $amqpQueue = Mockery::mock(AMQPQueue::class);
        $amqpQueue->shouldReceive('reject')->andReturnUsing(
            function (int|string $tag, int $flags) use (&$rejected): void {
                $rejected[] = [$tag, $flags];
            }
        );

        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getDeliveryTag')->andReturn(42);

        $method = new ReflectionMethod(Consumer::class, 'requeue');
        $method->invoke($this->consumer(), $amqpQueue, $envelope);

        $this->assertSame([[42, AMQP_REQUEUE]], $rejected, 'The delivery must be requeued, not dropped.');
    }

    public function testDeliveryWithoutATagIsLeftAlone(): void
    {
        $amqpQueue = Mockery::mock(AMQPQueue::class);
        $amqpQueue->shouldNotReceive('reject');

        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getDeliveryTag')->andReturn(null);

        $method = new ReflectionMethod(Consumer::class, 'requeue');
        $method->invoke($this->consumer(), $amqpQueue, $envelope);

        $this->assertTrue(true, 'Nothing to settle, and nothing thrown.');
    }

    public function testSettlementFailureIsReportedRatherThanEscaping(): void
    {
        $exceptions = Mockery::mock(ExceptionHandler::class);
        $exceptions->shouldReceive('report')->once();

        $consumer = new Consumer(
            Mockery::mock(QueueManager::class),
            Mockery::mock(Dispatcher::class),
            $exceptions,
            fn (): bool => false
        );

        $amqpQueue = Mockery::mock(AMQPQueue::class);
        $amqpQueue->shouldReceive('reject')->andThrow(new \AMQPChannelException('channel closed'));

        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getDeliveryTag')->andReturn(7);

        $method = new ReflectionMethod(Consumer::class, 'requeue');
        $method->invoke($consumer, $amqpQueue, $envelope);

        $this->assertTrue(true, 'A failed requeue must not abort the consume loop.');
    }

    /**
     * basic.qos governs basic.consume deliveries only, so a prefetch above 1
     * parks messages behind the single job a worker can run at a time — where a
     * timeout or crash turns them into redeliveries.
     */
    public function testPrefetchDefaultsToOneAndMarksTheChannelDirty(): void
    {
        $channel = Mockery::mock(\AMQPChannel::class);
        $channel->shouldReceive('setPrefetchCount')->once()->with(1);
        $channel->shouldNotReceive('setPrefetchSize');

        $connection = Mockery::mock(RabbitQueue::class);
        $connection->shouldReceive('connectionConfig')->with('options.queue.qos', [])->andReturn([]);
        $connection->shouldReceive('markChannelDirty')->once();

        $consumer = $this->consumer();

        $channelProperty = new \ReflectionProperty($consumer, 'amqpChannel');
        $channelProperty->setValue($consumer, $channel);

        (new ReflectionMethod(Consumer::class, 'configureQos'))->invoke($consumer, $connection);

        $this->assertTrue(true);
    }
}
