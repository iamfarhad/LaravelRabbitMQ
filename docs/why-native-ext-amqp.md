# Why native ext-amqp?

This package is intentionally built on the native PHP ext-amqp extension.

The target user is a Laravel team that controls its production PHP runtime and wants RabbitMQ workers optimized for long-running workloads.

## Use this package when you want

- Native AMQP support through ext-amqp.
- Connection and channel pooling.
- Explicit RabbitMQ topology controls.
- Publisher confirms and transaction helpers.
- Quorum queues, priority queues, dead-letter routing, and delayed jobs.
- Optional Laravel Horizon and Laravel Octane integration.
- RabbitMQ 3.13 and 4.x focused compatibility.

## Trade-off

| Area | Native ext-amqp | Pure PHP AMQP client |
| --- | --- | --- |
| Installation | Requires PHP extension and native libraries | Composer-only |
| Runtime profile | Better fit for controlled production runtimes | Better fit for portability |
| Shared hosting | Usually harder | Usually easier |
| Package focus | Production workers and native AMQP | Easy installation |

## Recommended short description

Native ext-amqp RabbitMQ queue driver for Laravel production workloads.

## Good fit

- Docker or custom PHP images.
- Kubernetes worker deployments.
- Dedicated servers or managed VPS environments.
- High-volume background jobs.
- Systems where RabbitMQ is part of the core architecture.

## Not the best fit

- Shared hosting without PECL or PHP extension support.
- Projects that need Composer-only AMQP installation.
- Prototypes where RabbitMQ is not production-critical.
