# Migration Guide

This guide helps applications migrate to `iamfarhad/laravel-rabbitmq` from Laravel's built-in queue drivers or other RabbitMQ queue packages.

## Migrate from database, Redis, SQS, or sync queues

1. Install the package and AMQP extension.

```bash
composer require iamfarhad/laravel-rabbitmq
pecl install amqp
```

2. Publish configuration.

```bash
php artisan vendor:publish \
  --provider="iamfarhad\\LaravelRabbitMQ\\LaravelRabbitQueueServiceProvider" \
  --tag="config"
```

3. Configure the queue connection.

```env
QUEUE_CONNECTION=rabbitmq
RABBITMQ_HOST=127.0.0.1
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
RABBITMQ_QUEUE=default
```

4. Run a smoke test.

```bash
php artisan queue:work rabbitmq --queue=default --once
```

5. Move workers gradually by queue name. Keep old workers running until old queues are drained.

## Migrate from `vladimir-yuldashev/laravel-queue-rabbitmq`

The package intentionally keeps Laravel Queue semantics familiar, but it is not a drop-in replacement for every internal config key or extension point.

### Composer package

```bash
composer remove vladimir-yuldashev/laravel-queue-rabbitmq
composer require iamfarhad/laravel-rabbitmq
```

### Connection name

Use the `rabbitmq` connection name:

```env
QUEUE_CONNECTION=rabbitmq
```

### Worker command

You can continue using Laravel's worker:

```bash
php artisan queue:work rabbitmq --queue=default
```

Or use the package consumer command for RabbitMQ-specific worker options:

```bash
php artisan rabbitmq:consume --queue=default --consume-mode=poll
php artisan rabbitmq:consume --queue=default --consume-mode=consume
```

### Configuration mapping

| Existing concept | New setting |
| --- | --- |
| Host, port, user, password, vhost | `RABBITMQ_HOST`, `RABBITMQ_PORT`, `RABBITMQ_USER`, `RABBITMQ_PASSWORD`, `RABBITMQ_VHOST` |
| Queue name | `RABBITMQ_QUEUE` |
| Exchange name | `RABBITMQ_EXCHANGE` |
| Exchange type | `RABBITMQ_EXCHANGE_TYPE` |
| Routing key | `RABBITMQ_EXCHANGE_ROUTING_KEY` |
| SSL/TLS options | `RABBITMQ_SECURE` and `options.ssl_options` in config |
| Dead lettering | `RABBITMQ_REROUTE_FAILED`, `RABBITMQ_FAILED_EXCHANGE`, `RABBITMQ_FAILED_ROUTING_KEY` |
| Quorum queue | `RABBITMQ_QUEUE_QUORUM` or per-queue `quorum` config |
| Lazy queue | `RABBITMQ_QUEUE_LAZY` or per-queue `lazy` config |
| Priority queue | `RABBITMQ_QUEUE_MAX_PRIORITY` or per-queue `priority` config |

### Important behavior checks

- This package uses native `ext-amqp`; make sure the extension is installed in every runtime and CI image.
- The default consume mode is `poll`, using `basic_get`, for Laravel worker lifecycle compatibility.
- `consume` mode uses push-style `basic_consume` and is best with one queue per worker process.
- Quorum queues and priority queues are mutually exclusive in RabbitMQ.
- Restart all workers after changing queue config.

## Migration checklist

- [ ] Confirm PHP, Laravel, RabbitMQ, and `ext-amqp` versions are supported.
- [ ] Publish and review `config/rabbitmq.php`.
- [ ] Configure connection credentials without committing secrets.
- [ ] Declare or verify exchanges, queues, and bindings.
- [ ] Run one job through `queue:work rabbitmq`.
- [ ] Run one job through `rabbitmq:consume` if you plan to use it.
- [ ] Verify failed-job behavior and dead-letter routing.
- [ ] Restart all workers.
- [ ] Monitor ready/unacked/dead-letter queue counts during rollout.
