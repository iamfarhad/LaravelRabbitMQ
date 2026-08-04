<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Support;

use AMQPChannel;
use AMQPExchange;
use AMQPQueue;
use iamfarhad\LaravelRabbitMQ\Tests\Doubles\TestableRpcClient;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Mockery;

/**
 * AMQPQueue::declareQueue() returns the queue's *message count*, not its name.
 * Assigning that to a `string` property under strict_types made the RPC client
 * constructor throw a TypeError, so RPC could never be used at all.
 */
class RpcClientTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        TestableRpcClient::reset();
        parent::tearDown();
    }

    public function testCallbackQueueNameComesFromTheQueueNotTheDeclareReturnValue(): void
    {
        $amqpQueue = Mockery::mock(AMQPQueue::class);
        $amqpQueue->shouldReceive('setFlags');
        // The broker-assigned name is readable from the queue; declareQueue()
        // hands back how many messages it holds.
        $amqpQueue->shouldReceive('declareQueue')->once()->andReturn(0);
        $amqpQueue->shouldReceive('getName')->andReturn('amq.gen-JzTY20BRgKO-HjmUJj0wLg');

        TestableRpcClient::$queueFactory = fn (): AMQPQueue => $amqpQueue;

        $client = new TestableRpcClient(Mockery::mock(AMQPChannel::class), 30);

        $this->assertSame('amq.gen-JzTY20BRgKO-HjmUJj0wLg', $client->getCallbackQueueName());
    }

    public function testConfiguredPrefixNamesTheCallbackQueue(): void
    {
        $assignedName = null;

        $amqpQueue = Mockery::mock(AMQPQueue::class);
        $amqpQueue->shouldReceive('setName')->andReturnUsing(function (string $name) use (&$assignedName): void {
            $assignedName = $name;
        });
        $amqpQueue->shouldReceive('setFlags');
        $amqpQueue->shouldReceive('declareQueue')->andReturn(0);
        $amqpQueue->shouldReceive('getName')->andReturnUsing(function () use (&$assignedName): string {
            return (string) $assignedName;
        });

        TestableRpcClient::$queueFactory = fn (): AMQPQueue => $amqpQueue;

        $client = new TestableRpcClient(Mockery::mock(AMQPChannel::class), 30, 'rpc_callback_');

        $this->assertStringStartsWith('rpc_callback_', $client->getCallbackQueueName());
        $this->assertSame($assignedName, $client->getCallbackQueueName());
    }

    public function testTimeoutIsReportedWhenNoReplyArrives(): void
    {
        $amqpQueue = Mockery::mock(AMQPQueue::class);
        $amqpQueue->shouldReceive('setFlags');
        $amqpQueue->shouldReceive('declareQueue')->andReturn(0);
        $amqpQueue->shouldReceive('getName')->andReturn('amq.gen-abc');
        $amqpQueue->shouldReceive('get')->andReturn(false);

        $amqpExchange = Mockery::mock(AMQPExchange::class);
        $amqpExchange->shouldReceive('setName');
        $amqpExchange->shouldReceive('publish');

        TestableRpcClient::$queueFactory = fn (): AMQPQueue => $amqpQueue;
        TestableRpcClient::$exchangeFactory = fn (): AMQPExchange => $amqpExchange;

        $client = new TestableRpcClient(Mockery::mock(AMQPChannel::class), 0);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('RPC call timed out after 0 seconds');

        $client->call('rpc-queue', '{"op":"ping"}');
    }
}
