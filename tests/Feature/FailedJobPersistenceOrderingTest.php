<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Feature;

use iamfarhad\LaravelRabbitMQ\Connectors\RabbitMQConnector;
use iamfarhad\LaravelRabbitMQ\Jobs\Listeners\SettleFailedDelivery;
use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use iamfarhad\LaravelRabbitMQ\Tests\TestCase;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/**
 * Regression coverage for issue #37: the authoritative `failed_jobs` record must
 * be persisted *before* the delivery is rejected, and a failed persistence must
 * leave the delivery eligible for redelivery instead of discarding it.
 *
 * The persistence step is modelled the way Laravel actually performs it — a
 * JobFailed listener registered before the package's settlement listener, which
 * is exactly the ordering `WorkCommand::listenForEvents()` produces.
 */
class FailedJobPersistenceOrderingTest extends TestCase
{
    private string $queue = 'failure-ordering-queue';

    /**
     * @var list<string>
     */
    private array $sequence = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sequence = [];
        SettleFailedDelivery::flushRegistrations();

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

    /**
     * Stand in for WorkCommand's failer listener: registered first, so it runs
     * before the package's settlement listener.
     */
    private function registerFailerListener(bool $shouldThrow = false): void
    {
        $this->app['events']->listen(JobFailed::class, function () use ($shouldThrow): void {
            $this->sequence[] = 'persisted';

            if ($shouldThrow) {
                throw new RuntimeException('failed-job provider is down');
            }
        });
    }

    /**
     * Record when the package's own settlement listener runs, without changing
     * its position: markAsFailed() appends it, so it is always last.
     */
    private function observeSettlement(): void
    {
        $this->app['events']->listen(JobFailed::class, function (JobFailed $event): void {
            if ($event->job instanceof RabbitMQJob && $event->job->isDeletedOrReleased()) {
                $this->sequence[] = 'settled';
            }
        });
    }

    /**
     * Requeueing unacked deliveries after a connection drops is asynchronous on
     * the broker side, so poll rather than assert on the first read.
     */
    private function assertQueueSizeEventually(int $expected, string $message): void
    {
        $deadline = microtime(true) + 5.0;
        $size = null;

        do {
            $size = Queue::connection('rabbitmq')->size($this->queue);

            if ($size === $expected) {
                break;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        $this->assertSame($expected, $size, $message);
    }

    private function pushAndReserve(): RabbitMQJob
    {
        Queue::connection('rabbitmq')->pushRaw(json_encode([
            'uuid' => 'ordering-'.bin2hex(random_bytes(4)),
            // An inert but *resolvable* class: Job::failed() resolves the
            // payload's job class, and an unresolvable one would throw for
            // reasons unrelated to what these tests assert.
            'displayName' => 'OrderingPayload',
            'job' => \stdClass::class,
            'maxTries' => 1,
            'data' => [],
        ], JSON_THROW_ON_ERROR), $this->queue);

        $job = Queue::connection('rabbitmq')->pop($this->queue);

        $this->assertInstanceOf(RabbitMQJob::class, $job, 'The job must be reserved for the test to be meaningful.');

        return $job;
    }

    public function testRecordIsPersistedBeforeTheDeliveryIsRejected(): void
    {
        $this->registerFailerListener();

        $job = $this->pushAndReserve();
        $job->fail(new RuntimeException('boom'));

        $this->assertSame(['persisted'], $this->sequence, 'The failer listener must have run.');
        $this->assertSame(
            0,
            Queue::connection('rabbitmq')->size($this->queue),
            'A recorded failure is settled, so nothing is left to redeliver.'
        );
    }

    /**
     * The package's listener is appended when the failure starts, so it always
     * sits after listeners registered earlier — which is what makes the
     * persist-then-settle ordering hold regardless of boot order.
     */
    public function testTheSettlementListenerIsAppendedAfterExistingListeners(): void
    {
        $this->registerFailerListener();

        $job = $this->pushAndReserve();
        $job->fail(new RuntimeException('boom'));

        $listeners = $this->app['events']->getListeners(JobFailed::class);

        $this->assertGreaterThanOrEqual(2, count($listeners));
    }

    /**
     * The core of the issue: if the failed-job provider throws, the dispatch
     * aborts before the settlement listener, so the delivery must survive.
     */
    public function testAFailedProviderWriteLeavesTheDeliveryEligibleForRedelivery(): void
    {
        $this->registerFailerListener(shouldThrow: true);

        $job = $this->pushAndReserve();

        try {
            $job->fail(new RuntimeException('boom'));
            $this->fail('The failing failed-job provider should have surfaced.');
        } catch (RuntimeException $exception) {
            $this->assertSame('failed-job provider is down', $exception->getMessage());
        }

        $this->assertSame(['persisted'], $this->sequence, 'Only the failer ran.');

        // Dropping every connection is what lets the broker redeliver an
        // unsettled delivery, exactly as it would when the worker process dies.
        RabbitMQConnector::resetPoolManager();

        $this->assertQueueSizeEventually(
            1,
            'The delivery must still be on the queue: a failure with no record must not be discarded.'
        );
    }

    public function testSettlementIsIdempotentAcrossRepeatedFailureEvents(): void
    {
        $job = $this->pushAndReserve();
        $event = new JobFailed('rabbitmq', $job, new RuntimeException('boom'));

        // Drive the listener twice: rejecting the same delivery tag a second time
        // would be a broker-level PRECONDITION_FAILED.
        (new SettleFailedDelivery)->handle($event);
        (new SettleFailedDelivery)->handle($event);

        $this->assertSame(0, Queue::connection('rabbitmq')->size($this->queue));
    }

    public function testMarkAsFailedNoLongerSettlesOnItsOwn(): void
    {
        $job = $this->pushAndReserve();

        $job->markAsFailed();

        RabbitMQConnector::resetPoolManager();

        $this->assertQueueSizeEventually(
            1,
            'markAsFailed() must not discard the delivery before the record exists.'
        );
    }

    public function testNonRabbitMqJobsAreIgnoredByTheListener(): void
    {
        $foreignJob = \Mockery::mock(Job::class);
        $foreignJob->shouldReceive('getQueue')->andReturn('other');

        (new SettleFailedDelivery)->handle(
            new JobFailed('database', $foreignJob, new RuntimeException('boom'))
        );

        $this->assertTrue(true, 'A foreign job must be passed over without error.');
    }

    /**
     * Resolving the queue connection must NOT register the listener: doing so is
     * exactly what would put it ahead of the failer listener.
     */
    public function testResolvingTheConnectionDoesNotRegisterTheListener(): void
    {
        SettleFailedDelivery::flushRegistrations();

        Queue::connection('rabbitmq');

        $this->assertCount(
            0,
            $this->app['events']->getListeners(JobFailed::class),
            'Registration must wait until a job actually fails.'
        );
    }

    public function testFirstFailureRegistersTheListenerExactlyOnce(): void
    {
        SettleFailedDelivery::flushRegistrations();

        $this->pushAndReserve()->markAsFailed();
        $first = count($this->app['events']->getListeners(JobFailed::class));

        $this->pushAndReserve()->markAsFailed();
        $second = count($this->app['events']->getListeners(JobFailed::class));

        $this->assertSame(1, $first);
        $this->assertSame($first, $second, 'The listener must not accumulate per failed job.');
    }
}
