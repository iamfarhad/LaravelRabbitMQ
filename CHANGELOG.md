# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### 🐛 Fixed

- **"Could not create queue. No channel available." under Octane / long-lived workers** ([#23](https://github.com/iamfarhad/LaravelRabbitMQ/issues/23)): when a connection died (broker restart, idle disconnect, missed heartbeats), its channels stayed in the pool and kept being handed out, so every subsequent operation failed with ext-amqp's "… No channel available." error until the worker was recycled. Recovery is now automatic:
  - The pool's channel liveness check now uses `AMQPChannel::isConnected()` (the same internal flag ext-amqp verifies before every operation) instead of `getChannelId()`, which never detects a dead connection. Dead channels are drained from the pool instead of being vended.
  - `RabbitQueue` validates its cached channel before reuse and transparently replaces it when the underlying connection is gone — essential for Octane/Swoole workers whose queue instance lives across many requests.
  - Queue/exchange declaration, publishing, purge, delete, and size operations now retry on a fresh channel (with backoff) when they fail because the channel's connection died. Broker-reported semantic errors (404 not-found, 406 precondition-failed, …) still surface immediately and are never retried.
  - The channel pool no longer multiplexes new channels onto a connection the broker has already closed, and retries channel creation once on a fresh connection; the connection pool skips and closes dead pooled connections instead of giving up after inspecting only one.
  - `ack`/`reject` now operate strictly on the channel that delivered the message: if that channel is dead they release it and let the broker requeue, instead of risking a delivery-tag mix-up on a replacement channel.

## [1.3.1] - 2026-07-15

### 🐛 Fixed

- **Infinite job retries** ([#21](https://github.com/iamfarhad/LaravelRabbitMQ/issues/21)): `RabbitMQJob::attempts()` always returned `1` because the attempt counter was never preserved across republishing, so jobs with `$tries > 1` never reached their retry limit and looped forever instead of failing. The attempt count is now persisted in the job payload on `release()` and read back on redelivery, so `attempts()` increments correctly and `maxTries` is enforced as expected.

## [1.1.0] - 2025-01-27

### 🚀 Major Advanced Features Added

#### Dead Letter Exchange (DLX)
- **Automatic DLX Setup**: Easy configuration for failed message handling
- **Configurable TTL**: Message time-to-live settings
- **Dead Letter Queues**: Automatic creation of DLQ for each queue
- **Routing Key Control**: Custom routing for dead-lettered messages
- **DLX Integration**: Seamless integration with existing queues

#### Advanced Routing System
- **ExchangeManager**: Comprehensive exchange management and routing
- **Topic Exchanges**: Pattern-based message routing (e.g., `user.*.email`)
- **Fanout Exchanges**: Broadcast messages to all bound queues
- **Headers Exchanges**: Route based on message headers
- **Exchange Bindings**: Flexible queue-to-exchange bindings

#### Multi-Queue & Multi-Exchange Support
- **Queue Configuration**: Define multiple queues with different settings
- **Exchange Configuration**: Configure multiple exchanges with various types
- **Lazy Queues**: Optimize memory for high-volume queues
- **Priority Queues**: Support for message and consumer priorities
- **Custom Arguments**: Full control over queue and exchange arguments

#### Exponential Backoff Strategy
- **ExponentialBackoff**: Intelligent retry mechanism with configurable parameters
- **Jitter Support**: Prevents thundering herd problem with randomized delays
- **Configurable Multiplier**: Customizable backoff progression
- **Max Delay Cap**: Prevents excessive wait times
- **Execute Helper**: Convenient wrapper for retry logic

#### RPC (Remote Procedure Call)
- **RpcClient**: Synchronous request-response pattern
- **RpcServer**: Handle RPC requests with callbacks
- **Correlation ID**: Automatic request-response matching
- **Timeout Control**: Configurable timeout for RPC calls
- **Reply Queue**: Automatic callback queue management

#### Publisher Confirms
- **Reliable Delivery**: Broker acknowledgment for published messages
- **Confirm Mode**: Enable/disable publisher confirms
- **Wait for Confirms**: Block until messages are confirmed
- **Pending Tracking**: Track unconfirmed messages
- **Timeout Control**: Configurable confirmation timeout

#### Transaction Management
- **AMQP Transactions**: Full transaction support
- **Atomic Operations**: Commit/rollback for multiple operations
- **Transaction Helper**: Convenient transaction wrapper
- **Nested Transaction Prevention**: Safety checks for transaction state
- **Error Handling**: Automatic rollback on exceptions

#### Delayed Messages
- **TTL-Based Delay**: Built-in delay using message TTL
- **Plugin Support**: RabbitMQ delayed message exchange plugin
- **Flexible Scheduling**: Schedule messages for future delivery
- **Header-Based Delay**: x-delay header support
- **Configurable Exchange**: Custom delayed exchange names

### 🔧 Configuration Enhancements

#### New Configuration Sections
```php
'backoff' => [
    'enabled' => true,
    'base_delay' => 1000,
    'max_delay' => 60000,
    'multiplier' => 2.0,
    'jitter' => true,
],

'exchanges' => [
    'default' => [...],
    'notifications' => [...],
    // Custom exchanges
],

'queues' => [
    'default' => [...],
    'high-priority' => [...],
    // Custom queues
],

'dead_letter' => [
    'enabled' => true,
    'exchange' => 'dlx',
    'exchange_type' => 'direct',
    'queue_suffix' => '.dlq',
],

'delayed_message' => [
    'enabled' => false,
    'plugin_enabled' => false,
],

'rpc' => [
    'enabled' => false,
    'timeout' => 30,
],

'publisher_confirms' => [
    'enabled' => false,
    'timeout' => 5,
],

'transactions' => [
    'enabled' => false,
],
```

#### New Environment Variables
- `RABBITMQ_BACKOFF_ENABLED` - Enable exponential backoff
- `RABBITMQ_BACKOFF_BASE_DELAY` - Base delay in milliseconds
- `RABBITMQ_BACKOFF_MAX_DELAY` - Maximum delay in milliseconds
- `RABBITMQ_BACKOFF_MULTIPLIER` - Delay multiplier
- `RABBITMQ_BACKOFF_JITTER` - Enable jitter
- `RABBITMQ_DLX_ENABLED` - Enable dead letter exchange
- `RABBITMQ_DLX_EXCHANGE` - DLX exchange name
- `RABBITMQ_DLX_EXCHANGE_TYPE` - DLX exchange type
- `RABBITMQ_DLX_QUEUE_SUFFIX` - DLQ suffix
- `RABBITMQ_DLX_TTL` - Message TTL in milliseconds
- `RABBITMQ_DELAYED_MESSAGE_ENABLED` - Enable delayed messages
- `RABBITMQ_DELAYED_PLUGIN_ENABLED` - Use delayed message plugin
- `RABBITMQ_DELAYED_EXCHANGE` - Delayed exchange name
- `RABBITMQ_RPC_ENABLED` - Enable RPC support
- `RABBITMQ_RPC_TIMEOUT` - RPC timeout in seconds
- `RABBITMQ_RPC_CALLBACK_PREFIX` - RPC callback queue prefix
- `RABBITMQ_PUBLISHER_CONFIRMS_ENABLED` - Enable publisher confirms
- `RABBITMQ_PUBLISHER_CONFIRMS_TIMEOUT` - Publisher confirms timeout
- `RABBITMQ_TRANSACTIONS_ENABLED` - Enable transactions

### 🛠️ API Enhancements

#### New RabbitQueue Methods
```php
// Advanced queue declaration
$queue->declareAdvancedQueue($name, $durable, $autoDelete, $lazy, $priority, $deadLetterConfig);

// Exchange management
$queue->getExchangeManager();
$queue->publishToExchange($exchange, $payload, $routingKey, $headers);

// Backoff and retry
$queue->getBackoff();

// Publisher confirms
$queue->getPublisherConfirms();

// Transactions
$queue->getTransactionManager();
$queue->transaction(callable $callback);

// RPC
$queue->getRpcClient();
$queue->rpcCall($queue, $message, $headers);

// Dead letter exchange
$queue->setupDeadLetterExchange($queueName, $dlxName, $dlxRoutingKey);

// Delayed messages
$queue->publishDelayed($queue, $payload, $delay, $headers);
```

#### New Support Classes
- `ExchangeManager` - Exchange and routing management
- `ExponentialBackoff` - Retry logic with exponential backoff
- `RpcClient` - RPC client implementation
- `RpcServer` - RPC server implementation
- `PublisherConfirms` - Publisher confirm handling
- `TransactionManager` - Transaction management

### 🔄 Breaking Changes

None. All new features are opt-in and backward compatible.

### 📦 Dependencies

#### Requirements (Unchanged)
- PHP 8.2+
- Laravel 11.x|12.x
- ext-amqp
- ext-pcntl

---

## [1.0.0] - Previous Versions

### Legacy Features
- Basic RabbitMQ queue driver functionality
- Connection pooling system
- Channel management
- Basic consumer commands
- Standard Laravel Queue API integration
