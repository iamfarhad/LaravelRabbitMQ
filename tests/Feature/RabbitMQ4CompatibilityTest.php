<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Feature;

use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use iamfarhad\LaravelRabbitMQ\Tests\Jobs\TestJob;
use iamfarhad\LaravelRabbitMQ\Tests\TestCase;
use Illuminate\Support\Facades\Queue;

class RabbitMQ4CompatibilityTest extends TestCase
{
    public function testCanDeclarePushAndPopQuorumQueueWithoutClassicPriorityArguments(): void
    {
        $queueName = 'rabbitmq-4-quorum-test-queue';

        config([
            'queue.connections.rabbitmq.queues.'.$queueName => [
                'quorum' => true,
                'priority' => 10,
                'arguments' => [],
            ],
        ]);

        /** @var RabbitQueue $connection */
        $connection = Queue::connection('rabbitmq');

        try {
            $connection->deleteQueue($queueName);
        } catch (\Throwable) {
        }

        Queue::pushOn($queueName, new TestJob('rabbitmq-4-quorum-payload'));

        $this->assertTrue($connection->queueExists($queueName));

        $job = $connection->pop($queueName);

        $this->assertInstanceOf(RabbitMQJob::class, $job);
        $job->delete();

        $connection->deleteQueue($queueName);
    }

    public function testCanPublishWithPublisherConfirmsEnabled(): void
    {
        $queueName = 'rabbitmq-4-confirms-test-queue';

        config([
            'queue.connections.rabbitmq.publisher_confirms.enabled' => true,
            'queue.connections.rabbitmq.publisher_confirms.timeout' => 5,
        ]);

        /** @var RabbitQueue $connection */
        $connection = Queue::connection('rabbitmq');

        try {
            $connection->deleteQueue($queueName);
        } catch (\Throwable) {
        }

        $jobId = Queue::pushOn($queueName, new TestJob('publisher-confirms-payload'));

        $this->assertNotNull($jobId);
        $this->assertIsString($jobId);
        $this->assertTrue($connection->queueExists($queueName));

        $connection->purgeQueue($queueName);
        $connection->deleteQueue($queueName);
    }
}
