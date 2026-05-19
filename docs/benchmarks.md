# Benchmarks

This page defines the benchmark plan for measuring Laravel RabbitMQ worker behavior with native ext-amqp.

The goal is not to publish synthetic numbers without context. The goal is to make performance claims reproducible.

## What to measure

Measure each scenario with the same PHP, Laravel, RabbitMQ, CPU, memory, and container limits.

| Scenario | Metric |
| --- | --- |
| Publish normal jobs | jobs per second, CPU, memory |
| Consume normal jobs in poll mode | jobs per second, CPU, memory |
| Consume normal jobs in consume mode | jobs per second, CPU, memory |
| Publish with confirms | jobs per second, confirm latency |
| Delayed jobs | publish latency, delivery accuracy |
| Quorum queues | throughput and recovery behavior |
| Worker runtime for 1 hour | memory growth and reconnect behavior |
| Worker runtime for 6 hours | memory growth and stability |

## Suggested comparison set

- `iamfarhad/laravel-rabbitmq` with native ext-amqp.
- A pure PHP AMQP Laravel queue driver.
- Laravel Redis queue as a familiar baseline.
- Laravel database queue as a low-throughput baseline.

## Environment template

Record this information for every benchmark run:

```text
PHP version:
Laravel version:
RabbitMQ version:
ext-amqp version:
OS or Docker image:
CPU:
Memory:
Queue type:
Worker mode:
Prefetch count:
Publisher confirms:
Message payload size:
Job handler duration:
```

## Benchmark commands

Create a dedicated benchmark app or command that can:

```bash
php artisan rabbitmq:bench:publish --queue=bench --jobs=10000 --payload-size=1024
php artisan rabbitmq:bench:consume --queue=bench --jobs=10000 --consume-mode=poll
php artisan rabbitmq:bench:consume --queue=bench --jobs=10000 --consume-mode=consume
```

Until first-class benchmark commands exist, use a demo application that dispatches a fixed number of jobs and measures end-to-end time.

## Reporting format

Use a table like this once measurements are available:

| Driver | Mode | Queue | Jobs | Payload | Jobs/sec | Peak memory | CPU notes |
| --- | --- | --- | ---: | ---: | ---: | ---: | --- |
| iamfarhad/laravel-rabbitmq | consume | classic | 10,000 | 1 KB | TBD | TBD | TBD |
| iamfarhad/laravel-rabbitmq | poll | classic | 10,000 | 1 KB | TBD | TBD | TBD |

## Interpretation rules

- Do not compare results from different machines.
- Run every benchmark at least three times.
- Report median values.
- Include configuration with the result.
- Separate publish performance from consume performance.
- Separate classic queues from quorum queues.
- Report publisher confirms separately because they intentionally add latency.

## Roadmap

- Add benchmark Artisan commands.
- Add a Docker Compose benchmark environment.
- Publish first benchmark results for RabbitMQ 3.13 and 4.x.
- Add a GitHub Actions manual workflow for benchmark smoke tests.
