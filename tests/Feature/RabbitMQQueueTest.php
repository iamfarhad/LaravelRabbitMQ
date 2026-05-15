<?php

declare(strict_types=1);

use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
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
        $delay = 60;

        $jobId = Queue::later($delay, $job);

        $this->assertNotNull($jobId);
        $this->assertIsString($jobId);
    }

    public function testCanGetQueueSize(): void
    {
        $queueName = 'size-test-queue';

        Queue::pushOn($queueName, new TestJob('size-test'));

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

        $this->assertEquals('rabbitmq', $connection->getConnectionName());
    }

    public function testCanHandleJobFailuresGracefully(): void
    {
        $job = new TestJob('failing-job', false);

        $jobId = Queue::push($job);
        $this->assertNotNull($jobId);
    }

    public function testCanPurgeQueue(): void
    {
        $queueName = 'purge-test-queue';

        Queue::pushOn($queueName, new TestJob('purge-test-1'));
        Queue::pushOn($queueName, new TestJob('purge-test-2'));

        $connection = Queue::connection('rabbitmq');
        $result = $connection->purgeQueue($queueName);

        $this->assertTrue($result >= 0 || $result === null);
    }

    public function testCanDeleteQueue(): void
    {
        $queueName = 'delete-test-queue';

        Queue::pushOn($queueName, new TestJob('delete-test'));

        $connection = Queue::connection('rabbitmq');
        $result = $connection->deleteQueue($queueName);

        $this->assertTrue($result >= 0 || $result === null);
    }

    public function testChecksQueueExistence(): void
    {
        $queueName = 'existence-test-queue';
        $connection = Queue::connection('rabbitmq');

        try {
            $connection->deleteQueue($queueName);
        } catch (Exception) {
        }

        Queue::pushOn($queueName, new TestJob('existence-test'));

        $this->assertTrue($connection->queueExists($queueName));

        $connection->purgeQueue($queueName);
        $this->assertTrue($connection->queueExists($queueName));

        $connection->deleteQueue($queueName);
    }

    public function testCanPopJobFromQueue(): void
    {
        $queueName = 'pop-test-queue';
        $testPayload = 'pop-test-payload';

        Queue::pushOn($queueName, new TestJob($testPayload));

        $connection = Queue::connection('rabbitmq');
        $job = $connection->pop($queueName);

        $this->assertNotNull($job);
        $this->assertInstanceOf(RabbitMQJob::class, $job);
    }

    public function testCanPopRawNonJsonPayloadFromQueue(): void
    {
        $queueName = 'raw-payload-test-queue';
        $rawPayload = 'plain-text-message';

        $connection = Queue::connection('rabbitmq');
        $connection->pushRaw($rawPayload, $queueName);

        $job = $connection->pop($queueName);

        $this->assertNotNull($job);
        $this->assertInstanceOf(RabbitMQJob::class, $job);
        $this->assertSame($rawPayload, $job->getRawBody());
        $this->assertSame($rawPayload, $job->payload()['raw']);
        $this->assertIsArray($job->headers());
    }

    public function testReturnsNullForEmptyQueue(): void
    {
        $queueName = 'empty-test-queue';
        $connection = Queue::connection('rabbitmq');

        Queue::pushOn($queueName, new TestJob('test'));

        $job = $connection->pop($queueName);
        $this->assertNotNull($job);

        $job = $connection->pop($queueName);
        $this->assertNull($job);

        try {
            $connection->deleteQueue($queueName);
        } catch (Exception) {
        }
    }

    public function testHandlesConnectionErrorsGracefully(): void
    {
        config(['queue.connections.rabbitmq.hosts.host' => 'invalid-host']);

        $this->expectException(Exception::class);
        Queue::connection('rabbitmq')->push(new TestJob('error-test'));
    }
}
