<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Doubles;

use AMQPExchange;
use AMQPQueue;
use iamfarhad\LaravelRabbitMQ\Support\RpcClient;

/**
 * RpcClient with its ext-amqp object-creation seams redirected at test-supplied
 * doubles.
 *
 * The factories must be installed before the constructor runs, because the
 * callback queue is declared during construction — hence the static holder.
 *
 * @see TestableRabbitQueue for why this exists instead of `overload:` mocking.
 */
class TestableRpcClient extends RpcClient
{
    /** @var (callable(): AMQPQueue)|null */
    public static $queueFactory = null;

    /** @var (callable(): AMQPExchange)|null */
    public static $exchangeFactory = null;

    public static function reset(): void
    {
        self::$queueFactory = null;
        self::$exchangeFactory = null;
    }

    protected function newAmqpQueue(): AMQPQueue
    {
        return self::$queueFactory !== null
            ? (self::$queueFactory)()
            : parent::newAmqpQueue();
    }

    protected function newAmqpExchange(): AMQPExchange
    {
        return self::$exchangeFactory !== null
            ? (self::$exchangeFactory)()
            : parent::newAmqpExchange();
    }
}
