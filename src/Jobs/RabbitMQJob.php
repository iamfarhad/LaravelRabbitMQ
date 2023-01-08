<?php

namespace iamfarhad\LaravelRabbitMQ\Jobs;

use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Arr;
use JsonException;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;

final class RabbitMQJob extends Job implements JobContract
{
    private readonly array $decoded;


    public function __construct(
        Container $container,
        protected RabbitQueue $rabbitQueue,
        protected AMQPMessage $amqpMessage,
        string $connectionName,
        string $queue
    ) {
        $this->container      = $container;
        $this->connectionName = $connectionName;
        $this->queue          = $queue;
        $this->decoded        = $this->payload();
    }

    /**
     * {@inheritdoc}
     */
    public function getJobId()
    {
        return $this->decoded['id'] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function getRawBody(): string
    {
        return $this->amqpMessage->getBody();
    }

    /**
     * {@inheritdoc}
     */
    public function attempts(): int
    {
        if (! $rabbitMQMessageHeaders = $this->getRabbitMQMessageHeaders()) {
            return 1;
        }

        $laravelAttempts = (int) Arr::get($rabbitMQMessageHeaders, 'laravel.attempts', 0);

        return ($laravelAttempts + 1);
    }


    private function convertMessageToFailed(): void
    {
        if ($this->amqpMessage->getExchange() !== 'failed_messages') {
            $this->rabbitQueue->declareQueue('failed_messages');
            $this->rabbitQueue->pushRaw($this->amqpMessage->getBody(), 'failed_messages');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function markAsFailed(): void
    {
        parent::markAsFailed();

        // We must tell rabbitMQ this Job is failed
        // The message must be rejected when the Job marked as failed, in case rabbitMQ wants to do some extra magic.
        // like: Death lettering the message to another exchange/routing-key.
        $this->rabbitQueue->reject($this);

        $this->convertMessageToFailed();
    }

    /**
     * {@inheritdoc}
     */
    public function delete(): void
    {
        parent::delete();

        // When delete is called and the Job was not failed, the message must be acknowledged.
        // This is because this is a controlled call by a developer. So the message was handled correct.
        if (! $this->failed) {
            $this->rabbitQueue->ack($this);
        }
    }

    /**
     * Release the job back into the queue.
     *
     * @param int $delay
     *
     * @throws JsonException
     */
    public function release($delay = 0): void
    {
        parent::release();

        // Always create a new message when this Job is released
        $this->rabbitQueue->laterRaw($delay, $this->amqpMessage->getBody(), $this->queue, $this->attempts());

        // Releasing a Job means the message was failed to process.
        // Because this Job message is always recreated and pushed as new message, this Job message is correctly handled.
        // We must tell rabbitMQ this job message can be removed by acknowledging the message.
        $this->rabbitQueue->ack($this);
    }

    public function getRabbitMQ(): RabbitQueue
    {
        return $this->rabbitQueue;
    }

    public function getRabbitMQMessage(): AMQPMessage
    {
        return $this->amqpMessage;
    }

    private function getRabbitMQMessageHeaders(): ?array
    {
        /*
         * @var AMQPTable|null $headers
         */
        if (! $headers = Arr::get($this->amqpMessage->get_properties(), 'application_headers')) {
            return null;
        }

        return $headers->getNativeData();
    }
}
