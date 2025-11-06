# Laravel RabbitMQ

[![Latest Stable Version](https://poser.pugx.org/iamfarhad/laravel-rabbitmq/v/stable?format=flat-square)](https://packagist.org/packages/iamfarhad/laravel-rabbitmq)
[![Total Downloads](https://poser.pugx.org/iamfarhad/laravel-rabbitmq/downloads?format=flat-square)](https://packagist.org/packages/iamfarhad/laravel-rabbitmq)
[![License](https://poser.pugx.org/iamfarhad/laravel-rabbitmq/license?format=flat-square)](https://packagist.org/packages/iamfarhad/laravel-rabbitmq)
[![Tests](https://github.com/iamfarhad/LaravelRabbitMQ/actions/workflows/tests.yml/badge.svg)](https://github.com/iamfarhad/LaravelRabbitMQ/actions/workflows/test.yml)

A robust RabbitMQ queue driver for Laravel featuring native Laravel Queue API integration, **advanced connection pooling**, **channel management**, and **high-performance async processing** capabilities.

## ✨ Key Features

### Core Features
- 🚀 **High-Performance Connection Pooling** - Reuse connections efficiently with configurable limits
- 🔄 **Advanced Channel Management** - Optimal AMQP channel pooling and lifecycle management  
- 🛡️ **Resilient Retry Strategy** - Exponential backoff with configurable retry attempts
- 📊 **Real-time Pool Monitoring** - Built-in statistics and health monitoring
- 🔧 **Production Ready** - Comprehensive error handling and graceful shutdowns
- ⚡ **Laravel Native** - Seamless integration with Laravel Queue API
- 🎯 **Zero Configuration** - Works out of the box with sensible defaults

### Advanced Features
- 🔀 **Advanced Routing** - Topic, fanout, and headers exchanges for complex routing patterns
- 🎯 **Multi-Queue Support** - Configure and manage multiple queues with different settings
- 📈 **Exponential Backoff** - Intelligent retry mechanism with jitter to prevent thundering herd
- 💀 **Dead Letter Exchange** - Automatic handling of failed messages with DLX
- 🔁 **RPC Support** - Synchronous request-response pattern for remote procedure calls
- ✅ **Publisher Confirms** - Reliable message delivery with broker acknowledgments
- 🔒 **Transaction Management** - Atomic operations with commit/rollback support
- ⏰ **Delayed Messages** - Schedule messages for future delivery (TTL or plugin-based)
- 🐌 **Lazy Queues** - Optimize memory usage for high-volume message queues
- ⚖️ **Consumer Priorities** - Control message distribution with priority consumers
- 🔧 **Advanced Queue Options** - Full control over queue arguments and behavior

## Table of Contents

- [Key Features](#-key-features)
- [Requirements](#requirements)
- [Quick Start](#quick-start)
- [Installation](#installation)
- [Configuration](#configuration)
  - [Basic Configuration](#basic-configuration)
  - [Connection Pooling](#connection-pooling)
  - [Environment Variables](#environment-variables)
- [Usage](#usage)
  - [Basic Usage](#basic-usage)
  - [Pool Monitoring](#pool-monitoring)
- [Advanced Features](#advanced-features)
- [Performance Tuning](#performance-tuning)
- [Production Deployment](#production-deployment)
- [Testing](#testing)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [License](#license)

## Requirements

- PHP 8.2 or higher
- Laravel 11.x or 12.x
- RabbitMQ Server 3.8+
- ext-amqp PHP extension
- ext-pcntl PHP extension (for parallel processing)

## Quick Start

Get up and running in 5 minutes:

```bash
# 1. Install package
composer require iamfarhad/laravel-rabbitmq

# 2. Install AMQP extension (if not already installed)
pecl install amqp

# 3. Start RabbitMQ (Docker)
docker run -d --name rabbitmq \
  -p 5672:5672 -p 15672:15672 \
  rabbitmq:3-management

# 4. Configure .env
echo "QUEUE_CONNECTION=rabbitmq" >> .env
echo "RABBITMQ_HOST=localhost" >> .env
echo "RABBITMQ_PORT=5672" >> .env
echo "RABBITMQ_USER=guest" >> .env
echo "RABBITMQ_PASSWORD=guest" >> .env

# 5. Create a job
php artisan make:job ProcessPodcast

# 6. Dispatch the job
php artisan tinker
>>> dispatch(new App\Jobs\ProcessPodcast());

# 7. Start worker
php artisan rabbitmq:consume --queue=default
```

That's it! Your jobs are now processing through RabbitMQ. 🎉

## Installation

### Install via Composer

```bash
composer require iamfarhad/laravel-rabbitmq
```

### Install AMQP Extension

The package requires the `ext-amqp` PHP extension. Install it based on your environment:

#### macOS (Homebrew)

```bash
brew install rabbitmq-c
pecl install amqp
```

#### Ubuntu/Debian

```bash
sudo apt-get install librabbitmq-dev libssh-dev
sudo pecl install amqp
```

#### Docker

Add to your Dockerfile:

```dockerfile
# Install dependencies
RUN apt-get update && apt-get install -y \
    librabbitmq-dev \
    libssh-dev

# Install AMQP extension
RUN pecl install amqp && docker-php-ext-enable amqp
```

#### Verify Installation

```bash
php -m | grep amqp
```

You should see `amqp` in the output.

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

### Basic Configuration

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
            'heartbeat' => env('RABBITMQ_HEARTBEAT_CONNECTION', 0),
        ],
        
        // 🚀 Connection and Channel Pool Configuration
        'pool' => [
            // Connection Pool Settings
            'max_connections' => env('RABBITMQ_MAX_CONNECTIONS', 10),
            'min_connections' => env('RABBITMQ_MIN_CONNECTIONS', 2),
            
            // Channel Pool Settings  
            'max_channels_per_connection' => env('RABBITMQ_MAX_CHANNELS_PER_CONNECTION', 100),
            
            // Retry Strategy
            'max_retries' => env('RABBITMQ_MAX_RETRIES', 3),
            'retry_delay' => env('RABBITMQ_RETRY_DELAY', 1000), // milliseconds
            
            // Health Check Settings
            'health_check_enabled' => env('RABBITMQ_HEALTH_CHECK_ENABLED', true),
            'health_check_interval' => env('RABBITMQ_HEALTH_CHECK_INTERVAL', 30), // seconds
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

### Connection Pooling

The package features advanced connection and channel pooling for optimal performance:

#### Connection Pool Benefits
- **Resource Efficiency**: Reuse existing connections instead of creating new ones
- **Performance**: Significantly faster job processing with reduced connection overhead
- **Reliability**: Automatic health monitoring and dead connection cleanup
- **Scalability**: Configurable limits to handle varying workloads

#### Pool Configuration Options

| Setting | Default | Description |
|---------|---------|-------------|
| `max_connections` | 10 | Maximum number of connections in the pool |
| `min_connections` | 2 | Minimum connections to maintain |
| `max_channels_per_connection` | 100 | Maximum channels per connection |
| `max_retries` | 3 | Connection retry attempts |
| `retry_delay` | 1000ms | Initial retry delay (exponential backoff) |
| `health_check_enabled` | true | Enable automatic health monitoring |
| `health_check_interval` | 30s | Health check frequency |

### Environment Variables

Add to your `.env` file:

```env
# Basic RabbitMQ Configuration
QUEUE_CONNECTION=rabbitmq

# RabbitMQ Connection
RABBITMQ_HOST=127.0.0.1
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
RABBITMQ_QUEUE=default

# Connection Options
RABBITMQ_HEARTBEAT_CONNECTION=0

# 🚀 Connection Pool Configuration
RABBITMQ_MAX_CONNECTIONS=10
RABBITMQ_MIN_CONNECTIONS=2
RABBITMQ_MAX_CHANNELS_PER_CONNECTION=100

# 🛡️ Retry Strategy
RABBITMQ_MAX_RETRIES=3
RABBITMQ_RETRY_DELAY=1000

# 📊 Health Monitoring
RABBITMQ_HEALTH_CHECK_ENABLED=true
RABBITMQ_HEALTH_CHECK_INTERVAL=30

# SSL/TLS Configuration (Optional)
RABBITMQ_SECURE=false
#RABBITMQ_SSL_CAFILE=/path/to/ca.pem
#RABBITMQ_SSL_LOCALCERT=/path/to/cert.pem
#RABBITMQ_SSL_LOCALKEY=/path/to/key.pem
#RABBITMQ_SSL_VERIFY_PEER=true
```

## Usage

### Basic Usage

#### Job Dispatching

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

### Pool Monitoring

Monitor your connection and channel pools in real-time:

#### View Current Pool Statistics

```bash
# Show current pool stats
php artisan rabbitmq:pool-stats

# Output in JSON format
php artisan rabbitmq:pool-stats --json

# Watch stats in real-time (refreshes every 5 seconds)
php artisan rabbitmq:pool-stats --watch --interval=5
```

#### Example Pool Stats Output

```
📡 Connection Pool
├─ Max Connections: 10
├─ Min Connections: 2
├─ Current Connections: 3
├─ Active Connections: 1
└─ Available Connections: 2

🔀 Channel Pool
├─ Max Channels/Connection: 100
├─ Current Channels: 5
├─ Active Channels: 2
└─ Available Channels: 3

⚙️ Configuration
├─ Max Retries: 3
├─ Retry Delay: 1000ms
├─ Health Check: Enabled
└─ Health Check Interval: 30s

📊 Utilization
├─ Connection Utilization: 33.3%
├─ Pool Capacity Used: 30.0%
└─ Channel Utilization: 40.0%

🟢 Pool Status: Healthy
```

#### Pool Health Indicators

- 🟢 **Healthy**: All pools operating within normal parameters
- 🟡 **Warning**: Pool utilization high or below minimum thresholds
- 🔴 **Critical**: Pool exhausted or connection failures detected

## Performance Tuning

### Connection Pool Optimization

#### High-Throughput Applications
```env
# Increase pool sizes for high-volume processing
RABBITMQ_MAX_CONNECTIONS=20
RABBITMQ_MIN_CONNECTIONS=5
RABBITMQ_MAX_CHANNELS_PER_CONNECTION=200
```

#### Low-Latency Applications  
```env
# Reduce retry delays for faster failover
RABBITMQ_MAX_RETRIES=2
RABBITMQ_RETRY_DELAY=500
RABBITMQ_HEALTH_CHECK_INTERVAL=15
```

#### Resource-Constrained Environments
```env
# Minimize resource usage
RABBITMQ_MAX_CONNECTIONS=3
RABBITMQ_MIN_CONNECTIONS=1
RABBITMQ_MAX_CHANNELS_PER_CONNECTION=50
RABBITMQ_HEALTH_CHECK_INTERVAL=60
```

### Performance Recommendations

| Scenario | Max Connections | Min Connections | Channels/Connection |
|----------|----------------|----------------|-------------------|
| **Development** | 3 | 1 | 50 |
| **Small Production** | 10 | 2 | 100 |
| **High-Volume** | 20 | 5 | 200 |
| **Enterprise** | 50 | 10 | 500 |

### Monitoring Performance

```bash
# Monitor pool efficiency
php artisan rabbitmq:pool-stats --watch

# Check for bottlenecks
php artisan queue:monitor rabbitmq:default,rabbitmq:high-priority

# View failed jobs
php artisan queue:failed
```

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

## 🚀 Advanced Features

### Exponential Backoff

Automatic retry with exponential backoff for failed operations:

```php
// Configuration in config/queue.php
'connections' => [
    'rabbitmq' => [
        'backoff' => [
            'enabled' => true,
            'base_delay' => 1000,      // 1 second
            'max_delay' => 60000,      // 60 seconds
            'multiplier' => 2.0,       // Double delay each retry
            'jitter' => true,          // Add randomness to prevent thundering herd
        ],
    ],
],

// Usage in your code
$queue = Queue::connection('rabbitmq');
$backoff = $queue->getBackoff();

$result = $backoff->execute(function () {
    // Your code that might fail
    return performRiskyOperation();
}, maxAttempts: 5);
```

### Advanced Routing with Exchanges

#### Topic Exchange

Route messages based on routing key patterns:

```php
use iamfarhad\LaravelRabbitMQ\Support\ExchangeManager;

$queue = Queue::connection('rabbitmq');
$exchangeManager = $queue->getExchangeManager();

// Setup topic exchange
$exchangeManager->setupTopicExchange('notifications', [
    'email-queue' => ['user.*.email', 'admin.*.email'],
    'sms-queue' => ['user.*.sms'],
    'push-queue' => ['*.*.push'],
]);

// Publish with routing key
$queue->publishToExchange(
    'notifications',
    json_encode(['message' => 'Hello']),
    'user.signup.email'
);
```

#### Fanout Exchange

Broadcast messages to all bound queues:

```php
// Setup fanout exchange
$exchangeManager->setupFanoutExchange('broadcasts', [
    'logger-queue',
    'analytics-queue',
    'backup-queue',
]);

// Publish to all queues
$queue->publishToExchange(
    'broadcasts',
    json_encode(['event' => 'user_registered'])
);
```

#### Headers Exchange

Route based on message headers:

```php
// Setup headers exchange
$exchangeManager->setupHeadersExchange('documents', [
    'pdf-queue' => ['x-match' => 'all', 'format' => 'pdf', 'priority' => 'high'],
    'image-queue' => ['x-match' => 'any', 'format' => 'jpg', 'format' => 'png'],
]);

// Publish with headers
$queue->publishToExchange(
    'documents',
    json_encode(['content' => '...']),
    '',
    ['format' => 'pdf', 'priority' => 'high']
);
```

### Multi-Queue Configuration

Configure multiple queues with different settings:

```php
// In config/queue.php
'connections' => [
    'rabbitmq' => [
        'queues' => [
            'default' => [
                'name' => 'default',
                'durable' => true,
                'lazy' => false,
                'priority' => null,
            ],
            'high-priority' => [
                'name' => 'high-priority',
                'durable' => true,
                'lazy' => false,
                'priority' => 10,
                'bindings' => [
                    ['exchange' => 'tasks', 'routing_key' => 'urgent.*'],
                ],
            ],
            'bulk-processing' => [
                'name' => 'bulk-processing',
                'durable' => true,
                'lazy' => true,  // Lazy queue for large message volumes
                'priority' => null,
            ],
        ],
    ],
],
```

### Lazy Queues

Optimize memory usage for queues with many messages:

```php
// Declare a lazy queue
$queue->declareAdvancedQueue(
    'large-queue',
    durable: true,
    autoDelete: false,
    lazy: true  // Messages stored on disk until needed
);

// Or configure in config/queue.php
'queues' => [
    'large-queue' => [
        'name' => 'large-queue',
        'lazy' => true,
    ],
],
```

### Consumer Priorities

Set consumer priority to control message distribution:

```php
// In config/queue.php
'queues' => [
    'high-priority' => [
        'name' => 'high-priority',
        'priority' => 10,  // Higher priority consumers get messages first
    ],
],

// Start consumer with priority
php artisan rabbitmq:consume --queue=high-priority
```

### Dead Letter Exchange (DLX)

Automatically handle failed messages:

```php
// Configuration
'connections' => [
    'rabbitmq' => [
        'dead_letter' => [
            'enabled' => true,
            'exchange' => 'dlx',
            'exchange_type' => 'direct',
            'queue_suffix' => '.dlq',
            'ttl' => 86400000,  // 24 hours in milliseconds
        ],
    ],
],

// Setup DLX for a queue
$queue->setupDeadLetterExchange('my-queue');

// Or declare queue with DLX
$queue->declareAdvancedQueue(
    'my-queue',
    durable: true,
    autoDelete: false,
    deadLetterConfig: [
        'exchange' => 'dlx',
        'routing_key' => 'my-queue.failed',
        'ttl' => 60000,  // 60 seconds
    ]
);
```

### Delayed Messages

Schedule messages for future delivery:

```php
// Using TTL-based delay (built-in)
$queue->publishDelayed(
    'notifications',
    json_encode(['message' => 'Reminder']),
    delay: 3600,  // 1 hour
    headers: ['type' => 'reminder']
);

// Using RabbitMQ Delayed Message Plugin
// First enable in config
'connections' => [
    'rabbitmq' => [
        'delayed_message' => [
            'enabled' => true,
            'plugin_enabled' => true,  // Requires rabbitmq_delayed_message_exchange plugin
            'exchange' => 'delayed',
        ],
    ],
],

// Then publish delayed messages
$queue->publishDelayed('my-queue', $payload, 3600);
```

### RPC (Remote Procedure Call)

Synchronous request-response pattern:

```php
// Enable RPC in config
'connections' => [
    'rabbitmq' => [
        'rpc' => [
            'enabled' => true,
            'timeout' => 30,  // seconds
        ],
    ],
],

// Client: Make RPC call
$queue = Queue::connection('rabbitmq');
$response = $queue->rpcCall(
    'rpc-queue',
    json_encode(['action' => 'calculate', 'data' => [1, 2, 3]]),
    ['priority' => 'high']
);

// Server: Handle RPC requests
use iamfarhad\LaravelRabbitMQ\Support\RpcServer;

$channel = $queue->getChannel();
$server = new RpcServer($channel, 'rpc-queue');

$server->listen(function ($message, $headers) {
    $data = json_decode($message, true);
    
    // Process request
    $result = processRequest($data);
    
    // Return response
    return json_encode(['result' => $result]);
});
```

### Publisher Confirms

Ensure reliable message delivery:

```php
// Enable publisher confirms
'connections' => [
    'rabbitmq' => [
        'publisher_confirms' => [
            'enabled' => true,
            'timeout' => 5,  // seconds
        ],
    ],
],

// Messages are automatically confirmed when published
$queue->push(new ProcessOrder($order));

// Manual control
$confirms = $queue->getPublisherConfirms();
$confirms->enable();

// Publish messages
$queue->pushRaw($payload, 'orders');

// Wait for confirmation
if ($confirms->waitForConfirms()) {
    // Message confirmed by broker
} else {
    // Message not confirmed - handle error
}
```

### Transaction Management

Atomic operations with transactions:

```php
// Enable transactions
'connections' => [
    'rabbitmq' => [
        'transactions' => [
            'enabled' => true,
        ],
    ],
],

// Use transactions
$queue = Queue::connection('rabbitmq');

$queue->transaction(function () use ($queue) {
    // All operations in this block are atomic
    $queue->push(new ProcessOrder($order1));
    $queue->push(new ProcessOrder($order2));
    $queue->push(new ProcessOrder($order3));
    
    // If any operation fails, all are rolled back
});

// Manual transaction control
$txManager = $queue->getTransactionManager();

$txManager->begin();
try {
    $queue->push(new ProcessOrder($order));
    $txManager->commit();
} catch (\Exception $e) {
    $txManager->rollback();
    throw $e;
}
```

### Advanced Queue Declaration

Declare queues with all available options:

```php
$queue->declareAdvancedQueue(
    name: 'advanced-queue',
    durable: true,
    autoDelete: false,
    lazy: true,
    priority: 10,
    deadLetterConfig: [
        'exchange' => 'dlx',
        'routing_key' => 'failed',
        'ttl' => 60000,
    ],
    additionalArguments: [
        'x-max-length' => 10000,
        'x-overflow' => 'reject-publish',
    ]
);
```

### Complete Example: E-commerce Order Processing

```php
// Setup exchanges and queues
$exchangeManager = $queue->getExchangeManager();

// Create topic exchange for orders
$exchangeManager->setupTopicExchange('orders', [
    'order-processing' => ['order.created', 'order.updated'],
    'order-notifications' => ['order.*'],
    'order-analytics' => ['order.completed'],
]);

// Setup high-priority queue with DLX
$queue->declareAdvancedQueue(
    'order-processing',
    durable: true,
    lazy: false,
    priority: 10,
    deadLetterConfig: [
        'exchange' => 'dlx',
        'routing_key' => 'order-processing.failed',
    ]
);

// Publish order with routing
$queue->publishToExchange(
    'orders',
    json_encode(['order_id' => 123, 'amount' => 99.99]),
    'order.created',
    ['priority' => 8, 'customer_id' => 456]
);

// Process with exponential backoff
$backoff = $queue->getBackoff();
$backoff->execute(function () use ($order) {
    processOrder($order);
}, maxAttempts: 3);
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

#### Complete Docker Compose Example

```yaml
version: '3.8'

services:
  app:
    build:
      context: .
      dockerfile: Dockerfile
    environment:
      QUEUE_CONNECTION: rabbitmq
      RABBITMQ_HOST: rabbitmq
      RABBITMQ_PORT: 5672
      RABBITMQ_USER: laravel
      RABBITMQ_PASSWORD: secret
      RABBITMQ_VHOST: /
      RABBITMQ_MAX_CONNECTIONS: 10
      RABBITMQ_MIN_CONNECTIONS: 2
    depends_on:
      - rabbitmq
    volumes:
      - ./:/var/www/html

  # Queue worker service
  queue:
    build:
      context: .
      dockerfile: Dockerfile
    command: php artisan rabbitmq:consume --queue=default --num-processes=4
    environment:
      QUEUE_CONNECTION: rabbitmq
      RABBITMQ_HOST: rabbitmq
      RABBITMQ_PORT: 5672
      RABBITMQ_USER: laravel
      RABBITMQ_PASSWORD: secret
    depends_on:
      - rabbitmq
      - app
    volumes:
      - ./:/var/www/html
    restart: unless-stopped

  rabbitmq:
    image: rabbitmq:3-management-alpine
    ports:
      - "5672:5672"
      - "15672:15672"
    environment:
      RABBITMQ_DEFAULT_USER: laravel
      RABBITMQ_DEFAULT_PASS: secret
      RABBITMQ_DEFAULT_VHOST: /
    volumes:
      - rabbitmq_data:/var/lib/rabbitmq
    healthcheck:
      test: rabbitmq-diagnostics -q ping
      interval: 30s
      timeout: 10s
      retries: 5

volumes:
  rabbitmq_data:
```

#### Dockerfile with AMQP Extension

```dockerfile
FROM php:8.4-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    librabbitmq-dev \
    libssh-dev \
    supervisor \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath

# Install AMQP extension
RUN pecl install amqp && docker-php-ext-enable amqp

# Install Redis extension (optional)
RUN pecl install redis && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# Install dependencies
RUN composer install --no-dev --optimize-autoloader

# Set permissions
RUN chown -R www-data:www-data /var/www/html

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
```

#### Supervisor Configuration for Docker

Create `deployment/supervisor/conf.d/laravel-worker.conf`:

```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/artisan rabbitmq:consume --queue=default --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/worker.log
stopwaitsecs=3600
```

Then update your Dockerfile to include supervisor:

```dockerfile
# Copy supervisor configuration
COPY deployment/supervisor/conf.d/*.conf /etc/supervisor/conf.d/

# Start supervisor
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
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

#### AMQP Extension Not Found

**Error**: `Class "AMQPConnection" not found`

**Solution**: Install the AMQP PHP extension:

```bash
# Check if extension is installed
php -m | grep amqp

# If not installed, follow installation steps above
# For Docker, rebuild containers:
docker-compose down
docker-compose build --no-cache app queue
docker-compose up -d
```

#### Jobs Not Processing / No Output

**Symptoms**: Queue size decreases but no logs or output appear

**Possible Causes**:

1. **AMQP extension missing** - Check logs for `Class "AMQPConnection" not found`
   ```bash
   # Check container logs
   docker logs laravel_queue --tail 100
   
   # Check Laravel logs
   tail -f storage/logs/laravel.log
   ```

2. **Worker running in Docker** - Output goes to container stdout
   ```bash
   # View real-time worker output
   docker logs -f laravel_queue
   
   # Or exec into container
   docker exec -it laravel_queue php artisan rabbitmq:consume --queue=default -vvv
   ```

3. **Supervisor buffering output** - Logs may be buffered
   ```bash
   # Check supervisor logs
   tail -f storage/logs/supervisor/laravel-worker-default.log
   ```

4. **Silent failures** - Add verbose flag
   ```bash
   php artisan rabbitmq:consume --queue=default -vvv
   ```

#### Connection Refused

Ensure RabbitMQ is running and accessible:

```bash
# Check RabbitMQ status
docker ps | grep rabbitmq

# Test connection
telnet localhost 5672

# Check RabbitMQ logs
docker logs laravel_rabbitmq
```

#### Permission Denied

Verify user permissions in RabbitMQ:

```bash
# Access RabbitMQ management UI
# http://localhost:15672
# Default: guest/guest

# Create user with permissions
docker exec laravel_rabbitmq rabbitmqctl add_user laravel secret
docker exec laravel_rabbitmq rabbitmqctl set_permissions -p / laravel ".*" ".*" ".*"
```

#### Memory Issues

Adjust consumer memory limits:

```bash
php artisan rabbitmq:consume --memory=512
```

#### Worker Keeps Restarting

**Symptoms**: Supervisor shows workers constantly exiting and restarting

**Solution**: Check for:

1. **Missing AMQP extension** - Most common cause
2. **Connection issues** - Verify RabbitMQ is accessible
3. **Configuration errors** - Check `config/queue.php`
4. **Memory limits** - Increase `--memory` option

```bash
# View supervisor status
docker exec laravel_queue supervisorctl status

# Check specific worker logs
docker exec laravel_queue tail -f /var/log/supervisor/laravel-worker-default.log
```

#### Pool Exhaustion

**Error**: Unable to get connection from pool

**Solution**: Increase pool size:

```env
RABBITMQ_MAX_CONNECTIONS=20
RABBITMQ_MIN_CONNECTIONS=5
```

#### Slow Message Processing

**Symptoms**: Messages pile up in queue

**Solutions**:

1. **Increase worker count**:
   ```bash
   php artisan rabbitmq:consume --num-processes=8
   ```

2. **Adjust prefetch count**:
   ```php
   'options' => [
       'queue' => [
           'qos' => [
               'prefetch_count' => 20,
           ]
       ]
   ]
   ```

3. **Monitor pool stats**:
   ```bash
   php artisan rabbitmq:pool-stats --watch
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