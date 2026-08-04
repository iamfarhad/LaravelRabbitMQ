<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Jobs\Listeners;

use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Throwable;

/**
 * Settles a permanently failed delivery once Laravel has recorded the failure.
 *
 * Why a listener rather than `markAsFailed()`
 * -------------------------------------------
 * `Job::fail()` calls `markAsFailed()` first and dispatches `JobFailed` last, in
 * a `finally`. The authoritative `failed_jobs` write is itself a `JobFailed`
 * listener (`WorkCommand::listenForEvents()` → `logFailedJob()`), so rejecting
 * inside `markAsFailed()` could discard the delivery before — or instead of —
 * persisting the record that explains why it died (issue #37).
 *
 * Listeners run in registration order, and a throwing listener aborts the rest
 * of the dispatch. So:
 *
 *   - failer write succeeds → this listener runs → delivery settled exactly once;
 *   - failer write throws   → this listener never runs → the delivery stays
 *                             unresolved and the broker redelivers it.
 *
 * Why registration is lazy
 * ------------------------
 * Being *after* the failer listener is the entire mechanism, so registration
 * must not happen at boot or when the queue connection is resolved — an
 * application that touches `Queue::connection()` from a service provider would
 * then put this listener first and reintroduce the bug. Instead it is appended
 * the first time a job actually fails, which is necessarily after the worker
 * command has registered its own listeners.
 *
 * If no failer listener is registered at all (a custom worker, or a queue
 * connection used outside a worker) this listener still runs, because there is
 * then no record to lose.
 */
class SettleFailedDelivery
{
    /**
     * Dispatchers this listener is already attached to, keyed by object id.
     *
     * @var array<int, true>
     */
    private static array $registered = [];

    public function handle(JobFailed $event): void
    {
        if (! $event->job instanceof RabbitMQJob) {
            return;
        }

        $event->job->settleTerminalFailure();
    }

    /**
     * Attach the listener to the application's dispatcher, once per dispatcher.
     */
    public static function ensureRegistered(?Container $container): void
    {
        if ($container === null || ! $container->bound(Dispatcher::class)) {
            return;
        }

        try {
            $dispatcher = $container->make(Dispatcher::class);
        } catch (Throwable) {
            return;
        }

        $dispatcherId = spl_object_id($dispatcher);

        if (isset(self::$registered[$dispatcherId])) {
            return;
        }

        self::$registered[$dispatcherId] = true;

        $dispatcher->listen(JobFailed::class, self::class);
    }

    /**
     * Forget every registration. Intended for tests and for long-lived runtimes
     * that rebuild the container.
     */
    public static function flushRegistrations(): void
    {
        self::$registered = [];
    }
}
