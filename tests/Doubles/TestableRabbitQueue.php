<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Doubles;

use AMQPChannel;
use AMQPExchange;
use AMQPQueue;
use iamfarhad\LaravelRabbitMQ\Connection\PoolManager;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;

/**
 * RabbitQueue with its ext-amqp object-creation seams redirected at test-supplied
 * factories.
 *
 * This replaces Mockery's `overload:` instance mocking, which requires the AMQP
 * classes *not* to exist and therefore could never run on a machine that has the
 * extension the package requires — leaving those tests permanently skipped
 * exactly where they mattered most.
 */
class TestableRabbitQueue extends RabbitQueue
{
    /** @var (callable(AMQPChannel): AMQPQueue)|null */
    private $queueFactory = null;

    /** @var (callable(AMQPChannel): AMQPExchange)|null */
    private $exchangeFactory = null;

    /**
     * Every AMQPQueue the driver builds, in creation order.
     *
     * @var list<AMQPQueue>
     */
    public array $createdQueues = [];

    /**
     * @var list<AMQPExchange>
     */
    public array $createdExchanges = [];

    /**
     * @param  callable(AMQPChannel): AMQPQueue  $factory
     */
    public function useQueueFactory(callable $factory): static
    {
        $this->queueFactory = $factory;

        return $this;
    }

    /**
     * @param  callable(AMQPChannel): AMQPExchange  $factory
     */
    public function useExchangeFactory(callable $factory): static
    {
        $this->exchangeFactory = $factory;

        return $this;
    }

    protected function newAmqpQueue(AMQPChannel $channel): AMQPQueue
    {
        $queue = $this->queueFactory !== null
            ? ($this->queueFactory)($channel)
            : parent::newAmqpQueue($channel);

        $this->createdQueues[] = $queue;

        return $queue;
    }

    protected function newAmqpExchange(AMQPChannel $channel): AMQPExchange
    {
        $exchange = $this->exchangeFactory !== null
            ? ($this->exchangeFactory)($channel)
            : parent::newAmqpExchange($channel);

        $this->createdExchanges[] = $exchange;

        return $exchange;
    }

    public static function make(PoolManager $poolManager, string $defaultQueue = 'default', string $connectionName = 'rabbitmq'): static
    {
        return new static($poolManager, $defaultQueue, [], false, $connectionName);
    }
}
