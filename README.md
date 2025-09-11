# Laravel RabbitMQ

[![Latest Stable Version](https://poser.pugx.org/iamfarhad/laravel-rabbitmq/v/stable?format=flat-square)](https://packagist.org/packages/iamfarhad/laravel-rabbitmq)
[![Total Downloads](https://poser.pugx.org/iamfarhad/laravel-rabbitmq/downloads?format=flat-square)](https://packagist.org/packages/iamfarhad/laravel-rabbitmq)
[![License](https://poser.pugx.org/iamfarhad/laravel-rabbitmq/license?format=flat-square)](https://packagist.org/packages/iamfarhad/laravel-rabbitmq)
[![Tests](https://github.com/iamfarhad/LaravelRabbitMQ/actions/workflows/tests.yml/badge.svg)](https://github.com/iamfarhad/LaravelRabbitMQ/actions/workflows/test.yml)

A RabbitMQ queue driver for Laravel featuring native Laravel Queue API integration with advanced message queuing capabilities.

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Advanced Features](#advanced-features)
- [Testing](#testing)
- [Contributing](#contributing)
- [License](#license)

## Requirements

- PHP 8.2 or higher
- Laravel 11.x or 12.x
- RabbitMQ Server 3.8+
- ext-amqp PHP extension
- ext-pcntl PHP extension (for parallel processing)

## Installation

### Install via Composer

```bash
composer require iamfarhad/laravel-rabbitmq
```

### Publish Configuration

```bash
php artisan vendor:publish --provider="iamfarhad\LaravelRabbitMQ\LaravelRabbitQueueServiceProvider" --tag="config"
```

### Lumen Installation

Register the service provider in `bootstrap/app.php`:

```php
$app->register(iamfarhad\LaravelRabbitMQ\LaravelRabbitQueueServiceProvider::class);
```

## Configuration

### 1. Update Queue Configuration

Add the RabbitMQ connection to `config/queue.php`:

```php
'connections' => [
    'rabbitmq' => [
        'driver' => 'rabbitmq',
        'queue'  => env('RABBITMQ_QUEUE', 'default'),
        
        'hosts' => [
            'host'      => env('RABBITMQ_HOST', '127.0.0.1'),
            'port'      => env('RABBITMQ_PORT', 5672),
            'user'      => env('RABBITMQ_USER', 'guest'),
            'password'  => env('RABBITMQ_PASSWORD', 'guest'),
            'vhost'     => env('RABBITMQ_VHOST', '/'),
            'lazy'      => env('RABBITMQ_LAZY_CONNECTION', true),
            'keepalive' => env('RABBITMQ_KEEPALIVE_CONNECTION', false),
            'heartbeat' => env('RABBITMQ_HEARTBEAT_CONNECTION', 0),
            'secure'    => env('RABBITMQ_SECURE', false),
        ],
        
        'options' => [
            'ssl_options' => [
                'cafile'      => env('RABBITMQ_SSL_CAFILE'),
                'local_cert'  => env('RABBITMQ_SSL_LOCALCERT'),
                'local_key'   => env('RABBITMQ_SSL_LOCALKEY'),
                'verify_peer' => env('RABBITMQ_SSL_VERIFY_PEER', true),
                'passphrase'  => env('RABBITMQ_SSL_PASSPHRASE'),
            ],
            'queue' => [
                'job' => \iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob::class,
                'qos' => [
                    'prefetch_size'  => 0,
                    'prefetch_count' => 10,
                    'global'         => false
                ]
            ],
        ],
    ],
]
```

### 2. Environment Configuration

Add to your `.env` file:

```env
QUEUE_CONNECTION=rabbitmq

# RabbitMQ Connection
RABBITMQ_HOST=127.0.0.1
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
RABBITMQ_QUEUE=default

# Connection Options
RABBITMQ_LAZY_CONNECTION=true
RABBITMQ_KEEPALIVE_CONNECTION=false
RABBITMQ_HEARTBEAT_CONNECTION=0

# SSL/TLS Configuration (Optional)
RABBITMQ_SECURE=false
#RABBITMQ_SSL_CAFILE=/path/to/ca.pem
#RABBITMQ_SSL_LOCALCERT=/path/to/cert.pem
#RABBITMQ_SSL_LOCALKEY=/path/to/key.pem
#RABBITMQ_SSL_VERIFY_PEER=true
```

## Usage

### Basic Job Dispatching

```php
// Dispatch to default queue
dispatch(new ProcessPodcast($podcast));

// Dispatch to specific queue
dispatch(new ProcessPodcast($podcast))->onQueue('podcasts');

// Delayed dispatch
dispatch(new ProcessPodcast($podcast))->delay(now()->addMinutes(10));
```

### Creating Jobs

```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessPodcast implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Podcast $podcast
    ) {}

    public function handle(): void
    {
        // Process the podcast...
    }
}
```

### Running Workers

#### Standard Laravel Worker

```bash
php artisan queue:work rabbitmq --queue=default
```

#### Dedicated RabbitMQ Consumer (Recommended)

```bash
php artisan rabbitmq:consume --queue=default
```

### Consumer Command Options

| Option | Description | Default |
|--------|-------------|---------|
| `--queue` | Queue names to process (comma separated) | default |
| `--once` | Process single job and exit | false |
| `--stop-when-empty` | Stop when queue is empty | false |
| `--delay` | Delay for failed jobs (seconds) | 0 |
| `--memory` | Memory limit in MB | 128 |
| `--timeout` | Job timeout in seconds | 60 |
| `--tries` | Number of attempts | 1 |
| `--sleep` | Sleep when no jobs available | 3 |
| `--num-processes` | Number of parallel processes | 2 |
| `--max-jobs` | Maximum jobs before restart | 0 |
| `--max-time` | Maximum runtime in seconds | 0 |

## Advanced Features

### Priority Queues

Enable priority-based processing:

```php
// In config/queue.php
'options' => [
    'queue' => [
        'arguments' => [
            'x-max-priority' => 10
        ]
    ]
]

// Dispatch with priority
dispatch(new UrgentJob($data))->onQueue('priority')->withPriority(8);
```

### Quality of Service (QoS)

Configure message prefetching:

```php
'options' => [
    'queue' => [
        'qos' => [
            'prefetch_size'  => 0,
            'prefetch_count' => 10,
            'global'         => false
        ]
    ]
]
```

### SSL/TLS Support

Enable secure connections:

```php
'hosts' => [
    'secure' => true,
    // other host config...
],

'options' => [
    'ssl_options' => [
        'cafile'      => '/path/to/ca.pem',
        'local_cert'  => '/path/to/cert.pem',
        'local_key'   => '/path/to/key.pem',
        'verify_peer' => true,
    ],
]
```

### Failed Job Handling

Configure retry behavior in your job:

```php
class ProcessPayment implements ShouldQueue
{
    public $tries = 3;
    public $maxExceptions = 2;
    public $backoff = [1, 5, 10];

    public function retryUntil(): DateTime
    {
        return now()->addMinutes(15);
    }

    public function failed(Throwable $exception): void
    {
        // Handle failure
    }
}
```

### Queue Management

```php
use iamfarhad\LaravelRabbitMQ\Facades\RabbitMQ;

// Check if queue exists
if (RabbitMQ::queueExists('my-queue')) {
    // Queue exists
}

// Declare a new queue
RabbitMQ::declareQueue('my-queue', $durable = true);

// Purge queue messages
RabbitMQ::purgeQueue('my-queue');

// Delete queue
RabbitMQ::deleteQueue('my-queue');
```

## Production Deployment

### Supervisor Configuration

```ini
[program:laravel-rabbitmq]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/artisan rabbitmq:consume --queue=default --memory=256
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/path/to/logs/rabbitmq.log
stopwaitsecs=3600
```

### Docker Setup

```yaml
version: '3.8'

services:
  app:
    build: .
    environment:
      RABBITMQ_HOST: rabbitmq
      RABBITMQ_PORT: 5672
      RABBITMQ_USER: laravel
      RABBITMQ_PASSWORD: secret
    depends_on:
      - rabbitmq

  rabbitmq:
    image: rabbitmq:3-management
    ports:
      - "5672:5672"
      - "15672:15672"
    environment:
      RABBITMQ_DEFAULT_USER: laravel
      RABBITMQ_DEFAULT_PASS: secret
    volumes:
      - rabbitmq_data:/var/lib/rabbitmq

volumes:
  rabbitmq_data:
```

## Testing

### Running Tests

```bash
# Run all tests
composer test

# Run specific test suites
composer test:unit
composer test:feature

# Code formatting
composer format
composer format-test
```

### Test Environment

Set up RabbitMQ for testing:

```bash
docker run -d --name rabbitmq-test \
  -p 5672:5672 -p 15672:15672 \
  rabbitmq:3-management
```

## Troubleshooting

### Common Issues

#### Connection Refused

Ensure RabbitMQ is running and accessible:

```bash
# Check RabbitMQ status
docker ps | grep rabbitmq

# Test connection
telnet localhost 5672
```

#### Permission Denied

Verify user permissions in RabbitMQ:

```bash
# Access RabbitMQ management UI
# http://localhost:15672
# Default: guest/guest
```

#### Memory Issues

Adjust consumer memory limits:

```bash
php artisan rabbitmq:consume --memory=512
```

## Architecture

### Components

- **RabbitQueue**: Core queue implementation
- **Consumer**: Message consumer with daemon support
- **RabbitMQJob**: Job representation with RabbitMQ features
- **RabbitMQConnector**: Connection management
- **ConsumeCommand**: Artisan command for consumption

### Contracts

- **RabbitQueueInterface**: Extended queue contract

## Contributing

1. Fork the repository
2. Create feature branch (`git checkout -b feature/amazing-feature`)
3. Commit changes (`git commit -m 'Add amazing feature'`)
4. Push to branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

### Development Standards

- Follow PSR-12 coding standards
- Write tests for new features
- Update documentation
- Use conventional commits

## Support

- [Issues](https://github.com/iamfarhad/LaravelRabbitMQ/issues)
- [Discussions](https://github.com/iamfarhad/LaravelRabbitMQ/discussions)

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).