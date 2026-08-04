<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Horizon;

use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use Illuminate\Contracts\Events\Dispatcher;

class HorizonRabbitQueue extends RabbitQueue
{
    private const HORIZON_JOB_PAYLOAD = 'Laravel\\Horizon\\JobPayload';

    private const HORIZON_JOB_PENDING = 'Laravel\\Horizon\\Events\\JobPending';

    private const HORIZON_JOB_PUSHED = 'Laravel\\Horizon\\Events\\JobPushed';

    private const HORIZON_JOB_RESERVED = 'Laravel\\Horizon\\Events\\JobReserved';

    private const HORIZON_JOB_DELETED = 'Laravel\\Horizon\\Events\\JobDeleted';

    /**
     * The job instance behind the payload currently being published, so the
     * Horizon payload can be tagged with its display name. Cleared as soon as
     * the publish finishes so a later raw push can never pick up a stale job.
     */
    private string|object|null $lastPushed = null;

    public function readyNow(?string $queue = null): int
    {
        return $this->size($queue);
    }

    public function push($job, $data = '', $queue = null): ?string
    {
        $this->lastPushed = $job;

        try {
            return parent::push($job, $data, $queue);
        } finally {
            $this->lastPushed = null;
        }
    }

    /**
     * Delayed dispatch goes through the inherited enqueueUsing() path so
     * `after_commit` and any createPayloadUsing() hooks still apply; the Horizon
     * payload preparation and events happen in laterRaw() below.
     */
    public function later($delay, $job, $data = '', $queue = null): ?string
    {
        $this->lastPushed = $job;

        try {
            return parent::later($delay, $job, $data, $queue);
        } finally {
            $this->lastPushed = null;
        }
    }

    public function pushRaw($payload, $queue = null, array $options = []): ?string
    {
        $payload = $this->prepareHorizonPayload((string) $payload, $this->lastPushed);
        $queueName = $this->getQueue($queue);

        $this->dispatchHorizonEvent($queueName, self::HORIZON_JOB_PENDING, [$payload]);

        return tap(parent::pushRaw($payload, $queue, $options), function () use ($queueName, $payload): void {
            $this->dispatchHorizonEvent($queueName, self::HORIZON_JOB_PUSHED, [$payload]);
        });
    }

    /**
     * The parent publishes through publishRaw(), not pushRaw(), so a delay that
     * resolves to zero no longer re-enters the override above — which used to
     * wrap the payload in a Horizon envelope twice and dispatch both the
     * pending and pushed events a second time.
     */
    public function laterRaw($delay, $payload, $queue = null, $attempts = 0): ?string
    {
        $payload = $this->prepareHorizonPayload((string) $payload, $this->lastPushed);
        $queueName = $this->getQueue($queue);

        $this->dispatchHorizonEvent($queueName, self::HORIZON_JOB_PENDING, [$payload]);

        return tap(parent::laterRaw($delay, $payload, $queue, $attempts), function () use ($queueName, $payload): void {
            $this->dispatchHorizonEvent($queueName, self::HORIZON_JOB_PUSHED, [$payload]);
        });
    }

    public function pop($queue = null)
    {
        return tap(parent::pop($queue), function ($job) use ($queue): void {
            if ($job instanceof RabbitMQJob) {
                $this->dispatchHorizonEvent(
                    $this->getQueue($queue),
                    self::HORIZON_JOB_RESERVED,
                    [$job->getRawBody()]
                );
            }
        });
    }

    public function deleteReserved($queue, $job): void
    {
        if (! $job instanceof RabbitMQJob) {
            return;
        }

        $this->dispatchHorizonEvent(
            $this->getQueue($queue),
            self::HORIZON_JOB_DELETED,
            [$job, $job->getRawBody()]
        );

        // Settle the delivery too, but only when nothing has settled it yet:
        // RabbitMQJob::delete() acks, and acking an already-settled delivery
        // would be reported as a settlement failure.
        if (! $job->isDeletedOrReleased()) {
            $job->delete();
        }
    }

    private function prepareHorizonPayload(string $payload, string|object|null $job): string
    {
        if (! class_exists(self::HORIZON_JOB_PAYLOAD)) {
            return $payload;
        }

        return (new (self::HORIZON_JOB_PAYLOAD)($payload))->prepare($job)->value;
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
