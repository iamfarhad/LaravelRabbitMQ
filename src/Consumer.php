<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ;

use AMQPChannel;
use AMQPChannelException;
use AMQPConnectionException;
use AMQPEnvelope;
use AMQPQueue;
use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use Illuminate\Container\Container;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerIdle;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use ReflectionMethod;
use RuntimeException;
use Throwable;

class Consumer extends Worker
{
    private Container $container;

    private string $consumerTag = '';

    private int $maxPriority = 0;

    private AMQPChannel $amqpChannel;

    private string $consumeMode = 'poll';

    /**
     * Jobs handled by this daemon loop. Mirrored onto the framework's own
     * counter by syncProcessedJobCounters() when that property exists.
     */
    private int $processedJobs = 0;

    /**
     * Whether Worker::stopIfNecessary() still takes the pre-Laravel-13
     * `$jobsProcessed` parameter. Resolved once per process by reflection:
     * Laravel 13 dropped that parameter (the worker tracks the count on
     * `$this->jobsProcessed` instead) and changed the return value from
     * `int|null` to `array{int, WorkerStopReason}|null`. Passing the old
     * argument list on 13 silently feeds the job count in as `$job`, which
     * breaks --stop-when-empty, --stop-when-empty-for and --max-jobs.
     */
    private static ?bool $stopIfNecessaryTakesJobsProcessed = null;

    /**
     * The job currently being processed.
     *
     * Keep this public and untyped to remain compatible with Laravel 13's
     * Worker::$currentJob property declaration.
     *
     * @var RabbitMQJob|null
     */
    public $currentJob = null;

    public function setContainer(Container $container): void
    {
        $this->container = $container;
    }

    public function setConsumerTag(string $value): void
    {
        $this->consumerTag = $value;
    }

    public function setMaxPriority(int $value): void
    {
        $this->maxPriority = $value;
    }

    public function getMaxPriority(): int
    {
        return $this->maxPriority;
    }

    public function setConsumeMode(string $mode): void
    {
        $this->consumeMode = in_array($mode, ['poll', 'consume'], true) ? $mode : 'poll';
    }

    /**
     * Listen to the given queue in a loop.
     *
     * @param  string  $connectionName
     * @param  string  $queue
     * @return int
     *
     * @throws Throwable
     */
    public function daemon($connectionName, $queue, WorkerOptions $options)
    {
        if ($this->supportsAsyncSignals()) {
            $this->listenForSignals();
        }

        $lastRestart = $this->getTimestampOfLastQueueRestart();
        $startTime = $this->workerStartTime();
        $this->processedJobs = 0;
        $this->syncProcessedJobCounters(false);

        $connection = $this->manager->connection($connectionName);

        if (! $connection instanceof RabbitQueue) {
            throw new RuntimeException('Connection must be an instance of RabbitQueue for RabbitMQ Consumer');
        }

        $this->raiseWorkerStartingEventIfSupported($connectionName, $queue, $options);

        $connection->declareConfiguredQueue($queue);
        $this->amqpChannel = $connection->getChannel();
        $jobClass = $connection->getJobClass();

        $amqpQueue = new AMQPQueue($this->amqpChannel);
        $amqpQueue->setName($queue);

        $this->resolveMaxPriority($connection, $queue);

        if ($this->consumeMode === 'consume' && $this->requiresEmptyQueueDetection($options)) {
            // basic.consume evaluates stop conditions only when a delivery
            // arrives, so an empty queue never triggers them — the worker would
            // simply block. Poll mode can observe emptiness, so use it.
            $this->warnAboutConsumeModeFallback();
            $this->consumeMode = 'poll';
        }

        if ($this->consumeMode === 'consume') {
            // basic.qos only governs basic.consume deliveries, so prefetch is
            // pointless (and misleading) in the basic.get based poll mode.
            $this->configureQos($connection);

            return $this->daemonUsingBasicConsume(
                $amqpQueue,
                $connection,
                $jobClass,
                $connectionName,
                $queue,
                $options,
                $lastRestart,
                $startTime
            );
        }

        while (true) {
            if (! $this->daemonShouldRun($options, $connectionName, $queue)) {
                $status = $this->pauseWorkerAndResolveStatus($options, $lastRestart, $startTime);

                if ($status !== null) {
                    return $this->stopWorker($status, $options);
                }

                continue;
            }

            $this->resetScopeIfSupported();

            $job = null;

            try {
                $envelope = $amqpQueue->get(AMQP_NOPARAM);

                if ($envelope instanceof AMQPEnvelope) {
                    $job = $this->processEnvelope($envelope, $connection, $jobClass, $connectionName, $queue, $options);
                } else {
                    $this->dispatchWorkerIdleEvent($connectionName, $queue, $options);
                    $this->sleep($options->sleep);
                }
            } catch (Throwable $exception) {
                $this->exceptions->report($exception);
                $this->stopWorkerIfLostConnection($exception);
            }

            $status = $this->resolveStopStatus($options, $lastRestart, $startTime, $job);

            if ($status !== null) {
                return $this->stopWorker($status, $options);
            }
        }
    }

    /**
     * @param  class-string<RabbitMQJob>  $jobClass
     * @param  int  $lastRestart
     */
    private function daemonUsingBasicConsume(
        AMQPQueue $amqpQueue,
        RabbitQueue $connection,
        string $jobClass,
        string $connectionName,
        string $queue,
        WorkerOptions $options,
        $lastRestart,
        float $startTime
    ): int {
        $stopStatus = null;

        $callback = function (AMQPEnvelope $envelope) use (
            &$stopStatus,
            $amqpQueue,
            $connection,
            $jobClass,
            $connectionName,
            $queue,
            $options,
            $lastRestart,
            $startTime
        ): bool {
            if (! $this->daemonShouldRun($options, $connectionName, $queue)) {
                // The broker already handed this delivery over. Returning
                // without settling it would strand the message unacked for the
                // whole maintenance/pause window (and up to prefetch_count
                // messages with it), so hand it straight back instead.
                $this->requeue($amqpQueue, $envelope);

                $stopStatus = $this->pauseWorkerAndResolveStatus($options, $lastRestart, $startTime);

                return $stopStatus === null;
            }

            $this->resetScopeIfSupported();

            $job = null;

            try {
                $job = $this->processEnvelope($envelope, $connection, $jobClass, $connectionName, $queue, $options);
            } catch (Throwable $exception) {
                $this->exceptions->report($exception);
                $this->stopWorkerIfLostConnection($exception);
            }

            $stopStatus = $this->resolveStopStatus($options, $lastRestart, $startTime, $job);

            return $stopStatus === null;
        };

        try {
            $amqpQueue->consume($callback, AMQP_NOPARAM, $this->consumerTag !== '' ? $this->consumerTag : null);
        } catch (AMQPChannelException|AMQPConnectionException $exception) {
            $this->exceptions->report($exception);
            $this->stopWorkerIfLostConnection($exception);
        }

        // Consumption ended for a reason the callback never saw (lost
        // connection, cancelled consumer). Re-evaluate so the exit code still
        // tells the supervisor what happened instead of always reporting 0.
        $stopStatus ??= $this->resolveStopStatus($options, $lastRestart, $startTime, null)
            ?? [static::EXIT_SUCCESS, null];

        return $this->stopWorker($stopStatus, $options);
    }

    /**
     * @param  class-string<RabbitMQJob>  $jobClass
     */
    private function processEnvelope(
        AMQPEnvelope $envelope,
        RabbitQueue $connection,
        string $jobClass,
        string $connectionName,
        string $queue,
        WorkerOptions $options
    ): RabbitMQJob {
        $job = new $jobClass(
            $this->container,
            $connection,
            $envelope,
            $connectionName,
            $queue
        );

        $this->currentJob = $job;

        if ($this->supportsAsyncSignals()) {
            $this->registerTimeoutHandler($job, $options);
        }

        $this->processedJobs++;
        $this->syncProcessedJobCounters(false);

        $this->runJob($job, $connectionName, $options);

        $this->syncProcessedJobCounters(true);

        if ($this->supportsAsyncSignals()) {
            $this->resetTimeoutHandler();
        }

        $this->currentJob = null;

        if ($options->rest > 0) {
            $this->sleep($options->rest);
        }

        return $job;
    }

    /**
     * Hand an unprocessed delivery back to the broker for redelivery.
     */
    private function requeue(AMQPQueue $amqpQueue, AMQPEnvelope $envelope): void
    {
        try {
            $deliveryTag = $envelope->getDeliveryTag();

            if ($deliveryTag !== null) {
                $amqpQueue->reject($deliveryTag, AMQP_REQUEUE);
            }
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
        }
    }

    private function configureQos(RabbitQueue $connection): void
    {
        $qosConfig = (array) $connection->connectionConfig('options.queue.qos', []);
        $prefetchCount = (int) ($qosConfig['prefetch_count'] ?? 1);
        $prefetchSize = (int) ($qosConfig['prefetch_size'] ?? 0);

        $this->amqpChannel->setPrefetchCount(max(1, $prefetchCount));

        if ($prefetchSize > 0) {
            $this->amqpChannel->setPrefetchSize($prefetchSize);
        }

        // setPrefetchCount()/setPrefetchSize() mutate channel state that would
        // otherwise leak to the next borrower of this pooled channel.
        $connection->markChannelDirty();
    }

    private function resolveMaxPriority(RabbitQueue $connection, string $queue): void
    {
        $queueConfig = (array) $connection->connectionConfig("queues.{$queue}", []);

        if (isset($queueConfig['priority'])) {
            $this->setMaxPriority((int) $queueConfig['priority']);
        }
    }

    /**
     * Determine if the daemon should process on this iteration.
     *
     * Mirrors the framework's own check, including the Looping event — dropping
     * it would silently disable Queue::looping() callbacks and Horizon's pause
     * hooks. Looping's third constructor argument only exists on newer
     * frameworks, where extra arguments are simply ignored.
     *
     * @param  string  $connectionName
     * @param  string  $queue
     */
    protected function daemonShouldRun(WorkerOptions $options, $connectionName, $queue): bool
    {
        if ((! $options->force && ($this->isDownForMaintenance)()) || $this->paused) {
            return false;
        }

        return $this->events->until(new Looping($connectionName, $queue, $options)) !== false;
    }

    public function stop($status = 0, $options = null, $reason = null)
    {
        return parent::stop($status, $options, $reason);
    }

    /**
     * Ask the framework whether the daemon should stop, normalising both the
     * argument list and the return shape across framework versions.
     *
     * @param  int  $lastRestart
     * @return array{0: int, 1: mixed}|null
     */
    private function resolveStopStatus(WorkerOptions $options, $lastRestart, float $startTime, ?RabbitMQJob $job): ?array
    {
        $arguments = self::stopIfNecessaryTakesJobsProcessed()
            ? [$options, $lastRestart, $startTime, $this->processedJobs, $job]
            : [$options, $lastRestart, $startTime, $job];

        // Dispatched dynamically: the parameter list genuinely differs between
        // the supported framework versions (see the property docblock).
        return $this->normalizeStopResult(
            call_user_func_array([$this, 'stopIfNecessary'], $arguments)
        );
    }

    /**
     * Sleep out the pause window and report whether the worker should stop.
     *
     * @param  int  $lastRestart
     * @return array{0: int, 1: mixed}|null
     */
    private function pauseWorkerAndResolveStatus(WorkerOptions $options, $lastRestart, float $startTime): ?array
    {
        $status = $this->normalizeStopResult($this->pauseWorker($options, $lastRestart, $startTime));

        if ($status !== null) {
            return $status;
        }

        // Frameworks whose pauseWorker() returns nothing would otherwise leave a
        // paused worker ignoring SIGTERM entirely.
        return $this->shouldQuit ? [static::EXIT_SUCCESS, null] : null;
    }

    /**
     * Laravel 13 returns `[status, WorkerStopReason]`; earlier versions return
     * a bare int. Both collapse to the same internal shape here.
     *
     * @return array{0: int, 1: mixed}|null
     */
    private function normalizeStopResult(mixed $result): ?array
    {
        if ($result === null) {
            return null;
        }

        if (is_array($result)) {
            return [(int) ($result[0] ?? static::EXIT_SUCCESS), $result[1] ?? null];
        }

        return [(int) $result, null];
    }

    /**
     * @param  array{0: int, 1: mixed}  $status
     */
    private function stopWorker(array $status, WorkerOptions $options): int
    {
        return (int) $this->stop($status[0], $options, $status[1]);
    }

    /**
     * Keep the framework's own bookkeeping in step with ours. Laravel 13 reads
     * `$this->jobsProcessed` for --max-jobs and `$this->lastJobProcessedAt` for
     * --stop-when-empty-for; on older frameworks those properties do not exist
     * and writing them would create deprecated dynamic properties.
     */
    private function syncProcessedJobCounters(bool $jobJustFinished): void
    {
        if (self::stopIfNecessaryTakesJobsProcessed()) {
            // Pre-Laravel-13: the worker has no such properties, and writing
            // them would create deprecated dynamic properties.
            return;
        }

        $this->jobsProcessed = $this->processedJobs;

        if ($jobJustFinished) {
            $this->lastJobProcessedAt = $this->currentTime();
        }
    }

    /**
     * The start time --max-time and --stop-when-empty-for are measured against.
     *
     * Read from the framework's own clock rather than calling hrtime() directly,
     * so it is guaranteed to be the same clock stopIfNecessary() compares it
     * with, whatever the installed version uses.
     */
    private function workerStartTime(): float
    {
        return (float) $this->currentTime();
    }

    /**
     * Whether the requested stop conditions depend on noticing that the queue is
     * empty — something a blocking basic.consume cannot report.
     */
    private function requiresEmptyQueueDetection(WorkerOptions $options): bool
    {
        return (bool) $options->stopWhenEmpty || (bool) $options->stopWhenEmptyFor;
    }

    private function warnAboutConsumeModeFallback(): void
    {
        if (! isset($this->container) || ! $this->container->bound('log')) {
            return;
        }

        try {
            $this->container->make('log')->warning(
                'rabbitmq:consume was asked to stop on an empty queue while in "consume" mode. '
                .'basic.consume only evaluates stop conditions when a delivery arrives, so an empty queue '
                .'would block forever; falling back to "poll" mode for this run.'
            );
        } catch (Throwable) {
            // Never let logging break the worker.
        }
    }

    private function resetScopeIfSupported(): void
    {
        $resetScope = $this->resetScope;

        if (is_callable($resetScope)) {
            $resetScope();
        }
    }

    /**
     * @param  string  $connectionName
     * @param  string  $queue
     */
    private function raiseWorkerStartingEventIfSupported($connectionName, $queue, WorkerOptions $options): void
    {
        if (class_exists(WorkerStarting::class)) {
            $this->events->dispatch(new WorkerStarting($connectionName, $queue, $options));
        }
    }

    /**
     * @param  string  $connectionName
     * @param  string  $queue
     */
    private function dispatchWorkerIdleEvent($connectionName, $queue, WorkerOptions $options): void
    {
        if (class_exists(WorkerIdle::class)) {
            $this->events->dispatch(new WorkerIdle($connectionName, $queue, $options));
        }
    }

    private static function stopIfNecessaryTakesJobsProcessed(): bool
    {
        if (self::$stopIfNecessaryTakesJobsProcessed !== null) {
            return self::$stopIfNecessaryTakesJobsProcessed;
        }

        $parameters = (new ReflectionMethod(Worker::class, 'stopIfNecessary'))->getParameters();

        return self::$stopIfNecessaryTakesJobsProcessed = isset($parameters[3])
            && $parameters[3]->getName() === 'jobsProcessed';
    }
}
