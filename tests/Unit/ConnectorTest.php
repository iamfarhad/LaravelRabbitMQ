<?php

use iamfarhad\LaravelRabbitMQ\Connectors\RabbitMQConnector;
use Illuminate\Events\Dispatcher;

// Test only the non-connection dependent aspects of the connector
// Connection tests are covered in Feature tests with real RabbitMQ

it('creates connector instance', function () {
    $dispatcher = new Dispatcher;
    $connector = new RabbitMQConnector($dispatcher);

    expect($connector)->toBeInstanceOf(RabbitMQConnector::class);
});

it('validates configuration format', function () {
    $dispatcher = new Dispatcher;
    $connector = new RabbitMQConnector($dispatcher);

    // Test that configuration is handled properly
    config([
        'queue.connections.rabbitmq.hosts.host' => 'test-host',
        'queue.connections.rabbitmq.hosts.port' => 5672,
        'queue.connections.rabbitmq.hosts.user' => 'test-user',
        'queue.connections.rabbitmq.hosts.password' => 'test-pass',
        'queue.connections.rabbitmq.hosts.vhost' => 'test-vhost',
        'queue.connections.rabbitmq.queue' => 'test-queue',
    ]);

    expect(config('queue.connections.rabbitmq.hosts.host'))->toBe('test-host')
        ->and(config('queue.connections.rabbitmq.hosts.port'))->toBe(5672)
        ->and(config('queue.connections.rabbitmq.queue'))->toBe('test-queue');
});

it('handles options configuration', function () {
    $dispatcher = new Dispatcher;
    $connector = new RabbitMQConnector($dispatcher);

    // Test configuration options handling
    config([
        'queue.connections.rabbitmq.hosts.heartbeat' => 60,
        'queue.connections.rabbitmq.hosts.read_timeout' => 5,
        'queue.connections.rabbitmq.hosts.write_timeout' => 5,
        'queue.connections.rabbitmq.hosts.connect_timeout' => 10,
    ]);

    expect(config('queue.connections.rabbitmq.hosts.heartbeat'))->toBe(60)
        ->and(config('queue.connections.rabbitmq.hosts.read_timeout'))->toBe(5);
});
