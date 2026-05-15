# Contributing

Thanks for contributing to Laravel RabbitMQ.

## Development requirements

- PHP 8.2 or higher.
- Composer 2.
- `ext-amqp` enabled.
- RabbitMQ 3.13 or 4.x for integration testing.
- `ext-pcntl` when testing multi-process consumers.

## Setup

```bash
git clone https://github.com/iamfarhad/LaravelRabbitMQ.git
cd LaravelRabbitMQ
composer install
```

Start RabbitMQ locally:

```bash
docker run -d --name laravel-rabbitmq \
  -p 5672:5672 \
  -p 15672:15672 \
  -e RABBITMQ_DEFAULT_USER=laravel \
  -e RABBITMQ_DEFAULT_PASS=secret \
  -e RABBITMQ_DEFAULT_VHOST=b2b-field \
  rabbitmq:3.13-management
```

## Quality checks

Run these before opening a pull request:

```bash
composer format-test
composer analyse
composer test
```

The full CI matrix also runs dependency-boundary jobs with Composer `--prefer-lowest` and `--prefer-stable`, multiple PHP/Laravel combinations, and RabbitMQ 3.13 / 4.x service containers.

## Pull request guidelines

- Keep pull requests focused and small enough to review.
- Include tests for behavior changes.
- Update README or docs when configuration, commands, or runtime support changes.
- Avoid breaking public APIs unless the pull request also includes migration notes.
- Do not include secrets, real credentials, or private broker topology in issues or tests.

## Coding standards

This package uses Laravel Pint and PHPStan.

Prefer clear, typed code and small methods. When changing worker, acknowledgement, retry, or connection-pool behavior, include tests covering failure paths.

## Documentation style

- Use fenced code blocks with language hints.
- Keep configuration examples secret-free.
- Mention version constraints when behavior depends on PHP, Laravel, RabbitMQ, or `ext-amqp`.
- Link to `SUPPORT.md`, `UPGRADE.md`, and `MIGRATION.md` when applicable.

## Security reports

Do not open public issues for vulnerabilities. Follow [SECURITY.md](SECURITY.md).
