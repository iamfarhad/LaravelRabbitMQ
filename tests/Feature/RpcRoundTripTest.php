<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Feature;

use AMQPEnvelope;
use AMQPExchange;
use AMQPQueue;
use Exception;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use iamfarhad\LaravelRabbitMQ\Support\RpcClient;
use iamfarhad\LaravelRabbitMQ\Support\RpcServer;
use iamfarhad\LaravelRabbitMQ\Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use ReflectionMethod;

/**
 * RPC against a real broker.
 *
 * Client and server both block — the client polling for a reply, the server
 * polling for a request — so a single process cannot run `call()` and `listen()`
 * concurrently. Each half is therefore driven against real broker state instead:
 * the server consumes a request that is already queued, and the client's wait is
 * exercised against a reply that is already sitting in its callback queue. That
 * covers the same code paths without forking.
 */
class RpcRoundTripTest extends TestCase
{
    private RabbitQueue $connection;

    private string $rpcQueue;

    protected function setUp(): void
    {
        parent::setUp();

        $connection = Queue::connection('rabbitmq');
        $this->assertInstanceOf(RabbitQueue::class, $connection);

        $this->connection = $connection;
        $this->rpcQueue = 'rpc-test-'.bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        try {
            $this->connection->deleteQueue($this->rpcQueue);
        } catch (\Throwable) {
            // Cleanup only.
        }

        parent::tearDown();
    }

    private function publishRequest(string $replyTo, string $correlationId, string $body = '{"op":"ping"}'): void
    {
        $exchange = new AMQPExchange($this->connection->getChannel());
        $exchange->setName('');
        $exchange->publish($body, $this->rpcQueue, AMQP_NOPARAM, [
            'correlation_id' => $correlationId,
            'reply_to' => $replyTo,
            'delivery_mode' => 2,
            'content_type' => 'application/json',
            'headers' => ['tenant' => 'acme'],
        ]);
    }

    private function drain(string $queue, float $seconds = 2.0): ?AMQPEnvelope
    {
        $amqpQueue = new AMQPQueue($this->connection->getChannel());
        $amqpQueue->setName($queue);
        $deadline = microtime(true) + $seconds;

        do {
            $envelope = $amqpQueue->get(AMQP_AUTOACK);

            if ($envelope instanceof AMQPEnvelope) {
                return $envelope;
            }

            usleep(25_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    public function testServerAnswersAQueuedRequest(): void
    {
        $replyQueue = 'rpc-reply-'.bin2hex(random_bytes(3));
        $this->connection->declareQueue($replyQueue);

        $server = new RpcServer($this->connection->getChannel(), $this->rpcQueue);
        $this->publishRequest($replyQueue, 'corr-1');

        $seen = [];
        $server->listen(function (string $message, array $headers) use ($server, &$seen): string {
            $seen = ['message' => $message, 'headers' => $headers];
            $server->stop();

            return '{"pong":true}';
        });

        $this->assertFalse($server->isRunning(), 'stop() must end the listen loop.');
        $this->assertSame('{"op":"ping"}', $seen['message']);
        $this->assertSame('acme', $seen['headers']['tenant'] ?? null);

        $reply = $this->drain($replyQueue);
        $this->assertNotNull($reply, 'The server must publish a reply to reply_to.');
        $this->assertSame('{"pong":true}', $reply->getBody());
        $this->assertSame('corr-1', $reply->getCorrelationId());

        $this->connection->deleteQueue($replyQueue);
    }

    /**
     * A request with no reply_to or correlation_id cannot be answered. The guard
     * is asserted directly, because listen() can only be left from inside the
     * callback and this path never reaches one.
     */
    public function testServerRejectsARequestItCannotReplyTo(): void
    {
        $server = new RpcServer($this->connection->getChannel(), $this->rpcQueue);

        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getCorrelationId')->andReturn('corr-1');
        $envelope->shouldReceive('getReplyTo')->andReturn('');

        $processRequest = new ReflectionMethod(RpcServer::class, 'processRequest');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid RPC request: missing reply_to or correlation_id');

        $processRequest->invoke($server, $envelope, fn (): string => '{}');
    }

    public function testServerRejectsARequestWithoutACorrelationId(): void
    {
        $server = new RpcServer($this->connection->getChannel(), $this->rpcQueue);

        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getCorrelationId')->andReturn(null);
        $envelope->shouldReceive('getReplyTo')->andReturn('somewhere');

        $processRequest = new ReflectionMethod(RpcServer::class, 'processRequest');

        $this->expectException(Exception::class);

        $processRequest->invoke($server, $envelope, fn (): string => '{}');
    }

    /**
     * A callback that throws must have its delivery nacked without requeue, or an
     * endlessly redelivered bad request would spin the loop forever.
     */
    public function testServerNacksADeliveryWhoseHandlerThrows(): void
    {
        $replyQueue = 'rpc-reply-'.bin2hex(random_bytes(3));
        $this->connection->declareQueue($replyQueue);

        $server = new RpcServer($this->connection->getChannel(), $this->rpcQueue);
        $this->publishRequest($replyQueue, 'corr-boom');

        $invoked = 0;
        $server->listen(function () use ($server, &$invoked): string {
            $invoked++;
            // Leave the loop first, then fail: listen() can only be exited from
            // inside the callback.
            $server->stop();

            throw new Exception('handler exploded');
        });

        $this->assertSame(1, $invoked);
        $this->assertNull($this->drain($replyQueue, 0.5), 'A failed handler must not publish a reply.');
        $this->assertSame(0, $this->connection->size($this->rpcQueue), 'The delivery must be nacked, not requeued.');

        $this->connection->deleteQueue($replyQueue);
    }

    public function testClientDeclaresAServerNamedCallbackQueue(): void
    {
        $client = new RpcClient($this->connection->getChannel(), 5);

        $this->assertStringStartsWith('amq.gen-', $client->getCallbackQueueName());
    }

    public function testClientHonoursTheConfiguredCallbackQueuePrefix(): void
    {
        $client = new RpcClient($this->connection->getChannel(), 5, 'rpc_callback_');

        $this->assertStringStartsWith('rpc_callback_', $client->getCallbackQueueName());
    }

    public function testClientPublishesARequestCarryingReplyToAndCorrelationId(): void
    {
        $client = new RpcClient($this->connection->getChannel(), 1, 'rpc_probe_');
        $this->connection->declareQueue($this->rpcQueue);

        // call() blocks until the timeout because nothing answers; the request it
        // published is still on the queue afterwards.
        try {
            $client->call($this->rpcQueue, '{"op":"ping"}', ['tenant' => 'acme']);
            $this->fail('An unanswered call must time out.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('timed out', $exception->getMessage());
        }

        $request = $this->drain($this->rpcQueue);

        $this->assertNotNull($request, 'The request must have been published.');
        $this->assertSame('{"op":"ping"}', $request->getBody());
        $this->assertSame($client->getCallbackQueueName(), $request->getReplyTo());
        $this->assertStringStartsWith('rpc_', (string) $request->getCorrelationId());
        $this->assertSame('acme', $request->getHeader('tenant'));
    }

    /**
     * The wait loop reads the callback queue, buffers replies by correlation ID
     * and returns the one asked for — exercised here against a reply that is
     * already on the broker, which is what a real server would have produced.
     */
    public function testClientReturnsABufferedReplyForItsCorrelationId(): void
    {
        $client = new RpcClient($this->connection->getChannel(), 5);

        $exchange = new AMQPExchange($this->connection->getChannel());
        $exchange->setName('');

        foreach (['other-call' => '{"not":"mine"}', 'mine' => '{"pong":true}'] as $correlationId => $body) {
            $exchange->publish($body, $client->getCallbackQueueName(), AMQP_NOPARAM, [
                'correlation_id' => $correlationId,
                'delivery_mode' => 2,
            ]);
        }

        $waitForResponse = new ReflectionMethod(RpcClient::class, 'waitForResponse');
        $response = $waitForResponse->invoke($client, 'mine');

        $this->assertSame('{"pong":true}', $response, 'The reply matching the correlation ID must be returned.');

        // The unrelated reply stays buffered rather than being discarded.
        $this->assertSame('{"not":"mine"}', $waitForResponse->invoke($client, 'other-call'));
    }

    public function testClientTimesOutWhenNoReplyArrives(): void
    {
        $client = new RpcClient($this->connection->getChannel(), 0);

        $waitForResponse = new ReflectionMethod(RpcClient::class, 'waitForResponse');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('RPC call timed out after 0 seconds');

        $waitForResponse->invoke($client, 'never-arrives');
    }

    public function testDriverRpcCallRequiresRpcToBeEnabled(): void
    {
        config(['queue.connections.rabbitmq.rpc.enabled' => false]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('RPC is not enabled in configuration');

        $this->connection->rpcCall($this->rpcQueue, '{"op":"ping"}');
    }

    public function testDriverBuildsAnRpcClientWhenEnabled(): void
    {
        config([
            'queue.connections.rabbitmq.rpc.enabled' => true,
            'queue.connections.rabbitmq.rpc.timeout' => 1,
            'queue.connections.rabbitmq.rpc.callback_queue_prefix' => 'rpc_driver_',
        ]);

        $client = $this->connection->getRpcClient();

        $this->assertStringStartsWith('rpc_driver_', $client->getCallbackQueueName());
        $this->assertSame($client, $this->connection->getRpcClient(), 'The client is reused for the channel.');
    }
}
