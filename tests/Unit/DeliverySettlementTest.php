<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit;

use AMQPChannel;
use AMQPChannelException;
use AMQPConnection;
use AMQPConnectionException;
use AMQPEnvelope;
use iamfarhad\LaravelRabbitMQ\Connection\PoolManager;
use iamfarhad\LaravelRabbitMQ\Exceptions\SettlementException;
use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use iamfarhad\LaravelRabbitMQ\Tests\Doubles\TestableRabbitQueue;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use ReflectionProperty;

/**
 * Settlement failures must be observable (issues #31, #33): a released channel
 * proves nothing about whether the broker actually settled the delivery, so
 * ack()/reject() must never return normally when the settlement did not reach
 * RabbitMQ. Deliveries are also never retried on a replacement channel, because
 * a delivery tag is scoped to the channel that delivered the message.
 *
 * AMQPQueue creation is redirected through TestableRabbitQueue's factory seam,
 * so these run with the real ext-amqp loaded.
 */
class DeliverySettlementTest extends UnitTestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testSuccessfulAckSettlesTheDeliveryExactlyOnceOnTheDeliveringChannel(): void
    {
        $amqpQueue = $this->amqpQueueDouble();
        $amqpQueue->shouldReceive('setName')->once()->with('orders');
        $amqpQueue->shouldReceive('ack')->once()->with(42);

        $queue = $this->queueWithLiveChannel($channel);

        $queue->ack($this->job(deliveryTag: 42, queueName: 'orders'));

        $this->assertSame($channel, $this->cachedChannel($queue));
    }

    public function testSuccessfulRejectDiscardsWithoutRequeueByDefault(): void
    {
        $amqpQueue = $this->amqpQueueDouble();
        $amqpQueue->shouldReceive('setName')->once();
        $amqpQueue->shouldReceive('reject')->once()->with(7, AMQP_NOPARAM);

        $queue = $this->queueWithLiveChannel($channel);

        $queue->reject($this->job(deliveryTag: 7));

        $this->assertSame($channel, $this->cachedChannel($queue));
    }

    public function testSuccessfulRejectPreservesRequeueSemantics(): void
    {
        $amqpQueue = $this->amqpQueueDouble();
        $amqpQueue->shouldReceive('setName')->once();
        $amqpQueue->shouldReceive('reject')->once()->with(7, AMQP_REQUEUE);

        $queue = $this->queueWithLiveChannel($channel);

        $queue->reject($this->job(deliveryTag: 7), true);

        $this->assertSame($channel, $this->cachedChannel($queue));
    }

    public function testAckReportsChannelExceptionAndReleasesTheDeliveringChannel(): void
    {
        $this->assertSettlementFailureIsReported(
            'ack',
            new AMQPChannelException('PRECONDITION_FAILED - unknown delivery tag 42', 406)
        );
    }

    public function testAckReportsConnectionExceptionAndReleasesTheDeliveringChannel(): void
    {
        $this->assertSettlementFailureIsReported(
            'ack',
            new AMQPConnectionException('Library error: connection closed')
        );
    }

    public function testRejectReportsChannelExceptionAndReleasesTheDeliveringChannel(): void
    {
        $this->assertSettlementFailureIsReported(
            'reject',
            new AMQPChannelException('PRECONDITION_FAILED - unknown delivery tag 42', 406)
        );
    }

    public function testRejectReportsConnectionExceptionAndReleasesTheDeliveringChannel(): void
    {
        $this->assertSettlementFailureIsReported(
            'reject',
            new AMQPConnectionException('Library error: connection closed')
        );
    }

    public function testFailedSettlementKeepsTheOriginalAmqpFailureAndItsMessageReachable(): void
    {
        // Laravel's lost-connection detection only inspects the outermost
        // message, so the original text has to survive the wrapping.
        $original = new AMQPConnectionException('Socket error: Lost connection to broker');

        $amqpQueue = $this->amqpQueueDouble();
        $amqpQueue->shouldReceive('setName')->once();
        $amqpQueue->shouldReceive('ack')->once()->andThrow($original);

        $queue = $this->queueWithLiveChannel($channel, expectRelease: true);

        try {
            $queue->ack($this->job());
            $this->fail('ack() should report the settlement failure.');
        } catch (SettlementException $exception) {
            $this->assertSame($original, $exception->getPrevious());
            $this->assertStringContainsString('Lost connection to broker', $exception->getMessage());
        }
    }

    public function testSettlementWithoutADeliveryTagIsReportedAndNeverTouchesTheBroker(): void
    {
        // No AMQPQueue overload here: constructing one would fail loudly, which
        // proves no settlement was attempted.
        $queue = $this->queueWithLiveChannel($channel);

        $this->expectException(SettlementException::class);
        $this->expectExceptionMessage('carries no delivery tag');

        $queue->ack($this->job(deliveryTag: null));
    }

    public function testDeadChannelIsNeverReplacedToSettleADelivery(): void
    {
        $deadChannel = Mockery::mock(AMQPChannel::class);
        $deadChannel->shouldReceive('isConnected')->andReturn(false);

        $poolManager = Mockery::mock(PoolManager::class);
        // Exactly one getChannel(): the released dead channel must not be
        // replaced in order to settle a tag that only existed on it.
        $poolManager->shouldReceive('getChannel')->once()->andReturn($deadChannel);
        $poolManager->shouldReceive('releaseChannel')->once()->with($deadChannel);

        $queue = TestableRabbitQueue::make($poolManager, 'default');
        $queue->getChannel();

        $this->expectException(SettlementException::class);
        $this->expectExceptionMessage('no longer usable');

        $queue->reject($this->job());
    }

    private function assertSettlementFailureIsReported(string $operation, \Throwable $brokerFailure): void
    {
        $amqpQueue = $this->amqpQueueDouble();
        $amqpQueue->shouldReceive('setName')->once();
        $amqpQueue->shouldReceive($operation)->once()->andThrow($brokerFailure);

        $queue = $this->queueWithLiveChannel($channel, expectRelease: true);

        try {
            $queue->{$operation}($this->job());
            $this->fail(sprintf('%s() should report the settlement failure.', $operation));
        } catch (SettlementException $exception) {
            $this->assertStringContainsString(
                sprintf('Failed to %s delivery on queue [default]', $operation),
                $exception->getMessage()
            );
            $this->assertSame($brokerFailure, $exception->getPrevious());
        }

        // The unusable delivering channel is dropped, which is what lets the
        // broker redeliver the unresolved delivery.
        $this->assertNull($this->cachedChannel($queue));
    }

    /**
     * The AMQPQueue double the driver under test will be handed. Stored on the
     * instance so queueWithLiveChannel() can wire it into the factory seam.
     */
    private ?Mockery\MockInterface $amqpQueueDouble = null;

    private function amqpQueueDouble(): Mockery\MockInterface
    {
        return $this->amqpQueueDouble = Mockery::mock(\AMQPQueue::class);
    }

    private function queueWithLiveChannel(?AMQPChannel &$channel, bool $expectRelease = false): RabbitQueue
    {
        $connection = Mockery::mock(AMQPConnection::class);
        $connection->shouldReceive('isConnected')->andReturn(true);

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('isConnected')->andReturn(true);
        $channel->shouldReceive('getConnection')->andReturn($connection);

        $poolManager = Mockery::mock(PoolManager::class);
        $poolManager->shouldReceive('getChannel')->once()->andReturn($channel);

        if ($expectRelease) {
            $poolManager->shouldReceive('releaseChannel')->once()->with($channel);
        } else {
            $poolManager->shouldNotReceive('releaseChannel');
        }

        $queue = TestableRabbitQueue::make($poolManager, 'default');

        if ($this->amqpQueueDouble !== null) {
            $double = $this->amqpQueueDouble;
            $queue->useQueueFactory(fn (): \AMQPQueue => $double);
        }

        $queue->getChannel();

        return $queue;
    }

    private function job(?int $deliveryTag = 42, string $queueName = 'default'): RabbitMQJob
    {
        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getDeliveryTag')->andReturn($deliveryTag);

        $job = Mockery::mock(RabbitMQJob::class);
        $job->shouldReceive('getRabbitMQMessage')->andReturn($envelope);
        $job->shouldReceive('getQueue')->andReturn($queueName);

        return $job;
    }

    private function cachedChannel(RabbitQueue $queue): ?AMQPChannel
    {
        // $amqpChannel is private to RabbitQueue, so reflect on the declaring class.
        return (new ReflectionProperty(RabbitQueue::class, 'amqpChannel'))->getValue($queue);
    }
}
