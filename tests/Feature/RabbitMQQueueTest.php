<?php

use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use iamfarhad\LaravelRabbitMQ\Tests\Jobs\TestJob;
use Illuminate\Support\Facades\Queue;

it('can connect to rabbitmq', function () {
    $connection = Queue::connection('rabbitmq');
    expect($connection)->toBeInstanceOf(RabbitQueue::class);
});

it('can push job to default queue', function () {
    $job = new TestJob('test-payload');

    $jobId = Queue::push($job);

    expect($jobId)->not->toBeNull()->toBeString();
});

it('can push job to specific queue', function () {
    $job = new TestJob('test-payload');
    $queueName = 'test-queue';

    $jobId = Queue::pushOn($queueName, $job);

    expect($jobId)->not->toBeNull()->toBeString();
});

it('can push delayed job', function () {
    $job = new TestJob('delayed-payload');
    $delay = 60; // 60 seconds

    $jobId = Queue::later($delay, $job);

    expect($jobId)->not->toBeNull()->toBeString();
});

it('can get queue size', function () {
    $queueName = 'size-test-queue';

    // Push a job to the queue
    Queue::pushOn($queueName, new TestJob('size-test'));

    // Get queue size
    $size = Queue::size($queueName);

    expect($size)->toBeInt()->toBeGreaterThanOrEqual(0);
});

it('can push bulk jobs', function () {
    $jobs = [
        new TestJob('bulk-job-1'),
        new TestJob('bulk-job-2'),
        new TestJob('bulk-job-3'),
    ];

    foreach ($jobs as $job) {
        $jobId = Queue::push($job);
        expect($jobId)->not->toBeNull();
    }
});

it('respects queue configuration', function () {
    $connection = Queue::connection('rabbitmq');

    // Test that the connection uses the correct configuration
    expect($connection->getConnectionName())->toBe('rabbitmq');
});

it('can handle job failures gracefully', function () {
    $job = new TestJob('failing-job', true); // Job that will fail

    expect(fn () => Queue::push($job))->not->toThrow(\Exception::class);
});

it('can purge queue', function () {
    $queueName = 'purge-test-queue';

    // Push some jobs
    Queue::pushOn($queueName, new TestJob('purge-test-1'));
    Queue::pushOn($queueName, new TestJob('purge-test-2'));

    $connection = Queue::connection('rabbitmq');
    $result = $connection->purgeQueue($queueName);

    // Purge should succeed (returns number of purged messages or null)
    expect($result >= 0 || $result === null)->toBeTrue();
});

it('can delete queue', function () {
    $queueName = 'delete-test-queue';

    // Create queue by pushing a job
    Queue::pushOn($queueName, new TestJob('delete-test'));

    $connection = Queue::connection('rabbitmq');
    $result = $connection->deleteQueue($queueName);

    // Delete should succeed
    expect($result >= 0 || $result === null)->toBeTrue();
});

it('checks queue existence', function () {
    $queueName = 'existence-test-queue-'.uniqid();
    $connection = Queue::connection('rabbitmq');

    // Queue should not exist initially
    expect($connection->queueExists($queueName))->toBeFalse();

    // Create queue by pushing a job
    Queue::pushOn($queueName, new TestJob('existence-test'));

    // Now queue should exist
    expect($connection->queueExists($queueName))->toBeTrue();

    // Clean up
    $connection->deleteQueue($queueName);
});

it('can pop job from queue', function () {
    $queueName = 'pop-test-queue';
    $testPayload = 'pop-test-payload';

    // Push a job
    Queue::pushOn($queueName, new TestJob($testPayload));

    $connection = Queue::connection('rabbitmq');
    $job = $connection->pop($queueName);

    expect($job)
        ->not->toBeNull()
        ->toBeInstanceOf(\iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob::class);
});

it('returns null for empty queue', function () {
    $queueName = 'empty-test-queue-'.uniqid();

    $connection = Queue::connection('rabbitmq');

    // Try to pop from a non-existent queue should return null
    $job = $connection->pop($queueName);

    expect($job)->toBeNull();
});

it('handles connection errors gracefully', function () {
    // Test with invalid configuration
    config(['queue.connections.rabbitmq.hosts.host' => 'invalid-host']);

    expect(fn () => Queue::connection('rabbitmq')->push(new TestJob('error-test')))
        ->toThrow(\Exception::class);
});
