<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Support;

use AMQPChannel;
use AMQPExchange;
use AMQPQueue;

class ExchangeManager
{
    public const TYPE_DIRECT = AMQP_EX_TYPE_DIRECT;

    public const TYPE_FANOUT = AMQP_EX_TYPE_FANOUT;

    public const TYPE_TOPIC = AMQP_EX_TYPE_TOPIC;

    public const TYPE_HEADERS = AMQP_EX_TYPE_HEADERS;

    public function __construct(
        private readonly AMQPChannel $channel
    ) {}

    /**
     * Declare an exchange
     */
    public function declareExchange(
        string $name,
        string $type = self::TYPE_DIRECT,
        bool $durable = true,
        bool $autoDelete = false,
        array $arguments = []
    ): AMQPExchange {
        $exchange = new AMQPExchange($this->channel);
        $exchange->setName($name);
        $exchange->setType($type);
        $exchange->setFlags(
            ($durable ? AMQP_DURABLE : AMQP_NOPARAM) |
                ($autoDelete ? AMQP_AUTODELETE : AMQP_NOPARAM)
        );

        if (! empty($arguments)) {
            $exchange->setArguments($arguments);
        }

        $exchange->declareExchange();

        return $exchange;
    }

    /**
     * Bind a queue to an exchange
     */
    public function bindQueue(
        string $queueName,
        string $exchangeName,
        string $routingKey = '',
        array $arguments = []
    ): void {
        $queue = new AMQPQueue($this->channel);
        $queue->setName($queueName);
        $queue->bind($exchangeName, $routingKey, $arguments);
    }

    /**
     * Unbind a queue from an exchange
     */
    public function unbindQueue(
        string $queueName,
        string $exchangeName,
        string $routingKey = ''
    ): void {
        $queue = new AMQPQueue($this->channel);
        $queue->setName($queueName);
        $queue->unbind($exchangeName, $routingKey);
    }

    /**
     * Bind an exchange to another exchange
     */
    public function bindExchange(
        string $destinationExchange,
        string $sourceExchange,
        string $routingKey = '',
        array $arguments = []
    ): void {
        $exchange = new AMQPExchange($this->channel);
        $exchange->setName($destinationExchange);
        $exchange->bind($sourceExchange, $routingKey, $arguments);
    }

    /**
     * Delete an exchange
     */
    public function deleteExchange(string $name, ?string $flags = null): void
    {
        $exchange = new AMQPExchange($this->channel);
        $exchange->setName($name);
        $exchange->delete($flags);
    }

    /**
     * Publish a message to an exchange
     */
    public function publish(
        string $exchangeName,
        string $message,
        string $routingKey = '',
        array $attributes = [],
        int $flags = AMQP_NOPARAM
    ): bool {
        $exchange = new AMQPExchange($this->channel);
        $exchange->setName($exchangeName);

        $exchange->publish($message, $routingKey, $flags, $attributes);

        return true;
    }

    /**
     * Setup a dead letter exchange for a queue
     */
    public function setupDeadLetterExchange(
        string $queueName,
        string $dlxName,
        string $dlxType = self::TYPE_DIRECT,
        ?string $dlxRoutingKey = null
    ): void {
        // Declare the dead letter exchange
        $this->declareExchange($dlxName, $dlxType, true, false);

        // Create dead letter queue
        $dlqName = $queueName . '.dlq';
        $dlq = new AMQPQueue($this->channel);
        $dlq->setName($dlqName);
        $dlq->setFlags(AMQP_DURABLE);
        $dlq->declareQueue();

        // Bind dead letter queue to dead letter exchange
        $this->bindQueue($dlqName, $dlxName, $dlxRoutingKey ?? $queueName);

        // Update main queue with DLX arguments
        $queue = new AMQPQueue($this->channel);
        $queue->setName($queueName);
        $queue->setArguments([
            'x-dead-letter-exchange' => $dlxName,
            'x-dead-letter-routing-key' => $dlxRoutingKey ?? $queueName,
        ]);
    }

    /**
     * Setup a delayed message exchange
     */
    public function setupDelayedExchange(
        string $exchangeName,
        string $type = self::TYPE_DIRECT
    ): AMQPExchange {
        return $this->declareExchange(
            $exchangeName,
            $type,
            true,
            false,
            ['x-delayed-type' => $type]
        );
    }

    /**
     * Create a topic exchange with multiple bindings
     */
    public function setupTopicExchange(
        string $exchangeName,
        array $bindings // ['queue_name' => ['routing.key.1', 'routing.key.2']]
    ): void {
        $this->declareExchange($exchangeName, self::TYPE_TOPIC, true, false);

        foreach ($bindings as $queueName => $routingKeys) {
            foreach ((array) $routingKeys as $routingKey) {
                $this->bindQueue($queueName, $exchangeName, $routingKey);
            }
        }
    }

    /**
     * Create a fanout exchange with multiple queues
     */
    public function setupFanoutExchange(
        string $exchangeName,
        array $queueNames
    ): void {
        $this->declareExchange($exchangeName, self::TYPE_FANOUT, true, false);

        foreach ($queueNames as $queueName) {
            $this->bindQueue($queueName, $exchangeName);
        }
    }

    /**
     * Create a headers exchange with bindings
     */
    public function setupHeadersExchange(
        string $exchangeName,
        array $bindings // ['queue_name' => ['x-match' => 'all', 'format' => 'pdf']]
    ): void {
        $this->declareExchange($exchangeName, self::TYPE_HEADERS, true, false);

        foreach ($bindings as $queueName => $headers) {
            $this->bindQueue($queueName, $exchangeName, '', $headers);
        }
    }
}
