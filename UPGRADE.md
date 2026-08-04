# Upgrade Guide

This guide highlights upgrade checks for Laravel RabbitMQ users.

## Before upgrading

1. Read the release notes and changelog for the target version.
2. Confirm your runtime is supported in [SUPPORT.md](SUPPORT.md).
3. Back up production queue topology and worker configuration.
4. Test with a staging RabbitMQ broker before deploying to production.
5. Restart all long-running queue workers after deployment.

## General upgrade steps

```bash
composer require iamfarhad/laravel-rabbitmq:^<target-version> --with-all-dependencies
php artisan vendor:publish \
  --provider="iamfarhad\\LaravelRabbitMQ\\LaravelRabbitQueueServiceProvider" \
  --tag="config" \
  --force
```

Review the published `config/rabbitmq.php` diff before committing it. Do not overwrite custom connection, exchange, queue, dead-letter, or SSL settings without checking them.

Run your test suite and queue smoke tests:

```bash
composer test
php artisan queue:work rabbitmq --queue=default --once
php artisan rabbitmq:consume --queue=default --num-processes=1 --once
```

If your version of `rabbitmq:consume` does not support `--once`, run it in a controlled environment and stop it after a successful job.

## Upgrading to 1.5.0

### If you run Laravel 13, upgrade

On Laravel 13 `rabbitmq:consume` could not shut down cleanly: it exited with code
`1` on every SIGTERM, memory limit, `queue:restart` and `--max-time`, which
supervisors and Kubernetes read as a crash loop. `--stop-when-empty`,
`--stop-when-empty-for` and `--max-jobs` never fired at all. No configuration
change is needed — but check any alerting keyed on worker exit codes, because
graceful stops now correctly report `0`.

### If you set `RABBITMQ_EXCHANGE`

Publishing through a configured exchange never bound the queue to it, so on a
broker where you had not created the binding by hand, **every published job was
silently discarded**. The driver now declares the queue, declares the exchange
and binds them.

Before deploying, check for messages that were lost rather than queued, and
verify the binding the driver will create matches any binding you added
manually — same exchange, same routing key from `RABBITMQ_EXCHANGE_ROUTING_KEY`.
A duplicate binding with a different routing key delivers each message twice.

Also note: with the default (empty) exchange, `RABBITMQ_EXCHANGE_ROUTING_KEY` is
now ignored. The default exchange routes only on the literal queue name, so a
custom pattern there matched nothing and dropped the message.

### If you set any numeric `RABBITMQ_*` variable in `.env`

Those used to raise a `TypeError` — for example `RABBITMQ_MAX_CONNECTIONS=20`
crashed with *"Cannot assign string to property ... of type int"*. If you worked
around this by leaving variables unset, you can now set them.

If you maintain your own `config/rabbitmq.php`, cast numeric and boolean values:

```php
'max_connections' => (int) env('RABBITMQ_MAX_CONNECTIONS', 10),
'health_check_enabled' => (bool) env('RABBITMQ_HEALTH_CHECK_ENABLED', true),
```

### Changed defaults

Review these before deploying; each is a behaviour change:

| Setting | Was | Now | Why it matters |
| --- | --- | --- | --- |
| `hosts.heartbeat` | `0` | `60` | Idle connections were reaped by brokers, load balancers and firewalls. |
| `hosts.connect_timeout` | `0` | `10` | A dead broker used to hang the request or worker on TCP connect. |
| `options.queue.qos.prefetch_count` | `10` | `1` | Only applies to `RABBITMQ_CONSUME_MODE=consume`. A higher prefetch parks messages behind the job in flight, where a timeout turns them into redeliveries. Raise it deliberately if your workers are I/O-bound. |
| `pool.lazy` | `false` | `true` | Sockets were opened just by resolving the queue connection. Set `RABBITMQ_LAZY_POOL=false` to pre-warm long-lived workers. |
| `delay_queue_granularity` | n/a | `1000` ms | Delayed-job TTLs are rounded **up** into buckets so arbitrary delays stop creating one durable queue each. Jobs never fire early. Set to `1` for exact TTLs. |

If you rely on sub-second delay precision, either set
`RABBITMQ_DELAY_QUEUE_GRANULARITY=1` or — better at scale — enable the
delayed-message plugin with `RABBITMQ_DELAYED_PLUGIN_ENABLED=true`, which uses a
single exchange instead of a queue per TTL.

### Topology mismatch warnings

Changing `quorum`, `lazy`, priority or dead-letter settings on a queue that
already exists used to be a silent no-op, because RabbitMQ queue arguments are
immutable. Those refusals are now logged as warnings. If they appear after this
upgrade, your running topology does not match your configuration: delete and
redeclare the queue during a drain window to apply it.

### Removed configuration keys

These were read by no code, so removing them changes nothing at runtime — but
they will no longer appear in a published config, and leaving them in yours is
harmless:

`hosts.keepalive`, `options.ssl_options.passphrase`, `options.queue.qos.global`,
`queues.*.name`, `queues.*.exclusive`, `exchanges.*`,
`delayed_message.enabled`, `backoff.enabled`.

Conversely, `queues.*.durable`, `queues.*.auto_delete`, `queues.*.bindings`,
`dead_letter.queue_suffix`, `dead_letter.ttl` and `rpc.callback_queue_prefix`
were also being ignored and now take effect. Check that the values in your config
are the ones you actually want.

### Multiple RabbitMQ connections

If you define more than one connection with `driver: rabbitmq`, each now reads
its own exchange, routing keys, quorum mode, priorities, publisher confirms, RPC,
transaction and job-class settings. Previously every connection used
`queue.connections.rabbitmq`'s values, so a second connection may have been
running with the first one's topology settings without you knowing.

### The RabbitMQ facade and RPC now work

Both were unusable before (`BindingResolutionException` and a constructor
`TypeError` respectively). Nothing to change — but if you wrote a workaround for
either, you can drop it.

## Upgrading to 1.4.0

### Terminal failure ownership changed

Permanently failed jobs are no longer copied to the `failed_messages` exchange
by default. A failed job is now rejected without requeue, which hands it to the
queue's configured `x-dead-letter-exchange` — one failure, one sink. Previously
both happened, so a queue with dead-letter routing recorded every failure twice.

- Using `RABBITMQ_REROUTE_FAILED=true` or any other dead-letter routing: no
  action needed. The duplicate `failed_messages` record simply stops appearing.
- Relying on the `failed_messages` copy: keep the old behaviour explicitly, and
  make sure the source queue has no dead-letter exchange of its own.

  ```env
  RABBITMQ_FAILED_OWNERSHIP=exchange
  RABBITMQ_FAILED_MESSAGES_EXCHANGE=failed_messages
  ```

Drain any existing `failed_messages` queue before switching, and point failure
dashboards, alerts, and replay tooling at whichever sink you selected.

### Consumer command options

`rabbitmq:consume` now accepts `--stop-when-empty-for` and `--json` with the
same meaning as `queue:work`. On Laravel 13 the command failed to start without
them; no configuration change is required.

## Upgrading PHP or Laravel

- PHP 8.2, 8.3, 8.4, and 8.5 are supported.
- Laravel 10.x, 11.x, 12.x, and 13.x are supported. Laravel 13 requires PHP 8.3 or newer.
- Make sure `ext-amqp` is available for the new PHP runtime.
- Rebuild containers or server images after changing PHP versions.

Check the installed extension:

```bash
php -m | grep amqp
php --ri amqp
```

## Upgrading RabbitMQ

RabbitMQ 3.13 and 4.x are the primary supported broker lines.

Before upgrading RabbitMQ:

- Confirm queue types used by your app: classic, quorum, lazy, priority, delayed-message plugin.
- Confirm delayed-message plugin compatibility if you use `RABBITMQ_DELAYED_PLUGIN_ENABLED=true`.
- Drain or pause non-critical workers during broker upgrades when possible.
- Verify vhosts, users, policies, exchanges, queues, bindings, and permissions after upgrade.

## Configuration checks

Review these settings during every major upgrade:

```env
QUEUE_CONNECTION=rabbitmq
RABBITMQ_WORKER=default
RABBITMQ_CONSUME_MODE=poll
RABBITMQ_EXCHANGE=
RABBITMQ_EXCHANGE_TYPE=direct
RABBITMQ_EXCHANGE_ROUTING_KEY=%s
RABBITMQ_QUEUE_QUORUM=false
RABBITMQ_QUEUE_LAZY=false
RABBITMQ_REROUTE_FAILED=false
```

Pay special attention to:

- `RABBITMQ_CONSUME_MODE=consume`, because it uses push-style `basic_consume` delivery.
- Quorum queues, because they are not compatible with priority queues.
- Failed-message rerouting and dead-letter exchange settings.
- Connection/channel pool limits for long-running workers.

## Worker restart

Always restart queue workers after upgrading package code or config:

```bash
php artisan queue:restart
```

Then restart Supervisor, systemd, Horizon, containers, or your process manager so workers load the new code.

## Rollback

If you need to rollback:

1. Stop workers.
2. Restore the previous package version and config.
3. Clear config cache.
4. Restart workers.
5. Verify no messages are stuck in unacked or dead-letter queues.

```bash
composer require iamfarhad/laravel-rabbitmq:<previous-version> --with-all-dependencies
php artisan config:clear
php artisan queue:restart
```
