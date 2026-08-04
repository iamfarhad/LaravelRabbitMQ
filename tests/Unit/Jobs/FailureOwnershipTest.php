<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Jobs;

use AMQPConnection;
use AMQPEnvelope;
use iamfarhad\LaravelRabbitMQ\Exceptions\SettlementException;
use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;

/**
 * A terminal failure must reach exactly one sink (issue #28): either the
 * broker's dead-letter routing or the package's own failure exchange, never
 * both.
 */
class FailureOwnershipTest extends UnitTestCase
{
    use MockeryPHPUnitIntegration;

    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        // No ext-amqp guard needed: the AMQP objects here are plain Mockery
        // doubles (never `overload:` instance mocks), so they work whether or
        // not the real extension is loaded.
        $this->container = new Container;
        $this->container->instance('config', new Repository);
        Container::setInstance($this->container);
    }

    public function testBrokerOwnershipRejectsWithoutPublishingASecondCopy(): void
    {
        $queue = $this->rabbitQueue();
        $queue->shouldReceive('reject')->once();
        $queue->shouldNotReceive('declareQueue');
        $queue->shouldNotReceive('pushRaw');

        $job = $this->makeJob($queue);
        $job->markAsFailed();

        $this->assertTrue($job->hasFailed());
    }

    public function testBrokerOwnershipIsTheDefaultEvenWithBrokerDeadLetterRoutingConfigured(): void
    {
        $this->config([
            'queue.connections.rabbitmq.reroute_failed' => true,
            'queue.connections.rabbitmq.failed_exchange' => 'app.failed',
        ]);

        $queue = $this->rabbitQueue();
        $queue->shouldReceive('reject')->once();
        $queue->shouldNotReceive('declareQueue');
        $queue->shouldNotReceive('pushRaw');

        $job = $this->makeJob($queue);
        $job->markAsFailed();

        $this->assertTrue($job->hasFailed());
    }

    public function testExchangeOwnershipRejectsAndPublishesExactlyOneCopy(): void
    {
        $this->config(['queue.connections.rabbitmq.failed.ownership' => 'exchange']);

        $queue = $this->rabbitQueue();
        $queue->shouldReceive('reject')->once();
        $queue->shouldReceive('declareQueue')->once()->with('failed_messages');
        $queue->shouldReceive('pushRaw')
            ->once()
            ->with('{"job":"TestJob"}', 'failed_messages', ['exchange' => '']);

        $job = $this->makeJob($queue);
        $job->markAsFailed();

        $this->assertTrue($job->hasFailed());
    }

    public function testExchangeOwnershipHonoursTheConfiguredFailureDestination(): void
    {
        $this->config([
            'queue.connections.rabbitmq.failed.ownership' => 'exchange',
            'queue.connections.rabbitmq.failed.exchange' => 'app_failed_messages',
        ]);

        $queue = $this->rabbitQueue();
        $queue->shouldReceive('reject')->once();
        $queue->shouldReceive('declareQueue')->once()->with('app_failed_messages');
        $queue->shouldReceive('pushRaw')->once()->with(Mockery::any(), 'app_failed_messages', ['exchange' => '']);

        $job = $this->makeJob($queue);
        $job->markAsFailed();

        $this->assertTrue($job->hasFailed());
    }

    public function testUnknownOwnershipModeFallsBackToASingleBrokerOwnedSink(): void
    {
        $this->config(['queue.connections.rabbitmq.failed.ownership' => 'not-a-mode']);

        $queue = $this->rabbitQueue();
        $queue->shouldReceive('reject')->once();
        $queue->shouldNotReceive('declareQueue');
        $queue->shouldNotReceive('pushRaw');

        $job = $this->makeJob($queue);
        $job->markAsFailed();

        $this->assertTrue($job->hasFailed());
    }

    public function testOwnershipIsReadFromTheJobsOwnConnection(): void
    {
        $this->config([
            'queue.connections.rabbitmq.failed.ownership' => 'broker',
            'queue.connections.rabbitmq-secondary.failed.ownership' => 'exchange',
            'queue.connections.rabbitmq-secondary.failed.exchange' => 'secondary_failed',
        ]);

        $queue = $this->rabbitQueue();
        $queue->shouldReceive('reject')->once();
        $queue->shouldReceive('declareQueue')->once()->with('secondary_failed');
        $queue->shouldReceive('pushRaw')->once()->with(Mockery::any(), 'secondary_failed', ['exchange' => '']);

        $job = $this->makeJob($queue, connectionName: 'rabbitmq-secondary');
        $job->markAsFailed();

        $this->assertTrue($job->hasFailed());
    }

    public function testAMessageAlreadyInTheFailureQueueIsNotCopiedAgain(): void
    {
        $this->config(['queue.connections.rabbitmq.failed.ownership' => 'exchange']);

        $queue = $this->rabbitQueue();
        $queue->shouldReceive('reject')->once();
        $queue->shouldNotReceive('declareQueue');
        $queue->shouldNotReceive('pushRaw');

        // Default-exchange deliveries carry an empty exchange name, so only the
        // queue identifies the message as already living in the failure sink.
        $job = $this->makeJob($queue, queueName: 'failed_messages');
        $job->markAsFailed();

        $this->assertTrue($job->hasFailed());
    }

    public function testAMessageDeliveredThroughTheFailureExchangeIsNotCopiedAgain(): void
    {
        $this->config(['queue.connections.rabbitmq.failed.ownership' => 'exchange']);

        $queue = $this->rabbitQueue();
        $queue->shouldReceive('reject')->once();
        $queue->shouldNotReceive('declareQueue');
        $queue->shouldNotReceive('pushRaw');

        $job = $this->makeJob($queue, exchangeName: 'failed_messages');
        $job->markAsFailed();

        $this->assertTrue($job->hasFailed());
    }

    public function testExchangeOwnershipIsSkippedWhenTheFailureDestinationIsEmpty(): void
    {
        $this->config([
            'queue.connections.rabbitmq.failed.ownership' => 'exchange',
            'queue.connections.rabbitmq.failed.exchange' => '',
        ]);

        $queue = $this->rabbitQueue();
        $queue->shouldReceive('reject')->once();
        $queue->shouldNotReceive('declareQueue');
        $queue->shouldNotReceive('pushRaw');

        $job = $this->makeJob($queue);
        $job->markAsFailed();

        $this->assertTrue($job->hasFailed());
    }

    public function testRetryableFailureIsReleasedWithoutTouchingAnyFailureSink(): void
    {
        $this->config(['queue.connections.rabbitmq.failed.ownership' => 'exchange']);

        $queue = $this->rabbitQueue();
        $queue->shouldReceive('laterRaw')->once();
        $queue->shouldReceive('ack')->once();
        $queue->shouldNotReceive('reject');
        $queue->shouldNotReceive('declareQueue');
        $queue->shouldNotReceive('pushRaw');

        $job = $this->makeJob($queue);
        $job->release(0);

        $this->assertFalse($job->hasFailed());
    }

    /**
     * Laravel calls markAsFailed() before the try/finally in Job::fail() that
     * dispatches JobFailed, so a settlement failure escaping here would suppress
     * the authoritative failed-job record entirely (issue #32).
     */
    public function testSettlementFailureIsReportedWithoutAbortingTheFailureLifecycle(): void
    {
        $reported = [];
        $this->container->instance(ExceptionHandler::class, $this->exceptionHandler($reported));

        $queue = $this->rabbitQueue();
        $queue->shouldReceive('reject')
            ->once()
            ->andThrow(SettlementException::channelUnusable('reject', 'default'));

        $job = $this->makeJob($queue);
        $job->markAsFailed();

        // The lifecycle continues — Laravel still gets to persist the record.
        $this->assertTrue($job->hasFailed());
        $this->assertCount(1, $reported);
        $this->assertInstanceOf(SettlementException::class, $reported[0]);
    }

    public function testExchangeOwnershipStillPublishesItsCopyWhenSettlementFails(): void
    {
        $this->config(['queue.connections.rabbitmq.failed.ownership' => 'exchange']);

        $reported = [];
        $this->container->instance(ExceptionHandler::class, $this->exceptionHandler($reported));

        $queue = $this->rabbitQueue();
        $queue->shouldReceive('reject')
            ->once()
            ->andThrow(SettlementException::brokerRefused('reject', 'default', new \RuntimeException('boom')));
        $queue->shouldReceive('declareQueue')->once()->with('failed_messages');
        $queue->shouldReceive('pushRaw')->once();

        $job = $this->makeJob($queue);
        $job->markAsFailed();

        $this->assertTrue($job->hasFailed());
        $this->assertCount(1, $reported);
    }

    public function testSettlementFailureIsSurvivableWithoutAnExceptionHandlerBound(): void
    {
        $queue = $this->rabbitQueue();
        $queue->shouldReceive('reject')
            ->once()
            ->andThrow(SettlementException::channelUnusable('reject', 'default'));

        $job = $this->makeJob($queue);
        $job->markAsFailed();

        $this->assertTrue($job->hasFailed());
    }

    private function exceptionHandler(array &$reported): ExceptionHandler&MockInterface
    {
        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->andReturnUsing(function ($e) use (&$reported): void {
            $reported[] = $e;
        });

        return $handler;
    }

    private function config(array $values): void
    {
        $repository = $this->container->make('config');

        foreach ($values as $key => $value) {
            $repository->set($key, $value);
        }
    }

    private function rabbitQueue(): RabbitQueue&MockInterface
    {
        $connection = Mockery::mock(AMQPConnection::class);
        $connection->shouldReceive('isConnected')->andReturn(true);

        $queue = Mockery::mock(RabbitQueue::class);
        $queue->shouldReceive('getConnection')->andReturn($connection);

        return $queue;
    }

    private function makeJob(
        RabbitQueue&MockInterface $queue,
        string $connectionName = 'rabbitmq',
        string $queueName = 'default',
        string $exchangeName = 'app',
    ): RabbitMQJob {
        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getBody')->andReturn('{"job":"TestJob"}');
        $envelope->shouldReceive('getHeaders')->andReturn([]);
        $envelope->shouldReceive('getExchangeName')->andReturn($exchangeName);

        return new RabbitMQJob($this->container, $queue, $envelope, $connectionName, $queueName);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }
}
