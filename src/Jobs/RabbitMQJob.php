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

class RabbitMQJob extends Job implements JobContract
{
    protected RabbitQueue $rabbitmq;

    protected AMQPMessage $message;

    protected array $decoded;

    public function __construct(
        Container $container,
        RabbitQueue $rabbitmq,
        AMQPMessage $message,
        string $connectionName,
        string $queue
    ) {
        $this->container = $container;
        $this->rabbitmq = $rabbitmq;
        $this->message = $message;
        $this->connectionName = $connectionName;
        $this->queue = $queue;
        $this->decoded = $this->payload();
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
        return $this->message->getBody();
    }

    /**
     * {@inheritdoc}
     */
    public function attempts(): int
    {
        if (! $data = $this->getRabbitMQMessageHeaders()) {
            return 1;
        }

        $laravelAttempts = (int) Arr::get($data, 'laravel.attempts', 0);

        return $laravelAttempts + 1;
    }

    private function convertMessageToFailed(): void
    {
        if ($this->message->getExchange() !== 'failed_messages') {
            $this->rabbitmq->declareQueue('failed_messages');
            $this->rabbitmq->pushRaw($this->message->getBody(), 'failed_messages');
        }
    }

    /**
     * {@inheritdoc}
     * @throws JsonException
     */
    public function markAsFailed(): void
    {
        parent::markAsFailed();

        // We must tell rabbitMQ this Job is failed
        // The message must be rejected when the Job marked as failed, in case rabbitMQ wants to do some extra magic.
        // like: Death lettering the message to another exchange/routing-key.
        $this->rabbitmq->reject($this);

        $this->convertMessageToFailed();
    }

    /**
     * {@inheritdoc}
     *
     */
    public function delete(): void
    {
        parent::delete();

        // When delete is called and the Job was not failed, the message must be acknowledged.
        // This is because this is a controlled call by a developer. So the message was handled correct.
        if (! $this->failed) {
            $this->rabbitmq->ack($this);
        }
    }

    /**
     * Release the job back into the queue.
     *
     * @param  int  $delay
     *
     * @throws JsonException
     */
    public function release($delay = 0): void
    {
        parent::release();

        // Always create a new message when this Job is released
        $this->rabbitmq->laterRaw($delay, $this->message->getBody(), $this->queue, $this->attempts());

        // Releasing a Job means the message was failed to process.
        // Because this Job message is always recreated and pushed as new message, this Job message is correctly handled.
        // We must tell rabbitMQ this job message can be removed by acknowledging the message.
        $this->rabbitmq->ack($this);
    }

    /**
     * Get the underlying RabbitMQ connection.
     *
     * @return RabbitQueue
     */
    public function getRabbitMQ(): RabbitQueue
    {
        return $this->rabbitmq;
    }
    public function getRabbitMQMessage(): AMQPMessage
    {
        return $this->message;
    }
    protected function getRabbitMQMessageHeaders(): ?array
    {
        /** @var AMQPTable|null $headers */
        if (! $headers = Arr::get($this->message->get_properties(), 'application_headers')) {
            return null;
        }

        return $headers->getNativeData();
    }
}
