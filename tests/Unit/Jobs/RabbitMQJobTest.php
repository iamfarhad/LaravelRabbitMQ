<?php

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Jobs;

use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use Illuminate\Container\Container;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;

class RabbitMQJobTest extends TestCase
{
    private Container $container;
    private RabbitQueue|\PHPUnit\Framework\MockObject\MockObject $rabbitQueue;
    private AMQPMessage $amqpMessage;
    private string $connectionName = 'test-connection';
    private string $queue = 'test-queue';

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();
        $this->rabbitQueue = $this->createMock(RabbitQueue::class);
        $this->amqpMessage = new AMQPMessage('{"id":"test-id","job":"test-job","data":"test-data"}');
    }

    public function testGetJobId(): void
    {
        $rabbitMQJob = new RabbitMQJob(
            $this->container,
            $this->rabbitQueue,
            $this->amqpMessage,
            $this->connectionName,
            $this->queue
        );

        $this->assertEquals('test-id', $rabbitMQJob->getJobId());
    }

    public function testGetRawBody(): void
    {
        $rabbitMQJob = new RabbitMQJob(
            $this->container,
            $this->rabbitQueue,
            $this->amqpMessage,
            $this->connectionName,
            $this->queue
        );

        $this->assertEquals('{"id":"test-id","job":"test-job","data":"test-data"}', $rabbitMQJob->getRawBody());
    }
}
