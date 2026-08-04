<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit;

use iamfarhad\LaravelRabbitMQ\Consumer;
use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Mockery;
use ReflectionMethod;

/**
 * Laravel 13 changed Worker::stopIfNecessary() from
 *   ($options, $lastRestart, $startTime, $jobsProcessed, $job): int|null
 * to
 *   ($options, $lastRestart, $startTime, $job): array{int, WorkerStopReason}|null
 *
 * The consumer used to pass the old argument list unconditionally, which fed the
 * processed-job count in as `$job` (breaking --stop-when-empty,
 * --stop-when-empty-for and --max-jobs) and returned the status array all the
 * way out to ConsumeCommand::consume(): int, where it became a TypeError on
 * every graceful shutdown.
 *
 * These tests pin the shim against whichever framework version is installed.
 */
class WorkerCompatibilityTest extends UnitTestCase
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

    private function invoke(Consumer $consumer, string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod($consumer, $method);

        return $reflection->invokeArgs($consumer, $arguments);
    }

    private function takesJobsProcessed(): bool
    {
        $parameters = (new ReflectionMethod(Consumer::class, 'stopIfNecessaryTakesJobsProcessed'))
            ->invoke(null);

        return (bool) $parameters;
    }

    public function testFrameworkGenerationIsDetectedFromTheRealWorkerSignature(): void
    {
        $parameters = (new ReflectionMethod(Worker::class, 'stopIfNecessary'))->getParameters();
        $expected = isset($parameters[3]) && $parameters[3]->getName() === 'jobsProcessed';

        $this->assertSame(
            $expected,
            $this->takesJobsProcessed(),
            'The consumer must agree with the installed framework about stopIfNecessary()\'s signature.'
        );
    }

    public function testStopStatusIsNormalisedToAnIntegerExitCode(): void
    {
        $consumer = $this->consumer();

        $this->assertNull($this->invoke($consumer, 'normalizeStopResult', [null]));

        // Laravel <= 12 returns a bare int.
        $this->assertSame([12, null], $this->invoke($consumer, 'normalizeStopResult', [12]));

        // Laravel 13 returns [status, WorkerStopReason].
        $this->assertSame(
            [0, 'reason-object'],
            $this->invoke($consumer, 'normalizeStopResult', [[0, 'reason-object']])
        );
    }

    /**
     * The regression itself: with nothing popped, --stop-when-empty must stop
     * the worker. It never did, because the slot the framework reads `$job` from
     * was receiving the processed-job count instead.
     */
    public function testStopWhenEmptyStopsTheWorkerWhenNoJobWasPopped(): void
    {
        $consumer = $this->consumer();
        $options = new WorkerOptions(stopWhenEmpty: true);

        $status = $this->invoke($consumer, 'resolveStopStatus', [
            $options,
            null,
            $this->invoke($consumer, 'workerStartTime'),
            null,
        ]);

        $this->assertIsArray($status, 'An empty queue with --stop-when-empty must stop the worker.');
        $this->assertIsInt($status[0]);
    }

    public function testStopWhenEmptyKeepsWorkingWhileJobsAreStillArriving(): void
    {
        $consumer = $this->consumer();
        $options = new WorkerOptions(stopWhenEmpty: true);

        $status = $this->invoke($consumer, 'resolveStopStatus', [
            $options,
            null,
            $this->invoke($consumer, 'workerStartTime'),
            Mockery::mock(RabbitMQJob::class),
        ]);

        $this->assertNull($status);
    }

    /**
     * --max-jobs is evaluated against the framework's own counter on Laravel 13,
     * so the consumer has to keep it in step with its local tally.
     */
    public function testMaxJobsIsHonouredThroughTheFrameworksOwnCounter(): void
    {
        $consumer = $this->consumer();
        $options = new WorkerOptions(maxJobs: 2);
        $startTime = $this->invoke($consumer, 'workerStartTime');

        $this->setProcessedJobs($consumer, 1);
        $this->assertNull(
            $this->invoke($consumer, 'resolveStopStatus', [$options, null, $startTime, null]),
            'One job of two must not stop the worker.'
        );

        $this->setProcessedJobs($consumer, 2);
        $this->assertIsArray(
            $this->invoke($consumer, 'resolveStopStatus', [$options, null, $startTime, null]),
            '--max-jobs must stop the worker once the limit is reached.'
        );
    }

    /**
     * The start time has to come from the same clock stopIfNecessary() compares
     * it against — Worker::currentTime() is a monotonic hrtime() reading, not a
     * wall-clock timestamp.
     */
    public function testMaxTimeIsNotExceededImmediatelyAfterStartup(): void
    {
        $consumer = $this->consumer();
        $options = new WorkerOptions(maxTime: 3600);

        $status = $this->invoke($consumer, 'resolveStopStatus', [
            $options,
            null,
            $this->invoke($consumer, 'workerStartTime'),
            null,
        ]);

        $this->assertNull($status, 'A worker one iteration old has not exceeded a one-hour --max-time.');
    }

    public function testMaxTimeStopsTheWorkerOnceTheWindowHasElapsed(): void
    {
        $consumer = $this->consumer();
        $options = new WorkerOptions(maxTime: 60);

        // A start time a full window in the past, on the framework's own clock.
        $startTime = $this->invoke($consumer, 'workerStartTime') - 61;

        $this->assertIsArray(
            $this->invoke($consumer, 'resolveStopStatus', [$options, null, $startTime, null])
        );
    }

    /**
     * Guards against reintroducing a wall-clock start time: subtracting a
     * monotonic reading from a unix timestamp yields the machine's uptime, which
     * would trip every time-based limit on the first loop iteration.
     */
    public function testWorkerStartTimeIsMeasuredOnTheFrameworksOwnClock(): void
    {
        $consumer = $this->consumer();

        $this->assertEqualsWithDelta(
            (float) (new ReflectionMethod($consumer, 'currentTime'))->invoke($consumer),
            $this->invoke($consumer, 'workerStartTime'),
            5.0
        );
    }

    private function setProcessedJobs(Consumer $consumer, int $count): void
    {
        $property = new \ReflectionProperty($consumer, 'processedJobs');
        $property->setValue($consumer, $count);

        $this->invoke($consumer, 'syncProcessedJobCounters', [false]);
    }
}
