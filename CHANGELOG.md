# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### 💥 Fixed — reported issues

- **A terminal failure could be discarded before `failed_jobs` was written** ([#37](https://github.com/iamfarhad/LaravelRabbitMQ/issues/37)). `Job::fail()` calls `markAsFailed()` first and dispatches `JobFailed` last; the authoritative `failed_jobs` write is itself a `JobFailed` listener. Rejecting inside `markAsFailed()` therefore removed the delivery from the queue *before* — or instead of — persisting the record explaining why it died. Settlement now happens in a `JobFailed` listener that is appended when the failure begins, so it always runs after the failer listener: if the failed-job provider throws, the dispatch aborts before reaching it, the delivery stays unresolved, and the broker redelivers. The `failed.ownership=exchange` copy moved into the same step, ordered before the reject, so a failed persistence can no longer leave an orphaned copy. This does not make the two writes atomic — nothing can — but it inverts the failure mode from "lost record" to "possible duplicate record", which is recoverable.
- **Release publication could not be blocked by an invalid changelog** ([#36](https://github.com/iamfarhad/LaravelRabbitMQ/issues/36)). Changelog verification ran on `release: [released]`, i.e. after the tag existed, and the workflow that replaced it *rewrote* `CHANGELOG.md` post-publication instead of asserting anything. Publishing now goes through a `workflow_dispatch` release workflow that validates first — exactly one dated `## [x.y.z] - YYYY-MM-DD` section with content, optional leading `v` normalised, tag must not already exist — and only creates the tag and release if that passes. Pull requests validate every released section so `master` stays releasable, and the post-release check is read-only monitoring.
- **`purgeQueue()` and `deleteQueue()` threw on a missing queue.** Their 404 handling only caught `AMQPChannelException`, but ext-amqp reports `NOT_FOUND` as an `AMQPQueueException`.
- **`rabbitmq:consume --stop-when-empty` hung in `consume` mode.** `basic.consume` only evaluates stop conditions when a delivery arrives, so an empty queue never triggered them. That combination now falls back to `poll` mode, which can observe emptiness, and logs why.

### 💥 Fixed — Laravel 13

- **`rabbitmq:consume` was broken on Laravel 13.** Laravel 13 changed `Worker::stopIfNecessary()` from `($options, $lastRestart, $startTime, $jobsProcessed, $job): int|null` to `($options, $lastRestart, $startTime, $job): array{int, WorkerStopReason}|null`. The consumer passed the old argument list unconditionally, which meant:
  - the processed-job count arrived in the `$job` slot, so `--stop-when-empty` and `--stop-when-empty-for` **never fired**;
  - the framework's own `$jobsProcessed` counter was never updated, so `--max-jobs` **never fired**;
  - the returned status *array* was handed to `Worker::stop()` and returned out through `ConsumeCommand::consume(): int`, raising a `TypeError` on every graceful shutdown (SIGTERM, memory limit, `queue:restart`, `--max-time`) and exiting **1** instead of 0 — which supervisors and Kubernetes read as a crash loop;
  - `pauseWorker()`'s return value was discarded, so a paused worker ignored quit, memory and restart signals entirely.

  The consumer now detects the installed signature by reflection and normalises both the argument list and the return shape. Laravel 10, 11 and 12 behaviour is unchanged.

### 💥 Fixed — other blockers

- **The `RabbitMQ` facade never worked.** Its accessor `rabbitmq.queue` was not bound in the container, so every call threw `BindingResolutionException` despite the alias being auto-registered through `composer.json`. It now resolves the application's RabbitMQ queue connection — the default queue connection when that is a RabbitMQ connection, otherwise the connection named `rabbitmq`.
- **RPC could never be used.** `RpcClient` assigned `AMQPQueue::declareQueue()` — which returns the queue's *message count* — to a `string $callbackQueueName` property, so the constructor threw a `TypeError` under `strict_types` before an RPC call could be made. The broker-assigned name is now read from the queue itself, `rpc.callback_queue_prefix` is honoured, and the reply queue is declared auto-delete.
- **Numeric values in `.env` crashed the driver.** Laravel's `env()` only coerces `true`/`false`/`null`/`empty`; every number arrives as a string. `ConnectionPool`, `ChannelPool`, `ConnectionFactory`, `ExponentialBackoff`, `PublisherConfirms`, `RpcClient` and the consumer's QoS all assigned those strings into typed properties and arguments under `strict_types`, so documented variables like `RABBITMQ_MAX_CONNECTIONS=20` were an outright `TypeError`. The shipped defaults are ints, which is why this only appeared once the driver was actually configured. Every value is now cast, both in `config/rabbitmq.php` and defensively at the point of use.
- **`getConnection()` leaked a pooled connection on every call.** It checked a connection out of the connection pool and nothing ever handed it back, so with `failed.ownership=exchange` every failed job leaked one until the pool threw `Connection pool exhausted`. The connection returned was also usually *not* the one backing the current channel, making the liveness check it existed for meaningless. It now returns the connection behind the channel in use.
- **Consume mode stranded messages while paused.** In `RABBITMQ_CONSUME_MODE=consume`, a delivery that arrived while the worker was paused or the application was in maintenance mode was neither acked nor rejected, leaving it (and up to `prefetch_count` more) unacked until the connection happened to drop. The delivery is now requeued before pausing.

### 💥 Fixed — reliability and correctness

- **A configured `exchange` silently discarded every message.** `declareDestination()` declared the exchange *instead of* the queue and never bound the two, so on a fresh broker setting `RABBITMQ_EXCHANGE` lost every job — and publisher confirms ACK an unroutable message, so nothing surfaced the loss. The queue is now always declared, the exchange is declared, and the queue is bound to it with the configured routing key. `queues.<queue>.bindings` is applied too; it was previously read by no code at all.
- **The default exchange no longer applies the routing-key pattern.** The default exchange routes solely on the literal queue name, so a custom `exchange_routing_key` produced a key that matched nothing and the message vanished. This also fixes delayed publishes, which route through the default exchange.
- **Multiple RabbitMQ connections are now supported.** Every topology and feature setting was read from the hardcoded `queue.connections.rabbitmq` block regardless of which connection was in use, so a second named connection silently inherited the first one's exchange, routing keys, quorum mode, priorities, publisher confirms, RPC, transaction and job-class settings — and received no package defaults at all. Settings now resolve per connection (own block → the `rabbitmq` block → the package defaults), and the service provider seeds defaults into every connection with `driver: rabbitmq`.
- **Pooled channels are no longer reused after being mutated.** `confirm.select`, `tx.select` and `basic.qos` permanently change a channel and AMQP offers no way to undo them, yet such channels were returned to the pool — handing confirm mode (plus the previous owner's confirm callback), an open transaction, or a prefetch to the next borrower. They are now retired on release. Enabling transactions while publisher confirms are on is also rejected outright rather than failing at the broker.
- **Publisher confirms track their messages again.** `registerPendingConfirm()` was never called, so the pending ledger was permanently empty, a NACK was reported as a bare delivery tag rather than a correlation ID, and a batch could not be confirmed with one wait. Optional `publisher_confirms.mandatory` adds a `basic.return` handler so an unroutable publish becomes a reported failure instead of a silent drop.
- **Horizon no longer double-processes delayed jobs.** `laterRaw()` published through `$this->pushRaw()` when the delay resolved to zero, re-entering `HorizonRabbitQueue`'s override: the payload was wrapped in a Horizon envelope twice and `JobPending`/`JobPushed` were dispatched twice. `later()` also bypassed `enqueueUsing()`, skipping `after_commit` and `createPayloadUsing()` for delayed jobs. Both are fixed, `HorizonRabbitQueue::deleteReserved()` now settles the delivery, and a stale `lastPushed` job reference can no longer tag an unrelated raw push.
- **Topology mismatches are reported instead of hidden.** A `406 PRECONDITION_FAILED` on redeclaring a queue or exchange was swallowed, so changing `quorum`, `lazy`, priority or dead-letter configuration on an existing queue was a silent no-op — and the broker-closed channel was left in use. Mismatches are now logged as warnings and the channel is retired.
- **`queueExists()` no longer reports every error as "absent".** A `403 ACCESS_REFUSED` or a dead channel was indistinguishable from a missing queue. Only `404` means absent now; anything else is thrown, and the broker-closed channel is always released.
- Pool counters can no longer drift negative from repeated or foreign `closeChannel()`/`closeConnection()` calls, which previously raised the effective connection ceiling. A dead connection released back to the pool is now actually closed instead of only decrementing a counter.

### ⚡ Performance and scale

- **Polling costs one broker round trip instead of two or three.** `pop()` issued a passive `queue.declare` before every `basic.get`, doubling broker load for the whole life of a worker. Declared queues, exchanges and bindings are now memoised per channel and forgotten whenever the channel is replaced.
- **Delayed jobs no longer create an unbounded number of queues.** Delay queues are named after their TTL, so jittered or computed backoff values produced a new durable queue per distinct delay. TTLs are now rounded up into buckets of `delay_queue_granularity` (default 1000 ms; rounding up never fires a job early). Set it to `1` to restore exact-TTL queues, or enable `delayed_message.plugin_enabled` when you need many distinct delays — the plugin path uses a single exchange and now also declares and binds the target queue.
- **`bulk()` confirms once per batch** rather than paying a broker round trip per message.
- Channel replacement and connection retries use bounded, jittered exponential backoff instead of a fixed linear delay, so a fleet reconnecting after a broker restart does not synchronise into a thundering herd. Every configured host now gets at least one connection attempt even when `pool.max_retries` is lower than the host count.
- `RpcClient` and `RpcServer` poll on a 1 ms escalating interval instead of a flat 100 ms, and `RpcClient` bounds its reply buffer so replies for callers that already timed out cannot accumulate.

### ⚠️ Changed defaults

- `hosts.heartbeat` now defaults to **60** (was `0`). Without heartbeats, idle connections are reaped by brokers, load balancers and firewalls, surfacing later as "Broken pipe".
- `hosts.connect_timeout` and `options.connect_timeout` now default to **10** seconds (was `0`, meaning no bound).
- `options.queue.qos.prefetch_count` now defaults to **1** (was `10`), and QoS is applied in consume mode only — `basic.qos` never governed the default `basic.get` poll mode. A single-threaded worker runs one job at a time; a higher prefetch parks the surplus behind it, where a timeout or crash turns them into redeliveries.
- `pool.lazy` now defaults to **true**. Eager initialisation opened `pool.min_connections` sockets as a side effect of merely resolving the queue connection, in every artisan one-shot and every request that never published. Set it to `false` to pre-warm long-lived workers.
- `laterRaw()`'s `$attempts` argument now defaults to **0** instead of `2`. It is an attempt count, and a value of 2 made a first-time delayed job report three attempts once attempts started travelling in message headers.
- `PoolManager::isHealthy()` now means "the pool can still serve work" rather than "the pool holds at least `min_connections`", which reported every idle lazy pool as unhealthy.
- `connection_name` is now a configurable label shown in the RabbitMQ management UI. It was previously overwritten with the transport string (`"ssl"`), making every TLS connection anonymous there.

### 🧹 Removed configuration keys

These were read by no code. Leaving them in place implied behaviour the driver never had:

`hosts.keepalive`, `options.ssl_options.passphrase`, `options.queue.qos.global`, `queues.*.name`, `queues.*.exclusive`, `exchanges.*`, `delayed_message.enabled`, `backoff.enabled`.

`queues.*.durable`, `queues.*.auto_delete`, `queues.*.bindings`, `dead_letter.queue_suffix`, `dead_letter.ttl` and `rpc.callback_queue_prefix` were also unread — those are now implemented rather than removed.

### 🔧 Internal

- `rabbitmq:pool-stats` resolves the queue connection so it has a pool to report on. It previously printed "No active RabbitMQ pool manager found" in any fresh artisan process, since pools are per-process. It takes an optional `connection` argument, and `--watch` no longer shells out to `clear(1)` per tick.
- `rabbitmq:queue-declare`, `queue-purge`, `queue-delete` and `exchange-declare` accept `--connection` instead of hardcoding the connection named `rabbitmq`.
- Cleanup and Horizon event listeners are registered once per dispatcher. `connect()` runs on every queue-connection resolution and used to stack another `WorkerStopping` (and, under Octane, `RequestTerminated`) closure each time, growing without bound and calling `closeAll()` once per accumulated listener.
- The consumer restores the framework's `Looping` event (so `Queue::looping()` callbacks and Horizon's pause hooks work), calls `resetScope` between jobs, and dispatches `WorkerStarting`/`WorkerIdle` where available.
- `RabbitQueue::createMessage()` is deprecated in favour of `correlationIdFor()`. It never created a message and ignored its `$attempts` argument.
- CI now runs `composer analyse`, which had never been wired up — the Laravel 13 arity break was reported by PHPStan but never seen. PHPStan is upgraded to 2.x, `larastan` is an optional local add-on (it does not support the Laravel 10 line), and the Laravel 10 and 11 rows the support matrix claims are now actually built.
- Attempt counts now travel in a `laravel.attempts` message header as well as the payload body. `attempts()` already preferred the header, but nothing ever set it.

## [1.4.1] - 2026-08-04

### ⚠️ Changed

- **`ack()` and `reject()` now report settlement failures instead of swallowing them** ([#31](https://github.com/iamfarhad/LaravelRabbitMQ/issues/31), [#33](https://github.com/iamfarhad/LaravelRabbitMQ/issues/33)): both methods used to catch `AMQPChannelException`/`AMQPConnectionException`, release the channel, and return normally — and to return silently when the delivering channel was already `null` or unusable. Callers could not tell a completed settlement from one that never reached the broker, so a job could be recorded as handled while RabbitMQ still owned an unresolved delivery and would redeliver it later. Every failure path now throws the new `iamfarhad\LaravelRabbitMQ\Exceptions\SettlementException` after releasing the unusable delivering channel (that release is what lets the broker redeliver). Successful settlement is unchanged, and a delivery tag is still never retried on a replacement channel, since tags are scoped to the channel that delivered the message.

  **Consequence to plan for:** a failed ack now surfaces as an exception from `RabbitMQJob::delete()`, which Laravel's worker reports through its exception handler. With `--tries=1` that also records a failed job even though the handler itself succeeded, and the delivery will be redelivered — job handlers consuming at-least-once deliveries must be idempotent. The original AMQP exception is available via `getPrevious()`, and its message is folded into the `SettlementException` message so Laravel's lost-connection detection keeps working. `RabbitQueue::close()` deliberately tolerates a settlement failure during shutdown, because closing the channel already lets the broker requeue.

### 🐛 Fixed

- **A failed reject could suppress the authoritative failed-job record** ([#32](https://github.com/iamfarhad/LaravelRabbitMQ/issues/32)): Laravel calls `markAsFailed()` *before* the `try`/`finally` in `Job::fail()` that dispatches `JobFailed` — the event whose listener writes to `failed_jobs`. With settlement failures now observable, an exception escaping `markAsFailed()` would have aborted that lifecycle and lost the durable explanation for the failure. `markAsFailed()` therefore reports a `SettlementException` through the application's exception handler and continues, so Laravel always gets to persist its record, and the unresolved delivery stays eligible for redelivery.

  **Known limitation:** the reject still happens before `failed_jobs` is written. If that write fails, the delivery may already be settled. Configure a dead-letter exchange (`failed.ownership=broker` with `reroute_failed`, the default since 1.4.0) so a rejected message is preserved in the DLQ regardless. See [Failure ownership](docs/production.md#failure-ownership).

### 🔧 Internal

- The near-identical `ack()`/`reject()` bodies are now one `settle()` helper, so the delivering-channel rules cannot drift between them.
- The changelog automation now checks out the repository's actual default branch. It previously targeted a `main` branch that does not exist here, so it failed on every release from 1.3.0 through 1.4.1 without ever updating `CHANGELOG.md`.

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
