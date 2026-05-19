# Growth roadmap

This roadmap tracks work that helps more production Laravel teams discover, trust, and adopt this package.

## Positioning

Core message:

> Native ext-amqp RabbitMQ queue driver for Laravel production workloads.

Target user:

- Controls the production PHP runtime.
- Uses RabbitMQ as a production system component.
- Wants long-running worker stability.
- Values native AMQP support, pooling, confirms, and topology controls.

## Repository conversion checklist

- [x] Update Composer description with native ext-amqp positioning.
- [x] Add discovery keywords for ext-amqp, Horizon, Octane, publisher confirms, quorum queues, and queue-driver usage.
- [x] Rewrite README opening section around production use.
- [x] Add installation guide for Docker, Alpine, Sail, Ubuntu, and GitHub Actions.
- [x] Add production deployment guide.
- [x] Add copy-paste recipes.
- [x] Add benchmark plan.
- [x] Add why-native-ext-amqp positioning page.
- [ ] Add real benchmark commands.
- [ ] Publish benchmark results.
- [ ] Add benchmark workflow or reproducible benchmark demo.
- [ ] Create a separate Laravel demo repository.
- [ ] Add GitHub repository topics.
- [ ] Publish external articles and community posts.

## Recommended GitHub topics

Add these topics in the repository settings:

```text
laravel
rabbitmq
laravel-queue
queue-driver
amqp
ext-amqp
php-amqp
rabbitmq-c
message-broker
distributed-systems
microservices
laravel-horizon
horizon
laravel-octane
octane
publisher-confirms
quorum-queues
dead-letter-exchange
delayed-messages
```

## Article ideas

- Using RabbitMQ with Laravel Queues in Production.
- Laravel RabbitMQ with Native ext-amqp.
- RabbitMQ vs Redis Queues in Laravel Production.
- How to Run Laravel Queue Workers with RabbitMQ, Supervisor, and Horizon.
- Laravel RabbitMQ Quorum Queues Explained.
- Using Publisher Confirms in Laravel RabbitMQ.
- php-amqplib vs ext-amqp for Laravel Queues.

## Demo repository scope

Create a separate repository named `laravel-rabbitmq-demo` with:

- Laravel 12 application.
- Docker Compose with RabbitMQ management image.
- PHP image with ext-amqp installed.
- Example jobs.
- Delayed job example.
- Quorum queue example.
- Failed job reroute example.
- Publisher confirms example.
- Horizon optional setup.
- Supervisor example.

## Benchmark scope

Add benchmark commands or a demo app that can measure:

- Publish throughput.
- Consume throughput in poll mode.
- Consume throughput in consume mode.
- Publisher confirm latency.
- Quorum queue behavior.
- Worker memory after 1 hour.
- Worker memory after 6 hours.

## Community launch checklist

- [ ] Tag a release after documentation changes.
- [ ] Publish benchmark results.
- [ ] Post a short launch note on LinkedIn, X, Reddit r/laravel, Reddit r/PHP, Laravel.io, and Laracasts forum.
- [ ] Submit to Laravel News community links.
- [ ] Ask users to star the repo if it helps them run RabbitMQ in production.
