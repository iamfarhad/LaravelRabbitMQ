# Laravel RabbitMQ Queue Driver

[![Latest Stable Version](https://poser.pugx.org/iamfarhad/laravel-rabbitmq/v/stable?format=flat-square)](https://packagist.org/packages/iamfarhad/laravel-rabbitmq)
[![Total Downloads](https://poser.pugx.org/iamfarhad/laravel-rabbitmq/downloads?format=flat-square)](https://packagist.org/packages/iamfarhad/laravel-rabbitmq)
[![License](https://poser.pugx.org/iamfarhad/laravel-rabbitmq/license?format=flat-square)](https://packagist.org/packages/iamfarhad/laravel-rabbitmq)
[![Tests](https://github.com/iamfarhad/LaravelRabbitMQ/actions/workflows/test.yml/badge.svg)](https://github.com/iamfarhad/LaravelRabbitMQ/actions/workflows/test.yml)
[![Coding Standards](https://github.com/iamfarhad/LaravelRabbitMQ/actions/workflows/code-style.yml/badge.svg)](https://github.com/iamfarhad/LaravelRabbitMQ/actions/workflows/code-style.yml)

A robust and production-ready RabbitMQ queue driver implementation for Laravel, providing advanced message queuing capabilities with high performance, reliability, and enterprise-grade features. Built with modern PHP architecture and extensive testing.

## Features

### Core Functionality
- **Native Laravel Queue API Integration** - Drop-in replacement for Laravel's default queue drivers
- **Advanced Message Processing** - Support for delayed/scheduled jobs with precise timing
- **Priority Queues** - Process high-priority messages first
- **Parallel Processing** - Multiple consumer processes for improved throughput
- **Error Handling & Retries** - Robust failure handling with automatic retries and backoff strategies

### Enterprise Features
- **SSL/TLS Secure Connections** - Production-ready encrypted communication
- **Quality of Service (QoS)** - Fine-grained control over message prefetching and processing
- **Dead Letter Exchanges** - Automatic handling of failed messages
- **Queue Management** - Automatic queue creation, binding, and management
- **Connection Management** - Lazy connections, keepalive, and heartbeat support

### Developer Experience
- **Comprehensive Testing** - Extensive test suite with Pest framework
- **Modern Architecture** - Clean separation of concerns with contracts and dedicated components
- **Docker Ready** - Optimized for containerized environments
- **Monitoring Support** - Integration-ready for monitoring and observability tools

## Architecture

This package follows modern PHP architecture principles with clear separation of concerns:

### Core Components

- **`LaravelRabbitQueueServiceProvider`** - Main service provider handling registration and bootstrapping
- **`RabbitMQConnector`** - Manages RabbitMQ connections and queue instances
- **`RabbitQueue`** - Core queue implementation with AMQP protocol handling
- **`Consumer`** - Advanced message consumer with daemon capabilities
- **`ConsumeCommand`** - Artisan command for dedicated RabbitMQ message consumption

### Contracts & Interfaces

- **`ConsumerInterface`** - Defines consumer behavior and lifecycle management
- **`RabbitQueueInterface`** - Extends Laravel's Queue contract with RabbitMQ-specific methods

### Support Classes

- **`RabbitMQJob`** - Represents a queued job with RabbitMQ-specific features
- **`MessageHelpers`** - Utility functions for message handling and processing
- **Exception Classes** - Specific exceptions for different error scenarios

## Requirements

- **PHP 8.2+** - Modern PHP with improved performance and type safety
- **Laravel 11.x or 12.x** - Latest Laravel versions with enhanced queue features
- **RabbitMQ Server 3.8+** - Production-ready message broker
- **ext-amqp** - Native AMQP extension for optimal performance
- **ext-pcntl** - Required for parallel processing capabilities

## Support Policy

| Package Version | Laravel Version | PHP Version | Bug Fixes Until   |
|-----------------|-----------------|-------------|-------------------|
| 1.0.x           | 11.x, 12.x      | ^8.2        | December 2025     |

## Installation

### Via Composer

Install the package via Composer:

```bash
composer require iamfarhad/laravel-rabbitmq
```

The package will automatically register itself through Laravel's package discovery.

### Publish Configuration

Publish the configuration file to customize settings:

```bash
php artisan vendor:publish --provider="iamfarhad\LaravelRabbitMQ\LaravelRabbitQueueServiceProvider" --tag="config"
```

This will create `config/rabbitmq.php` with all available configuration options.

### For Lumen

For Lumen applications, manually register the service provider in `bootstrap/app.php`:

```php
$app->register(iamfarhad\LaravelRabbitMQ\LaravelRabbitQueueServiceProvider::class);
```

## Configuration

### Queue Connection Setup

Add the RabbitMQ connection to your `config/queue.php` file:

```php
'connections' => [
    // ... other connections
    
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
                'cafile'      => env('RABBITMQ_SSL_CAFILE', null),
                'local_cert'  => env('RABBITMQ_SSL_LOCALCERT', null),
                'local_key'   => env('RABBITMQ_SSL_LOCALKEY', null),
                'verify_peer' => env('RABBITMQ_SSL_VERIFY_PEER', true),
                'passphrase'  => env('RABBITMQ_SSL_PASSPHRASE', null),
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

### Environment Variables

Add these environment variables to your `.env` file:

```env
# Queue Configuration
QUEUE_CONNECTION=rabbitmq
RABBITMQ_QUEUE=default

# RabbitMQ Connection
RABBITMQ_HOST=127.0.0.1
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/

# Connection Options
RABBITMQ_LAZY_CONNECTION=true
RABBITMQ_KEEPALIVE_CONNECTION=false
RABBITMQ_HEARTBEAT_CONNECTION=0

# SSL/TLS (Optional)
RABBITMQ_SECURE=false
# RABBITMQ_SSL_CAFILE=/path/to/ca.pem
# RABBITMQ_SSL_LOCALCERT=/path/to/cert.pem
# RABBITMQ_SSL_LOCALKEY=/path/to/key.pem
# RABBITMQ_SSL_VERIFY_PEER=true
```

### Docker Environment

For Docker deployments, typical configuration might look like:

```env
RABBITMQ_HOST=rabbitmq
RABBITMQ_PORT=5672
RABBITMQ_USER=laravel
RABBITMQ_PASSWORD=secret
RABBITMQ_VHOST=app
```

## Basic Usage

Once configured, you can use Laravel's Queue API as normal. If you're already familiar with Laravel queues, you don't need to change anything in your code.

### Dispatching Jobs

```php
// Dispatch a job to the default queue
dispatch(new ProcessPodcast($podcast));

// Dispatch a job to a specific queue
dispatch(new ProcessPodcast($podcast))->onQueue('podcasts');

// Dispatch a job with delay
dispatch(new ProcessPodcast($podcast))->delay(now()->addMinutes(10));
```

### Creating Jobs

```php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessPodcast implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private $podcast)
    {
        // Specify a custom queue
        $this->onQueue('podcasts');
    }

    public function handle()
    {
        // Process the podcast...
    }
}
```

## Message Consumption

This package provides two consumption methods, each optimized for different use cases:

### 1. Standard Laravel Queue Worker

Laravel's built-in queue worker for simple use cases:

```bash
php artisan queue:work rabbitmq --queue=default
```

### 2. Dedicated RabbitMQ Consumer (Recommended)

High-performance consumer optimized for RabbitMQ with `basic_consume`:

```bash
php artisan rabbitmq:consume --queue=default
```

### Consumer Command Options

```bash
php artisan rabbitmq:consume [connection] [options]
```

#### Core Options
- `connection` - The queue connection name (optional, defaults to default)
- `--queue=` - The queue names to consume from (can specify multiple)
- `--name=default` - Consumer name for identification
- `--consumer-tag` - Custom consumer tag for RabbitMQ

#### Processing Control
- `--once` - Process only one job and exit
- `--stop-when-empty` - Stop when queue is empty
- `--max-jobs=0` - Maximum jobs before stopping (0 = unlimited)
- `--max-time=0` - Maximum runtime in seconds (0 = unlimited)
- `--memory=128` - Memory limit in megabytes

#### Error Handling
- `--delay=0` - Delay for failed jobs (seconds)
- `--backoff=0` - Backoff time after uncaught exceptions
- `--tries=1` - Number of retry attempts
- `--timeout=60` - Job timeout in seconds
- `--rest=0` - Rest time between jobs

#### Performance
- `--num-processes=2` - Number of parallel processes
- `--max-priority=null` - Maximum priority level to consume
- `--sleep=3` - Sleep time when no jobs available

#### Advanced
- `--force` - Run even in maintenance mode

### Parallel Processing Example

Run multiple consumer processes for high throughput:

```bash
# Single queue with 4 parallel processes
php artisan rabbitmq:consume --queue=high-priority --num-processes=4

# Multiple queues with priority handling
php artisan rabbitmq:consume --queue=critical,high,normal --max-priority=10
```

## Advanced Features

### Priority Queues

Implement priority-based message processing:

```php
// Configure queue with maximum priority (1-255)
'options' => [
    'queue' => [
        'qos' => [
            'prefetch_count' => 10,
            'global' => false
        ],
        'max_priority' => 10, // Enable priority queues
    ]
]

// Dispatch job with priority
$job = new ProcessUrgentTask($data);
dispatch($job->onQueue('urgent-tasks')->withProperty('priority', 8));
```

### Quality of Service (QoS)

Fine-tune message delivery performance:

```php
'options' => [
    'queue' => [
        'qos' => [
            'prefetch_size'  => 0,     // No size limit (recommended)
            'prefetch_count' => 10,    // Messages to prefetch per consumer
            'global'         => false  // Apply per consumer (recommended)
        ]
    ]
]
```

#### QoS Guidelines:
- **High throughput**: `prefetch_count: 20-50`
- **Memory constrained**: `prefetch_count: 1-5`
- **Balanced processing**: `prefetch_count: 10-15`

### SSL/TLS Secure Connections

Enable encrypted connections for production environments:

```php
// In config/queue.php
'hosts' => [
    'secure' => true,
    // ... other host config
],

'options' => [
    'ssl_options' => [
        'cafile'      => '/path/to/ca-certificates.pem',
        'local_cert'  => '/path/to/client-cert.pem',
        'local_key'   => '/path/to/client-key.pem',
        'verify_peer' => true,
        'verify_peer_name' => true,
        'passphrase'  => env('RABBITMQ_SSL_PASSPHRASE'),
    ],
]
```

### Connection Management

Optimize connection handling for different scenarios:

```php
'hosts' => [
    'lazy'      => true,  // Connect only when needed (recommended)
    'keepalive' => false, // Disable for short-lived processes
    'heartbeat' => 60,    // Enable for long-running consumers
],
```

### Queue Management API

Programmatically manage queues:

```php
use iamfarhad\LaravelRabbitMQ\Facades\RabbitMQ;

// Check if queue exists
if (RabbitMQ::queueExists('my-queue')) {
    // Queue operations
}

// Declare queue with options
RabbitMQ::declareQueue('priority-queue', $durable = true, $autoDelete = false, [
    'x-max-priority' => 10
]);

// Purge queue
RabbitMQ::purgeQueue('test-queue');

// Delete queue
RabbitMQ::deleteQueue('temporary-queue');
```

## Error Handling & Reliability

### Automatic Retry Logic

Failed jobs are automatically retried based on configuration:

```php
// In your job class
class ProcessPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;           // Number of attempts
    public $maxExceptions = 2;   // Max exceptions before failing
    public $backoff = [1, 5, 10]; // Progressive backoff in seconds

    public function retryUntil()
    {
        return now()->addMinutes(15); // Stop retrying after 15 minutes
    }

    public function failed(Throwable $exception)
    {
        // Send user notification of failed payment
        // Log to monitoring system
    }
}
```

### Error Types & Handling

```php
// Temporary failures (will retry)
if ($temporaryIssue) {
    $this->release(30); // Retry in 30 seconds
}

// Permanent failures (won't retry)
if ($permanentIssue) {
    $this->fail('Invalid payment data');
}

// Conditional retries
public function shouldRetry(\Throwable $exception): bool
{
    return !($exception instanceof \InvalidArgumentException);
}
```

### Dead Letter Queues

Configure dead letter exchanges for failed messages:

```php
'options' => [
    'queue' => [
        'arguments' => [
            'x-dead-letter-exchange' => 'failed-jobs',
            'x-dead-letter-routing-key' => 'failed',
            'x-message-ttl' => 86400000, // 24 hours in milliseconds
        ]
    ]
]
```

### Monitoring & Observability

Integrate with monitoring systems:

```php
public function failed(\Throwable $exception)
{
    // Log to your monitoring service
    Log::error('Job failed', [
        'job' => static::class,
        'queue' => $this->queue,
        'attempts' => $this->attempts(),
        'exception' => $exception->getMessage(),
    ]);

    // Report to external monitoring
    report($exception);
}
```

## Testing

This package includes comprehensive testing with Pest:

### Running Tests

```bash
# Run all tests
composer test

# Run tests with HTML coverage report
composer test-coverage

# Run tests with Clover coverage report (for CI)
composer test-coverage-clover

# Run tests in parallel
composer test-parallel

# Format code
composer format

# Check code formatting
composer format-test
```

### Test Categories

- **Unit Tests** - Test individual components in isolation
- **Feature Tests** - Test complete workflows and integrations
- **Integration Tests** - Test with actual RabbitMQ server

### Test Environment Setup

For integration tests, ensure RabbitMQ is running:

```bash
# Using Docker
docker run -d --name rabbitmq-test \
  -p 5672:5672 -p 15672:15672 \
  -e RABBITMQ_DEFAULT_USER=laravel \
  -e RABBITMQ_DEFAULT_PASS=secret \
  -e RABBITMQ_DEFAULT_VHOST=b2b-field \
  rabbitmq:3-management
```

## Performance Optimization

### Production Recommendations

1. **Use dedicated consumer processes**:
   ```bash
   php artisan rabbitmq:consume --num-processes=4 --memory=256
   ```

2. **Optimize QoS settings**:
   ```php
   'prefetch_count' => 20, // Higher for CPU-bound jobs
   'prefetch_count' => 5,  // Lower for memory-intensive jobs
   ```

3. **Enable connection pooling**:
   ```php
   'lazy' => true,
   'keepalive' => true,
   'heartbeat' => 60,
   ```

4. **Use supervisor for process management**:
   ```ini
   [program:rabbitmq-consumer]
   command=php artisan rabbitmq:consume --queue=default
   autostart=true
   autorestart=true
   user=www-data
   numprocs=4
   ```

### Monitoring Metrics

Monitor these key metrics in production:

- **Queue depth** - Messages waiting to be processed
- **Consumer throughput** - Messages processed per second
- **Error rates** - Failed job percentage
- **Processing time** - Average job execution time
- **Memory usage** - Consumer memory consumption

## Contributing

We welcome contributions! Please follow these guidelines:

### Development Setup

1. Fork and clone the repository
2. Install dependencies: `composer install`
3. Set up testing environment (see Testing section)
4. Run tests: `composer test`

### Code Standards

- Follow PSR-12 coding standards
- Write comprehensive tests for new features
- Update documentation for API changes
- Use meaningful commit messages

### Pull Request Process

1. Create feature branch: `git checkout -b feature/amazing-feature`
2. Write tests for your changes
3. Ensure all tests pass: `composer test`
4. Format code: `composer format`
5. Commit changes: `git commit -m 'feat: add amazing feature'`
6. Push branch: `git push origin feature/amazing-feature`
7. Open a Pull Request with detailed description

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).

---

**Built with ❤️ for the Laravel community**
