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

## Upgrading PHP or Laravel

- PHP 8.2, 8.3, 8.4, and 8.5 are supported.
- Laravel 11.x, 12.x, and 13.x are supported.
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
