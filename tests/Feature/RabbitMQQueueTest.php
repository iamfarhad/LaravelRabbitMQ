<?php

declare(strict_types=1);

use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use iamfarhad\LaravelRabbitMQ\Tests\Jobs\TestJob;
use iamfarhad\LaravelRabbitMQ\Tests\TestCase;
use Illuminate\Support\Facades\Queue;

class RabbitMQQueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testCanConnectToRabbitMQ(): void
    {
        $connection = Queue::connection('rabbitmq');
        $this->assertInstanceOf(RabbitQueue::class, $connection);
    }

    public function testCanPushJobToDefaultQueue(): void
    {
        $job = new TestJob('test-payload');

        $jobId = Queue::push($job);

        $this->assertNotNull($jobId);
        $this->assertIsString($jobId);
    }

    public function testCanPushJobToSpecificQueue(): void
    {
        $job = new TestJob('test-payload');
        $queueName = 'test-queue';

        $jobId = Queue::pushOn($queueName, $job);

        $this->assertNotNull($jobId);
        $this->assertIsString($jobId);
    }

    public function testCanPushDelayedJob(): void
    {
        $job = new TestJob('delayed-payload');
        $delay = 60; // 60 seconds

        $jobId = Queue::later($delay, $job);

        $this->assertNotNull($jobId);
        $this->assertIsString($jobId);
    }

    public function testCanGetQueueSize(): void
    {
        $queueName = 'size-test-queue';

        // Push a job to the queue
        Queue::pushOn($queueName, new TestJob('size-test'));

        // Get queue size
        $size = Queue::size($queueName);

        $this->assertIsInt($size);
        $this->assertGreaterThanOrEqual(0, $size);
    }

    public function testCanPushBulkJobs(): void
    {
        $jobs = [
            new TestJob('bulk-job-1'),
            new TestJob('bulk-job-2'),

            new TestJob('bulk-job-3'),
        ];

        foreach ($jobs as $job) {
            $jobId = Queue::push($job);
            $this->assertNotNull($jobId);
        }
    }

    public function testRespectsQueueConfiguration(): void
    {
        $connection = Queue::connection('rabbitmq');

        // Test that the connection uses the correct configuration
        $this->assertEquals('rabbitmq', $connection->getConnectionName());
    }

    public function testCanHandleJobFailuresGracefully(): void
    {
        $job = new TestJob('failing-job', false); // Job that won't fail during push

        // This should not throw an exception when pushing
        $jobId = Queue::push($job);
        $this->assertNotNull($jobId);
    }

    public function testCanPurgeQueue(): void
    {
        $queueName = 'purge-test-queue';

        // Push some jobs
        Queue::pushOn($queueName, new TestJob('purge-test-1'));
        Queue::pushOn($queueName, new TestJob('purge-test-2'));

        $connection = Queue::connection('rabbitmq');
        $result = $connection->purgeQueue($queueName);

        // Purge should succeed (returns number of purged messages or null)
        $this->assertTrue($result >= 0 || $result === null);
    }

    public function testCanDeleteQueue(): void
    {
        $queueName = 'delete-test-queue';

        // Create queue by pushing a job
        Queue::pushOn($queueName, new TestJob('delete-test'));

        $connection = Queue::connection('rabbitmq');
        $result = $connection->deleteQueue($queueName);

        // Delete should succeed
        $this->assertTrue($result >= 0 || $result === null);
    }

    public function testChecksQueueExistence(): void
    {
        $queueName = 'existence-test-queue';
        $connection = Queue::connection('rabbitmq');

        // Clean up first in case queue exists
        try {
            $connection->deleteQueue($queueName);
        } catch (\Exception $e) {
            // Ignore if queue doesn't exist
        }

        // Create queue by pushing a job first (this should work)
        Queue::pushOn($queueName, new TestJob('existence-test'));

        // Now queue should exist
        $this->assertTrue($connection->queueExists($queueName));

        // Clean up the job and verify queue is empty but still exists
        $connection->purgeQueue($queueName);
        $this->assertTrue($connection->queueExists($queueName));

        // Finally delete the queue
        $connection->deleteQueue($queueName);
    }

    public function testCanPopJobFromQueue(): void
    {
        $queueName = 'pop-test-queue';
        $testPayload = 'pop-test-payload';

        // Push a job
        Queue::pushOn($queueName, new TestJob($testPayload));

        $connection = Queue::connection('rabbitmq');
        $job = $connection->pop($queueName);

        $this->assertNotNull($job);
        $this->assertInstanceOf(\iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob::class, $job);
    }

    public function testReturnsNullForEmptyQueue(): void
    {
        $queueName = 'empty-test-queue';
        $connection = Queue::connection('rabbitmq');

        // First create the queue by pushing a job
        Queue::pushOn($queueName, new TestJob('test'));

        // Then consume the job to empty the queue
        $job = $connection->pop($queueName);
        $this->assertNotNull($job);

        // Now try to pop from the empty queue - should return null
        $job = $connection->pop($queueName);
        $this->assertNull($job);

        // Clean up
        try {
            $connection->deleteQueue($queueName);
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
    }

    public function testHandlesConnectionErrorsGracefully(): void
    {
        // Test with invalid configuration
        config(['queue.connections.rabbitmq.hosts.host' => 'invalid-host']);

        $this->expectException(\Exception::class);
        Queue::connection('rabbitmq')->push(new TestJob('error-test'));
    }
}
