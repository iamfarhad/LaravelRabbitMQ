<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Support;

use AMQPChannel;
use AMQPEnvelope;
use AMQPExchange;
use AMQPQueue;
use Error;
use iamfarhad\LaravelRabbitMQ\Support\RpcServer;
use iamfarhad\LaravelRabbitMQ\Tests\Doubles\TestableRpcClient;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Mockery;

class RpcTest extends UnitTestCase
{
    private AMQPChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Mockery::mock(AMQPChannel::class);
    }

    protected function tearDown(): void
    {
        TestableRpcClient::reset();
        Mockery::close();
        parent::tearDown();
    }

    public function testRpcServerProcessesValidInvalidAndThrowingRequests(): void
    {
        $queue = Mockery::mock(AMQPQueue::class);
        $queue->shouldReceive('setName')->once()->with('rpc.queue');
        $queue->shouldReceive('setFlags')->once()->with(AMQP_DURABLE);
        $queue->shouldReceive('declareQueue')->once()->andReturn(0);

        $validEnvelope = Mockery::mock(AMQPEnvelope::class);
        $validEnvelope->shouldReceive('getCorrelationId')->andReturn('corr-1');
        $validEnvelope->shouldReceive('getReplyTo')->andReturn('reply.queue');
        $validEnvelope->shouldReceive('getBody')->andReturn('{"ping":true}');
        $validEnvelope->shouldReceive('getHeaders')->andReturn(['x-trace' => '1']);
        $validEnvelope->shouldReceive('getDeliveryTag')->andReturn(1);

        $invalidEnvelope = Mockery::mock(AMQPEnvelope::class);
        $invalidEnvelope->shouldReceive('getCorrelationId')->andReturn('');
        $invalidEnvelope->shouldReceive('getReplyTo')->andReturn('');
        $invalidEnvelope->shouldReceive('getDeliveryTag')->andReturn(2);

        $throwingEnvelope = Mockery::mock(AMQPEnvelope::class);
        $throwingEnvelope->shouldReceive('getCorrelationId')->andReturn('corr-3');
        $throwingEnvelope->shouldReceive('getReplyTo')->andReturn('reply.queue');
        $throwingEnvelope->shouldReceive('getBody')->andReturn('boom');
        $throwingEnvelope->shouldReceive('getHeaders')->andReturn([]);
        $throwingEnvelope->shouldReceive('getDeliveryTag')->andReturn(3);

        $exchange = Mockery::mock(AMQPExchange::class);
        $exchange->shouldReceive('setName')->once()->with('');
        $exchange->shouldReceive('publish')->once()->with(
            '{"pong":true}',
            'reply.queue',
            AMQP_NOPARAM,
            [
                'correlation_id' => 'corr-1',
                'delivery_mode' => 2,
                'content_type' => 'application/json',
            ]
        );

        $server = new class($this->channel, 'rpc.queue', $queue, $exchange) extends RpcServer
        {
            public function __construct(
                AMQPChannel $channel,
                string $queueName,
                private AMQPQueue $queueDouble,
                private AMQPExchange $exchangeDouble,
            ) {
                parent::__construct($channel, $queueName);
            }

            protected function newAmqpQueue(): AMQPQueue
            {
                return $this->queueDouble;
            }

            protected function newAmqpExchange(): AMQPExchange
            {
                return $this->exchangeDouble;
            }
        };

        $queue->shouldReceive('ack')->once()->with(1);
        $queue->shouldReceive('nack')->once()->with(2);
        $queue->shouldReceive('nack')->once()->with(3);

        $deliveries = [$validEnvelope, $invalidEnvelope, $throwingEnvelope, null];
        $queue->shouldReceive('get')->times(4)->with(AMQP_NOPARAM)->andReturnUsing(function () use (&$deliveries, $server) {
            $next = array_shift($deliveries);
            if ($next === null) {
                $server->stop();
            }

            return $next;
        });

        $this->assertFalse($server->isRunning());

        $server->listen(function (string $body, array $headers) use ($server): string {
            $this->assertTrue($server->isRunning());

            if ($body === 'boom') {
                throw new Error('fatal callback error');
            }

            $this->assertSame(['x-trace' => '1'], $headers);

            return '{"pong":true}';
        });

        $this->assertFalse($server->isRunning());
    }

    public function testRpcClientCallBuffersOutOfOrderRepliesEvictsOverflowAndCloses(): void
    {
        $amqpQueue = Mockery::mock(AMQPQueue::class);
        $amqpQueue->shouldReceive('setFlags');
        $amqpQueue->shouldReceive('declareQueue')->andReturn(0);
        $amqpQueue->shouldReceive('getName')->andReturn('amq.gen-reply');
        $amqpQueue->shouldReceive('delete')->once();

        $sentCorrelationId = null;
        $amqpExchange = Mockery::mock(AMQPExchange::class);
        $amqpExchange->shouldReceive('setName')->with('');
        $amqpExchange->shouldReceive('publish')->once()->andReturnUsing(
            function (string $msg, string $queue, int $flags, array $attrs) use (&$sentCorrelationId): void {
                $sentCorrelationId = $attrs['correlation_id'];
            }
        );

        $calls = 0;
        $amqpQueue->shouldReceive('get')->with(AMQP_AUTOACK)->andReturnUsing(
            function () use (&$calls, &$sentCorrelationId): AMQPEnvelope {
                $calls++;

                if ($calls === 1) {
                    // Empty correlation ID is ignored.
                    $empty = Mockery::mock(AMQPEnvelope::class);
                    $empty->shouldReceive('getCorrelationId')->andReturn('');

                    return $empty;
                }

                if ($calls <= 258) {
                    // Fill beyond MAX_BUFFERED_RESPONSES (256) to exercise eviction.
                    $orphan = Mockery::mock(AMQPEnvelope::class);
                    $orphan->shouldReceive('getCorrelationId')->andReturn('orphan-'.$calls);
                    $orphan->shouldReceive('getBody')->andReturn('ignored');

                    return $orphan;
                }

                $reply = Mockery::mock(AMQPEnvelope::class);
                $reply->shouldReceive('getCorrelationId')->andReturn($sentCorrelationId);
                $reply->shouldReceive('getBody')->andReturn('{"ok":true}');

                return $reply;
            }
        );

        TestableRpcClient::$queueFactory = fn (): AMQPQueue => $amqpQueue;
        TestableRpcClient::$exchangeFactory = fn (): AMQPExchange => $amqpExchange;

        $client = new TestableRpcClient($this->channel, 5);
        $result = $client->call('rpc.queue', '{"ping":1}', ['x-header' => 'v']);

        $this->assertSame('{"ok":true}', $result);
        $client->close();
    }

    public function testRpcClientThrowsWhenBrokerDoesNotAssignCallbackQueueName(): void
    {
        $amqpQueue = Mockery::mock(AMQPQueue::class);
        $amqpQueue->shouldReceive('setFlags');
        $amqpQueue->shouldReceive('declareQueue')->andReturn(0);
        $amqpQueue->shouldReceive('getName')->andReturn('');

        TestableRpcClient::$queueFactory = fn (): AMQPQueue => $amqpQueue;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to resolve the RPC callback queue name');

        new TestableRpcClient($this->channel, 5);
    }
}
