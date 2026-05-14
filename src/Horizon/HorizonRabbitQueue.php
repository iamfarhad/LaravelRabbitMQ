<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Horizon;

use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use Illuminate\Contracts\Events\Dispatcher;

class HorizonRabbitQueue extends RabbitQueue
{
    private string|object|null $lastPushed = null;

    public function readyNow(?string $queue = null): int
    {
        return $this->size($queue);
    }

    public function push($job, $data = '', $queue = null): ?string
    {
        $this->lastPushed = $job;

        return parent::push($job, $data, $queue);
    }

    public function pushRaw($payload, $queue = null, array $options = []): ?string
    {
        $payload = $this->prepareHorizonPayload((string) $payload, $this->lastPushed);
        $queueName = $this->getQueue($queue);

        $this->dispatchHorizonEvent($queueName, \Laravel\Horizon\Events\JobPending::class, [$payload]);

        return tap(parent::pushRaw($payload, $queue, $options), function () use ($queueName, $payload): void {
            $this->dispatchHorizonEvent($queueName, \Laravel\Horizon\Events\JobPushed::class, [$payload]);
        });
    }

    public function later($delay, $job, $data = '', $queue = null): ?string
    {
        $payload = $this->prepareHorizonPayload(
            $this->createPayload($job, $this->getQueue($queue), $data),
            $job
        );
        $queueName = $this->getQueue($queue);

        $this->dispatchHorizonEvent($queueName, \Laravel\Horizon\Events\JobPending::class, [$payload]);

        return tap(parent::laterRaw($delay, $payload, $queue), function () use ($queueName, $payload): void {
            $this->dispatchHorizonEvent($queueName, \Laravel\Horizon\Events\JobPushed::class, [$payload]);
        });
    }

    public function pop($queue = null)
    {
        return tap(parent::pop($queue), function ($job) use ($queue): void {
            if ($job instanceof RabbitMQJob) {
                $this->dispatchHorizonEvent(
                    $this->getQueue($queue),
                    \Laravel\Horizon\Events\JobReserved::class,
                    [$job->getRawBody()]
                );
            }
        });
    }

    public function deleteReserved($queue, $job): void
    {
        if ($job instanceof RabbitMQJob) {
            $this->dispatchHorizonEvent(
                $this->getQueue($queue),
                \Laravel\Horizon\Events\JobDeleted::class,
                [$job, $job->getRawBody()]
            );
        }
    }

    private function prepareHorizonPayload(string $payload, string|object|null $job): string
    {
        if (! class_exists(\Laravel\Horizon\JobPayload::class)) {
            return $payload;
        }

        return (new \Laravel\Horizon\JobPayload($payload))->prepare($job)->value;
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function dispatchHorizonEvent(string $queue, string $eventClass, array $arguments): void
    {
        if (! class_exists($eventClass) || ! $this->container || ! $this->container->bound(Dispatcher::class)) {
            return;
        }

        $event = new $eventClass(...$arguments);

        $this->container->make(Dispatcher::class)->dispatch(
            $event->connection($this->getConnectionName())->queue($queue)
        );
    }
}
