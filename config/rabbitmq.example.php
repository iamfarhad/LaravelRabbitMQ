<?php

/**
 * Laravel RabbitMQ - Advanced Configuration Example
 *
 * This file demonstrates all available configuration options
 * for the Laravel RabbitMQ package with advanced features.
 */

return [
    'driver' => 'rabbitmq',
    'queue' => env('RABBITMQ_QUEUE', 'default'),

    // ==================== Connection Settings ====================
    'hosts' => [
        'host' => env('RABBITMQ_HOST', '127.0.0.1'),
        'port' => env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
        'lazy' => env('RABBITMQ_LAZY_CONNECTION', true),
        'keepalive' => env('RABBITMQ_KEEPALIVE_CONNECTION', false),
        'heartbeat' => env('RABBITMQ_HEARTBEAT_CONNECTION', 0),
        'secure' => env('RABBITMQ_SECURE', false),
    ],

    // ==================== Connection & Channel Pool ====================
    'pool' => [
        // Connection Pool Settings
        'max_connections' => env('RABBITMQ_MAX_CONNECTIONS', 10),
        'min_connections' => env('RABBITMQ_MIN_CONNECTIONS', 2),

        // Channel Pool Settings
        'max_channels_per_connection' => env('RABBITMQ_MAX_CHANNELS_PER_CONNECTION', 100),

        // Retry Strategy
        'max_retries' => env('RABBITMQ_MAX_RETRIES', 3),
        'retry_delay' => env('RABBITMQ_RETRY_DELAY', 1000), // milliseconds

        // Health Check Settings
        'health_check_enabled' => env('RABBITMQ_HEALTH_CHECK_ENABLED', true),
        'health_check_interval' => env('RABBITMQ_HEALTH_CHECK_INTERVAL', 30), // seconds
    ],

    // ==================== Exponential Backoff ====================
    'backoff' => [
        'enabled' => env('RABBITMQ_BACKOFF_ENABLED', true),
        'base_delay' => env('RABBITMQ_BACKOFF_BASE_DELAY', 1000), // milliseconds
        'max_delay' => env('RABBITMQ_BACKOFF_MAX_DELAY', 60000), // milliseconds
        'multiplier' => env('RABBITMQ_BACKOFF_MULTIPLIER', 2.0),
        'jitter' => env('RABBITMQ_BACKOFF_JITTER', true),
    ],

    // ==================== Exchange Configuration ====================
    'exchanges' => [
        'default' => [
            'name' => env('RABBITMQ_EXCHANGE', ''),
            'type' => env('RABBITMQ_EXCHANGE_TYPE', 'direct'), // direct, fanout, topic, headers
            'durable' => env('RABBITMQ_EXCHANGE_DURABLE', true),
            'auto_delete' => env('RABBITMQ_EXCHANGE_AUTO_DELETE', false),
            'arguments' => [],
        ],

        // Example: Topic Exchange for Notifications
        'notifications' => [
            'name' => 'notifications',
            'type' => 'topic',
            'durable' => true,
            'auto_delete' => false,
            'arguments' => [],
        ],

        // Example: Fanout Exchange for Broadcasting
        'broadcasts' => [
            'name' => 'broadcasts',
            'type' => 'fanout',
            'durable' => true,
            'auto_delete' => false,
        ],

        // Example: Headers Exchange
        'documents' => [
            'name' => 'documents',
            'type' => 'headers',
            'durable' => true,
            'auto_delete' => false,
        ],
    ],

    // ==================== Queue Configuration ====================
    'queues' => [
        'default' => [
            'name' => env('RABBITMQ_QUEUE', 'default'),
            'durable' => env('RABBITMQ_QUEUE_DURABLE', true),
            'auto_delete' => env('RABBITMQ_QUEUE_AUTO_DELETE', false),
            'exclusive' => env('RABBITMQ_QUEUE_EXCLUSIVE', false),
            'lazy' => env('RABBITMQ_QUEUE_LAZY', false),
            'priority' => env('RABBITMQ_QUEUE_PRIORITY', null), // null or max priority (1-255)
            'arguments' => [],
            'bindings' => [],
        ],

        // Example: High Priority Queue
        'high-priority' => [
            'name' => 'high-priority',
            'durable' => true,
            'auto_delete' => false,
            'exclusive' => false,
            'lazy' => false,
            'priority' => 10,
            'arguments' => [],
            'bindings' => [
                [
                    'exchange' => 'tasks',
                    'routing_key' => 'urgent.*',
                ],
            ],
        ],

        // Example: Lazy Queue for High Volume
        'bulk-processing' => [
            'name' => 'bulk-processing',
            'durable' => true,
            'auto_delete' => false,
            'exclusive' => false,
            'lazy' => true, // Messages stored on disk
            'priority' => null,
            'arguments' => [
                'x-max-length' => 100000,
                'x-overflow' => 'reject-publish',
            ],
        ],

        // Example: Queue with Dead Letter Exchange
        'orders' => [
            'name' => 'orders',
            'durable' => true,
            'auto_delete' => false,
            'exclusive' => false,
            'lazy' => false,
            'priority' => 5,
            'arguments' => [
                'x-dead-letter-exchange' => 'dlx',
                'x-dead-letter-routing-key' => 'orders.failed',
                'x-message-ttl' => 3600000, // 1 hour
            ],
        ],
    ],

    // ==================== Dead Letter Exchange ====================
    'dead_letter' => [
        'enabled' => env('RABBITMQ_DLX_ENABLED', true),
        'exchange' => env('RABBITMQ_DLX_EXCHANGE', 'dlx'),
        'exchange_type' => env('RABBITMQ_DLX_EXCHANGE_TYPE', 'direct'),
        'queue_suffix' => env('RABBITMQ_DLX_QUEUE_SUFFIX', '.dlq'),
        'ttl' => env('RABBITMQ_DLX_TTL', null), // Message TTL in milliseconds
    ],

    // ==================== Delayed Messages ====================
    'delayed_message' => [
        'enabled' => env('RABBITMQ_DELAYED_MESSAGE_ENABLED', false),
        'exchange' => env('RABBITMQ_DELAYED_EXCHANGE', 'delayed'),
        'plugin_enabled' => env('RABBITMQ_DELAYED_PLUGIN_ENABLED', false), // rabbitmq_delayed_message_exchange plugin
    ],

    // ==================== RPC Configuration ====================
    'rpc' => [
        'enabled' => env('RABBITMQ_RPC_ENABLED', false),
        'timeout' => env('RABBITMQ_RPC_TIMEOUT', 30), // seconds
        'callback_queue_prefix' => env('RABBITMQ_RPC_CALLBACK_PREFIX', 'rpc_callback_'),
    ],

    // ==================== Publisher Confirms ====================
    'publisher_confirms' => [
        'enabled' => env('RABBITMQ_PUBLISHER_CONFIRMS_ENABLED', false),
        'timeout' => env('RABBITMQ_PUBLISHER_CONFIRMS_TIMEOUT', 5), // seconds
    ],

    // ==================== Transactions ====================
    'transactions' => [
        'enabled' => env('RABBITMQ_TRANSACTIONS_ENABLED', false),
    ],

    // ==================== Options ====================
    'options' => [
        'ssl_options' => [
            'cafile' => env('RABBITMQ_SSL_CAFILE', null),
            'local_cert' => env('RABBITMQ_SSL_LOCALCERT', null),
            'local_key' => env('RABBITMQ_SSL_LOCALKEY', null),
            'verify_peer' => env('RABBITMQ_SSL_VERIFY_PEER', true),
            'passphrase' => env('RABBITMQ_SSL_PASSPHRASE', null),
        ],
        'queue' => [
            'job' => \iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob::class,
            'qos' => [
                'prefetch_size' => 0,
                'prefetch_count' => 10,
                'global' => false,
            ],
        ],
    ],
];
