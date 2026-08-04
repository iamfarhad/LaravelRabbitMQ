# Recipes

Short, copy-paste recipes for common production RabbitMQ patterns in Laravel.

## Delayed jobs

Use Laravel's delay API:

```php
dispatch(new App\Jobs\SendReminder($user))->delay(now()->addMinutes(10));
```

Without a broker plugin the package routes the job through a per-TTL delay queue
that dead-letters back to the target queue.

Because each distinct TTL needs its own queue, delays are rounded **up** into
buckets so jittered or computed backoff cannot create an unbounded number of
them. Rounding up never fires a job early:

```env
# Bucket size in milliseconds. Set to 1 for exact TTLs.
RABBITMQ_DELAY_QUEUE_GRANULARITY=1000
```

For many distinct or sub-second delays, use the delayed-message plugin instead —
a single exchange rather than a queue per TTL:

```env
RABBITMQ_DELAYED_PLUGIN_ENABLED=true
RABBITMQ_DELAYED_EXCHANGE=delayed
```

That path requires the `rabbitmq_delayed_message_exchange` plugin enabled on the
broker.

## Quorum queues

Enable quorum queues globally:

```env
RABBITMQ_QUEUE_QUORUM=true
```

Or per queue:

```php
'queues' => [
    'orders' => [
        'quorum' => true,
    ],
],
```

Quorum queues do not support `x-max-priority`, so the driver omits the priority
argument for a quorum queue rather than letting the broker refuse the declare.
Choose one or the other per queue.

## Priority queues

```env
RABBITMQ_PRIORITIZE_DELAYED=true
RABBITMQ_QUEUE_MAX_PRIORITY=10
```

Per queue:

```php
'queues' => [
    'critical' => [
        'priority' => 10,
    ],
],
```

## Publisher confirms

```env
RABBITMQ_PUBLISHER_CONFIRMS_ENABLED=true
RABBITMQ_PUBLISHER_CONFIRMS_TIMEOUT=5

# Also report a message the broker could not route anywhere, which confirms
# alone acknowledge after discarding.
RABBITMQ_PUBLISHER_CONFIRMS_MANDATORY=true
```

Use this for workflows where RabbitMQ must confirm message receipt before the application considers the publish successful. Each publish waits for a broker round trip; `Queue::bulk()` confirms the whole batch with one wait.

## Dead-letter routing

```env
RABBITMQ_REROUTE_FAILED=true
RABBITMQ_FAILED_EXCHANGE=failed.jobs
RABBITMQ_FAILED_ROUTING_KEY=%s.failed
```

Declare and monitor the failed exchange and queues as part of your deployment process.

This pairs with the default `RABBITMQ_FAILED_OWNERSHIP=broker`, so a permanently
failed job produces exactly one record — in the DLQ. See
[failure ownership](production.md#failure-ownership) before combining it with
`RABBITMQ_FAILED_OWNERSHIP=exchange`, which would record the same failure twice.

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

### Long-lived workers and dropped connections

Octane workers live for many requests, so a pooled AMQP connection will
eventually be closed underneath them — a broker restart, an idle disconnect, a
load balancer timeout, or missed heartbeats. The pool detects dead channels and
connections when they are next used and transparently replaces them, so no
special configuration is required for recovery.

Recommended settings for Octane (Swoole or RoadRunner):

```env
QUEUE_CONNECTION=rabbitmq

# Keep pooled connections warm across requests; recovery is automatic.
RABBITMQ_OCTANE_RESET_ON_REQUEST=false

# Heartbeats let the broker and client notice dead peers sooner. Keep it
# lower than any idle timeout between the app and the broker.
RABBITMQ_HEARTBEAT_CONNECTION=30

# At least twice the heartbeat, so a read cannot time out before heartbeat
# frames have had a chance to be exchanged.
RABBITMQ_READ_TIMEOUT=60
RABBITMQ_WRITE_TIMEOUT=10
RABBITMQ_CONNECT_TIMEOUT=5

# Periodically sweep pooled-but-idle channels/connections.
RABBITMQ_HEALTH_CHECK_ENABLED=true
RABBITMQ_HEALTH_CHECK_INTERVAL=10
```

Size `RABBITMQ_MAX_CONNECTIONS` from your worker count: each worker process
holds its own pool, so the limit applies per process, not per host. A publisher
usually needs a single connection (channels are multiplexed onto it up to
`RABBITMQ_MAX_CHANNELS_PER_CONNECTION`), so the default of 10 per process is
already generous.

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

Hosts are shuffled per connection attempt, so concurrent workers do not all pile
onto the same node first, and retries cycle to the next host rather than
hammering the one that just failed. Every configured host gets at least one
attempt even when `RABBITMQ_MAX_RETRIES` is lower than the host count.

## Hot queue worker

```bash
php artisan rabbitmq:consume --queue=emails --consume-mode=consume --memory=256 --tries=3 --timeout=60
```

Use one queue per worker group in consume mode. Scale with more worker processes or replicas.

Two things apply to this mode only:

- `RABBITMQ_PREFETCH_COUNT` takes effect here (`basic.qos` governs
  `basic_consume`, not `basic_get`). It defaults to `1`, which is right for a
  single-threaded worker; raise it only for short, I/O-bound jobs, and keep the
  job timeout well under the time to work through a full batch.
- `--stop-when-empty` and `--stop-when-empty-for` fall back to poll mode for the
  run, because `basic_consume` only evaluates stop conditions when a delivery
  arrives and would otherwise block forever on an idle queue.

## Safe default worker

```bash
php artisan rabbitmq:consume --queue=default --consume-mode=poll --memory=256 --tries=3 --timeout=60
```

Poll mode is a conservative default and matches Laravel worker expectations closely.
