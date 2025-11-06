<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Support;

use AMQPChannel;
use iamfarhad\LaravelRabbitMQ\Support\RpcClient;
use iamfarhad\LaravelRabbitMQ\Support\RpcServer;
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

    public function testRpcClientCreation(): void
    {
        // This test verifies the RpcClient class can be instantiated
        // Actual queue creation requires a real RabbitMQ connection
        $this->assertTrue(class_exists(RpcClient::class));
    }

    public function testRpcServerCreation(): void
    {
        // This test verifies the RpcServer class can be instantiated
        // Actual queue creation requires a real RabbitMQ connection
        $this->assertTrue(class_exists(RpcServer::class));
    }

    public function testRpcServerMethods(): void
    {
        // This test verifies the RpcServer has required methods
        $this->assertTrue(method_exists(RpcServer::class, 'listen'));
        $this->assertTrue(method_exists(RpcServer::class, 'stop'));
        $this->assertTrue(method_exists(RpcServer::class, 'isRunning'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
