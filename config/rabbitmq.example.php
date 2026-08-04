<?php

use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;

/**
 * Laravel RabbitMQ - Advanced Configuration Example
 *
 * Demonstrates the available configuration options. Only the keys the driver
 * actually reads appear here; see config/rabbitmq.php for the shipped defaults.
 *
 * Note the explicit casts. Laravel's env() only coerces the literal strings
 * "true", "false", "null" and "empty" — every number comes back as a string, and
 * the driver assigns these into typed properties under strict_types.
 */
return [
    'driver' => 'rabbitmq',
    'queue' => env('RABBITMQ_QUEUE', 'default'),

    // Label shown in the RabbitMQ management UI and `rabbitmqctl
    // list_connections`. Set this: it is what lets you tell one application's
    // connections from another's.
    'connection_name' => env('RABBITMQ_CONNECTION_NAME', env('APP_NAME', 'laravel')),

    // ==================== Connection Settings ====================
    // A list of hosts enables failover; connection attempts are shuffled and
    // cycled so a retry targets a different node.
    'hosts' => [
        'host' => env('RABBITMQ_HOST', '127.0.0.1'),
        'port' => (int) env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
        'lazy' => (bool) env('RABBITMQ_LAZY_CONNECTION', true),

        // Keeps idle connections alive through brokers, load balancers and
        // firewalls that reap silent TCP sessions.
        'heartbeat' => (int) env('RABBITMQ_HEARTBEAT_CONNECTION', 60),
        'connect_timeout' => (int) env('RABBITMQ_CONNECT_TIMEOUT', 10),

        // Leave read_timeout at 0 for RABBITMQ_CONSUME_MODE=consume: a non-zero
        // read timeout aborts a blocking basic.consume on an idle queue. For
        // poll mode, use at least 2x heartbeat.
        'read_timeout' => (int) env('RABBITMQ_READ_TIMEOUT', 0),
        'write_timeout' => (int) env('RABBITMQ_WRITE_TIMEOUT', 0),

        'secure' => (bool) env('RABBITMQ_SECURE', false),
    ],

    // ==================== Connection & Channel Pool ====================
    'pool' => [
        'max_connections' => (int) env('RABBITMQ_MAX_CONNECTIONS', 10),
        'min_connections' => (int) env('RABBITMQ_MIN_CONNECTIONS', 2),
        'max_channels_per_connection' => (int) env('RABBITMQ_MAX_CHANNELS_PER_CONNECTION', 100),

        // Retry strategy for opening a connection. Delays are exponential and
        // jittered from retry_delay; every configured host gets at least one
        // attempt even when max_retries is lower than the host count.
        'max_retries' => (int) env('RABBITMQ_MAX_RETRIES', 3),
        'retry_delay' => (int) env('RABBITMQ_RETRY_DELAY', 1000), // milliseconds

        // Lazy by default: eager initialisation would open min_connections
        // sockets in every artisan one-shot and every request that never
        // publishes. Set false to pre-warm long-lived worker processes.
        'lazy' => (bool) env('RABBITMQ_LAZY_POOL', true),

        'health_check_enabled' => (bool) env('RABBITMQ_HEALTH_CHECK_ENABLED', true),
        'health_check_interval' => (int) env('RABBITMQ_HEALTH_CHECK_INTERVAL', 30), // seconds
    ],

    // ==================== Publishing ====================
    // With a non-empty exchange the driver declares the exchange, declares the
    // queue, and binds them with the routing key from exchange_routing_key.
    // Leave empty to publish through the default exchange, where every queue is
    // implicitly bound by name.
    'exchange' => env('RABBITMQ_EXCHANGE', 'tasks'),
    'exchange_type' => env('RABBITMQ_EXCHANGE_TYPE', 'direct'),
    'exchange_routing_key' => env('RABBITMQ_EXCHANGE_ROUTING_KEY', '%s'),

    'prioritize_delayed' => (bool) env('RABBITMQ_PRIORITIZE_DELAYED', false),
    'queue_max_priority' => (int) env('RABBITMQ_QUEUE_MAX_PRIORITY', 10),
    'quorum' => (bool) env('RABBITMQ_QUEUE_QUORUM', false),

    'reroute_failed' => (bool) env('RABBITMQ_REROUTE_FAILED', false),
    'failed_exchange' => env('RABBITMQ_FAILED_EXCHANGE', 'dlx'),
    'failed_routing_key' => env('RABBITMQ_FAILED_ROUTING_KEY', '%s.failed'),

    // Granularity, in milliseconds, that delayed-job TTLs are rounded up to.
    // Each distinct TTL needs its own broker-side delay queue, so bucketing
    // keeps jittered backoff values from creating an unbounded number of them.
    // Rounding up never fires a job early. Set to 1 to disable.
    'delay_queue_granularity' => (int) env('RABBITMQ_DELAY_QUEUE_GRANULARITY', 1000),

    // ==================== Failure ownership ====================
    'failed' => [
        // 'broker'   — reject without requeue, letting the queue's configured
        //              x-dead-letter-exchange own the failure (use with
        //              reroute_failed).
        // 'exchange' — additionally publish a copy to failed.exchange. Do not
        //              combine with reroute_failed, or failures are recorded
        //              twice.
        'ownership' => env('RABBITMQ_FAILED_OWNERSHIP', 'broker'),
        'exchange' => env('RABBITMQ_FAILED_MESSAGES_EXCHANGE', 'failed_messages'),
    ],

    // ==================== Exponential Backoff ====================
    // Available to application code through RabbitQueue::getBackoff(). Channel
    // replacement and connection retries use their own bounded backoff, so a
    // long job backoff can never stall a reconnect.
    'backoff' => [
        'base_delay' => (int) env('RABBITMQ_BACKOFF_BASE_DELAY', 1000), // milliseconds
        'max_delay' => (int) env('RABBITMQ_BACKOFF_MAX_DELAY', 60000), // milliseconds
        'multiplier' => (float) env('RABBITMQ_BACKOFF_MULTIPLIER', 2.0),
        'jitter' => (bool) env('RABBITMQ_BACKOFF_JITTER', true),
    ],

    // ==================== Queue Configuration ====================
    // Keys are queue names; anything omitted falls back to the connection-wide
    // settings above.
    // A key present for a queue overrides the connection-wide setting of the
    // same name, so only list what you want to differ — an explicit `false`
    // here beats a `true` above. That is why `lazy` and `quorum` are omitted
    // from the default queue: they are set connection-wide.
    'queues' => [
        'default' => [
            'durable' => (bool) env('RABBITMQ_QUEUE_DURABLE', true),
            'auto_delete' => (bool) env('RABBITMQ_QUEUE_AUTO_DELETE', false),
            'priority' => env('RABBITMQ_QUEUE_PRIORITY', null), // null or max priority (1-255)
            'arguments' => [],
            'bindings' => [],
        ],

        // Example: high priority queue with an extra topic binding.
        // Note: x-max-priority is not supported on quorum queues.
        'high-priority' => [
            'durable' => true,
            'priority' => 10,
            'bindings' => [
                [
                    'exchange' => 'tasks',
                    'exchange_type' => 'topic',
                    'routing_key' => 'urgent.*',
                ],
            ],
        ],

        // Example: high-volume queue capped with a reject-publish overflow.
        // On RabbitMQ 4.x classic queues are lazy by default and x-queue-mode
        // is a no-op.
        'bulk-processing' => [
            'durable' => true,
            'lazy' => true,
            'arguments' => [
                'x-max-length' => 100000,
                'x-overflow' => 'reject-publish',
            ],
        ],

        // Example: replicated queue. Quorum queues survive node loss but do not
        // support priorities or lazy mode.
        'payments' => [
            'durable' => true,
            'quorum' => true,
        ],

        // Example: dead-letter routing declared directly as queue arguments.
        // Queue arguments are immutable — set these before the queue first
        // exists, or delete and redeclare it.
        'orders' => [
            'durable' => true,
            'arguments' => [
                'x-dead-letter-exchange' => 'dlx',
                'x-dead-letter-routing-key' => 'orders.failed',
                'x-message-ttl' => 3600000, // 1 hour
            ],
        ],
    ],

    // ==================== Dead Letter Exchange ====================
    // Applied by RabbitQueue::setupDeadLetterExchange().
    'dead_letter' => [
        'enabled' => (bool) env('RABBITMQ_DLX_ENABLED', true),
        'exchange' => env('RABBITMQ_DLX_EXCHANGE', 'dlx'),
        'exchange_type' => env('RABBITMQ_DLX_EXCHANGE_TYPE', 'direct'),
        'queue_suffix' => env('RABBITMQ_DLX_QUEUE_SUFFIX', '.dlq'),
        'ttl' => env('RABBITMQ_DLX_TTL', null), // Retention for dead-lettered messages, in milliseconds
    ],

    // ==================== Delayed Messages ====================
    // The plugin path uses a single x-delayed-message exchange instead of one
    // delay queue per TTL — prefer it when you need many distinct delays.
    // Requires the rabbitmq_delayed_message_exchange plugin.
    'delayed_message' => [
        'exchange' => env('RABBITMQ_DELAYED_EXCHANGE', 'delayed'),
        'exchange_type' => env('RABBITMQ_DELAYED_EXCHANGE_TYPE', 'direct'),
        'plugin_enabled' => (bool) env('RABBITMQ_DELAYED_PLUGIN_ENABLED', false),
    ],

    // ==================== RPC Configuration ====================
    'rpc' => [
        'enabled' => (bool) env('RABBITMQ_RPC_ENABLED', false),
        'timeout' => (int) env('RABBITMQ_RPC_TIMEOUT', 30), // seconds

        // Leave empty to let the broker name the exclusive reply queue.
        'callback_queue_prefix' => env('RABBITMQ_RPC_CALLBACK_PREFIX', ''),
    ],

    // ==================== Publisher Confirms ====================
    'publisher_confirms' => [
        'enabled' => (bool) env('RABBITMQ_PUBLISHER_CONFIRMS_ENABLED', true),
        'timeout' => (int) env('RABBITMQ_PUBLISHER_CONFIRMS_TIMEOUT', 5), // seconds

        // Publish with AMQP_MANDATORY so the broker returns an unroutable
        // message instead of discarding it. Without this an unroutable message
        // is still ACKed and the loss is invisible.
        'mandatory' => (bool) env('RABBITMQ_PUBLISHER_CONFIRMS_MANDATORY', true),
    ],

    // ==================== Transactions ====================
    // Mutually exclusive with publisher confirms: a channel cannot be in both
    // confirm mode and transaction mode.
    'transactions' => [
        'enabled' => (bool) env('RABBITMQ_TRANSACTIONS_ENABLED', false),
    ],

    // ==================== Octane ====================
    'octane' => [
        'reset_on_request' => (bool) env('RABBITMQ_OCTANE_RESET_ON_REQUEST', false),
    ],

    // ==================== Options ====================
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
            'consume_mode' => env('RABBITMQ_CONSUME_MODE', 'poll'),

            // basic.qos governs basic.consume deliveries only, so this applies
            // to consume mode. A single-threaded worker runs one job at a time;
            // a prefetch above 1 parks the surplus behind it, where a timeout or
            // crash turns them into redeliveries.
            'qos' => [
                'prefetch_size' => (int) env('RABBITMQ_PREFETCH_SIZE', 0),
                'prefetch_count' => (int) env('RABBITMQ_PREFETCH_COUNT', 1),
            ],
        ],
    ],
];
