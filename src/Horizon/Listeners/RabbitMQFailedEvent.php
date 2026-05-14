<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Horizon\Listeners;

use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed as LaravelJobFailed;

class RabbitMQFailedEvent
{
    private const HORIZON_JOB_FAILED = 'Laravel\\Horizon\\Events\\JobFailed';

    public function __construct(private Dispatcher $events) {}

    public function handle(LaravelJobFailed $event): void
    {
        if (! $event->job instanceof RabbitMQJob || ! class_exists(self::HORIZON_JOB_FAILED)) {
            return;
        }

        $this->events->dispatch(
            (new (self::HORIZON_JOB_FAILED)(
                $event->exception,
                $event->job,
                $event->job->getRawBody()
            ))->connection($event->connectionName)->queue($event->job->getQueue())
        );
    }
}
