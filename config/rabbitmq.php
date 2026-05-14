<?php

use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;

return [
    'driver' => 'rabbitmq',
    'queue' => env('RABBITMQ_QUEUE', 'default'),
    'after_commit' => env('RABBITMQ_AFTER_COMMIT', false),

    // Set to "horizon" to enable optional Horizon event integration when Laravel Horizon is installed.
    'worker' => env('RABBITMQ_WORKER', 'default'),

    // Backward compatible single host config. You may also replace this with a list of hosts:
    // 'hosts' => [
    //     ['host' => 'rabbitmq-1', 'port' => 5672, 'user' => 'guest', 'password' => 'guest', 'vhost' => '/'],
    //     ['host' => 'rabbitmq-2', 'port' => 5672, 'user' => 'guest', 'password' => 'guest', 'vhost' => '/'],
    // ],
    'hosts' => [
        'host' => env('RABBITMQ_HOST', '127.0.0.1'),
        'port' => env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
        'lazy' => env('RABBITMQ_LAZY_CONNECTION', true),
        'keepalive' => env('RABBITMQ_KEEPALIVE_CONNECTION', false),
        'heartbeat' => env('RABBITMQ_HEARTBEAT_CONNECTION', 0),
        'read_timeout' => env('RABBITMQ_READ_TIMEOUT', 0),
        'write_timeout' => env('RABBITMQ_WRITE_TIMEOUT', 0),
        'connect_timeout' => env('RABBITMQ_CONNECT_TIMEOUT', 0),
        'secure' => env('RABBITMQ_SECURE', false),
    ],

    // Publishing Configuration for normal Laravel queue jobs.
    'exchange' => env('RABBITMQ_EXCHANGE', ''),
    'exchange_type' => env('RABBITMQ_EXCHANGE_TYPE', 'direct'),
    'exchange_routing_key' => env('RABBITMQ_EXCHANGE_ROUTING_KEY', '%s'),
    'prioritize_delayed' => env('RABBITMQ_PRIORITIZE_DELAYED', false),
    'queue_max_priority' => env('RABBITMQ_QUEUE_MAX_PRIORITY', 10),
    'quorum' => env('RABBITMQ_QUEUE_QUORUM', false),
    'reroute_failed' => env('RABBITMQ_REROUTE_FAILED', false),
    'failed_exchange' => env('RABBITMQ_FAILED_EXCHANGE', ''),
    'failed_routing_key' => env('RABBITMQ_FAILED_ROUTING_KEY', '%s.failed'),

    // Connection and Channel Pool Configuration
    'pool' => [
        'max_connections' => env('RABBITMQ_MAX_CONNECTIONS', 10),
        'min_connections' => env('RABBITMQ_MIN_CONNECTIONS', 2),
        'max_channels_per_connection' => env('RABBITMQ_MAX_CHANNELS_PER_CONNECTION', 100),
        'max_retries' => env('RABBITMQ_MAX_RETRIES', 3),
        'retry_delay' => env('RABBITMQ_RETRY_DELAY', 1000), // milliseconds
        'health_check_enabled' => env('RABBITMQ_HEALTH_CHECK_ENABLED', true),
        'health_check_interval' => env('RABBITMQ_HEALTH_CHECK_INTERVAL', 30), // seconds
    ],

    // Octane Integration
    'octane' => [
        // Keep false by default for performance. Enable when each request should start with fresh AMQP pools.
        'reset_on_request' => env('RABBITMQ_OCTANE_RESET_ON_REQUEST', false),
    ],

    // Exponential Backoff Configuration
    'backoff' => [
        'enabled' => env('RABBITMQ_BACKOFF_ENABLED', true),
        'base_delay' => env('RABBITMQ_BACKOFF_BASE_DELAY', 1000), // milliseconds
        'max_delay' => env('RABBITMQ_BACKOFF_MAX_DELAY', 60000), // milliseconds
        'multiplier' => env('RABBITMQ_BACKOFF_MULTIPLIER', 2.0),
        'jitter' => env('RABBITMQ_BACKOFF_JITTER', true),
    ],

    // Exchange Configuration
    'exchanges' => [
        'default' => [
            'name' => env('RABBITMQ_EXCHANGE', ''),
            'type' => env('RABBITMQ_EXCHANGE_TYPE', 'direct'), // direct, fanout, topic, headers
            'durable' => env('RABBITMQ_EXCHANGE_DURABLE', true),
            'auto_delete' => env('RABBITMQ_EXCHANGE_AUTO_DELETE', false),
            'arguments' => [],
        ],
    ],

    // Queue Configuration
    'queues' => [
        'default' => [
            'name' => env('RABBITMQ_QUEUE', 'default'),
            'durable' => env('RABBITMQ_QUEUE_DURABLE', true),
            'auto_delete' => env('RABBITMQ_QUEUE_AUTO_DELETE', false),
            'exclusive' => env('RABBITMQ_QUEUE_EXCLUSIVE', false),
            'lazy' => env('RABBITMQ_QUEUE_LAZY', false),
            'priority' => env('RABBITMQ_QUEUE_PRIORITY', null), // null or max priority (1-255)
            'quorum' => env('RABBITMQ_QUEUE_QUORUM', false),
            'arguments' => [],
            'bindings' => [
                [
                    'exchange' => 'default',
                    'routing_key' => '',
                ],
            ],
        ],
    ],

    // Dead Letter Exchange Configuration
    'dead_letter' => [
        'enabled' => env('RABBITMQ_DLX_ENABLED', true),
        'exchange' => env('RABBITMQ_DLX_EXCHANGE', 'dlx'),
        'exchange_type' => env('RABBITMQ_DLX_EXCHANGE_TYPE', 'direct'),
        'queue_suffix' => env('RABBITMQ_DLX_QUEUE_SUFFIX', '.dlq'),
        'ttl' => env('RABBITMQ_DLX_TTL', null), // Message TTL in milliseconds
    ],

    // Delayed Message Configuration
    'delayed_message' => [
        'enabled' => env('RABBITMQ_DELAYED_MESSAGE_ENABLED', false),
        'exchange' => env('RABBITMQ_DELAYED_EXCHANGE', 'delayed'),
        'plugin_enabled' => env('RABBITMQ_DELAYED_PLUGIN_ENABLED', false),
    ],

    // RPC Configuration
    'rpc' => [
        'enabled' => env('RABBITMQ_RPC_ENABLED', false),
        'timeout' => env('RABBITMQ_RPC_TIMEOUT', 30), // seconds
        'callback_queue_prefix' => env('RABBITMQ_RPC_CALLBACK_PREFIX', 'rpc_callback_'),
    ],

    // Publisher Confirms Configuration
    'publisher_confirms' => [
        'enabled' => env('RABBITMQ_PUBLISHER_CONFIRMS_ENABLED', false),
        'timeout' => env('RABBITMQ_PUBLISHER_CONFIRMS_TIMEOUT', 5), // seconds
    ],

    // Transaction Configuration
    'transactions' => [
        'enabled' => env('RABBITMQ_TRANSACTIONS_ENABLED', false),
    ],

    'options' => [
        'read_timeout' => env('RABBITMQ_READ_TIMEOUT', 0),
        'write_timeout' => env('RABBITMQ_WRITE_TIMEOUT', 0),
        'connect_timeout' => env('RABBITMQ_CONNECT_TIMEOUT', 0),
        'ssl_options' => [
            'cafile' => env('RABBITMQ_SSL_CAFILE', null),
            'local_cert' => env('RABBITMQ_SSL_LOCALCERT', null),
            'local_key' => env('RABBITMQ_SSL_LOCALKEY', null),
            'verify_peer' => env('RABBITMQ_SSL_VERIFY_PEER', true),
            'passphrase' => env('RABBITMQ_SSL_PASSPHRASE', null),
        ],
        'queue' => [
            'job' => RabbitMQJob::class,
            // rabbitmq:consume supports "poll" (basic_get) and "consume" (basic_consume) modes.
            'consume_mode' => env('RABBITMQ_CONSUME_MODE', 'poll'),
            'qos' => [
                'prefetch_size' => 0,
                'prefetch_count' => 10,
                'global' => false,
            ],
        ],
    ],
];
