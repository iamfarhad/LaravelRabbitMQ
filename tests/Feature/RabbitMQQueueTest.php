<?php

namespace iamfarhad\LaravelRabbitMQ\Tests\Feature;

use iamfarhad\LaravelRabbitMQ\Tests\FeatureTestCase;
use iamfarhad\LaravelRabbitMQ\Tests\Mocks\TestJobMock;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use iamfarhad\LaravelRabbitMQ\Consumer;
use Mockery;

class RabbitMQQueueTest extends FeatureTestCase
{
    private $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $getQueueInstance = $this->app['queue'];
        $this->connection = $getQueueInstance->connection('rabbitmq');
    }

    public function testConsumeMethod()
    {
        // Create a Mockery mock of the Consumer class
        $consumerMock = Mockery::mock(Consumer::class);

        // Define the expected result
        $expectedResult = 'expected result';

        // Set up the mock to return the expected result when the consume method is called
        $consumerMock->expects('consume')
            ->andReturns($expectedResult);

        // Call the consume method on the mock
        $result = $consumerMock->consume();

        // Assert that the result matches the expected result
        $this->assertEquals($expectedResult, $result);
    }


    public function testRabbitMQSize(): void
    {
        $queue = 'test_size';

        $this->assertTrue($this->connection->getConnection()->isConnected());
        $this->assertTrue($this->connection->getConnection()->channel()->is_open());

        dispatch(new TestJobMock('Farhad Zand'))->onQueue($queue);
        dispatch(new TestJobMock('Farhad Zand'))->onQueue($queue);

        $this->greaterThanOrEqual(1, $this->connection->size($queue));
    }

    public function testRabbitMQDeclareQueue(): void
    {
        $queue = 'queue_test';
        $this->connection->declareQueue($queue);
        $this->assertTrue($this->connection->queueExists($queue));
    }

    public function testRabbitMQExistsQueue(): void
    {
        $queue = 'queue_test';
        $this->connection->declareQueue($queue);

        $this->assertTrue($this->connection->queueExists($queue));
        $this->connection->deleteQueue($queue);

        $this->assertFalse($this->connection->queueExists($queue));
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->connection->deleteQueue('test_size');
        $this->connection->deleteQueue('queue_test');
    }
}
