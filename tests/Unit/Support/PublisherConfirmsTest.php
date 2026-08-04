<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Support;

use AMQPChannel;
use AMQPQueueException;
use iamfarhad\LaravelRabbitMQ\Support\PublisherConfirms;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Mockery;

class PublisherConfirmsTest extends UnitTestCase
{
    private AMQPChannel $channel;

    private PublisherConfirms $confirms;

    /**
     * The ACK callback handed to ext-amqp by enable().
     *
     * @var callable|null
     */
    private $ackCallback;

    /**
     * The NACK callback handed to ext-amqp by enable().
     *
     * @var callable|null
     */
    private $nackCallback;

    /**
     * The basic.return callback handed to ext-amqp by enable().
     *
     * @var callable|null
     */
    private $returnCallback;

    private int $callbackRegistrations = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Mockery::mock(AMQPChannel::class);
        $this->confirms = new PublisherConfirms($this->channel, 5);
    }

    public function testEnable(): void
    {
        $this->expectConfirmCallbackRegistration();
        $this->channel->shouldReceive('confirmSelect')->once();

        $this->confirms->enable();
        $this->assertTrue($this->confirms->isEnabled());
    }

    public function testDisable(): void
    {
        $this->expectConfirmCallbackRegistration();
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

    /**
     * Regression for #25: without registered callbacks ext-amqp aborts the
     * publish with "Unhandled basic.ack method from server received.", so the
     * callbacks must be installed before confirm mode is switched on.
     */
    public function testEnableRegistersConfirmCallbacksBeforeEnablingConfirmMode(): void
    {
        $registrationOrder = [];

        $this->channel->shouldReceive('setConfirmCallback')
            ->once()
            ->andReturnUsing(function (callable $ack, callable $nack) use (&$registrationOrder): void {
                $registrationOrder[] = 'setConfirmCallback';
                $this->ackCallback = $ack;
                $this->nackCallback = $nack;
            });

        $this->channel->shouldReceive('setReturnCallback')
            ->once()
            ->andReturnUsing(function (callable $return) use (&$registrationOrder): void {
                $registrationOrder[] = 'setReturnCallback';
                $this->returnCallback = $return;
            });

        $this->channel->shouldReceive('confirmSelect')
            ->once()
            ->andReturnUsing(function () use (&$registrationOrder): void {
                $registrationOrder[] = 'confirmSelect';
            });

        $this->confirms->enable();

        $this->assertSame(['setConfirmCallback', 'setReturnCallback', 'confirmSelect'], $registrationOrder);
        $this->assertIsCallable($this->ackCallback);
        $this->assertIsCallable($this->nackCallback);
        $this->assertIsCallable($this->returnCallback);
    }

    /**
     * Regression for #25: a broker ACK is consumed by the callback and the wait
     * completes successfully instead of raising the unhandled-method error.
     */
    public function testBrokerAckLetsWaitForConfirmsSucceed(): void
    {
        $this->enableConfirms();
        $this->channel->shouldReceive('waitForConfirm')->once();

        ($this->ackCallback)(1, false);

        $this->assertTrue($this->confirms->waitForConfirms());
    }

    public function testBrokerNackIsSurfacedAsFailedConfirmation(): void
    {
        $this->enableConfirms();
        $this->channel->shouldReceive('waitForConfirm')->once();

        ($this->nackCallback)(7, false, false);

        $this->assertTrue($this->confirms->hasPendingNack());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Message was nacked by broker: 7');

        $this->confirms->waitForConfirms();
    }

    public function testNackReportsTheCorrelationIdOfTheNackedDelivery(): void
    {
        $this->enableConfirms();
        $this->channel->shouldReceive('waitForConfirm')->once();

        $seqNo = $this->confirms->registerPendingConfirm('correlation-a');
        ($this->nackCallback)($seqNo, false, false);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Message was nacked by broker: correlation-a');

        $this->confirms->waitForConfirms();
    }

    /**
     * Regression for #26: a NACK may only fail the confirmation it belongs to.
     * On a reused (long-lived) publisher the following ACK must succeed.
     */
    public function testNackStateIsSingleUseAndDoesNotLeakIntoALaterAck(): void
    {
        $this->enableConfirms();
        $this->channel->shouldReceive('waitForConfirm')->twice();

        ($this->nackCallback)(1, false, false);

        try {
            $this->confirms->waitForConfirms();
            $this->fail('The NACKed confirmation should have failed.');
        } catch (\Exception $e) {
            $this->assertSame('Message was nacked by broker: 1', $e->getMessage());
        }

        $this->assertFalse($this->confirms->hasPendingNack());

        ($this->ackCallback)(2, false);

        $this->assertTrue($this->confirms->waitForConfirms());
    }

    public function testNackStateIsClearedWhenTheWaitItselfFails(): void
    {
        $this->enableConfirms();
        $this->channel->shouldReceive('waitForConfirm')
            ->twice()
            ->andReturnUsing(
                function (): void {
                    throw new AMQPQueueException('Timeout waiting for confirms');
                },
                function (): void {}
            );

        ($this->nackCallback)(1, false, false);

        try {
            $this->confirms->waitForConfirms();
            $this->fail('A failing wait should surface an exception.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Failed to wait for confirms', $e->getMessage());
        }

        $this->assertFalse($this->confirms->hasPendingNack());
        $this->assertTrue($this->confirms->waitForConfirms());
    }

    public function testMultipleAckConfirmsEveryDeliveryUpToTheDeliveryTag(): void
    {
        $this->enableConfirms();

        $this->confirms->registerPendingConfirm('correlation-1');
        $this->confirms->registerPendingConfirm('correlation-2');
        $this->confirms->registerPendingConfirm('correlation-3');

        // Outstanding deliveries remain, so ext-amqp must keep waiting.
        $this->assertTrue(($this->ackCallback)(2, true));
        $this->assertSame(1, $this->confirms->getPendingCount());

        // Nothing left outstanding: the wait has to be released.
        $this->assertFalse(($this->ackCallback)(3, true));
        $this->assertSame(0, $this->confirms->getPendingCount());
    }

    public function testMultipleNackReportsEveryDeliveryUpToTheDeliveryTag(): void
    {
        $this->enableConfirms();
        $this->channel->shouldReceive('waitForConfirm')->once();

        $this->confirms->registerPendingConfirm('correlation-1');
        $this->confirms->registerPendingConfirm('correlation-2');

        $this->assertFalse(($this->nackCallback)(2, true, false));
        $this->assertSame(0, $this->confirms->getPendingCount());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Message was nacked by broker: correlation-1, correlation-2');

        $this->confirms->waitForConfirms();
    }

    public function testRepeatedEnableRegistersCallbacksOnlyOnce(): void
    {
        $this->enableConfirms();

        $this->confirms->enable();
        $this->confirms->enable();

        $this->assertSame(1, $this->callbackRegistrations);
        $this->assertTrue($this->confirms->isEnabled());
    }

    public function testReEnablingAfterDisableDoesNotStackCallbacksOrKeepStaleNackState(): void
    {
        $this->enableConfirms();
        $this->channel->shouldReceive('confirmSelect')->once();
        $this->channel->shouldReceive('waitForConfirm')->once();

        ($this->nackCallback)(1, false, false);

        $this->confirms->disable();
        $this->assertFalse($this->confirms->hasPendingNack());

        $this->confirms->enable();
        $this->assertSame(1, $this->callbackRegistrations);

        ($this->ackCallback)(1, false);

        $this->assertTrue($this->confirms->waitForConfirms());
    }

    public function testClearPendingAlsoDropsStoredNackState(): void
    {
        $this->enableConfirms();
        $this->channel->shouldReceive('waitForConfirm')->once();

        ($this->nackCallback)(1, false, false);

        $this->confirms->clearPending();

        $this->assertFalse($this->confirms->hasPendingNack());
        $this->assertTrue($this->confirms->waitForConfirms());
    }

    /**
     * An unroutable mandatory publish is ACKed by RabbitMQ after being returned,
     * so basic.return is the only signal that the message went nowhere.
     */
    public function testUnroutableReturnIsSurfacedAsFailedConfirmation(): void
    {
        $this->enableConfirms();
        $this->channel->shouldReceive('waitForConfirm')->once();

        $this->confirms->registerPendingConfirm('correlation-a');
        ($this->returnCallback)(312, 'NO_ROUTE', 'events', 'orders');

        $this->assertTrue($this->confirms->hasPendingReturn());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(
            'Message was returned as unroutable by broker: 312 NO_ROUTE (exchange [events], routing key [orders])'
        );

        $this->confirms->waitForConfirms();
    }

    public function testReturnStateIsSingleUseAndDoesNotLeakIntoALaterAck(): void
    {
        $this->enableConfirms();
        $this->channel->shouldReceive('waitForConfirm')->twice();

        ($this->returnCallback)(312, 'NO_ROUTE', '', 'missing');

        try {
            $this->confirms->waitForConfirms();
            $this->fail('The returned message should have failed the confirmation.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('unroutable', $e->getMessage());
        }

        $this->assertFalse($this->confirms->hasPendingReturn());

        ($this->ackCallback)(1, false);

        $this->assertTrue($this->confirms->waitForConfirms());
    }

    /**
     * The ACK callback keeps the wait alive while anything is still outstanding,
     * which is what lets a batch be confirmed with a single wait.
     */
    public function testAckKeepsWaitingWhileDeliveriesAreStillOutstanding(): void
    {
        $this->enableConfirms();

        $this->confirms->registerPendingConfirm('correlation-a');
        $this->confirms->registerPendingConfirm('correlation-b');

        $this->assertSame(2, $this->confirms->getPendingCount());
        $this->assertTrue(($this->ackCallback)(1, false), 'One delivery is still outstanding.');
        $this->assertSame(1, $this->confirms->getPendingCount());
        $this->assertFalse(($this->ackCallback)(2, false), 'Nothing is outstanding any more.');
        $this->assertSame(0, $this->confirms->getPendingCount());
    }

    /**
     * A raw publish elsewhere on the channel shifts the broker's sequence. The
     * ledger must still drain, or every later wait would block until timeout.
     */
    public function testUnknownDeliveryTagStillResolvesTheOldestOutstandingDelivery(): void
    {
        $this->enableConfirms();

        $this->confirms->registerPendingConfirm('correlation-a');

        $this->assertFalse(($this->ackCallback)(9999, false));
        $this->assertSame(0, $this->confirms->getPendingCount());
    }

    /**
     * Enable confirm mode while capturing the callbacks ext-amqp would invoke.
     */
    private function enableConfirms(): void
    {
        $this->expectConfirmCallbackRegistration();
        $this->channel->shouldReceive('confirmSelect')->once();

        $this->confirms->enable();
    }

    private function expectConfirmCallbackRegistration(): void
    {
        $this->channel->shouldReceive('setConfirmCallback')
            ->andReturnUsing(function (callable $ack, callable $nack): void {
                $this->callbackRegistrations++;
                $this->ackCallback = $ack;
                $this->nackCallback = $nack;
            });

        $this->channel->shouldReceive('setReturnCallback')
            ->andReturnUsing(function (callable $return): void {
                $this->returnCallback = $return;
            });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
