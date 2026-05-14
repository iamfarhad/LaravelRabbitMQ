# Laravel RabbitMQ

[![Latest Stable Version](https://poser.pugx.org/iamfarhad/laravel-rabbitmq/v/stable?format=flat-square)](https://packagist.org/packages/iamfarhad/laravel-rabbitmq)
[![Total Downloads](https://poser.pugx.org/iamfarhad/laravel-rabbitmq/downloads?format=flat-square)](https://packagist.org/packages/iamfarhad/laravel-rabbitmq)
[![License](https://poser.pugx.org/iamfarhad/laravel-rabbitmq/license?format=flat-square)](https://packagist.org/packages/iamfarhad/laravel-rabbitmq)
[![Tests](https://github.com/iamfarhad/LaravelRabbitMQ/actions/workflows/tests.yml/badge.svg)](https://github.com/iamfarhad/LaravelRabbitMQ/actions/workflows/tests.yml)

A production-focused RabbitMQ queue driver for Laravel with native Laravel Queue integration, connection/channel pooling, configurable RabbitMQ topology, Horizon hooks, Octane-safe pool reset support, and an optional high-performance `basic_consume` worker mode.

## Features

- Laravel Queue API compatible RabbitMQ driver.
- Native `ext-amqp` implementation for high-performance AMQP operations.
- Connection and channel pooling with health checks and retry backoff.
- Backward-compatible single-host config and production multi-host config.
- Connection timeout support: read, write, and connect timeout.
- Configurable exchange, exchange type, and routing-key publishing for normal Laravel jobs.
- Lazy queues, priority queues, quorum queues, delayed messages, dead-letter routing, and failed-message rerouting.
- Publisher confirms, transactions, RPC helpers, exchange helpers, and queue management helpers.
- Optional Laravel Horizon integration via `RABBITMQ_WORKER=horizon`.
- Optional Laravel Octane pool reset via `RABBITMQ_OCTANE_RESET_ON_REQUEST=true`.
- Optional `basic_consume` worker mode via `RABBITMQ_CONSUME_MODE=consume` or `--consume-mode=consume`.
- Artisan admin commands for declaring exchanges, declaring queues, purging queues, deleting queues, and viewing pool stats.

## Requirements

- PHP 8.2 or higher.
- Laravel 11.x, 12.x, or 13.x.
- RabbitMQ 3.8 or higher.
- `ext-amqp` PHP extension.
- `ext-pcntl` is optional and only required when running `rabbitmq:consume --num-processes` with a value greater than `1`.

## Installation

```bash
composer require iamfarhad/laravel-rabbitmq
```

Install the AMQP extension when it is not already available:

```bash
pecl install amqp
```

For Debian/Ubuntu images, install the native dependency first:

```bash
sudo apt-get update
sudo apt-get install -y librabbitmq-dev libssh-dev
sudo pecl install amqp
```

Publish the config:

```bash
php artisan vendor:publish \
  --provider="iamfarhad\LaravelRabbitMQ\LaravelRabbitQueueServiceProvider" \
  --tag="config"
```

Set Laravel to use RabbitMQ:

```env
QUEUE_CONNECTION=rabbitmq
```

## Quick start

```env
QUEUE_CONNECTION=rabbitmq
RABBITMQ_HOST=127.0.0.1
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
RABBITMQ_QUEUE=default
```

Start RabbitMQ locally:

```bash
docker run -d --name rabbitmq \
  -p 5672:5672 \
  -p 15672:15672 \
  rabbitmq:3-management
```

Dispatch Laravel jobs normally:

```php
dispatch(new App\Jobs\ProcessPodcast($podcast));
dispatch(new App\Jobs\ProcessPodcast($podcast))->onQueue('podcasts');
dispatch(new App\Jobs\ProcessPodcast($podcast))->delay(now()->addMinutes(10));
```

Run a worker:

```bash
php artisan rabbitmq:consume --queue=default --num-processes=1
```

Laravel's default worker also works:

```bash
php artisan queue:work rabbitmq --queue=default
```

## Configuration

The package merges `config/rabbitmq.php` into `queue.connections.rabbitmq`, so you can configure the package from the published config file or directly in `config/queue.php`.

### Single-host configuration

```php
'rabbitmq' => [
    'driver' => 'rabbitmq',
    'queue' => env('RABBITMQ_QUEUE', 'default'),
    'hosts' => [
        'host' => env('RABBITMQ_HOST', '127.0.0.1'),
        'port' => env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
        'heartbeat' => env('RABBITMQ_HEARTBEAT_CONNECTION', 0),
        'read_timeout' => env('RABBITMQ_READ_TIMEOUT', 0),
        'write_timeout' => env('RABBITMQ_WRITE_TIMEOUT', 0),
        'connect_timeout' => env('RABBITMQ_CONNECT_TIMEOUT', 0),
    ],
]
```

### Multi-host configuration

Use a list of hosts for basic high-availability. The connector randomly selects a host for each new connection attempt.

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

### Pool configuration

```env
RABBITMQ_MAX_CONNECTIONS=10
RABBITMQ_MIN_CONNECTIONS=2
RABBITMQ_MAX_CHANNELS_PER_CONNECTION=100
RABBITMQ_MAX_RETRIES=3
RABBITMQ_RETRY_DELAY=1000
RABBITMQ_HEALTH_CHECK_ENABLED=true
RABBITMQ_HEALTH_CHECK_INTERVAL=30
```

| Setting | Default | Description |
| --- | --- | --- |
| `max_connections` | `10` | Maximum open AMQP connections. |
| `min_connections` | `2` | Minimum warm connections to keep available. |
| `max_channels_per_connection` | `100` | Channel cap per AMQP connection. |
| `max_retries` | `3` | Connection retry attempts. |
| `retry_delay` | `1000` | Initial retry delay in milliseconds. |
| `health_check_enabled` | `true` | Enable pool health checks. |
| `health_check_interval` | `30` | Health check interval in seconds. |

## Publishing topology

By default the package preserves RabbitMQ's default exchange behavior and routes jobs directly to the queue name. For production routing, configure an exchange and routing-key pattern:

```env
RABBITMQ_EXCHANGE=jobs
RABBITMQ_EXCHANGE_TYPE=topic
RABBITMQ_EXCHANGE_ROUTING_KEY=jobs.%s
```

`%s` is replaced with the Laravel queue name. For example, queue `emails` publishes with routing key `jobs.emails`.

Supported exchange types:

- `direct`
- `fanout`
- `topic`
- `headers`

## Queue options

### Lazy queues

```env
RABBITMQ_QUEUE_LAZY=true
```

Or per queue:

```php
'queues' => [
    'bulk' => [
        'name' => 'bulk',
        'lazy' => true,
    ],
],
```

### Priority queues

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

### Quorum queues

```env
RABBITMQ_QUEUE_QUORUM=true
```

Per queue:

```php
'queues' => [
    'orders' => [
        'name' => 'orders',
        'quorum' => true,
    ],
],
```

Priority queues and quorum queues are mutually exclusive in RabbitMQ. When quorum mode is enabled, the package does not set `x-max-priority`.

### Failed-message rerouting

```env
RABBITMQ_REROUTE_FAILED=true
RABBITMQ_FAILED_EXCHANGE=failed.jobs
RABBITMQ_FAILED_ROUTING_KEY=%s.failed
```

When enabled, declared queues receive dead-letter arguments pointing failed messages to the configured failed exchange and routing key.

## Worker modes

### Poll mode

`poll` is the default and uses `basic_get`. It is the safest mode and matches Laravel's worker lifecycle expectations.

```bash
php artisan rabbitmq:consume --queue=default --consume-mode=poll
```

### Consume mode

`consume` uses RabbitMQ's `basic_consume` push-style delivery. It avoids polling overhead and is better for hot queues, but it should be used with one queue per worker process.

```bash
php artisan rabbitmq:consume --queue=default --consume-mode=consume
```

Or configure it globally:

```env
RABBITMQ_CONSUME_MODE=consume
```

### Parallel workers

```bash
php artisan rabbitmq:consume --queue=default --num-processes=4
```

`ext-pcntl` is required only for `--num-processes` greater than `1`.

## Laravel Horizon

Install Horizon in your Laravel application, then enable the Horizon queue integration:

```env
RABBITMQ_WORKER=horizon
```

When Horizon is installed and `RABBITMQ_WORKER=horizon` is set, the package uses an optional Horizon-aware queue class and dispatches Horizon events for:

- pending jobs
- pushed jobs
- reserved jobs
- deleted jobs
- failed jobs

The package does not require Horizon. All Horizon references are guarded and inactive when Horizon is not installed.

## Laravel Octane

Long-running Octane workers can reuse AMQP pools across requests. That is usually best for performance. If your application needs a fresh pool after every Octane request, enable:

```env
RABBITMQ_OCTANE_RESET_ON_REQUEST=true
```

This hook is optional and only active when Laravel Octane is installed.

## Admin commands

```bash
# Pool stats
php artisan rabbitmq:pool-stats
php artisan rabbitmq:pool-stats --json
php artisan rabbitmq:pool-stats --watch --interval=5

# Exchanges
php artisan rabbitmq:exchange-declare jobs --type=topic

# Queues
php artisan rabbitmq:queue-declare orders --durable=1
php artisan rabbitmq:queue-declare bulk --lazy=1
php artisan rabbitmq:queue-declare quorum-orders --quorum=1
php artisan rabbitmq:queue-declare critical --priority=10
php artisan rabbitmq:queue-purge orders --force
php artisan rabbitmq:queue-delete orders --force
```

## Advanced helpers

### Publish to an exchange

```php
$queue = Queue::connection('rabbitmq');

$queue->publishToExchange(
    'notifications',
    json_encode(['message' => 'Welcome']),
    'user.created.email',
    ['source' => 'users']
);
```

### Delayed messages

```php
$queue->publishDelayed('emails', $payload, delay: 3600);
```

For the RabbitMQ delayed-message plugin:

```env
RABBITMQ_DELAYED_PLUGIN_ENABLED=true
RABBITMQ_DELAYED_EXCHANGE=delayed
```

### Publisher confirms

```env
RABBITMQ_PUBLISHER_CONFIRMS_ENABLED=true
RABBITMQ_PUBLISHER_CONFIRMS_TIMEOUT=5
```

### RPC

```env
RABBITMQ_RPC_ENABLED=true
RABBITMQ_RPC_TIMEOUT=30
```

```php
$response = Queue::connection('rabbitmq')->rpcCall(
    'rpc-queue',
    json_encode(['action' => 'calculate'])
);
```

### Transactions

```env
RABBITMQ_TRANSACTIONS_ENABLED=true
```

```php
Queue::connection('rabbitmq')->transaction(function ($queue) {
    $queue->push(new App\Jobs\ProcessOrder(1));
    $queue->push(new App\Jobs\ProcessOrder(2));
});
```

## Environment variables

```env
QUEUE_CONNECTION=rabbitmq
RABBITMQ_QUEUE=default
RABBITMQ_HOST=127.0.0.1
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
RABBITMQ_HEARTBEAT_CONNECTION=0
RABBITMQ_READ_TIMEOUT=0
RABBITMQ_WRITE_TIMEOUT=0
RABBITMQ_CONNECT_TIMEOUT=0
RABBITMQ_SECURE=false

RABBITMQ_MAX_CONNECTIONS=10
RABBITMQ_MIN_CONNECTIONS=2
RABBITMQ_MAX_CHANNELS_PER_CONNECTION=100
RABBITMQ_MAX_RETRIES=3
RABBITMQ_RETRY_DELAY=1000
RABBITMQ_HEALTH_CHECK_ENABLED=true
RABBITMQ_HEALTH_CHECK_INTERVAL=30

RABBITMQ_EXCHANGE=
RABBITMQ_EXCHANGE_TYPE=direct
RABBITMQ_EXCHANGE_ROUTING_KEY=%s
RABBITMQ_PRIORITIZE_DELAYED=false
RABBITMQ_QUEUE_MAX_PRIORITY=10
RABBITMQ_QUEUE_LAZY=false
RABBITMQ_QUEUE_QUORUM=false
RABBITMQ_REROUTE_FAILED=false
RABBITMQ_FAILED_EXCHANGE=
RABBITMQ_FAILED_ROUTING_KEY=%s.failed

RABBITMQ_WORKER=default
RABBITMQ_CONSUME_MODE=poll
RABBITMQ_OCTANE_RESET_ON_REQUEST=false

RABBITMQ_PUBLISHER_CONFIRMS_ENABLED=false
RABBITMQ_PUBLISHER_CONFIRMS_TIMEOUT=5
RABBITMQ_RPC_ENABLED=false
RABBITMQ_RPC_TIMEOUT=30
RABBITMQ_TRANSACTIONS_ENABLED=false
RABBITMQ_DELAYED_PLUGIN_ENABLED=false
RABBITMQ_DELAYED_EXCHANGE=delayed
```

## Production deployment

### Supervisor example

```ini
[program:laravel-rabbitmq]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/artisan rabbitmq:consume --queue=default --consume-mode=consume --memory=256 --tries=3 --timeout=60 --num-processes=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/supervisor/laravel-rabbitmq.log
stopwaitsecs=3600
```

For `--consume-mode=consume`, prefer one queue per worker process. Scale with Supervisor `numprocs`, containers, or process managers rather than a comma-separated queue list.

### Docker notes

Install AMQP in your PHP image:

```dockerfile
RUN apt-get update && apt-get install -y \
    librabbitmq-dev \
    libssh-dev \
    && pecl install amqp \
    && docker-php-ext-enable amqp
```

Install `pcntl` only if you use multi-process consumers inside the same PHP process:

```dockerfile
RUN docker-php-ext-install pcntl
```

## Testing and quality

```bash
composer format-test
composer analyse
composer test
```

The GitHub Actions test workflow runs Pint, PHPStan, and PHPUnit across the supported Laravel/PHP matrix with a RabbitMQ service container.

## Troubleshooting

### `Class AMQPConnection not found`

Install and enable `ext-amqp`:

```bash
pecl install amqp
php -m | grep amqp
```

### Parallel worker error about `pcntl`

Install `ext-pcntl`, or run a single process:

```bash
php artisan rabbitmq:consume --queue=default --num-processes=1
```

### Queue size or queue existence checks return zero after missing queue errors

RabbitMQ closes a channel after a passive queue declaration miss. The package releases that cached channel and uses a fresh channel on the next operation.

### Horizon events do not appear

Confirm Horizon is installed and set:

```env
RABBITMQ_WORKER=horizon
```

Then restart your workers.

## License

The MIT License (MIT). See [LICENSE](LICENSE) for more information.
