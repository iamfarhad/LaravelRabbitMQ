# Laravel RabbitMQ

[![Latest Stable Version](https://poser.pugx.org/iamfarhad/laravel-rabbitmq/v/stable?format=flat-square)](https://packagist.org/packages/iamfarhad/laravel-rabbitmq)
[![Total Downloads](https://poser.pugx.org/iamfarhad/laravel-rabbitmq/downloads?format=flat-square)](https://packagist.org/packages/iamfarhad/laravel-rabbitmq)
[![License](https://poser.pugx.org/iamfarhad/laravel-rabbitmq/license?format=flat-square)](https://packagist.org/packages/iamfarhad/laravel-rabbitmq)
[![Tests](https://github.com/iamfarhad/LaravelRabbitMQ/actions/workflows/tests.yml/badge.svg)](https://github.com/iamfarhad/LaravelRabbitMQ/actions/workflows/tests.yml)

Native `ext-amqp` RabbitMQ queue driver for Laravel production workloads.

Built for teams that control their infrastructure and want native RabbitMQ performance for long-running Laravel workers, connection/channel pooling, publisher confirms, quorum queues, Horizon support, Octane support, and RabbitMQ 3.13 / 4.x readiness.

## Why this package?

Most Laravel RabbitMQ packages optimize for Composer-only installation. This package intentionally optimizes for production systems where the PHP runtime can include native `ext-amqp`.

Use it when you want:

- Laravel Queue API compatibility.
- Native `ext-amqp` implementation.
- Connection and channel pooling with health checks and retry backoff.
- Multi-host production configuration.
- Configurable exchanges, exchange types, and routing keys.
- Lazy queues, priority queues, quorum queues, delayed messages, dead-letter routing, and failed-message rerouting.
- Publisher confirms, transactions, RPC helpers, exchange helpers, and queue management helpers.
- Optional Laravel Horizon integration.
- Optional Laravel Octane pool reset support.
- Optional high-performance `basic_consume` worker mode.
- Artisan commands for declaring exchanges, declaring queues, purging queues, deleting queues, and viewing pool stats.

## Documentation

- [Why native ext-amqp?](docs/why-native-ext-amqp.md)
- [Installation guide](docs/installation.md)
- [Production deployment guide](docs/production.md)
- [Recipes](docs/recipes.md)
- [Benchmarks](docs/benchmarks.md)
- [Support policy and compatibility matrix](SUPPORT.md)
- [Security policy](SECURITY.md)
- [Contributing guide](CONTRIBUTING.md)
- [Upgrade guide](UPGRADE.md)
- [Migration guide](MIGRATION.md)
- [Comparison with `vladimir-yuldashev/laravel-queue-rabbitmq`](docs/comparison-vladimir-yuldashev.md)

## Requirements

- PHP 8.2 or higher.
- Laravel 10.x, 11.x, 12.x, or 13.x according to the Composer constraints.
- RabbitMQ 3.13 or 4.x for the primary supported/tested matrix; RabbitMQ 3.8-3.12 is best effort.
- `ext-amqp` PHP extension.
- `ext-pcntl` only when running `rabbitmq:consume --num-processes` with a value greater than `1`.

See [SUPPORT.md](SUPPORT.md) for the full Laravel/PHP/RabbitMQ support matrix.

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

For Docker, Alpine, Laravel Sail, and GitHub Actions examples, see the [installation guide](docs/installation.md).

Publish the config:

```bash
php artisan vendor:publish \
  --provider="iamfarhad\\LaravelRabbitMQ\\LaravelRabbitQueueServiceProvider" \
  --tag="config"
```

Set Laravel to use RabbitMQ:

```env
QUEUE_CONNECTION=rabbitmq
```

## Quick start

Start RabbitMQ locally:

```bash
docker run -d --name rabbitmq \
  -p 5672:5672 \
  -p 15672:15672 \
  rabbitmq:3.13-management
```

Configure your application:

```env
QUEUE_CONNECTION=rabbitmq
RABBITMQ_HOST=127.0.0.1
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
RABBITMQ_QUEUE=default
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

## Production baseline

For production, start with explicit heartbeat, timeout, retry, and health-check settings:

```env
QUEUE_CONNECTION=rabbitmq
RABBITMQ_CONSUME_MODE=consume
RABBITMQ_HEARTBEAT_CONNECTION=30
RABBITMQ_CONNECT_TIMEOUT=10
RABBITMQ_READ_TIMEOUT=30
RABBITMQ_WRITE_TIMEOUT=30
RABBITMQ_MAX_RETRIES=3
RABBITMQ_RETRY_DELAY=1000
RABBITMQ_HEALTH_CHECK_ENABLED=true
RABBITMQ_HEALTH_CHECK_INTERVAL=30
```

See [production deployment](docs/production.md) for Supervisor, systemd, Docker Compose, Kubernetes, prefetch, publisher confirms, quorum queues, and dead-letter routing examples.

## Configuration highlights

### Multi-host configuration

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

### Publishing topology

```env
RABBITMQ_EXCHANGE=jobs
RABBITMQ_EXCHANGE_TYPE=topic
RABBITMQ_EXCHANGE_ROUTING_KEY=jobs.%s
```

`%s` is replaced with the Laravel queue name. For example, queue `emails` publishes with routing key `jobs.emails`.

## Worker modes

### Poll mode

`poll` is the default and uses `basic_get`. It is the safest mode and matches Laravel's worker lifecycle expectations.

```bash
php artisan rabbitmq:consume --queue=default --consume-mode=poll
```

### Consume mode

`consume` uses RabbitMQ's `basic_consume` push-style delivery. It avoids polling overhead and is better for hot queues.

```bash
php artisan rabbitmq:consume --queue=default --consume-mode=consume
```

For `consume` mode, prefer one queue per worker process. Scale with Supervisor `numprocs`, containers, or Kubernetes replicas.

## Common recipes

See [recipes](docs/recipes.md) for copy-paste examples covering:

- Delayed jobs.
- Quorum queues.
- Priority queues.
- Publisher confirms.
- Dead-letter routing.
- Horizon.
- Octane.
- Multi-host failover.
- Hot queue workers.

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

## Testing and quality

```bash
composer format-test
composer analyse
composer test
```

## Troubleshooting

### `Class AMQPConnection not found`

Install and enable `ext-amqp`:

```bash
pecl install amqp
php -m | grep amqp
```

For detailed installation options, see [installation guide](docs/installation.md).

### Parallel worker error about `pcntl`

Install `ext-pcntl`, or run a single process:

```bash
php artisan rabbitmq:consume --queue=default --num-processes=1
```

### Horizon events do not appear

Confirm Horizon is installed and set:

```env
RABBITMQ_WORKER=horizon
```

Then restart your workers.

## Support the project

If this package helps you run RabbitMQ in production with Laravel, please consider giving it a star. It helps other production teams discover the project.

## Security

Please report vulnerabilities privately. See [SECURITY.md](SECURITY.md).

## Contributing

Contributions are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request.

## License

The MIT License (MIT). See [LICENSE](LICENSE) for more information.
