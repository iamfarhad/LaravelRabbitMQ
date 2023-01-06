<?php

namespace iamfarhad\LaravelRabbitMQ\Tests;

use iamfarhad\LaravelRabbitMQ\Connectors\RabbitMQConnector;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use Mockery;
use PhpAmqpLib\Connection\AMQPConnectionFactory;
use DG\BypassFinals;

class RabbitMQConnectorTest extends UnitTestCase
{
    private RabbitQueue $rabbitQueueMock;

    private RabbitMQConnector $rabbitMQConnector;

    private AMQPConnectionConfig $AMQPConnectionConfigMock;

    private AMQPConnectionFactory $AMQPConnectionFactoryMock;

    private Dispatcher $dispatcherMock;


    public function setUp(): void
    {
        $this->rabbitQueueMock           = Mockery::mock(RabbitQueue::class);
        $this->AMQPConnectionFactoryMock = Mockery::mock(AMQPConnectionFactory::class);
        $this->dispatcherMock            = Mockery::mock(Dispatcher::class);

        $this->AMQPConnectionConfigMock = new AMQPConnectionConfig();

        $this->rabbitMQConnector = new RabbitMQConnector($this->dispatcherMock);

        BypassFinals::enable();

        parent::setUp();
    }


    public function testRabbitmqConnectorImplementsConnectorInterface(): void
    {
        $this->assertInstanceOf(ConnectorInterface::class, $this->rabbitMQConnector);
    }


    public function testRabbitmqConnectorConnectWithNoConfig(): void
    {
        $this->AMQPConnectionFactoryMock->shouldReceive('create')
            ->with(AMQPConnectionConfig::class)
            ->andReturn(AbstractConnection::class);

        $this->rabbitQueueMock->shouldReceive('__construct')
            ->with($this->AMQPConnectionFactoryMock, 'default')
            ->andReturn($this->rabbitQueueMock);

        $this->dispatcherMock->expects('listen')->once();

        $this->rabbitMQConnector->connect([]);
    }
}
