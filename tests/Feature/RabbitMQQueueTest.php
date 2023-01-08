<?php

namespace iamfarhad\LaravelRabbitMQ\Tests\Feature;

use iamfarhad\LaravelRabbitMQ\Tests\FeatureTestCase;
use iamfarhad\LaravelRabbitMQ\Tests\Mocks\TestJobMock;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

class RabbitMQQueueTest extends FeatureTestCase
{
    private $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $getQueueInstance = $this->app['queue'];
        $this->connection = $getQueueInstance->connection('rabbitmq');

        $this->connection->purgeQueue('test_size');
        $this->connection->purgeQueue('queue_test');
    }

    public function testRabbitMQSize(): void
    {
        $queue = 'test_size';

        $this->assertTrue($this->connection->getConnection()->isConnected());
        $this->assertTrue($this->connection->getConnection()->channel()->is_open());

        dispatch(new TestJobMock('Farhad Zand'))->onQueue($queue);
        $this->assertEquals(1, $this->connection->size($queue));
    }

    public function testRabbitMQDeclareQueue(): void
    {
        $queue = 'queue_test';
        $this->connection->declareQueue($queue);

        $this->assertTrue($this->connection->queueExists($queue));

        $this->connection->deleteQueue($queue);
    }

    public function testRabbitMQExistsQueue(): void
    {
        $queue = 'queue_test';
        $this->connection->declareQueue($queue);

        $this->assertTrue($this->connection->queueExists($queue));
        $this->connection->deleteQueue($queue);

        $this->assertFalse($this->connection->queueExists($queue));
    }
}
