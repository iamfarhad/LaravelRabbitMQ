<?php

namespace iamfarhad\LaravelRabbitMQ\Connectors;

use AMQPConnection;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Events\WorkerStopping;

readonly class RabbitMQConnector implements ConnectorInterface
{
    public function __construct(private Dispatcher $dispatcher) {}

    public function connect(array $config = []): Queue
    {
        $connectionConfig = [
            'host' => config('queue.connections.rabbitmq.hosts.host', '127.0.0.1'),
            'port' => config('queue.connections.rabbitmq.hosts.port', 5672),
            'login' => config('queue.connections.rabbitmq.hosts.user', 'guest'),
            'password' => config('queue.connections.rabbitmq.hosts.password', 'guest'),
            'vhost' => config('queue.connections.rabbitmq.hosts.vhost', '/'),
        ];

        // Add optional connection parameters
        if (config('queue.connections.rabbitmq.hosts.heartbeat')) {
            $connectionConfig['heartbeat'] = config('queue.connections.rabbitmq.hosts.heartbeat');
        }

        if (config('queue.connections.rabbitmq.hosts.read_timeout')) {
            $connectionConfig['read_timeout'] = config('queue.connections.rabbitmq.hosts.read_timeout');
        }

        if (config('queue.connections.rabbitmq.hosts.write_timeout')) {
            $connectionConfig['write_timeout'] = config('queue.connections.rabbitmq.hosts.write_timeout');
        }

        if (config('queue.connections.rabbitmq.hosts.connect_timeout')) {
            $connectionConfig['connect_timeout'] = config('queue.connections.rabbitmq.hosts.connect_timeout');
        }

        // Create AMQP Connection
        $connection = new AMQPConnection($connectionConfig);
        $connection->connect();

        $defaultQueue = config('queue.connections.rabbitmq.queue', 'default');
        $options = config('queue.connections.rabbitmq.options', []);

        $rabbitQueue = new RabbitQueue($connection, $defaultQueue, $options);

        $this->dispatcher->listen(WorkerStopping::class, fn () => $rabbitQueue->close());

        return $rabbitQueue;
    }
}
