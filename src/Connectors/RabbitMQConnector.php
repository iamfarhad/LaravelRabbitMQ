<?php

namespace iamfarhad\LaravelRabbitMQ\Connectors;

use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Events\WorkerStopping;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;

class RabbitMQConnector implements ConnectorInterface
{
    private readonly Dispatcher $dispatcher;

    public function __construct(Dispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function connect(array $config = []): Queue
    {
        $configs = new AMQPConnectionConfig();
        // @todo move to config
        $configs->setHost('eagle-rmq');

        $connection = AMQPConnectionFactory::create($configs);

        $queueConnection = new RabbitQueue($connection, 'default');

        $this->dispatcher->listen(WorkerStopping::class, static function () use ($queueConnection): void {
            $queueConnection->close();
        });

        return $queueConnection;
    }
}
