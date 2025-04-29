<?php

namespace iamfarhad\LaravelRabbitMQ;

use Exception;
use Illuminate\Container\Container;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class Consumer extends Worker
{
    private Container $container;

    private string $consumerTag;

    private int $maxPriority;

    private AMQPChannel $amqpChannel;

    private object|null $currentJob = null;

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

    /**
     * Listen to the given queue in a loop.
     *
     * @param  string $connectionName
     * @param  string $queue
     * @return int
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
        
        // Check if the connection is a RabbitQueue instance
        if (! $connection instanceof RabbitQueue) {
            throw new \RuntimeException('Connection must be an instance of RabbitQueue for RabbitMQ Consumer');
        }
        
        $connection->declareQueue($queue);

        $this->amqpChannel = $connection->getChannel();

        $jobClass = $connection->getJobClass();
        $arguments = [];
        if ($this->maxPriority !== 0) {
            $arguments['priority'] = [
                'I',
                $this->maxPriority,
            ];
        }

        $prefetchSize = config('queue.connections.rabbitmq.queue.prefetch_size') ?? 0;
        $prefetchCount = config('queue.connections.rabbitmq.queue.prefetch_count') ?? 10;
        $global = config('queue.connections.rabbitmq.queue.global') ?? false;

        $this->amqpChannel->basic_qos(
            $prefetchSize,
            $prefetchCount,
            $global
        );

        $this->amqpChannel->basic_consume(
            $queue,
            $this->consumerTag,
            false,
            false,
            false,
            false,
            function (AMQPMessage $amqpMessage) use ($connection, $options, $connectionName, $queue, $jobClass, &$jobsProcessed): void {
                $job = new $jobClass(
                    $this->container,
                    $connection,
                    $amqpMessage,
                    $connectionName,
                    $queue
                );

                $this->currentJob = $job;

                if ($this->supportsAsyncSignals()) {
                    $this->registerTimeoutHandler($job, $options);
                }

                ++$jobsProcessed;

                $this->runJob($job, $connectionName, $options);

                if ($this->supportsAsyncSignals()) {
                    $this->resetTimeoutHandler();
                }
            },
            null,
            $arguments
        );

        while ($this->amqpChannel->is_consuming()) {
            // Before reserving any jobs, we will make sure this queue is not paused and
            // if it is we will just pause this worker for a given amount of time and
            // make sure we do not need to kill this worker process off completely.
            if (! $this->daemonShouldRun($options, $connectionName, $queue)) {
                $this->pauseWorker($options, $timestampOfLastQueueRestart);

                continue;
            }

            // If the daemon should run (not in maintenance mode, etc.), then we can wait for a job.
            try {
                $this->amqpChannel->wait(null, true, (int) $options->timeout);
            } catch (AMQPRuntimeException $amqpRuntimeException) {
                $this->exceptions->report($amqpRuntimeException);

                $this->kill(1);
            } catch (Exception | Throwable $exception) {
                $this->exceptions->report($exception);

                $this->stopWorkerIfLostConnection($exception);
            }

            // If no job is got off the queue, we will need to sleep the worker.
            if ($this->currentJob === null) {
                $this->sleep($options->sleep);
            }

            // Finally, we will check to see if we have exceeded our memory limits or if
            // the queue should restart based on other indications. If so, we'll stop
            // this worker and let whatever is "monitoring" it restart the process.
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

            $this->currentJob = null;
        }
    }

    /**
     * Determine if the daemon should process on this iteration.
     *
     * @param string $connectionName
     * @param string $queue
     */
    protected function daemonShouldRun(WorkerOptions $options, $connectionName, $queue): bool
    {
        return ! (($this->isDownForMaintenance)() && ! $options->force) && ! $this->paused;
    }

    public function stop($status = 0, $options = []): int
    {
        // Tell the server you are going to stop consuming.
        // It will finish up the last message and not send you anymore.
        $this->amqpChannel->basic_cancel($this->consumerTag, false, true);

        return parent::stop($status);
    }
}
