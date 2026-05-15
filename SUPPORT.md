# Support Policy

This package follows the supported PHP and Laravel versions declared in `composer.json` and verified by GitHub Actions.

## Supported versions

| Component | Supported | CI coverage | Notes |
| --- | --- | --- | --- |
| PHP | 8.2, 8.3, 8.4, 8.5 | Yes | PHP 8.5 is included in the test matrix to catch upcoming/runtime compatibility issues early. |
| Laravel | 10.x, 11.x, 12.x, 13.x | Yes | Support is provided through `illuminate/queue` and `illuminate/support`. |
| RabbitMQ | 3.13, 4.x | Yes | These are the actively tested broker lines. |
| RabbitMQ | 3.8 - 3.12 | Best effort | Expected to work for common AMQP 0-9-1 queue usage, but not part of the primary CI matrix. |
| RabbitMQ | < 3.8 | No | Upgrade RabbitMQ before opening compatibility bugs. |
| PHP extension | `ext-amqp` | Yes | Required. The package uses the native AMQP extension. |
| Optional extension | `ext-pcntl` | Partial | Required only for `rabbitmq:consume --num-processes` values greater than `1`. |
| Laravel Horizon | Compatible | Optional | Enable with `RABBITMQ_WORKER=horizon`; Horizon is not required. |
| Laravel Octane | Compatible | Optional | Enable per-request pool cleanup with `RABBITMQ_OCTANE_RESET_ON_REQUEST=true`. |

## Laravel / PHP support matrix

| Laravel | PHP 8.2 | PHP 8.3 | PHP 8.4 | PHP 8.5 | Testbench |
| --- | --- | --- | --- | --- | --- |
| 10.x | Supported | Supported | Not tested | Not tested | 8.x |
| 11.x | Supported | Supported | Supported | Supported | 9.x |
| 12.x | Supported | Supported | Supported | Supported | 10.x |
| 13.x | Not tested | Supported | Supported | Supported | 11.x |

## RabbitMQ support matrix

| RabbitMQ | Status | CI image |
| --- | --- | --- |
| 3.13.x | Supported | `rabbitmq:3.13-management` |
| 4.x | Supported | `rabbitmq:4-management` |
| 3.8.x - 3.12.x | Best effort | Not tested on every pull request |
| Older than 3.8 | Unsupported | Not tested |

## Transport support

| Transport | Status | Notes |
| --- | --- | --- |
| `tcp` | Supported | Default AMQP connection over TCP. |
| `ssl` | Supported | Native `ext-amqp` TLS/SSL connection options. |
| `tls` | Supported | Alias-style transport option for TLS deployments. |

## What gets fixed

Security fixes are prioritized for all supported versions when a safe fix is available.

Bug fixes are prioritized when they affect a supported Laravel/PHP/RabbitMQ combination, include a reproducible example, and do not require breaking API changes.

New features target the latest supported Laravel and RabbitMQ combinations first. Older supported combinations may receive the feature when the implementation remains compatible.

## Opening support requests

Before opening an issue, please include:

- Package version or commit SHA.
- PHP, Laravel, RabbitMQ, and `ext-amqp` versions.
- Whether the worker is `queue:work` or `rabbitmq:consume`.
- Whether `RABBITMQ_CONSUME_MODE` is `poll` or `consume`.
- A minimal reproduction or failing test.
- Redacted RabbitMQ connection/topology configuration.

Use GitHub Discussions or a question issue for usage questions. Use the security policy for vulnerabilities.
