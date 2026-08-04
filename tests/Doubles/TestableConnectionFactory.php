<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Doubles;

use AMQPConnection;
use iamfarhad\LaravelRabbitMQ\Connection\ConnectionFactory;

/**
 * ConnectionFactory with connection creation redirected at a test-supplied
 * factory, so connection retry and failover can be exercised without a broker.
 *
 * @see TestableRabbitQueue for why this exists instead of `overload:` mocking.
 */
class TestableConnectionFactory extends ConnectionFactory
{
    /** @var (callable(array<string, mixed>): AMQPConnection)|null */
    private $connectionFactory = null;

    /**
     * The connection config handed to each creation attempt, in order.
     *
     * @var list<array<string, mixed>>
     */
    public array $attempts = [];

    /**
     * @param  callable(array<string, mixed>): AMQPConnection  $factory
     */
    public function useConnectionFactory(callable $factory): static
    {
        $this->connectionFactory = $factory;

        return $this;
    }

    protected function newAmqpConnection(array $config): AMQPConnection
    {
        $this->attempts[] = $config;

        return $this->connectionFactory !== null
            ? ($this->connectionFactory)($config)
            : parent::newAmqpConnection($config);
    }
}
