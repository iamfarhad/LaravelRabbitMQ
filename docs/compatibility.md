# Compatibility Guide

## Laravel Horizon

Horizon compatibility is optional and guarded. The package only switches to the Horizon-aware queue class when Horizon is installed and the worker mode is configured as:

```env
RABBITMQ_WORKER=horizon
```

When enabled, the queue emits Horizon-compatible pending, pushed, reserved, deleted, and failed job events while keeping normal Laravel queue behavior available when Horizon is not installed.

## Laravel Octane

The package can reuse AMQP pools across long-running Octane workers for performance. To force a fresh pool after every Octane request, enable:

```env
RABBITMQ_OCTANE_RESET_ON_REQUEST=true
```

This listener is only registered when Octane classes are available.

## after_commit

The connection supports Laravel's `after_commit` queue behavior through:

```env
RABBITMQ_AFTER_COMMIT=true
```

Use this when jobs should only be dispatched after open database transactions commit.

## Lazy connection pools

Lazy connection mode delays creating AMQP connections until the first queue operation. This is useful for CLI commands, Octane workers, and apps that boot the queue connection without always publishing or consuming jobs.

The pool reads the existing host-level `lazy` option and pool-level `lazy` option when present.

## Native network transport

This package uses native `ext-amqp` transport only. Supported transport values are:

- `tcp`
- `ssl`
- `tls`

Set the transport in `queue.connections.rabbitmq.transport`, `queue.connections.rabbitmq.protocol`, or per host as `transport` / `protocol`. The legacy `secure=true` option still maps to SSL behavior.

Example:

```php
'rabbitmq' => [
    'driver' => 'rabbitmq',
    'transport' => 'tls',
    'hosts' => [
        'host' => env('RABBITMQ_HOST', '127.0.0.1'),
        'port' => env('RABBITMQ_PORT', 5671),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
    ],
]
```

## Raw messages and custom jobs

`RabbitMQJob` is extensible. Set a custom job class in:

```php
'options' => [
    'queue' => [
        'job' => App\Queue\Jobs\CustomRabbitMQJob::class,
    ],
],
```

The custom class must extend `iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob`.

The base job exposes helpers for raw RabbitMQ messages:

- `getRawBody()`
- `headers()`
- `exchangeName()`
- `routingKey()`
- `deliveryTag()`
- `getRabbitMQMessage()`

Non-JSON messages are converted to a safe payload array containing `raw`, `headers`, and RabbitMQ metadata, so custom job classes can process external producer messages without requiring Laravel's normal serialized job payload.

## No php-amqplib fallback

This package intentionally does not include a `php-amqplib` fallback transport. Native `ext-amqp` remains the only supported AMQP transport.
