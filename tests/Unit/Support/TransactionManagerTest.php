<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Support;

use AMQPChannel;
use iamfarhad\LaravelRabbitMQ\Support\TransactionManager;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Mockery;

class TransactionManagerTest extends UnitTestCase
{
    private AMQPChannel $channel;

    private TransactionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Mockery::mock(AMQPChannel::class);
        $this->manager = new TransactionManager($this->channel);
    }

    public function testBeginTransaction(): void
    {
        $this->channel->shouldReceive('startTransaction')->once();

        $this->manager->begin();
        $this->assertTrue($this->manager->inTransaction());
    }

    public function testCommitTransaction(): void
    {
        $this->channel->shouldReceive('startTransaction')->once();
        $this->channel->shouldReceive('commitTransaction')->once();

        $this->manager->begin();
        $this->manager->commit();

        $this->assertFalse($this->manager->inTransaction());
    }

    public function testRollbackTransaction(): void
    {
        $this->channel->shouldReceive('startTransaction')->once();
        $this->channel->shouldReceive('rollbackTransaction')->once();

        $this->manager->begin();
        $this->manager->rollback();

        $this->assertFalse($this->manager->inTransaction());
    }

    public function testTransactionSuccess(): void
    {
        $this->channel->shouldReceive('startTransaction')->once();
        $this->channel->shouldReceive('commitTransaction')->once();

        $result = $this->manager->transaction(fn () => 'success');

        $this->assertEquals('success', $result);
        $this->assertFalse($this->manager->inTransaction());
    }

    public function testTransactionRollbackOnException(): void
    {
        $this->channel->shouldReceive('startTransaction')->once();
        $this->channel->shouldReceive('rollbackTransaction')->once();

        $this->expectException(\Exception::class);

        $this->manager->transaction(function () {
            throw new \Exception('Test exception');
        });

        $this->assertFalse($this->manager->inTransaction());
    }

    public function testCannotBeginTransactionTwice(): void
    {
        $this->channel->shouldReceive('startTransaction')->once();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Transaction already started');

        $this->manager->begin();
        $this->manager->begin();
    }

    public function testCannotCommitWithoutTransaction(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No active transaction to commit');

        $this->manager->commit();
    }

    public function testCannotRollbackWithoutTransaction(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No active transaction to rollback');

        $this->manager->rollback();
    }

    public function testTransactionPreservesOriginalCommitException(): void
    {
        $this->channel->shouldReceive('startTransaction')->once();
        $this->channel->shouldReceive('commitTransaction')
            ->once()
            ->andThrow(new \AMQPChannelException('broker rejected commit'));
        $this->channel->shouldNotReceive('rollbackTransaction');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to commit transaction: broker rejected commit');

        $this->manager->transaction(fn () => 'ok');
    }

    public function testRollbackFailureClearsInTransactionFlagAndThrows(): void
    {
        $this->channel->shouldReceive('startTransaction')->once();
        $this->channel->shouldReceive('rollbackTransaction')
            ->once()
            ->andThrow(new \AMQPChannelException('rollback failed'));

        $this->manager->begin();

        try {
            $this->manager->rollback();
            $this->fail('Expected rollback exception.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Failed to rollback transaction: rollback failed', $e->getMessage());
            $this->assertFalse($this->manager->inTransaction());
        }
    }

    public function testTransactionPreservesCallbackExceptionEvenWhenRollbackThrows(): void
    {
        $this->channel->shouldReceive('startTransaction')->once();
        $this->channel->shouldReceive('rollbackTransaction')
            ->once()
            ->andThrow(new \AMQPChannelException('rollback failed'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('callback boom');

        $this->manager->transaction(function (): void {
            throw new \RuntimeException('callback boom');
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
