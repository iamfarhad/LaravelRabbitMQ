<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Jobs;

use AMQPEnvelope;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Arr;
use Throwable;

class RabbitMQJob extends Job implements JobContract
{
    private const FAILED_MESSAGES_EXCHANGE = 'failed_messages';

    protected array $decoded = [];

    public function __construct(
        Container $container,
        protected RabbitQueue $rabbitQueue,
        protected AMQPEnvelope $amqpEnvelope,
        string $connectionName,
        string $queue
    ) {
        $this->container = $container;
        $this->connectionName = $connectionName;
        $this->queue = $queue;
        $this->decoded = $this->payload();
    }

    public function getJobId(): ?string
    {
        return $this->decoded['id'] ?? $this->amqpEnvelope->getCorrelationId() ?: null;
    }

    public function getRawBody(): string
    {
        return $this->amqpEnvelope->getBody();
    }

    public function headers(): array
    {
        return (array) ($this->amqpEnvelope->getHeaders() ?: []);
    }

    public function exchangeName(): ?string
    {
        $exchange = $this->amqpEnvelope->getExchangeName();

        return $exchange !== '' ? $exchange : null;
    }

    public function routingKey(): ?string
    {
        if (! method_exists($this->amqpEnvelope, 'getRoutingKey')) {
            return null;
        }

        $routingKey = $this->amqpEnvelope->getRoutingKey();

        return $routingKey !== '' ? $routingKey : null;
    }

    public function deliveryTag(): ?string
    {
        $deliveryTag = $this->amqpEnvelope->getDeliveryTag();

        return $deliveryTag !== null ? (string) $deliveryTag : null;
    }

    private function convertMessageToFailed(): void
    {
        try {
            if ($this->amqpEnvelope->getExchangeName() !== self::FAILED_MESSAGES_EXCHANGE) {
                if (! $this->rabbitQueue->getConnection()->isConnected()) {
                    return;
                }

                $this->rabbitQueue->declareQueue(self::FAILED_MESSAGES_EXCHANGE);
                $this->rabbitQueue->pushRaw($this->amqpEnvelope->getBody(), self::FAILED_MESSAGES_EXCHANGE);
            }
        } catch (Throwable) {
        }
    }

    public function attempts(): int
    {
        if (! $rabbitMQMessageHeaders = $this->getRabbitMQMessageHeaders()) {
            return 1;
        }

        $laravelAttempts = (int) Arr::get($rabbitMQMessageHeaders, 'laravel.attempts', 0);

        return $laravelAttempts + 1;
    }

    public function markAsFailed(): void
    {
        parent::markAsFailed();
        $this->rabbitQueue->reject($this);
        $this->convertMessageToFailed();
    }

    public function delete(): void
    {
        parent::delete();

        if (! $this->failed) {
            $this->rabbitQueue->ack($this);
        }
    }

    public function release($delay = 0): void
    {
        parent::release();
        $this->rabbitQueue->laterRaw($delay, $this->amqpEnvelope->getBody(), $this->queue, $this->attempts());
        $this->rabbitQueue->ack($this);
    }

    public function getRabbitMQ(): RabbitQueue
    {
        return $this->rabbitQueue;
    }

    public function getRabbitMQMessage(): AMQPEnvelope
    {
        return $this->amqpEnvelope;
    }

    protected function getRabbitMQMessageHeaders(): ?array
    {
        $headers = $this->headers();

        if ($headers === [] || ! isset($headers['laravel'])) {
            return null;
        }

        return $headers;
    }
}
