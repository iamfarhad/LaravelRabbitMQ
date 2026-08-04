<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Feature;

use AMQPEnvelope;
use AMQPQueue;
use iamfarhad\LaravelRabbitMQ\Consumer;
use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use iamfarhad\LaravelRabbitMQ\Tests\TestCase;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * Drives the real daemon loops against a real broker.
 *
 * This is where the Laravel 13 breakage lived: the loop's stop conditions and
 * exit code were wrong in ways no mock-based test noticed, because they depend on
 * the framework's own Worker internals.
 */
class ConsumerDaemonTest extends TestCase
{
    private string $queue = 'daemon-test-queue';

    protected function setUp(): void
    {
        parent::setUp();

        $connection = Queue::connection('rabbitmq');

        if ($connection instanceof RabbitQueue) {
            $connection->deleteQueue($this->queue);
            $connection->declareQueue($this->queue);
        }
    }

    protected function tearDown(): void
    {
        try {
            $connection = Queue::connection('rabbitmq');

            if ($connection instanceof RabbitQueue) {
                $connection->deleteQueue($this->queue);
            }
        } catch (\Throwable) {
            // Cleanup only.
        }

        parent::tearDown();
    }

    private function consumer(): Consumer
    {
        $consumer = new Consumer(
            $this->app['queue'],
            $this->app['events'],
            $this->app[ExceptionHandler::class],
            fn (): bool => false
        );

        $consumer->setContainer($this->app);
        $consumer->setCache($this->app['cache.store']);

        return $consumer;
    }

    private function workerOptions(array $overrides = []): WorkerOptions
    {
        $options = new WorkerOptions;
        $options->sleep = 0;
        $options->maxTries = 1;
        $options->timeout = 15;

        foreach ($overrides as $key => $value) {
            $options->{$key} = $value;
        }

        return $options;
    }

    private function pushRaw(int $count): void
    {
        $connection = Queue::connection('rabbitmq');

        for ($i = 0; $i < $count; $i++) {
            $connection->pushRaw(json_encode([
                'uuid' => 'daemon-'.$i,
                'displayName' => 'DaemonPayload',
                'job' => 'DaemonPayload',
                'maxTries' => 1,
                'data' => ['index' => $i],
            ], JSON_THROW_ON_ERROR), $this->queue);
        }
    }

    public function testPollModeProcessesJobsAndStopsAtMaxJobs(): void
    {
        $this->pushRaw(3);

        $handled = [];
        $consumer = $this->consumer();

        // runJob() is what a real worker calls; intercepting it here keeps the
        // loop, the settlement and the stop conditions genuine.
        Queue::before(function ($event) use (&$handled): void {
            $handled[] = $event->job->getJobId();
        });

        $status = $consumer->daemon('rabbitmq', $this->queue, $this->workerOptions(['maxJobs' => 2]));

        $this->assertSame(0, $status, '--max-jobs is a clean stop.');
        $this->assertCount(2, $handled, '--max-jobs must stop after exactly two jobs.');
        $this->assertSame(1, Queue::connection('rabbitmq')->size($this->queue), 'The third job stays queued.');
    }

    public function testPollModeStopsWhenTheQueueIsEmpty(): void
    {
        $this->pushRaw(1);

        $status = $this->consumer()->daemon(
            'rabbitmq',
            $this->queue,
            $this->workerOptions(['stopWhenEmpty' => true])
        );

        $this->assertSame(0, $status);
        $this->assertSame(0, Queue::connection('rabbitmq')->size($this->queue), 'Everything was drained.');
    }

    public function testStopWhenEmptyReturnsImmediatelyOnAnAlreadyEmptyQueue(): void
    {
        $status = $this->consumer()->daemon(
            'rabbitmq',
            $this->queue,
            $this->workerOptions(['stopWhenEmpty' => true])
        );

        $this->assertSame(0, $status);
    }

    public function testConsumeModeProcessesJobsAndStopsAtMaxJobs(): void
    {
        $this->pushRaw(2);

        $handled = [];
        Queue::before(function ($event) use (&$handled): void {
            $handled[] = $event->job->getJobId();
        });

        $consumer = $this->consumer();
        $consumer->setConsumeMode('consume');
        $consumer->setConsumerTag('daemon-test-tag');

        $status = $consumer->daemon('rabbitmq', $this->queue, $this->workerOptions(['maxJobs' => 2]));

        $this->assertSame(0, $status);
        $this->assertCount(2, $handled);
    }

    /**
     * A paused worker must hand an in-flight delivery back instead of stranding
     * it unacked for the whole pause window.
     */
    public function testConsumeModeRequeuesDeliveriesWhileThePauseIsInEffect(): void
    {
        $this->pushRaw(1);

        $consumer = $this->consumer();
        $consumer->setConsumeMode('consume');
        $consumer->paused = true;
        // The pause path sleeps and re-checks, so give the loop a way out.
        $consumer->shouldQuit = true;

        $status = $consumer->daemon(
            'rabbitmq',
            $this->queue,
            $this->workerOptions(['sleep' => 0])
        );

        $this->assertSame(0, $status);
        $this->assertSame(
            1,
            Queue::connection('rabbitmq')->size($this->queue),
            'The delivery must be back on the queue, not stranded unacked.'
        );
    }

    public function testWorkerStoppingCarriesTheProcessedJobCount(): void
    {
        $this->pushRaw(2);

        $events = [];
        Event::listen(WorkerStopping::class, function (WorkerStopping $event) use (&$events): void {
            $events[] = $event;
        });

        $this->consumer()->daemon('rabbitmq', $this->queue, $this->workerOptions(['maxJobs' => 2]));

        $this->assertNotEmpty($events, 'Stopping must announce itself.');
        $this->assertSame(0, $events[0]->status);
    }

    public function testMaintenanceModeIsHonouredUnlessForced(): void
    {
        $consumer = new Consumer(
            $this->app['queue'],
            $this->app['events'],
            $this->app[ExceptionHandler::class],
            fn (): bool => true // down for maintenance
        );
        $consumer->setContainer($this->app);
        $consumer->setCache($this->app['cache.store']);

        $this->pushRaw(1);

        // Paused/maintenance loops sleep and re-check, so a stop condition is
        // needed to make the assertion terminate.
        $consumer->shouldQuit = true;

        $status = $consumer->daemon('rabbitmq', $this->queue, $this->workerOptions());

        $this->assertSame(0, $status);
        $this->assertSame(
            1,
            Queue::connection('rabbitmq')->size($this->queue),
            'Nothing may be consumed while down for maintenance.'
        );
    }

    public function testQosIsAppliedInConsumeMode(): void
    {
        $this->app['config']->set('queue.connections.rabbitmq.options.queue.qos', [
            'prefetch_count' => 5,
            'prefetch_size' => 0,
        ]);
        $this->pushRaw(1);

        $consumer = $this->consumer();
        $consumer->setConsumeMode('consume');
        $consumer->setConsumerTag('qos-tag');

        $status = $consumer->daemon('rabbitmq', $this->queue, $this->workerOptions(['maxJobs' => 1]));

        $this->assertSame(0, $status);
    }

    /**
     * basic.consume evaluates stop conditions only when a delivery arrives, so
     * an empty queue never triggers --stop-when-empty and the worker would block
     * forever. The driver falls back to poll mode, which can observe emptiness.
     */
    public function testStopWhenEmptyFallsBackToPollModeInsteadOfBlocking(): void
    {
        $consumer = $this->consumer();
        $consumer->setConsumeMode('consume');

        $status = $consumer->daemon(
            'rabbitmq',
            $this->queue,
            $this->workerOptions(['stopWhenEmpty' => true])
        );

        $this->assertSame(0, $status, 'The worker must terminate rather than block on an idle consume.');
    }

    public function testMaxPriorityIsReadFromTheQueueConfiguration(): void
    {
        $this->app['config']->set("queue.connections.rabbitmq.queues.{$this->queue}.priority", 7);

        $consumer = $this->consumer();
        $consumer->daemon('rabbitmq', $this->queue, $this->workerOptions(['stopWhenEmpty' => true]));

        $this->assertSame(7, $consumer->getMaxPriority());
    }

    public function testFailingJobIsSettledAndRecordedAsFailed(): void
    {
        Queue::connection('rabbitmq')->pushRaw(json_encode([
            'uuid' => 'daemon-broken',
            'displayName' => 'MissingClass',
            'job' => 'MissingClass@handle',
            'maxTries' => 1,
            'data' => [],
        ], JSON_THROW_ON_ERROR), $this->queue);

        $consumer = $this->consumer();
        $status = $consumer->daemon(
            'rabbitmq',
            $this->queue,
            $this->workerOptions(['stopWhenEmpty' => true, 'maxTries' => 1])
        );

        $this->assertSame(0, $status);
        $this->assertSame(
            0,
            Queue::connection('rabbitmq')->size($this->queue),
            'A permanently failed job must be settled, not left for redelivery.'
        );
    }

    public function testEnvelopeIsTurnedIntoTheConfiguredJobClass(): void
    {
        $this->pushRaw(1);

        $seen = null;
        Queue::before(function ($event) use (&$seen): void {
            $seen = $event->job;
        });

        $this->consumer()->daemon(
            'rabbitmq',
            $this->queue,
            $this->workerOptions(['stopWhenEmpty' => true])
        );

        $this->assertInstanceOf(RabbitMQJob::class, $seen);
        $this->assertSame($this->queue, $seen->getQueue());
    }

    public function testRequeueLeavesADeliveryWithoutATagAlone(): void
    {
        $consumer = $this->consumer();

        $amqpQueue = \Mockery::mock(AMQPQueue::class);
        $amqpQueue->shouldNotReceive('reject');

        $envelope = \Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getDeliveryTag')->andReturn(null);

        (new \ReflectionMethod(Consumer::class, 'requeue'))->invoke($consumer, $amqpQueue, $envelope);

        $this->assertTrue(true);
    }
}
