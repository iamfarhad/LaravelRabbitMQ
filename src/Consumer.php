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

    private ?RabbitMQJob $currentJob = null;

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

        // Check if the connection is a RabbitQueue instance
        if (! $connection instanceof RabbitQueue) {
            throw new RuntimeException('Connection must be an instance of RabbitQueue for RabbitMQ Consumer');
        }

        $connection->declareQueue($queue);
        $this->amqpChannel = $connection->getChannel();
        $jobClass = $connection->getJobClass();

        $amqpQueue = new AMQPQueue($this->amqpChannel);
        $amqpQueue->setName($queue);

        // Set QoS
        $qosConfig = config('queue.connections.rabbitmq.options.queue.qos', []);
        $prefetchCount = $qosConfig['prefetch_count'] ?? 10;
        $prefetchSize = $qosConfig['prefetch_size'] ?? 0;
        $global = $qosConfig['global'] ?? false;

        $this->amqpChannel->setPrefetchCount($prefetchCount);
        if ($prefetchSize > 0) {
            $this->amqpChannel->setPrefetchSize($prefetchSize);
        }

        // Set consumer priority if configured
        $queueConfig = config("queue.connections.rabbitmq.queues.{$queue}", []);
        if (isset($queueConfig['priority'])) {
            $this->setMaxPriority($queueConfig['priority']);
        }

        while (true) {
            // Before reserving any jobs, we will make sure this queue is not paused and
            // if it is we will just pause this worker for a given amount of time and
            // make sure we do not need to kill this worker process off completely.
            if (! $this->daemonShouldRun($options, $connectionName, $queue)) {
                $this->pauseWorker($options, $timestampOfLastQueueRestart);

                continue;
            }

            try {
                // Try to get a message from the queue
                $envelope = $amqpQueue->get(AMQP_NOPARAM);

                if ($envelope instanceof AMQPEnvelope) {
                    $job = new $jobClass(
                        $this->container,
                        $connection,
                        $envelope,
                        $connectionName,
                        $queue
                    );

                    /** @var RabbitMQJob $job */
                    $this->currentJob = $job;

                    if ($this->supportsAsyncSignals()) {
                        $this->registerTimeoutHandler($job, $options);
                    }

                    $jobsProcessed++;

                    $this->runJob($job, $connectionName, $options);

                    if ($this->supportsAsyncSignals()) {
                        $this->resetTimeoutHandler();
                    }

                    $this->currentJob = null;
                } else {
                    // No job available, sleep for a bit
                    $this->sleep($options->sleep);
                }
            } catch (AMQPChannelException|AMQPConnectionException $exception) {
                $this->exceptions->report($exception);
                $this->stopWorkerIfLostConnection($exception);
            } catch (Exception|Throwable $exception) {
                $this->exceptions->report($exception);
                $this->stopWorkerIfLostConnection($exception);
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

    public function stop($status = 0, $options = []): int
    {
        // For ext-amqp, we don't need to explicitly cancel consumption
        // as we're using a polling approach rather than a callback approach

        return parent::stop($status);
    }
}
