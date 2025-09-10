<?php

// Test only basic job structure without AMQP connections
// Connection-dependent tests are covered in Feature tests

use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;

// RabbitMQJob tests require real AMQPEnvelope objects which need connections
// All job functionality is thoroughly tested in Feature tests with real RabbitMQ

it('validates job class exists', function () {
    expect(class_exists(RabbitMQJob::class))->toBeTrue();
});
