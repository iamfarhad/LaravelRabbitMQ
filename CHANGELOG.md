# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.0] - 2026-08-04

### ⚠️ Changed

- **Terminal failure ownership is now explicit** ([#28](https://github.com/iamfarhad/LaravelRabbitMQ/issues/28)): a permanently failed job used to be rejected *and* copied to a hard-coded `failed_messages` exchange, so a queue with broker dead-letter routing (`reroute_failed`, `x-dead-letter-exchange`) recorded the same failure twice, with divergent retention, alerting, and replay policies. Ownership is now selected by the new `failed.ownership` setting (`RABBITMQ_FAILED_OWNERSHIP`):
  - `broker` (**new default**) — the job is rejected without requeue and the queue's configured dead-letter exchange owns the failure.
  - `exchange` — the previous behaviour: reject, then also publish a copy to the queue named by `failed.exchange` (`RABBITMQ_FAILED_MESSAGES_EXCHANGE`, default `failed_messages`). Set this if you relied on the `failed_messages` copy, and do not combine it with `reroute_failed`.

  The copy is now published through the default exchange so it actually lands in the failure queue that is declared for it, instead of being routed by the connection's configured publishing exchange. The re-entry guard also checks the consumed queue, not just the envelope's exchange name, so consuming the failure queue can no longer republish failures in a loop. Settings are read from the failing job's own connection, falling back to `queue.connections.rabbitmq`.

  See [UPGRADE.md](UPGRADE.md#upgrading-to-140) for the migration steps.

### 🚀 Added

- `failed.ownership` / `failed.exchange` configuration for terminal-failure routing, with the `RABBITMQ_FAILED_OWNERSHIP` and `RABBITMQ_FAILED_MESSAGES_EXCHANGE` environment variables.
- `RabbitMQJob::failureOwner()` is `protected`, so a custom job class (`options.queue.job`) can decide failure ownership per job instead of per connection.
- `PublisherConfirms::hasPendingNack()` for inspecting whether a broker NACK is waiting to be reported.

### 🐛 Fixed

- **Publisher confirms aborted the publish with "Unhandled `basic.ack` method from server received."** ([#25](https://github.com/iamfarhad/LaravelRabbitMQ/issues/25)): `PublisherConfirms::enable()` called `confirmSelect()` without installing ext-amqp's confirm callbacks, so ext-amqp refused to process the broker's acknowledgement and turned a successful publish into a fatal error. The ACK/NACK callbacks are now registered before confirm mode is switched on, exactly once per instance, so repeated `enable()` calls cannot stack handlers. The callbacks also release `waitForConfirm()` as soon as nothing is outstanding instead of blocking for the full timeout, and confirm-wait timeouts (`AMQPQueueException`) are now reported as a failed confirmation.
- **A publisher-confirm NACK could fail a later, successfully acknowledged publish** ([#26](https://github.com/iamfarhad/LaravelRabbitMQ/issues/26)): the stored NACK was never cleared, so on a long-lived publisher or worker every subsequent `waitForConfirms()` kept re-throwing the first broker NACK. NACK state is now single-use: the wait takes it, clears it, and only then reports the failure. `disable()` and `clearPending()` also clear it, and a failing wait no longer leaves stale state behind for the next publish.
- **`rabbitmq:consume` failed to start on Laravel 13** ([#27](https://github.com/iamfarhad/LaravelRabbitMQ/issues/27)): the command overrides `queue:work`'s signature but omitted `--stop-when-empty-for` and `--json`. Laravel 13's `WorkCommand::gatherWorkerOptions()` reads `stop-when-empty-for` unconditionally, so Symfony threw for the undefined option and the worker exited — before consuming anything and even when the option was never passed — putting deployments into a restart loop. Both options are now declared, keep their `queue:work` meanings, and are covered by a test that compares the command against every option the installed `WorkCommand` reads, so future framework drift is caught.

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
