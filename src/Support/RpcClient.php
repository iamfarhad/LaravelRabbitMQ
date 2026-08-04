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
    /**
     * Upper bound on replies buffered for correlation IDs nobody is waiting for
     * any more (a caller that timed out, a duplicate reply). Without it a
     * long-lived client leaks one entry per orphaned reply.
     */
    private const MAX_BUFFERED_RESPONSES = 256;

    private const POLL_MIN_INTERVAL_US = 1000;

    private const POLL_MAX_INTERVAL_US = 50000;

    private AMQPQueue $callbackQueue;

    /**
     * @var array<string, string>
     */
    private array $responses = [];

    private string $callbackQueueName;

    public function __construct(
        private readonly AMQPChannel $channel,
        private readonly int $timeout = 30,
        private readonly string $callbackQueuePrefix = ''
    ) {
        $this->setupCallbackQueue();
    }

    /**
     * Setup the callback queue for RPC responses.
     *
     * AMQPQueue::declareQueue() returns the queue's *message count*, not its
     * name, so the name has to be read back from the queue object — which is
     * where ext-amqp stores the broker-assigned name for a server-named queue.
     */
    private function setupCallbackQueue(): void
    {
        $this->callbackQueue = $this->newAmqpQueue();

        if ($this->callbackQueuePrefix !== '') {
            $this->callbackQueue->setName($this->callbackQueuePrefix.bin2hex(random_bytes(8)));
        }

        // Exclusive and auto-delete: the reply queue belongs to this client and
        // must not outlive it.
        $this->callbackQueue->setFlags(AMQP_EXCLUSIVE | AMQP_AUTODELETE);
        $this->callbackQueue->declareQueue();

        $this->callbackQueueName = (string) $this->callbackQueue->getName();

        if ($this->callbackQueueName === '') {
            throw new Exception('Failed to resolve the RPC callback queue name from the broker.');
        }
    }

    /**
     * ext-amqp object-creation seams; see RabbitQueue for the rationale.
     */
    protected function newAmqpQueue(): AMQPQueue
    {
        return new AMQPQueue($this->channel);
    }

    protected function newAmqpExchange(): AMQPExchange
    {
        return new AMQPExchange($this->channel);
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
        $exchange = $this->newAmqpExchange();
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
     * Wait for RPC response.
     *
     * Polls with a short, escalating interval: a reply that is already waiting
     * is picked up in about a millisecond instead of the previous flat 100ms,
     * while an idle wait still backs off so it does not spin on basic.get.
     */
    private function waitForResponse(string $correlationId): string
    {
        $deadline = hrtime(true) + ($this->timeout * 1_000_000_000);
        $interval = self::POLL_MIN_INTERVAL_US;

        while (! isset($this->responses[$correlationId])) {
            if (hrtime(true) > $deadline) {
                unset($this->responses[$correlationId]);

                throw new Exception("RPC call timed out after {$this->timeout} seconds");
            }

            $envelope = $this->callbackQueue->get(AMQP_AUTOACK);

            if ($envelope instanceof AMQPEnvelope) {
                $this->bufferResponse($envelope);
                $interval = self::POLL_MIN_INTERVAL_US;

                continue;
            }

            usleep($interval);
            $interval = min($interval * 2, self::POLL_MAX_INTERVAL_US);
        }

        $response = $this->responses[$correlationId];
        unset($this->responses[$correlationId]);

        return $response;
    }

    private function bufferResponse(AMQPEnvelope $envelope): void
    {
        $correlationId = (string) $envelope->getCorrelationId();

        if ($correlationId === '') {
            return;
        }

        // Bound the buffer so replies for callers that already gave up cannot
        // accumulate for the lifetime of this client.
        if (count($this->responses) >= self::MAX_BUFFERED_RESPONSES) {
            $oldest = array_key_first($this->responses);

            if ($oldest !== null) {
                unset($this->responses[$oldest]);
            }
        }

        $this->responses[$correlationId] = $envelope->getBody();
    }

    /**
     * Generate a unique correlation ID
     */
    private function generateCorrelationId(): string
    {
        return 'rpc_'.MessageHelpers::generateCorrelationId();
    }

    /**
     * Get the callback queue name
     */
    public function getCallbackQueueName(): string
    {
        return $this->callbackQueueName;
    }
}
