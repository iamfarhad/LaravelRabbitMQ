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

        // set AMQP account
        $configs->setHost(config('queue.connections.rabbitmq.hosts.host'));
        $configs->setPort(config('queue.connections.rabbitmq.hosts.port'));
        $configs->setUser(config('queue.connections.rabbitmq.hosts.user'));
        $configs->setPassword(config('queue.connections.rabbitmq.hosts.password'));
        $configs->setVhost(config('queue.connections.rabbitmq.hosts.vhost'));

        $configs->setIsLazy(config('queue.connections.rabbitmq.hosts.lazy'));
        $configs->setKeepalive(config('queue.connections.rabbitmq.hosts.keepalive'));
        $configs->setHeartbeat(config('queue.connections.rabbitmq.hosts.heartbeat'));


        // set SSL Options
        $configs->setSslCaCert(config('queue.connections.rabbitmq.options.ssl_options.cafile'));
        $configs->setSslCert(config('queue.connections.rabbitmq.options.ssl_options.local_cert'));
        $configs->setSslKey(config('queue.connections.rabbitmq.options.ssl_options.local_key'));
        $configs->setSslVerify(config('queue.connections.rabbitmq.options.ssl_options.verify_peer'));
        $configs->setSslPassPhrase(config('queue.connections.rabbitmq.options.ssl_options.passphrase'));

        // Create AMQP Connection
        $connection = AMQPConnectionFactory::create($configs);
        $defaultQueue = config('queue.connections.rabbitmq.queue');

        $queueConnection = new RabbitQueue($connection, $defaultQueue);

        $this->dispatcher->listen(WorkerStopping::class, static function () use ($queueConnection): void {
            $queueConnection->close();
        });

        return $queueConnection;
    }
}
