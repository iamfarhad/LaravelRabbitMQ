<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Support;

use AMQPChannel;
use AMQPChannelException;
use AMQPExchange;
use AMQPQueue;
use AMQPQueueException;
use iamfarhad\LaravelRabbitMQ\Support\ExchangeManager;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Mockery;

class ExchangeManagerTest extends UnitTestCase
{
    private AMQPChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Mockery::mock(AMQPChannel::class);
    }

    private function makeManager(?AMQPExchange $exchange = null, ?AMQPQueue $queue = null): ExchangeManager
    {
        return new class($this->channel, $exchange, $queue) extends ExchangeManager
        {
            public function __construct(
                AMQPChannel $channel,
                private ?AMQPExchange $exchangeDouble,
                private ?AMQPQueue $queueDouble,
            ) {
                parent::__construct($channel);
            }

            protected function newAmqpExchange(): AMQPExchange
            {
                return $this->exchangeDouble ?? Mockery::mock(AMQPExchange::class);
            }

            protected function newAmqpQueue(): AMQPQueue
            {
                return $this->queueDouble ?? Mockery::mock(AMQPQueue::class);
            }
        };
    }

    public function testDeclaresBindsUnbindsPublishesAndDeletesExchange(): void
    {
        $exchange = Mockery::mock(AMQPExchange::class);
        $exchange->shouldReceive('setName')->atLeast()->once();
        $exchange->shouldReceive('setType')->once()->with(ExchangeManager::TYPE_TOPIC);
        $exchange->shouldReceive('setFlags')->once()->with(AMQP_DURABLE | AMQP_AUTODELETE);
        $exchange->shouldReceive('setArguments')->once()->with(['alternate-exchange' => 'alt']);
        $exchange->shouldReceive('declareExchange')->once();
        $exchange->shouldReceive('bind')->once()->with('source', 'key.#', ['x-arg' => 1]);
        $exchange->shouldReceive('publish')->once()->with('body', 'rk', AMQP_NOPARAM, ['delivery_mode' => 2]);
        $exchange->shouldReceive('delete')->once()->with(null);

        $queue = Mockery::mock(AMQPQueue::class);
        $queue->shouldReceive('setName')->twice()->with('orders');
        $queue->shouldReceive('bind')->once()->with('events', 'order.*', ['x-match' => 'all']);
        $queue->shouldReceive('unbind')->once()->with('events', 'order.*');

        $manager = $this->makeManager($exchange, $queue);

        $declared = $manager->declareExchange('events', ExchangeManager::TYPE_TOPIC, true, true, ['alternate-exchange' => 'alt']);
        $this->assertSame($exchange, $declared);

        $manager->bindQueue('orders', 'events', 'order.*', ['x-match' => 'all']);
        $manager->unbindQueue('orders', 'events', 'order.*');
        $manager->bindExchange('dest', 'source', 'key.#', ['x-arg' => 1]);
        $this->assertTrue($manager->publish('events', 'body', 'rk', ['delivery_mode' => 2]));
        $manager->deleteExchange('events');
    }

    public function testSetupDelayedTopicFanoutAndHeadersExchanges(): void
    {
        $exchange = Mockery::mock(AMQPExchange::class);
        $exchange->shouldReceive('setName')->times(4);
        $exchange->shouldReceive('setType')->with(ExchangeManager::TYPE_DELAYED_MESSAGE)->once();
        $exchange->shouldReceive('setType')->with(ExchangeManager::TYPE_TOPIC)->once();
        $exchange->shouldReceive('setType')->with(ExchangeManager::TYPE_FANOUT)->once();
        $exchange->shouldReceive('setType')->with(ExchangeManager::TYPE_HEADERS)->once();
        $exchange->shouldReceive('setFlags')->times(4)->with(AMQP_DURABLE);
        $exchange->shouldReceive('setArguments')->once()->with(['x-delayed-type' => ExchangeManager::TYPE_DIRECT]);
        $exchange->shouldReceive('declareExchange')->times(4);

        $queue = Mockery::mock(AMQPQueue::class);
        $queue->shouldReceive('setName')->times(4);
        $queue->shouldReceive('bind')->once()->with('topic-ex', 'a.*', []);
        $queue->shouldReceive('bind')->once()->with('topic-ex', 'b.*', []);
        $queue->shouldReceive('bind')->once()->with('fanout-ex', '', []);
        $queue->shouldReceive('bind')->once()->with('headers-ex', '', ['x-match' => 'all']);

        $manager = $this->makeManager($exchange, $queue);

        $manager->setupDelayedExchange('delayed-ex');
        $manager->setupTopicExchange('topic-ex', ['q1' => ['a.*', 'b.*']]);
        $manager->setupFanoutExchange('fanout-ex', ['q2']);
        $manager->setupHeadersExchange('headers-ex', ['q3' => ['x-match' => 'all']]);

        $this->assertTrue(true);
    }

    public function testSetupDeadLetterExchangeToleratesPreconditionFailedOnExistingMainQueue(): void
    {
        $exchange = Mockery::mock(AMQPExchange::class);
        $exchange->shouldReceive('setName')->once()->with('dlx');
        $exchange->shouldReceive('setType')->once()->with(ExchangeManager::TYPE_DIRECT);
        $exchange->shouldReceive('setFlags')->once()->with(AMQP_DURABLE);
        $exchange->shouldReceive('declareExchange')->once();

        $declareAttempt = 0;
        $queue = Mockery::mock(AMQPQueue::class);
        $queue->shouldReceive('setName');
        $queue->shouldReceive('setFlags')->with(AMQP_DURABLE);
        $queue->shouldReceive('setArguments')->once()->with(['x-message-ttl' => 60000]);
        $queue->shouldReceive('setArguments')->once()->with([
            'x-dead-letter-exchange' => 'dlx',
            'x-dead-letter-routing-key' => 'orders.dlq',
        ]);
        $queue->shouldReceive('bind')->once()->with('dlx', 'orders.dlq', []);
        $queue->shouldReceive('declareQueue')->twice()->andReturnUsing(function () use (&$declareAttempt): int {
            if (++$declareAttempt === 2) {
                throw new AMQPQueueException('PRECONDITION_FAILED', 406);
            }

            return 0;
        });

        $manager = $this->makeManager($exchange, $queue);
        $manager->setupDeadLetterExchange('orders', 'dlx', ExchangeManager::TYPE_DIRECT, 'orders.dlq', '', 60000);

        $this->assertSame(2, $declareAttempt);
    }

    public function testSetupDeadLetterExchangeRethrowsNonPreconditionErrors(): void
    {
        $exchange = Mockery::mock(AMQPExchange::class);
        $exchange->shouldReceive('setName');
        $exchange->shouldReceive('setType');
        $exchange->shouldReceive('setFlags');
        $exchange->shouldReceive('declareExchange');

        $declareAttempt = 0;
        $queue = Mockery::mock(AMQPQueue::class);
        $queue->shouldReceive('setName');
        $queue->shouldReceive('setFlags');
        $queue->shouldReceive('setArguments');
        $queue->shouldReceive('bind');
        $queue->shouldReceive('declareQueue')->twice()->andReturnUsing(function () use (&$declareAttempt): int {
            if (++$declareAttempt === 2) {
                throw new AMQPChannelException('ACCESS_REFUSED', 403);
            }

            return 0;
        });

        $manager = $this->makeManager($exchange, $queue);

        $this->expectException(AMQPChannelException::class);
        $this->expectExceptionCode(403);

        $manager->setupDeadLetterExchange('orders', 'dlx');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
