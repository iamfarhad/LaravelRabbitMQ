<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Support;

use AMQPChannel;
use AMQPEnvelope;
use AMQPExchange;
use AMQPQueue;
use Exception;

class RpcClient
{
    private AMQPQueue $callbackQueue;

    private array $responses = [];

    private string $callbackQueueName;

    public function __construct(
        private readonly AMQPChannel $channel,
        private readonly int $timeout = 30
    ) {
        $this->setupCallbackQueue();
    }

    /**
     * Setup the callback queue for RPC responses
     */
    private function setupCallbackQueue(): void
    {
        $this->callbackQueue = new AMQPQueue($this->channel);
        $this->callbackQueue->setFlags(AMQP_EXCLUSIVE);
        $this->callbackQueueName = $this->callbackQueue->declareQueue();
    }

    /**
     * Make an RPC call
     */
    public function call(
        string $queue,
        string $message,
        array $headers = []
    ): string {
        $correlationId = $this->generateCorrelationId();

        // Publish the request
        $exchange = new AMQPExchange($this->channel);
        $exchange->setName('');

        $attributes = [
            'correlation_id' => $correlationId,
            'reply_to' => $this->callbackQueueName,
            'delivery_mode' => 2,
            'content_type' => 'application/json',
        ];

        if (! empty($headers)) {
            $attributes['headers'] = $headers;
        }

        $exchange->publish($message, $queue, AMQP_NOPARAM, $attributes);

        // Wait for response
        return $this->waitForResponse($correlationId);
    }

    /**
     * Wait for RPC response
     */
    private function waitForResponse(string $correlationId): string
    {
        $startTime = time();

        while (! isset($this->responses[$correlationId])) {
            if (time() - $startTime > $this->timeout) {
                throw new Exception("RPC call timed out after {$this->timeout} seconds");
            }

            $envelope = $this->callbackQueue->get(AMQP_AUTOACK);

            if ($envelope instanceof AMQPEnvelope) {
                $responseCorrelationId = $envelope->getCorrelationId();
                $this->responses[$responseCorrelationId] = $envelope->getBody();
            } else {
                usleep(100000); // 100ms
            }
        }

        $response = $this->responses[$correlationId];
        unset($this->responses[$correlationId]);

        return $response;
    }

    /**
     * Generate a unique correlation ID
     */
    private function generateCorrelationId(): string
    {
        return uniqid('rpc_', true);
    }

    /**
     * Get the callback queue name
     */
    public function getCallbackQueueName(): string
    {
        return $this->callbackQueueName;
    }
}
