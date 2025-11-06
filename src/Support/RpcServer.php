<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Support;

use AMQPChannel;
use AMQPEnvelope;
use AMQPExchange;
use AMQPQueue;
use Exception;

class RpcServer
{
    private AMQPQueue $queue;

    private bool $running = false;

    public function __construct(
        private readonly AMQPChannel $channel,
        private readonly string $queueName
    ) {
        $this->setupQueue();
    }

    /**
     * Setup the RPC server queue
     */
    private function setupQueue(): void
    {
        $this->queue = new AMQPQueue($this->channel);
        $this->queue->setName($this->queueName);
        $this->queue->setFlags(AMQP_DURABLE);
        $this->queue->declareQueue();
    }

    /**
     * Start listening for RPC requests
     *
     * @param  callable(string, array): string  $callback
     */
    public function listen(callable $callback): void
    {
        $this->running = true;

        while ($this->running) {
            $envelope = $this->queue->get(AMQP_NOPARAM);

            if ($envelope instanceof AMQPEnvelope) {
                try {
                    $this->processRequest($envelope, $callback);
                    $this->queue->ack($envelope->getDeliveryTag());
                } catch (Exception $e) {
                    $this->queue->nack($envelope->getDeliveryTag());
                    // Log error or handle as needed
                }
            } else {
                usleep(100000); // 100ms
            }
        }
    }

    /**
     * Process an RPC request
     */
    private function processRequest(AMQPEnvelope $envelope, callable $callback): void
    {
        $correlationId = $envelope->getCorrelationId();
        $replyTo = $envelope->getReplyTo();

        if (! $replyTo || ! $correlationId) {
            throw new Exception('Invalid RPC request: missing reply_to or correlation_id');
        }

        $message = $envelope->getBody();
        $headers = $envelope->getHeaders() ?? [];

        // Execute the callback to get the response
        $response = $callback($message, $headers);

        // Send the response
        $this->sendResponse($replyTo, $response, $correlationId);
    }

    /**
     * Send RPC response
     */
    private function sendResponse(string $replyTo, string $response, string $correlationId): void
    {
        $exchange = new AMQPExchange($this->channel);
        $exchange->setName('');

        $attributes = [
            'correlation_id' => $correlationId,
            'delivery_mode' => 2,
            'content_type' => 'application/json',
        ];

        $exchange->publish($response, $replyTo, AMQP_NOPARAM, $attributes);
    }

    /**
     * Stop the RPC server
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Check if server is running
     */
    public function isRunning(): bool
    {
        return $this->running;
    }
}
