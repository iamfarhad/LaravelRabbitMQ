<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Horizon\Listeners;

use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed as LaravelJobFailed;

class RabbitMQFailedEvent
{
    public function __construct(private Dispatcher $events) {}

    public function handle(LaravelJobFailed $event): void
    {
        if (! $event->job instanceof RabbitMQJob || ! class_exists(\Laravel\Horizon\Events\JobFailed::class)) {
            return;
        }

        $this->events->dispatch(
            (new \Laravel\Horizon\Events\JobFailed(
                $event->exception,
                $event->job,
                $event->job->getRawBody()
            ))->connection($event->connectionName)->queue($event->job->getQueue())
        );
    }
}
