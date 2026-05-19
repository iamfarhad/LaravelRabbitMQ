# Recipes

Short, copy-paste recipes for common production RabbitMQ patterns in Laravel.

## Delayed jobs

Use Laravel's delay API:

```php
dispatch(new App\Jobs\SendReminder($user))->delay(now()->addMinutes(10));
```

The package creates delay queues using TTL and dead-letter routing unless the delayed-message plugin path is enabled.

For the RabbitMQ delayed-message plugin:

```env
RABBITMQ_DELAYED_PLUGIN_ENABLED=true
RABBITMQ_DELAYED_EXCHANGE=delayed
```

## Quorum queues

Enable quorum queues globally:

```env
RABBITMQ_QUEUE_QUORUM=true
```

Or per queue:

```php
'queues' => [
    'orders' => [
        'name' => 'orders',
        'quorum' => true,
    ],
],
```

Do not combine quorum queues with priority queues.

## Priority queues

```env
RABBITMQ_PRIORITIZE_DELAYED=true
RABBITMQ_QUEUE_MAX_PRIORITY=10
```

Per queue:

```php
'queues' => [
    'critical' => [
        'name' => 'critical',
        'priority' => 10,
    ],
],
```

## Publisher confirms

```env
RABBITMQ_PUBLISHER_CONFIRMS_ENABLED=true
RABBITMQ_PUBLISHER_CONFIRMS_TIMEOUT=5
```

Use this for workflows where RabbitMQ must confirm message receipt before the application considers the publish successful.

## Dead-letter routing

```env
RABBITMQ_REROUTE_FAILED=true
RABBITMQ_FAILED_EXCHANGE=failed.jobs
RABBITMQ_FAILED_ROUTING_KEY=%s.failed
```

Declare and monitor the failed exchange and queues as part of your deployment process.

## Horizon

```env
RABBITMQ_WORKER=horizon
```

Install Laravel Horizon in the application. The package keeps Horizon integration guarded, so this setting only takes effect when Horizon classes are available.

## Octane

For maximum performance, leave pool reuse enabled:

```env
RABBITMQ_OCTANE_RESET_ON_REQUEST=false
```

If an application needs a fresh pool after each Octane request:

```env
RABBITMQ_OCTANE_RESET_ON_REQUEST=true
```

## Multi-host failover

```php
'hosts' => [
    [
        'host' => 'rabbitmq-1',
        'port' => 5672,
        'user' => 'laravel',
        'password' => 'secret',
        'vhost' => '/',
    ],
    [
        'host' => 'rabbitmq-2',
        'port' => 5672,
        'user' => 'laravel',
        'password' => 'secret',
        'vhost' => '/',
    ],
],
```

The connector selects a host for each new connection attempt.

## Hot queue worker

```bash
php artisan rabbitmq:consume --queue=emails --consume-mode=consume --memory=256 --tries=3 --timeout=60
```

Use one queue per worker group in consume mode. Scale with more worker processes or replicas.

## Safe default worker

```bash
php artisan rabbitmq:consume --queue=default --consume-mode=poll --memory=256 --tries=3 --timeout=60
```

Poll mode is a conservative default and matches Laravel worker expectations closely.
