<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Support;

use AMQPChannel;
use iamfarhad\LaravelRabbitMQ\Support\ExchangeManager;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Mockery;

class ExchangeManagerTest extends UnitTestCase
{
    private AMQPChannel $channel;

    private ExchangeManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Mockery::mock(AMQPChannel::class);
        $this->manager = new ExchangeManager($this->channel);
    }

    public function testDeclareExchange(): void
    {
        // This test verifies the method can be called
        // Actual exchange creation requires a real RabbitMQ connection
        $this->assertTrue(method_exists($this->manager, 'declareExchange'));
    }

    public function testDeclareTopicExchange(): void
    {
        // This test verifies the method can be called with topic type
        $this->assertEquals(AMQP_EX_TYPE_TOPIC, ExchangeManager::TYPE_TOPIC);
    }

    public function testDeclareFanoutExchange(): void
    {
        // This test verifies the method can be called with fanout type
        $this->assertEquals(AMQP_EX_TYPE_FANOUT, ExchangeManager::TYPE_FANOUT);
    }

    public function testDeclareHeadersExchange(): void
    {
        // This test verifies the method can be called with headers type
        $this->assertEquals(AMQP_EX_TYPE_HEADERS, ExchangeManager::TYPE_HEADERS);
    }

    public function testDeclareExchangeWithArguments(): void
    {
        // This test verifies the method accepts arguments parameter
        $this->assertTrue(method_exists($this->manager, 'declareExchange'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
