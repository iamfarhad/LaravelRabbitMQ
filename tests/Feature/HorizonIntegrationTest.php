<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Feature;

use iamfarhad\LaravelRabbitMQ\Connectors\RabbitMQConnector;
use iamfarhad\LaravelRabbitMQ\Horizon\HorizonRabbitQueue;
use iamfarhad\LaravelRabbitMQ\Horizon\Listeners\RabbitMQFailedEvent;
use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use iamfarhad\LaravelRabbitMQ\Tests\TestCase;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Events\JobDeleted;
use Laravel\Horizon\Events\JobPending;
use Laravel\Horizon\Events\JobPushed;
use Laravel\Horizon\Events\JobReserved;
use Laravel\Horizon\JobPayload;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;

/**
 * Horizon integration against a real broker.
 *
 * The driver talks to Horizon entirely through `class_exists()` plus a small,
 * stable surface — `JobPayload::prepare()->value` and four events with a fluent
 * `connection()`/`queue()` — so that surface is stubbed here rather than pulling
 * in laravel/horizon and its Redis stack. That keeps the dependency matrix out of
 * it while still exercising the real code paths.
 *
 * Process isolation is required: defining the stubs globally would make
 * `shouldUseHorizonQueue()` true for every other test in the suite.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class HorizonIntegrationTest extends TestCase
{
    private string $queue = 'horizon-test-queue';

    protected function setUp(): void
    {
        parent::setUp();

        self::defineHorizonStubs();

        $connection = Queue::connection('rabbitmq');

        if ($connection instanceof RabbitQueue) {
            $connection->deleteQueue($this->queue);
        }
    }

    protected function tearDown(): void
    {
        try {
            Queue::connection('rabbitmq')->deleteQueue($this->queue);
        } catch (\Throwable) {
            // Cleanup only.
        }

        parent::tearDown();
    }

    /**
     * The minimum of Horizon the driver actually touches.
     */
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

    /** @var array<string, mixed> */
    public array $decoded = [];

    public function __construct(string $value)
    {
        $this->value = $value;
        $this->decoded = json_decode($value, true) ?: [];
    }

    public function prepare($job = null): self
    {
        // Horizon stamps identity onto the payload; recording that it ran is
        // enough to prove the driver prepares exactly once.
        $this->decoded['horizon_prepared'] = ($this->decoded['horizon_prepared'] ?? 0) + 1;
        $this->decoded['horizon_job'] = is_object($job) ? get_class($job) : $job;
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

    /**
     * Drop QueueManager's cached connections so the next resolution runs the
     * connector again. QueueManager exposes no forgetConnection().
     */
    private function forgetQueueConnections(): void
    {
        RabbitMQConnector::resetPoolManager();

        $connections = new \ReflectionProperty($this->app['queue'], 'connections');
        $connections->setValue($this->app['queue'], []);
    }

    private function horizonQueue(): HorizonRabbitQueue
    {
        config(['queue.connections.rabbitmq.worker' => 'horizon']);

        // Force a fresh resolution so the connector picks the Horizon subclass.
        $this->forgetQueueConnections();

        $connection = Queue::connection('rabbitmq');
        $this->assertInstanceOf(HorizonRabbitQueue::class, $connection);

        return $connection;
    }

    public function testConnectorSelectsTheHorizonQueueWhenHorizonIsPresent(): void
    {
        $this->assertInstanceOf(HorizonRabbitQueue::class, $this->horizonQueue());
    }

    public function testConnectorKeepsThePlainQueueWhenTheWorkerIsNotHorizon(): void
    {
        config(['queue.connections.rabbitmq.worker' => 'default']);
        $this->forgetQueueConnections();

        $connection = Queue::connection('rabbitmq');

        $this->assertInstanceOf(RabbitQueue::class, $connection);
        $this->assertNotInstanceOf(HorizonRabbitQueue::class, $connection);
    }

    public function testPushDispatchesPendingAndPushedExactlyOnce(): void
    {
        $queue = $this->horizonQueue();
        Event::fake([JobPending::class, JobPushed::class]);

        $queue->pushRaw('{"id":"h1"}', $this->queue);

        Event::assertDispatchedTimes(JobPending::class, 1);
        Event::assertDispatchedTimes(JobPushed::class, 1);
    }

    /**
     * The regression: laterRaw() used to publish through the overridden pushRaw()
     * when the delay resolved to zero, wrapping the payload twice and dispatching
     * both events a second time.
     */
    public function testZeroDelayDispatchesEachEventOnceAndPreparesOnce(): void
    {
        $queue = $this->horizonQueue();
        Event::fake([JobPending::class, JobPushed::class]);

        $queue->laterRaw(0, '{"id":"h2"}', $this->queue);

        Event::assertDispatchedTimes(JobPending::class, 1);
        Event::assertDispatchedTimes(JobPushed::class, 1);

        $job = $queue->pop($this->queue);
        $this->assertNotNull($job);

        $decoded = json_decode($job->getRawBody(), true);
        $this->assertSame(1, $decoded['horizon_prepared'], 'The payload must be prepared exactly once.');

        $job->delete();
    }

    public function testDelayedPushStillDispatchesBothEvents(): void
    {
        $queue = $this->horizonQueue();
        Event::fake([JobPending::class, JobPushed::class]);

        $queue->laterRaw(2, '{"id":"h3"}', $this->queue);

        Event::assertDispatchedTimes(JobPending::class, 1);
        Event::assertDispatchedTimes(JobPushed::class, 1);
    }

    public function testPopDispatchesJobReserved(): void
    {
        $queue = $this->horizonQueue();
        $queue->pushRaw('{"id":"h4"}', $this->queue);

        Event::fake([JobReserved::class]);

        $job = $queue->pop($this->queue);
        $this->assertInstanceOf(RabbitMQJob::class, $job);

        Event::assertDispatchedTimes(JobReserved::class, 1);

        $job->delete();
    }

    public function testPopDispatchesNothingForAnEmptyQueue(): void
    {
        $queue = $this->horizonQueue();

        Event::fake([JobReserved::class]);

        $this->assertNull($queue->pop($this->queue));

        Event::assertNotDispatched(JobReserved::class);
    }

    public function testDeleteReservedDispatchesJobDeletedAndSettlesTheDelivery(): void
    {
        $queue = $this->horizonQueue();
        $queue->pushRaw('{"id":"h5"}', $this->queue);

        $job = $queue->pop($this->queue);
        $this->assertInstanceOf(RabbitMQJob::class, $job);

        Event::fake([JobDeleted::class]);

        $queue->deleteReserved($this->queue, $job);

        Event::assertDispatchedTimes(JobDeleted::class, 1);
        $this->assertTrue($job->isDeletedOrReleased(), 'The delivery must be settled, not only announced.');
        $this->assertSame(0, $queue->size($this->queue));
    }

    public function testDeleteReservedIgnoresAForeignJob(): void
    {
        $queue = $this->horizonQueue();

        Event::fake([JobDeleted::class]);

        $queue->deleteReserved($this->queue, \Mockery::mock(Job::class));

        Event::assertNotDispatched(JobDeleted::class);
    }

    public function testDeleteReservedDoesNotSettleTwice(): void
    {
        $queue = $this->horizonQueue();
        $queue->pushRaw('{"id":"h6"}', $this->queue);

        $job = $queue->pop($this->queue);
        $this->assertInstanceOf(RabbitMQJob::class, $job);

        $job->delete();
        $queue->deleteReserved($this->queue, $job);

        $this->assertSame(0, $queue->size($this->queue), 'A second settle would be a broker error.');
    }

    public function testReadyNowReportsTheQueueDepth(): void
    {
        $queue = $this->horizonQueue();
        $queue->pushRaw('{"id":"h7"}', $this->queue);
        $queue->pushRaw('{"id":"h8"}', $this->queue);

        // Broker counts settle asynchronously, so poll rather than reading once.
        $depth = 0;
        $deadline = microtime(true) + 5.0;

        do {
            $depth = $queue->readyNow($this->queue);
            $depth === 2 || usleep(50_000);
        } while ($depth !== 2 && microtime(true) < $deadline);

        $this->assertSame(2, $depth);
    }

    public function testFailedEventListenerTranslatesToHorizonsEvent(): void
    {
        $queue = $this->horizonQueue();
        $queue->pushRaw('{"id":"h9"}', $this->queue);

        $job = $queue->pop($this->queue);
        $this->assertInstanceOf(RabbitMQJob::class, $job);

        $dispatched = [];
        $this->app['events']->listen(
            \Laravel\Horizon\Events\JobFailed::class,
            function ($event) use (&$dispatched): void {
                $dispatched[] = $event;
            }
        );

        (new RabbitMQFailedEvent($this->app['events']))->handle(
            new JobFailed('rabbitmq', $job, new RuntimeException('boom'))
        );

        $this->assertCount(1, $dispatched);
        $this->assertSame('rabbitmq', $dispatched[0]->connectionName);
        $this->assertSame($this->queue, $dispatched[0]->queue);

        $job->delete();
    }

    public function testFailedEventListenerIgnoresForeignJobs(): void
    {
        $this->horizonQueue();

        $dispatched = [];
        $this->app['events']->listen(
            \Laravel\Horizon\Events\JobFailed::class,
            function ($event) use (&$dispatched): void {
                $dispatched[] = $event;
            }
        );

        (new RabbitMQFailedEvent($this->app['events']))->handle(
            new JobFailed('database', \Mockery::mock(Job::class), new RuntimeException('boom'))
        );

        $this->assertSame([], $dispatched);
    }

    public function testPushGoesThroughTheLaravelDispatchPathAndPreparesOnce(): void
    {
        $queue = $this->horizonQueue();
        $queue->setContainer($this->app);

        $queue->push('SomeJobName', ['a' => 1], $this->queue);

        $job = $queue->pop($this->queue);
        $this->assertNotNull($job);

        $decoded = json_decode($job->getRawBody(), true);
        $this->assertSame(1, $decoded['horizon_prepared']);
        $this->assertSame('SomeJobName', $decoded['horizon_job']);

        $job->delete();
    }
}
