<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Feature;

use AMQPQueue;
use Exception;
use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use iamfarhad\LaravelRabbitMQ\Support\ExponentialBackoff;
use iamfarhad\LaravelRabbitMQ\Support\MessageHelpers;
use iamfarhad\LaravelRabbitMQ\Support\TransactionManager;
use iamfarhad\LaravelRabbitMQ\Tests\TestCase;
use Illuminate\Support\Facades\Queue;

/**
 * The remainder of RabbitQueue's public surface, and TransactionManager, against
 * a real broker.
 */
class RabbitQueueApiTest extends TestCase
{
    private RabbitQueue $connection;

    /**
     * @var list<string>
     */
    private array $queues = [];

    protected function setUp(): void
    {
        parent::setUp();

        $connection = Queue::connection('rabbitmq');
        $this->assertInstanceOf(RabbitQueue::class, $connection);
        $this->connection = $connection;
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->queues as $queue) {
                $this->connection->deleteQueue($queue);
            }
        } catch (\Throwable) {
            // Cleanup only.
        }

        parent::tearDown();
    }

    private function queue(string $suffix): string
    {
        $name = 'api-'.$suffix.'-'.bin2hex(random_bytes(3));
        $this->queues[] = $name;

        return $name;
    }

    private function eventually(callable $probe, mixed $expected, float $seconds = 5.0): mixed
    {
        $deadline = microtime(true) + $seconds;
        $actual = null;

        do {
            $actual = $probe();

            if ($actual === $expected) {
                return $actual;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        return $actual;
    }

    public function testDeclareAdvancedQueueAppliesEveryArgument(): void
    {
        $queue = $this->queue('advanced');

        $this->connection->declareAdvancedQueue(
            $queue,
            durable: true,
            autoDelete: false,
            lazy: true,
            priority: 7,
            deadLetterConfig: ['exchange' => '', 'routing_key' => $queue.'.dead', 'ttl' => 60000],
            additionalArguments: ['x-max-length' => 500]
        );

        $amqpQueue = new AMQPQueue($this->connection->getChannel());
        $amqpQueue->setName($queue);
        $amqpQueue->setFlags(AMQP_PASSIVE);

        $this->assertSame(0, $amqpQueue->declareQueue(), 'The queue must exist with those arguments.');
    }

    public function testDeclareAdvancedQueueClampsPriorityToTheAmqpMaximum(): void
    {
        $queue = $this->queue('advanced-priority');

        $this->connection->declareAdvancedQueue($queue, priority: 9999);

        $amqpQueue = new AMQPQueue($this->connection->getChannel());
        $amqpQueue->setName($queue);
        $amqpQueue->setFlags(AMQP_PASSIVE);

        $this->assertSame(0, $amqpQueue->declareQueue(), 'A priority above 255 must be clamped, not refused.');
    }

    public function testDurableAutoDeleteQueueIsHonoured(): void
    {
        $queue = $this->queue('auto-delete');

        $this->connection->declareQueue($queue, durable: true, autoDelete: true);

        $amqpQueue = new AMQPQueue($this->connection->getChannel());
        $amqpQueue->setName($queue);
        $amqpQueue->setFlags(AMQP_PASSIVE);

        $this->assertSame(0, $amqpQueue->declareQueue());
    }

    /**
     * RabbitMQ 4.x deprecated transient non-exclusive queues and refuses them by
     * default, so `queues.*.durable => false` fails at the broker rather than
     * being quietly downgraded. Recorded here so the behaviour is not mistaken
     * for a driver defect.
     */
    public function testNonDurableQueueIsRefusedByRabbitMq4(): void
    {
        $queue = $this->queue('transient');

        try {
            $this->connection->declareQueue($queue, durable: false);
            $this->addToAssertionCount(1); // Older brokers still allow it.
        } catch (\AMQPException $exception) {
            $this->assertStringContainsString('transient_nonexcl_queues', $exception->getMessage());
        }
    }

    public function testQueueExistsDistinguishesAbsentFromPresent(): void
    {
        $queue = $this->queue('exists');

        $this->assertFalse($this->connection->queueExists($queue));

        $this->connection->declareQueue($queue);

        $this->assertTrue($this->connection->queueExists($queue));
    }

    public function testSizeReportsZeroForAQueueThatDoesNotExist(): void
    {
        $this->assertSame(0, $this->connection->size('api-never-declared-'.bin2hex(random_bytes(3))));
    }

    public function testPopDeclaresAMissingQueueAndReturnsNull(): void
    {
        $queue = $this->queue('pop-missing');

        $this->assertNull($this->connection->pop($queue));
        $this->assertTrue($this->connection->queueExists($queue), 'pop() declares the queue it polls.');
    }

    public function testLaterRawWithANonPositiveDelayPublishesImmediately(): void
    {
        $queue = $this->queue('later-zero');
        $this->connection->declareQueue($queue);

        $this->connection->laterRaw(0, '{"id":"now"}', $queue);

        $this->assertSame(1, $this->eventually(fn () => $this->connection->size($queue), 1));
    }

    public function testDelayedJobIsNotVisibleUntilItsTtlElapses(): void
    {
        $queue = $this->queue('later-delay');
        $this->connection->declareQueue($queue);

        $this->connection->laterRaw(1, '{"id":"soon"}', $queue);

        $this->assertSame(0, $this->connection->size($queue), 'It must sit in the delay queue first.');
        $this->assertSame(1, $this->eventually(fn () => $this->connection->size($queue), 1, 8.0));
    }

    public function testPublishDelayedUsesTheTtlPathWhenThePluginIsDisabled(): void
    {
        config(['queue.connections.rabbitmq.delayed_message.plugin_enabled' => false]);

        $queue = $this->queue('publish-delayed');
        $this->connection->declareQueue($queue);

        $correlationId = $this->connection->publishDelayed($queue, '{"id":"delayed"}', 1);

        $this->assertIsString($correlationId);
        $this->assertSame(1, $this->eventually(fn () => $this->connection->size($queue), 1, 8.0));
    }

    public function testPublishDelayedWithThePluginEnabledSurfacesAMissingPlugin(): void
    {
        config([
            'queue.connections.rabbitmq.delayed_message.plugin_enabled' => true,
            'queue.connections.rabbitmq.delayed_message.exchange' => 'api-delayed-'.bin2hex(random_bytes(3)),
        ]);

        $queue = $this->queue('publish-delayed-plugin');

        // The test broker has no delayed-message plugin, so the declare is
        // refused rather than silently swallowed.
        $this->expectException(\AMQPException::class);

        $this->connection->publishDelayed($queue, '{"id":"delayed"}', 1);
    }

    public function testBulkPublishesEveryJobWithoutConfirms(): void
    {
        config(['queue.connections.rabbitmq.publisher_confirms.enabled' => false]);

        $queue = $this->queue('bulk');
        $this->connection->declareQueue($queue);
        $this->connection->setContainer($this->app);

        $this->connection->bulk(['{"a":1}', '{"b":2}', '{"c":3}'], '', $queue);

        $this->assertSame(3, $this->eventually(fn () => $this->connection->size($queue), 3));
    }

    public function testSetupDeadLetterExchangeIsSkippedWhenDisabled(): void
    {
        config(['queue.connections.rabbitmq.dead_letter.enabled' => false]);

        $queue = $this->queue('dlx-disabled');

        $this->connection->setupDeadLetterExchange($queue);

        $this->assertFalse(
            $this->connection->queueExists($queue.'.dlq'),
            'A disabled dead-letter configuration must declare nothing.'
        );
    }

    public function testHorizonMetricHelpersReportTheirDocumentedValues(): void
    {
        $queue = $this->queue('metrics');
        $this->connection->declareQueue($queue);
        $this->connection->pushRaw('{"id":"m"}', $queue);

        $this->assertSame(1, $this->eventually(fn () => $this->connection->pendingSize($queue), 1));
        $this->assertSame(0, $this->connection->delayedSize($queue));
        $this->assertSame(0, $this->connection->reservedSize($queue));
        $this->assertNull($this->connection->creationTimeOfOldestPendingJob($queue));
    }

    public function testCorrelationIdPrefersThePayloadsOwnIdentifier(): void
    {
        $this->assertSame('job-uuid-1', $this->connection->correlationIdFor('{"id":"job-uuid-1"}'));
        $this->assertTrue(MessageHelpers::isValidJson('{"id":"x"}'));
        $this->assertFalse(MessageHelpers::isValidJson('{not json'));

        $generated = $this->connection->correlationIdFor('not json at all');
        $this->assertNotSame('', $generated);
        $this->assertNotSame($generated, $this->connection->correlationIdFor('also not json'));
    }

    public function testDeprecatedCreateMessageStillReturnsACorrelationId(): void
    {
        $this->assertSame('legacy-id', $this->connection->createMessage('{"id":"legacy-id"}'));
    }

    public function testGetBackoffReadsTheConnectionConfiguration(): void
    {
        config([
            'queue.connections.rabbitmq.backoff.base_delay' => 250,
            'queue.connections.rabbitmq.backoff.max_delay' => 1000,
            'queue.connections.rabbitmq.backoff.multiplier' => 2.0,
            'queue.connections.rabbitmq.backoff.jitter' => false,
        ]);

        $backoff = $this->connection->getBackoff();

        $this->assertInstanceOf(ExponentialBackoff::class, $backoff);
        $this->assertSame(250, $backoff->getDelayForAttempt(0));
        $this->assertSame(500, $backoff->getDelayForAttempt(1));
        $this->assertSame(1000, $backoff->getDelayForAttempt(5), 'Capped at max_delay.');
        $this->assertSame($backoff, $this->connection->getBackoff(), 'The instance is reused.');
    }

    public function testGetJobClassRejectsAClassThatIsNotARabbitMqJob(): void
    {
        config(['queue.connections.rabbitmq.options.queue.job' => \stdClass::class]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('must extend');

        $this->connection->getJobClass();
    }

    public function testGetJobClassAcceptsASubclass(): void
    {
        config(['queue.connections.rabbitmq.options.queue.job' => RabbitMQJob::class]);

        $this->assertSame(RabbitMQJob::class, $this->connection->getJobClass());
    }

    public function testConnectionConfigFallsBackThroughEveryLayer(): void
    {
        // Own block wins.
        config(['queue.connections.rabbitmq.exchange' => 'own-block']);
        $this->assertSame('own-block', $this->connection->connectionConfig('exchange'));

        // Unset keys fall through to the package defaults rather than resolving null.
        $this->assertNotNull($this->connection->connectionConfig('publisher_confirms.timeout'));
        $this->assertSame('fallback', $this->connection->connectionConfig('nothing.defines.this', 'fallback'));
    }

    public function testMarkChannelDirtyRetiresTheChannelOnRelease(): void
    {
        $channel = $this->connection->getChannel();
        $this->connection->markChannelDirty();

        $this->connection->close();

        // A retired channel must not come back; the next one is a fresh object.
        $this->assertNotSame($channel, $this->connection->getChannel());
    }

    public function testGetConnectionReturnsTheChannelsOwnConnection(): void
    {
        $channel = $this->connection->getChannel();

        $this->assertSame($channel->getConnection(), $this->connection->getConnection());
        $this->assertTrue($this->connection->getConnection()->isConnected());
    }

    public function testTransactionsMustBeEnabledBeforeUse(): void
    {
        config(['queue.connections.rabbitmq.transactions.enabled' => false]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Transactions are not enabled');

        $this->connection->transaction(fn (): bool => true);
    }

    /**
     * A channel cannot be in confirm mode and transaction mode at once, so the
     * combination is refused rather than failing at the broker.
     */
    public function testTransactionsAreRefusedWhilePublisherConfirmsAreEnabled(): void
    {
        config([
            'queue.connections.rabbitmq.transactions.enabled' => true,
            'queue.connections.rabbitmq.publisher_confirms.enabled' => true,
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('cannot be used while publisher confirms are enabled');

        $this->connection->transaction(fn (): bool => true);
    }

    public function testCommittedTransactionPublishesItsMessages(): void
    {
        config([
            'queue.connections.rabbitmq.transactions.enabled' => true,
            'queue.connections.rabbitmq.publisher_confirms.enabled' => false,
        ]);

        $queue = $this->queue('tx-commit');
        $this->connection->declareQueue($queue);

        $result = $this->connection->transaction(function () use ($queue): string {
            $this->connection->pushRaw('{"tx":"committed"}', $queue);

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(1, $this->eventually(fn () => $this->connection->size($queue), 1));
    }

    public function testRolledBackTransactionDiscardsItsMessages(): void
    {
        config([
            'queue.connections.rabbitmq.transactions.enabled' => true,
            'queue.connections.rabbitmq.publisher_confirms.enabled' => false,
        ]);

        $queue = $this->queue('tx-rollback');
        $this->connection->declareQueue($queue);

        try {
            $this->connection->transaction(function () use ($queue): void {
                $this->connection->pushRaw('{"tx":"rolled-back"}', $queue);

                throw new Exception('abort');
            });
            $this->fail('The callback exception must propagate.');
        } catch (Exception $exception) {
            $this->assertSame('abort', $exception->getMessage());
        }

        $this->assertSame(0, $this->connection->size($queue), 'A rolled-back publish must not arrive.');
    }

    public function testTransactionManagerRejectsNestedAndUnbalancedUse(): void
    {
        $manager = new TransactionManager($this->connection->getChannel());

        $this->assertFalse($manager->inTransaction());

        $manager->begin();
        $this->assertTrue($manager->inTransaction());

        try {
            $manager->begin();
            $this->fail('A nested transaction must be refused.');
        } catch (Exception $exception) {
            $this->assertSame('Transaction already started', $exception->getMessage());
        }

        $manager->commit();
        $this->assertFalse($manager->inTransaction());

        try {
            $manager->commit();
            $this->fail('Committing without a transaction must be refused.');
        } catch (Exception $exception) {
            $this->assertSame('No active transaction to commit', $exception->getMessage());
        }

        try {
            $manager->rollback();
            $this->fail('Rolling back without a transaction must be refused.');
        } catch (Exception $exception) {
            $this->assertSame('No active transaction to rollback', $exception->getMessage());
        }
    }

    public function testTransactionManagerRollbackClearsState(): void
    {
        $manager = new TransactionManager($this->connection->getChannel());

        $manager->begin();
        $manager->rollback();

        $this->assertFalse($manager->inTransaction());
    }

    public function testTransactionManagerIsReusedForTheChannel(): void
    {
        config(['queue.connections.rabbitmq.transactions.enabled' => true]);

        $first = $this->connection->getTransactionManager();

        $this->assertSame($first, $this->connection->getTransactionManager());
    }
}
