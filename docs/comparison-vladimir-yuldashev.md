# Comparison: `iamfarhad/laravel-rabbitmq` vs `vladimir-yuldashev/laravel-queue-rabbitmq`

This page helps teams choose between this package and `vladimir-yuldashev/laravel-queue-rabbitmq`.

## Summary

| Area | `iamfarhad/laravel-rabbitmq` | `vladimir-yuldashev/laravel-queue-rabbitmq` |
| --- | --- | --- |
| Primary goal | Production-focused Laravel queue driver with explicit RabbitMQ topology helpers and modern Laravel support | Established Laravel RabbitMQ queue connector with broad community history |
| AMQP implementation | Native `ext-amqp` | Commonly used with PHP AMQP libraries / package-specific implementation details |
| Laravel support | 11.x, 12.x, 13.x | Check the upstream package constraints for current support |
| PHP support | 8.2, 8.3, 8.4, 8.5 | Check the upstream package constraints for current support |
| RabbitMQ support | 3.13 and 4.x tested in CI; 3.8 - 3.12 best effort | Check upstream docs and CI |
| Worker modes | Laravel `queue:work`, package `rabbitmq:consume` in `poll` or `consume` mode | Laravel queue worker integration |
| Connection/channel pooling | Built in | Check upstream implementation |
| Topology helpers | Exchange/queue declare, purge, delete, pool stats commands | Check upstream feature set |
| Horizon integration | Optional guarded Horizon event integration | Check upstream feature set |
| Octane support | Optional pool reset hook | Check upstream feature set |
| CI matrix | PHP/Laravel matrix plus RabbitMQ 3.13 and 4.x | Check upstream CI |

## When to choose this package

Choose `iamfarhad/laravel-rabbitmq` when you want:

- Native `ext-amqp` support.
- Explicit support for Laravel 11, 12, and 13.
- CI coverage for PHP 8.2 through 8.5.
- CI coverage for RabbitMQ 3.13 and 4.x.
- Connection and channel pooling.
- Optional `basic_consume` worker mode for hot queues.
- Built-in commands for queue/exchange administration.
- Optional Horizon and Octane integration points.

## Migration notes

See [MIGRATION.md](../MIGRATION.md) for a step-by-step migration guide and configuration mapping.

## Caveats

This comparison is intentionally conservative. Always check the upstream package's latest README, composer constraints, and CI status before making a final decision.
