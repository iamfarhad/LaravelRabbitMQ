<?php

namespace iamfarhad\LaravelRabbitMQ\Connectors;

use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Events\WorkerStopping;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;

readonly class RabbitMQConnector implements ConnectorInterface
{
    public function __construct(private Dispatcher $dispatcher)
    {
    }

    public function connect(array $config = []): Queue
    {
        $amqpConnectionConfig = new AMQPConnectionConfig();

        // set AMQP account
        $amqpConnectionConfig->setHost(config('queue.connections.rabbitmq.hosts.host'));
        $amqpConnectionConfig->setPort(config('queue.connections.rabbitmq.hosts.port'));
        $amqpConnectionConfig->setUser(config('queue.connections.rabbitmq.hosts.user'));
        $amqpConnectionConfig->setPassword(config('queue.connections.rabbitmq.hosts.password'));
        $amqpConnectionConfig->setVhost(config('queue.connections.rabbitmq.hosts.vhost'));

        $amqpConnectionConfig->setIsLazy(config('queue.connections.rabbitmq.hosts.lazy'));
        $amqpConnectionConfig->setKeepalive(config('queue.connections.rabbitmq.hosts.keepalive'));
        $amqpConnectionConfig->setHeartbeat(config('queue.connections.rabbitmq.hosts.heartbeat'));
        $amqpConnectionConfig->setIsSecure(config('queue.connections.rabbitmq.hosts.secure'));

        // set SSL Options
        if ($amqpConnectionConfig->isSecure()) {
            $amqpConnectionConfig->setSslCaCert(config('queue.connections.rabbitmq.options.ssl_options.cafile'));
            $amqpConnectionConfig->setSslCert(config('queue.connections.rabbitmq.options.ssl_options.local_cert'));
            $amqpConnectionConfig->setSslKey(config('queue.connections.rabbitmq.options.ssl_options.local_key'));
            $amqpConnectionConfig->setSslVerify(config('queue.connections.rabbitmq.options.ssl_options.verify_peer'));
            $amqpConnectionConfig->setSslPassPhrase(config('queue.connections.rabbitmq.options.ssl_options.passphrase'));
        }

        // Create AMQP Connection
        $connection = AMQPConnectionFactory::create($amqpConnectionConfig);
        $defaultQueue = config('queue.connections.rabbitmq.queue');

        $rabbitQueue = new RabbitQueue($connection, $defaultQueue);

        $this->dispatcher->listen(WorkerStopping::class, fn () => $rabbitQueue->close());

        return $rabbitQueue;
    }
}
