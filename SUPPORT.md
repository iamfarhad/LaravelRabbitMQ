# Support Policy

This package follows the supported PHP and Laravel versions declared in `composer.json` and verified by GitHub Actions.

## Supported versions

| Component | Supported | CI coverage | Notes |
| --- | --- | --- | --- |
| PHP | 8.2, 8.3, 8.4, 8.5 | Yes | PHP 8.5 is included in the test matrix to catch upcoming/runtime compatibility issues early. |
| Laravel | 12.x, 13.x | Yes | 10.x and 11.x are declared in `composer.json` but are not CI-verified; see the matrix below. |
| RabbitMQ | 3.13, 4.x | Yes | These are the actively tested broker lines. |
| RabbitMQ | 3.8 - 3.12 | Best effort | Expected to work for common AMQP 0-9-1 queue usage, but not part of the primary CI matrix. |
| RabbitMQ | < 3.8 | No | Upgrade RabbitMQ before opening compatibility bugs. |
| PHP extension | `ext-amqp` | Yes | Required. The package uses the native AMQP extension. |
| Optional extension | `ext-pcntl` | Partial | Required only for `rabbitmq:consume --num-processes` values greater than `1`. |
| Laravel Horizon | Compatible | Optional | Enable with `RABBITMQ_WORKER=horizon`; Horizon is not required. |
| Laravel Octane | Compatible | Optional | Enable per-request pool cleanup with `RABBITMQ_OCTANE_RESET_ON_REQUEST=true`. |

## Laravel / PHP support matrix

| Laravel | PHP 8.2 | PHP 8.3 | PHP 8.4 | PHP 8.5 | Testbench | In CI |
| --- | --- | --- | --- | --- | --- | --- |
| 10.x | Best effort | Best effort | Not tested | Not tested | 8.x | No — see below |
| 11.x | Best effort | Best effort | Best effort | Best effort | 9.x | No — see below |
| 12.x | Supported | Supported | Supported | Supported | 10.x | PHP 8.2, 8.3, 8.4, 8.5 |
| 13.x | Not supported (Laravel 13 requires PHP >= 8.3) | Supported | Supported | Supported | 11.x | PHP 8.3, 8.4, 8.5 |

The "In CI" column lists the PHP versions each Laravel line is actually built
against on every pull request.

### Why Laravel 10.x and 11.x are not in CI

`composer.json` still allows `^10.0|^11.0`, and the driver contains no code that
is known to break on them — the worker compatibility layer is exercised against
both sides of the Laravel 13 `Worker` API change, and the Laravel 12 build passes
the full suite.

They are nonetheless not built in CI: **every released 10.x and 11.x version of
`laravel/framework` is currently flagged by Composer's security-advisory
policy**, so `composer update` refuses to resolve them. The only ways to build
those rows would be to disable advisory blocking or pin a flagged release, both
of which mean testing against framework versions with known vulnerabilities.

Practical consequence: if you are on Laravel 10 or 11, upgrade the framework.
Bugs affecting those lines are still fixed when they are reproducible and the fix
does not require breaking the supported versions, but they are not verified per
commit.

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
