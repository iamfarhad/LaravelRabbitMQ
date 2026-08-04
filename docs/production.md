# Production deployment guide

This guide covers production defaults for running Laravel queue workers with RabbitMQ and native ext-amqp.

## Recommended production baseline

```env
QUEUE_CONNECTION=rabbitmq
RABBITMQ_CONSUME_MODE=consume
RABBITMQ_HEARTBEAT_CONNECTION=30
RABBITMQ_CONNECT_TIMEOUT=10
RABBITMQ_READ_TIMEOUT=30
RABBITMQ_WRITE_TIMEOUT=30
RABBITMQ_MAX_RETRIES=3
RABBITMQ_RETRY_DELAY=1000
RABBITMQ_HEALTH_CHECK_ENABLED=true
RABBITMQ_HEALTH_CHECK_INTERVAL=30
```

Use `consume` mode for hot queues and `poll` mode when you want the most Laravel-like worker lifecycle.

## Supervisor

```ini
[program:laravel-rabbitmq]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/artisan rabbitmq:consume --queue=default --consume-mode=consume --memory=256 --tries=3 --timeout=60 --num-processes=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/supervisor/laravel-rabbitmq.log
stopwaitsecs=3600
```

Scale with `numprocs`, containers, or separate deployments. Prefer one hot queue per worker group when using `consume` mode.

## systemd

```ini
[Unit]
Description=Laravel RabbitMQ worker
After=network.target

[Service]
User=www-data
Group=www-data
Restart=always
WorkingDirectory=/var/www/html
ExecStart=/usr/bin/php artisan rabbitmq:consume --queue=default --consume-mode=consume --memory=256 --tries=3 --timeout=60
TimeoutStopSec=3600
KillSignal=SIGTERM

[Install]
WantedBy=multi-user.target
```

## Docker Compose worker

```yaml
services:
  worker:
    build: .
    command: php artisan rabbitmq:consume --queue=default --consume-mode=consume --memory=256 --tries=3 --timeout=60
    restart: unless-stopped
    depends_on:
      rabbitmq:
        condition: service_healthy

  rabbitmq:
    image: rabbitmq:3.13-management
    ports:
      - "5672:5672"
      - "15672:15672"
    healthcheck:
      test: ["CMD", "rabbitmq-diagnostics", "ping"]
      interval: 10s
      timeout: 5s
      retries: 5
```

## Kubernetes worker

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: laravel-rabbitmq-worker
spec:
  replicas: 3
  selector:
    matchLabels:
      app: laravel-rabbitmq-worker
  template:
    metadata:
      labels:
        app: laravel-rabbitmq-worker
    spec:
      containers:
        - name: worker
          image: your-app:latest
          command: ["php", "artisan", "rabbitmq:consume"]
          args: ["--queue=default", "--consume-mode=consume", "--memory=256", "--tries=3", "--timeout=60"]
          envFrom:
            - secretRef:
                name: laravel-env
          resources:
            requests:
              cpu: 100m
              memory: 256Mi
            limits:
              cpu: 1000m
              memory: 512Mi
```

## Prefetch and concurrency

`basic.qos` governs `basic_consume` deliveries only, so prefetch applies to
`RABBITMQ_CONSUME_MODE=consume` and does nothing in the default `poll` mode.

The default is `1`, which is the right value for a single-threaded worker: it
runs one job at a time, and anything prefetched beyond that sits unacked behind
the job in flight — where a timeout, memory limit or crash turns it into a
redelivery rather than throughput.

```php
'options' => [
    'queue' => [
        'qos' => [
            'prefetch_size' => 0,
            'prefetch_count' => 1,
        ],
    ],
],
```

Raise `prefetch_count` only for short, I/O-bound jobs, and only after measuring
processing time, memory usage and retry behaviour. Make sure your job timeout is
comfortably shorter than the time it takes to work through a full prefetch batch,
or the tail of every batch gets redelivered.

## Publisher confirms

Enable publisher confirms for workflows where message persistence must be acknowledged by RabbitMQ:

```env
RABBITMQ_PUBLISHER_CONFIRMS_ENABLED=true
RABBITMQ_PUBLISHER_CONFIRMS_TIMEOUT=5
```

This improves delivery confidence at the cost of extra publish latency. A
broker NACK fails only the publish it belongs to: it surfaces as an exception
from that publish and is then cleared, so a long-lived publisher or worker
keeps reporting later acknowledged publishes as successful.

## Quorum queues

Use quorum queues for stronger queue durability in RabbitMQ clusters:

```env
RABBITMQ_QUEUE_QUORUM=true
```

Do not combine quorum queues with priority queues.

## Dead-letter routing

```env
RABBITMQ_REROUTE_FAILED=true
RABBITMQ_FAILED_EXCHANGE=failed.jobs
RABBITMQ_FAILED_ROUTING_KEY=%s.failed
```

Create dashboards and alerts around failed exchanges and DLQs.

### Failure ownership

A permanently failed job reaches exactly one failure sink, selected by
`RABBITMQ_FAILED_OWNERSHIP`:

| Value | Behaviour |
| --- | --- |
| `broker` (default) | The job is rejected without requeue and the queue's configured `x-dead-letter-exchange` owns the failure. Use this with `RABBITMQ_REROUTE_FAILED=true`. |
| `exchange` | The job is rejected without requeue and a copy is also published to the queue named by `RABBITMQ_FAILED_MESSAGES_EXCHANGE` (default `failed_messages`). |

```env
# Broker-owned failures (recommended): one record, in the DLQ.
RABBITMQ_FAILED_OWNERSHIP=broker

# Package-owned failures: a copy in a dedicated queue instead.
RABBITMQ_FAILED_OWNERSHIP=exchange
RABBITMQ_FAILED_MESSAGES_EXCHANGE=failed_messages
```

Do not point `exchange` ownership at a queue that also has dead-letter routing
configured — the same failure would then be recorded twice, with divergent
retention, alerting, and replay policies.

### Settlement failures

Acknowledging or rejecting a delivery can fail — a broker restart, a dropped
connection, or an expired delivery tag. When it does, the package throws
`iamfarhad\LaravelRabbitMQ\Exceptions\SettlementException` after dropping the
unusable delivering channel, rather than returning as if the broker had settled
the delivery. Dropping that channel is what lets RabbitMQ redeliver.

Two consequences to design for:

- **Handlers must be idempotent.** An unsettled delivery is redelivered, so a
  job whose work succeeded but whose ack failed will run again.
- **A failed ack is reported as a job exception.** Laravel's worker reports it
  through the exception handler. With `--tries=1` this also records a failed job
  even though the handler succeeded — alert on `SettlementException` separately
  from ordinary job failures.

Terminal failure is the one exception: `markAsFailed()` reports a settlement
failure through the exception handler instead of throwing, because Laravel calls
it before the lifecycle step that writes `failed_jobs`, and throwing would
discard that record.

Note that the reject still precedes the `failed_jobs` write. If that write fails,
the delivery may already have been settled — which is a further reason to run
broker-owned failures with a dead-letter exchange, so the payload survives in the
DLQ either way.

## Operational checklist

- Build PHP images with ext-amqp installed and verified.
- Set heartbeat and timeout values explicitly.
- Use Supervisor, systemd, or orchestrator restarts.
- Restart workers during deploys.
- Monitor worker memory and restart before memory pressure.
- Use separate worker groups for high-priority queues.
- Enable publisher confirms where message loss is unacceptable.
- Use DLQs for failed-message inspection.
- Document queue topology in version control.
