<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ;

use AMQPChannel;
use AMQPChannelException;
use AMQPConnectionException;
use AMQPEnvelope;
use AMQPQueue;
use Exception;
use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use Illuminate\Container\Container;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
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

        $timestampOfLastQueueRestart = $this->getTimestampOfLastQueueRestart();
        $startTime = (hrtime(true) / 1e9);
        $jobsProcessed = 0;

        $connection = $this->manager->connection($connectionName);

        if (! $connection instanceof RabbitQueue) {
            throw new RuntimeException('Connection must be an instance of RabbitQueue for RabbitMQ Consumer');
        }

        $connection->declareQueue($queue);
        $this->amqpChannel = $connection->getChannel();
        $jobClass = $connection->getJobClass();

        $amqpQueue = new AMQPQueue($this->amqpChannel);
        $amqpQueue->setName($queue);

        $this->configureQos($queue);

        if ($this->consumeMode === 'consume') {
            return $this->daemonUsingBasicConsume(
                $amqpQueue,
                $connection,
                $jobClass,
                $connectionName,
                $queue,
                $options,
                $timestampOfLastQueueRestart,
                $startTime,
                $jobsProcessed
            );
        }

        while (true) {
            if (! $this->daemonShouldRun($options, $connectionName, $queue)) {
                $this->pauseWorker($options, $timestampOfLastQueueRestart);

                continue;
            }

            try {
                $envelope = $amqpQueue->get(AMQP_NOPARAM);

                if ($envelope instanceof AMQPEnvelope) {
                    $jobsProcessed += $this->processEnvelope($envelope, $connection, $jobClass, $connectionName, $queue, $options);
                } else {
                    $this->sleep($options->sleep);
                }
            } catch (AMQPChannelException|AMQPConnectionException $exception) {
                $this->exceptions->report($exception);
                $this->stopWorkerIfLostConnection($exception);
            } catch (Exception|Throwable $exception) {
                $this->exceptions->report($exception);
                $this->stopWorkerIfLostConnection($exception);
            }

            $status = $this->stopIfNecessary(
                $options,
                $timestampOfLastQueueRestart,
                $startTime,
                $jobsProcessed,
                $this->currentJob
            );

            if (! is_null($status)) {
                return $this->stop($status);
            }
        }
    }

    /**
     * @param  class-string<RabbitMQJob>  $jobClass
     */
    private function daemonUsingBasicConsume(
        AMQPQueue $amqpQueue,
        RabbitQueue $connection,
        string $jobClass,
        string $connectionName,
        string $queue,
        WorkerOptions $options,
        int $timestampOfLastQueueRestart,
        float $startTime,
        int &$jobsProcessed
    ): int {
        $callback = function (AMQPEnvelope $envelope) use (
            $connection,
            $jobClass,
            $connectionName,
            $queue,
            $options,
            $timestampOfLastQueueRestart,
            $startTime,
            &$jobsProcessed
        ): bool {
            if (! $this->daemonShouldRun($options, $connectionName, $queue)) {
                $this->pauseWorker($options, $timestampOfLastQueueRestart);

                return true;
            }

            try {
                $jobsProcessed += $this->processEnvelope($envelope, $connection, $jobClass, $connectionName, $queue, $options);
            } catch (Throwable $exception) {
                $this->exceptions->report($exception);
                $this->stopWorkerIfLostConnection($exception);
            }

            $status = $this->stopIfNecessary(
                $options,
                $timestampOfLastQueueRestart,
                $startTime,
                $jobsProcessed,
                $this->currentJob
            );

            return is_null($status);
        };

        try {
            $amqpQueue->consume($callback, AMQP_NOPARAM, $this->consumerTag ?: null);
        } catch (AMQPChannelException|AMQPConnectionException $exception) {
            $this->exceptions->report($exception);
            $this->stopWorkerIfLostConnection($exception);
        }

        return $this->stop(0);
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
    ): int {
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

        $this->runJob($job, $connectionName, $options);

        if ($this->supportsAsyncSignals()) {
            $this->resetTimeoutHandler();
        }

        $this->currentJob = null;

        return 1;
    }

    private function configureQos(string $queue): void
    {
        $qosConfig = config('queue.connections.rabbitmq.options.queue.qos', []);
        $prefetchCount = $qosConfig['prefetch_count'] ?? 10;
        $prefetchSize = $qosConfig['prefetch_size'] ?? 0;

        $this->amqpChannel->setPrefetchCount($prefetchCount);
        if ($prefetchSize > 0) {
            $this->amqpChannel->setPrefetchSize($prefetchSize);
        }

        $queueConfig = config("queue.connections.rabbitmq.queues.{$queue}", []);
        if (isset($queueConfig['priority'])) {
            $this->setMaxPriority((int) $queueConfig['priority']);
        }
    }

    /**
     * Determine if the daemon should process on this iteration.
     *
     * @param  string  $connectionName
     * @param  string  $queue
     */
    protected function daemonShouldRun(WorkerOptions $options, $connectionName, $queue): bool
    {
        return ! (($this->isDownForMaintenance)() && ! $options->force) && ! $this->paused;
    }

    public function stop($status = 0, $options = null, $reason = null)
    {
        return parent::stop($status, $options, $reason);
    }
}
