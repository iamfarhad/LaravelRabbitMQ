<?php

use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;

/*
|--------------------------------------------------------------------------
| A note on casting
|--------------------------------------------------------------------------
| Laravel's env() only coerces the literal strings "true", "false", "null"
| and "empty". Every other value — including every number — comes back as a
| string. The driver assigns these into typed properties and constructor
| arguments under strict_types, so numeric and boolean options are cast here
| rather than left to blow up at runtime the first time someone sets one of
| these variables in .env.
*/

return [
    'driver' => 'rabbitmq',
    'queue' => env('RABBITMQ_QUEUE', 'default'),
    'after_commit' => (bool) env('RABBITMQ_AFTER_COMMIT', false),

    // Set to "horizon" to enable optional Horizon event integration when Laravel Horizon is installed.
    'worker' => env('RABBITMQ_WORKER', 'default'),

    // Label shown for this connection in the RabbitMQ management UI and in
    // `rabbitmqctl list_connections`. Highly recommended in production: it is
    // what lets you tell one application's connections from another's.
    'connection_name' => env('RABBITMQ_CONNECTION_NAME', env('APP_NAME', 'laravel')),

    // Backward compatible single host config. You may also replace this with a list of hosts:
    // 'hosts' => [
    //     ['host' => 'rabbitmq-1', 'port' => 5672, 'user' => 'guest', 'password' => 'guest', 'vhost' => '/'],
    //     ['host' => 'rabbitmq-2', 'port' => 5672, 'user' => 'guest', 'password' => 'guest', 'vhost' => '/'],
    // ],
    'hosts' => [
        'host' => env('RABBITMQ_HOST', '127.0.0.1'),
        'port' => (int) env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
        'lazy' => (bool) env('RABBITMQ_LAZY_CONNECTION', true),

        // Heartbeats keep an otherwise idle connection alive through brokers,
        // load balancers and firewalls that reap silent TCP sessions. Leaving
        // this at 0 (the pre-1.5 default) is what makes long-lived publishers
        // fail with "Broken pipe" after an idle period.
        'heartbeat' => (int) env('RABBITMQ_HEARTBEAT_CONNECTION', 60),

        // Bounded connect so a dead broker fails fast instead of hanging the
        // request or worker on a TCP connect.
        'connect_timeout' => (int) env('RABBITMQ_CONNECT_TIMEOUT', 10),

        // Leave read_timeout at 0 when using RABBITMQ_CONSUME_MODE=consume: a
        // non-zero read timeout aborts a blocking basic.consume on an idle
        // queue. For poll mode, set it to at least 2x heartbeat so a half-open
        // TCP connection cannot hang the worker indefinitely.
        'read_timeout' => (int) env('RABBITMQ_READ_TIMEOUT', 0),
        'write_timeout' => (int) env('RABBITMQ_WRITE_TIMEOUT', 0),

        'secure' => (bool) env('RABBITMQ_SECURE', false),
    ],

    // Publishing Configuration for normal Laravel queue jobs.
    //
    // When `exchange` is non-empty the driver declares the exchange, declares
    // the queue, and binds the queue to that exchange with the routing key
    // produced by `exchange_routing_key`. Leave it empty to publish through the
    // default exchange, where every queue is implicitly bound by name.
    'exchange' => env('RABBITMQ_EXCHANGE', ''),
    'exchange_type' => env('RABBITMQ_EXCHANGE_TYPE', 'direct'),
    'exchange_routing_key' => env('RABBITMQ_EXCHANGE_ROUTING_KEY', '%s'),
    'prioritize_delayed' => (bool) env('RABBITMQ_PRIORITIZE_DELAYED', false),
    'queue_max_priority' => (int) env('RABBITMQ_QUEUE_MAX_PRIORITY', 10),
    'quorum' => (bool) env('RABBITMQ_QUEUE_QUORUM', false),
    'reroute_failed' => (bool) env('RABBITMQ_REROUTE_FAILED', false),
    'failed_exchange' => env('RABBITMQ_FAILED_EXCHANGE', ''),
    'failed_routing_key' => env('RABBITMQ_FAILED_ROUTING_KEY', '%s.failed'),

    // Granularity, in milliseconds, that delayed-job TTLs are rounded up to.
    //
    // Without the plugin, each distinct delay needs its own broker-side delay
    // queue, so arbitrary or jittered backoff values would create an unbounded
    // number of queues. Rounding up buckets them and never fires a job early.
    // Set to 1 to disable bucketing, or enable the delayed-message plugin below
    // if you need many distinct sub-second delays.
    'delay_queue_granularity' => (int) env('RABBITMQ_DELAY_QUEUE_GRANULARITY', 1000),

    // Who owns a permanently failed job. Exactly one sink receives it, so a
    // broker dead-letter setup can never produce a second, divergent record.
    'failed' => [
        // 'broker'   — reject without requeue and let the queue's configured
        //              x-dead-letter-exchange own the failure (default; this is
        //              the mode to use together with `reroute_failed`).
        // 'exchange' — reject, then also publish a copy to the queue named in
        //              `failed.exchange` below. This is the pre-1.4.0
        //              behaviour; do not combine it with `reroute_failed` or
        //              any other x-dead-letter-exchange on the source queue,
        //              or the same failure is recorded twice.
        'ownership' => env('RABBITMQ_FAILED_OWNERSHIP', 'broker'),

        // Destination used only by the 'exchange' ownership mode.
        'exchange' => env('RABBITMQ_FAILED_MESSAGES_EXCHANGE', 'failed_messages'),
    ],

    // Connection and Channel Pool Configuration
    'pool' => [
        'max_connections' => (int) env('RABBITMQ_MAX_CONNECTIONS', 10),
        'min_connections' => (int) env('RABBITMQ_MIN_CONNECTIONS', 2),
        'max_channels_per_connection' => (int) env('RABBITMQ_MAX_CHANNELS_PER_CONNECTION', 100),
        'max_retries' => (int) env('RABBITMQ_MAX_RETRIES', 3),
        'retry_delay' => (int) env('RABBITMQ_RETRY_DELAY', 1000), // milliseconds

        // Lazy by default: eagerly opening min_connections sockets would happen
        // as a side effect of merely resolving the queue connection, in every
        // artisan one-shot and every request that never publishes anything.
        'lazy' => (bool) env('RABBITMQ_LAZY_POOL', true),

        'health_check_enabled' => (bool) env('RABBITMQ_HEALTH_CHECK_ENABLED', true),
        'health_check_interval' => (int) env('RABBITMQ_HEALTH_CHECK_INTERVAL', 30), // seconds
    ],

    // Octane Integration
    'octane' => [
        // Keep false by default for performance. Enable when each request should start with fresh AMQP pools.
        'reset_on_request' => (bool) env('RABBITMQ_OCTANE_RESET_ON_REQUEST', false),
    ],

    // Exponential Backoff Configuration
    //
    // Available to application code through RabbitQueue::getBackoff(). Channel
    // replacement and connection retries use their own bounded backoff so a long
    // job backoff can never stall a reconnect; only `jitter` is shared.
    'backoff' => [
        'base_delay' => (int) env('RABBITMQ_BACKOFF_BASE_DELAY', 1000), // milliseconds
        'max_delay' => (int) env('RABBITMQ_BACKOFF_MAX_DELAY', 60000), // milliseconds
        'multiplier' => (float) env('RABBITMQ_BACKOFF_MULTIPLIER', 2.0),
        'jitter' => (bool) env('RABBITMQ_BACKOFF_JITTER', true),
    ],

    // Queue Configuration
    //
    // Keys are queue names. A key present here overrides the connection-wide
    // setting of the same name for that queue, so only list what you actually
    // want to differ — an explicit `false` here beats a `true` above.
    //
    // Available per-queue overrides: `lazy`, `quorum`, `priority`, `durable`,
    // `auto_delete`, `arguments`, `bindings`.
    'queues' => [
        'default' => [
            // No connection-wide equivalent, so these are always explicit.
            'durable' => (bool) env('RABBITMQ_QUEUE_DURABLE', true),
            'auto_delete' => (bool) env('RABBITMQ_QUEUE_AUTO_DELETE', false),

            // null means "no per-queue maximum priority".
            'priority' => env('RABBITMQ_QUEUE_PRIORITY', null), // null or max priority (1-255)

            // `lazy` and `quorum` are deliberately absent: they are configured
            // connection-wide above (RABBITMQ_QUEUE_LAZY, RABBITMQ_QUEUE_QUORUM).
            // Listing them here with a default would shadow those values,
            // because an explicit per-queue setting always wins.

            // Extra x-arguments passed verbatim to queue.declare.
            'arguments' => [],

            // Additional exchange bindings applied when this queue is declared,
            // on top of the connection-wide `exchange` binding. For example:
            //
            // 'bindings' => [
            //     ['exchange' => 'events', 'exchange_type' => 'topic', 'routing_key' => 'order.*'],
            // ],
            'bindings' => [],
        ],
    ],

    // Dead Letter Exchange Configuration
    //
    // Applied by RabbitQueue::setupDeadLetterExchange(). Call it before the
    // target queue is first declared: RabbitMQ queue arguments are immutable.
    'dead_letter' => [
        'enabled' => (bool) env('RABBITMQ_DLX_ENABLED', true),
        'exchange' => env('RABBITMQ_DLX_EXCHANGE', 'dlx'),
        'exchange_type' => env('RABBITMQ_DLX_EXCHANGE_TYPE', 'direct'),
        'queue_suffix' => env('RABBITMQ_DLX_QUEUE_SUFFIX', '.dlq'),
        'ttl' => env('RABBITMQ_DLX_TTL', null), // Retention for dead-lettered messages, in milliseconds
    ],

    // Delayed Message Configuration
    //
    // Enable the plugin path when you need many distinct delays: it uses a
    // single x-delayed-message exchange instead of one delay queue per TTL.
    // Requires the rabbitmq_delayed_message_exchange plugin on the broker.
    'delayed_message' => [
        'exchange' => env('RABBITMQ_DELAYED_EXCHANGE', 'delayed'),
        'exchange_type' => env('RABBITMQ_DELAYED_EXCHANGE_TYPE', 'direct'),
        'plugin_enabled' => (bool) env('RABBITMQ_DELAYED_PLUGIN_ENABLED', false),
    ],

    // RPC Configuration
    'rpc' => [
        'enabled' => (bool) env('RABBITMQ_RPC_ENABLED', false),
        'timeout' => (int) env('RABBITMQ_RPC_TIMEOUT', 30), // seconds

        // Leave empty to let the broker name the exclusive reply queue.
        'callback_queue_prefix' => env('RABBITMQ_RPC_CALLBACK_PREFIX', ''),
    ],

    // Publisher Confirms Configuration
    'publisher_confirms' => [
        'enabled' => (bool) env('RABBITMQ_PUBLISHER_CONFIRMS_ENABLED', false),
        'timeout' => (int) env('RABBITMQ_PUBLISHER_CONFIRMS_TIMEOUT', 5), // seconds

        // Publish with AMQP_MANDATORY so the broker returns an unroutable
        // message instead of discarding it. Only honoured while publisher
        // confirms are enabled, because that is where the basic.return handler
        // lives that turns the return into a reported failure.
        'mandatory' => (bool) env('RABBITMQ_PUBLISHER_CONFIRMS_MANDATORY', false),
    ],

    // Transaction Configuration
    //
    // Mutually exclusive with publisher confirms: a channel cannot be in both
    // confirm mode and transaction mode.
    'transactions' => [
        'enabled' => (bool) env('RABBITMQ_TRANSACTIONS_ENABLED', false),
    ],

    'options' => [
        'read_timeout' => (int) env('RABBITMQ_READ_TIMEOUT', 0),
        'write_timeout' => (int) env('RABBITMQ_WRITE_TIMEOUT', 0),
        'connect_timeout' => (int) env('RABBITMQ_CONNECT_TIMEOUT', 10),
        'ssl_options' => [
            'cafile' => env('RABBITMQ_SSL_CAFILE', null),
            'local_cert' => env('RABBITMQ_SSL_LOCALCERT', null),
            'local_key' => env('RABBITMQ_SSL_LOCALKEY', null),
            'verify_peer' => (bool) env('RABBITMQ_SSL_VERIFY_PEER', true),
        ],
        'queue' => [
            'job' => RabbitMQJob::class,
            // rabbitmq:consume supports "poll" (basic_get) and "consume" (basic_consume) modes.
            'consume_mode' => env('RABBITMQ_CONSUME_MODE', 'poll'),

            // Connection-wide lazy-queue default. Override per queue with
            // `queues.<queue>.lazy`. Note that RabbitMQ 4.x classic queues are
            // lazy by default and treat x-queue-mode as a no-op.
            'lazy' => (bool) env('RABBITMQ_QUEUE_LAZY', false),

            // basic.qos only governs basic.consume deliveries, so this applies
            // to consume mode only. A single-threaded worker processes one job
            // at a time: a prefetch above 1 parks the surplus behind the job in
            // flight, where a timeout or crash turns them into redeliveries.
            'qos' => [
                'prefetch_size' => (int) env('RABBITMQ_PREFETCH_SIZE', 0),
                'prefetch_count' => (int) env('RABBITMQ_PREFETCH_COUNT', 1),
            ],
        ],
    ],
];
