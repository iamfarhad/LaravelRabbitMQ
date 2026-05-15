# Security Policy

Thank you for helping keep Laravel RabbitMQ secure.

## Supported versions

Security fixes are provided for the currently supported package versions and the supported runtime matrix documented in [SUPPORT.md](SUPPORT.md).

## Reporting a vulnerability

Please do not report security vulnerabilities in public GitHub issues.

Report vulnerabilities through GitHub Security Advisories when available, or email the maintainer listed in `composer.json` with the subject:

```text
Security report for iamfarhad/laravel-rabbitmq
```

Include as much detail as possible:

- Affected package version or commit SHA.
- PHP, Laravel, RabbitMQ, and `ext-amqp` versions.
- Configuration needed to reproduce the issue, with credentials removed.
- A proof of concept or clear reproduction steps.
- Potential impact and whether the issue is being actively exploited.

## Response expectations

The maintainer will triage valid reports as quickly as possible. Confirmed vulnerabilities will be fixed privately when practical, then disclosed with a release note or advisory after a patch is available.

## Scope

In scope:

- Package code that can compromise confidentiality, integrity, or availability of Laravel applications using this driver.
- Unsafe handling of RabbitMQ connection settings, queue payloads, acknowledgements, retries, or failed jobs.
- Dependency or CI issues that materially affect package consumers.

Out of scope:

- Vulnerabilities in RabbitMQ itself, PHP, Laravel, `ext-amqp`, or infrastructure outside this repository.
- Reports that require access to secrets, credentials, or systems not controlled by the reporter.
- Denial-of-service reports without a realistic package-level mitigation.
