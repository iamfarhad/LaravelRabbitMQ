<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Support;

use AMQPChannel;
use iamfarhad\LaravelRabbitMQ\Support\PublisherConfirms;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Mockery;

class PublisherConfirmsTest extends UnitTestCase
{
    private AMQPChannel $channel;

    private PublisherConfirms $confirms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Mockery::mock(AMQPChannel::class);
        $this->confirms = new PublisherConfirms($this->channel, 5);
    }

    public function testEnable(): void
    {
        $this->channel->shouldReceive('confirmSelect')->once();

        $this->confirms->enable();
        $this->assertTrue($this->confirms->isEnabled());
    }

    public function testDisable(): void
    {
        $this->channel->shouldReceive('confirmSelect')->once();

        $this->confirms->enable();
        $this->confirms->disable();

        $this->assertFalse($this->confirms->isEnabled());
    }

    public function testRegisterPendingConfirm(): void
    {
        $seqNo = $this->confirms->registerPendingConfirm('test-correlation-id');

        $this->assertEquals(1, $seqNo);
        $this->assertEquals(1, $this->confirms->getPendingCount());
    }

    public function testConfirmMessage(): void
    {
        $seqNo = $this->confirms->registerPendingConfirm('test-correlation-id');

        $correlationId = $this->confirms->confirmMessage($seqNo);

        $this->assertEquals('test-correlation-id', $correlationId);
        $this->assertEquals(0, $this->confirms->getPendingCount());
    }

    public function testClearPending(): void
    {
        $this->confirms->registerPendingConfirm('test-1');
        $this->confirms->registerPendingConfirm('test-2');

        $this->assertEquals(2, $this->confirms->getPendingCount());

        $this->confirms->clearPending();

        $this->assertEquals(0, $this->confirms->getPendingCount());
    }

    public function testWaitForConfirmsThrowsWhenNotEnabled(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Publisher confirms not enabled');

        $this->confirms->waitForConfirms();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
