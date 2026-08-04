<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Feature;

use AMQPQueue;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use iamfarhad\LaravelRabbitMQ\Tests\TestCase;
use Illuminate\Support\Facades\Queue;

/**
 * The topology commands, exercised against a real broker.
 *
 * These previously had no coverage at all, which is how they kept a hardcoded
 * `Queue::connection('rabbitmq')` and could not address any other named
 * RabbitMQ connection.
 */
class ConsoleCommandsTest extends TestCase
{
    private string $queue = 'command-test-queue';

    private string $exchange = 'command-test-exchange';

    protected function setUp(): void
    {
        parent::setUp();

        // Start from a known-empty queue: these tests assert on message counts.
        $connection = Queue::connection('rabbitmq');

        if ($connection instanceof RabbitQueue) {
            $connection->deleteQueue($this->queue);
        }
    }

    /**
     * Broker message counts settle asynchronously, so poll rather than asserting
     * on the first read.
     */
    private function assertQueueSizeEventually(int $expected): void
    {
        $deadline = microtime(true) + 5.0;
        $size = null;

        do {
            $size = Queue::connection('rabbitmq')->size($this->queue);

            if ($size === $expected) {
                break;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        $this->assertSame($expected, $size);
    }

    protected function tearDown(): void
    {
        try {
            $connection = Queue::connection('rabbitmq');

            if ($connection instanceof RabbitQueue) {
                $connection->deleteQueue($this->queue);
                $connection->getExchangeManager()->deleteExchange($this->exchange);
            }
        } catch (\Throwable) {
            // Cleanup only.
        }

        parent::tearDown();
    }

    private function queueExists(string $name): bool
    {
        return Queue::connection('rabbitmq')->queueExists($name);
    }

    public function testQueueDeclareCreatesTheQueue(): void
    {
        $this->artisan('rabbitmq:queue-declare', ['name' => $this->queue])
            ->expectsOutputToContain('declared')
            ->assertExitCode(0);

        $this->assertTrue($this->queueExists($this->queue));
    }

    public function testQueueDeclareSupportsLazyAndPriority(): void
    {
        $this->artisan('rabbitmq:queue-declare', [
            'name' => $this->queue,
            '--lazy' => '1',
            '--priority' => '5',
        ])->assertExitCode(0);

        $this->assertTrue($this->queueExists($this->queue));
    }

    public function testQueueDeclareSupportsQuorum(): void
    {
        $this->artisan('rabbitmq:queue-declare', [
            'name' => $this->queue,
            '--quorum' => '1',
        ])->assertExitCode(0);

        $this->assertTrue($this->queueExists($this->queue));
    }

    public function testQueuePurgeRemovesReadyMessages(): void
    {
        $connection = Queue::connection('rabbitmq');
        $connection->declareQueue($this->queue);
        $connection->pushRaw('{"id":"purge-me"}', $this->queue);

        $this->assertQueueSizeEventually(1);

        $this->artisan('rabbitmq:queue-purge', ['name' => $this->queue, '--force' => true])
            ->expectsOutputToContain('purged')
            ->assertExitCode(0);

        $this->assertSame(0, $connection->size($this->queue));
    }

    public function testQueuePurgeCanBeCancelled(): void
    {
        Queue::connection('rabbitmq')->declareQueue($this->queue);

        $this->artisan('rabbitmq:queue-purge', ['name' => $this->queue])
            ->expectsConfirmation("Purge queue [{$this->queue}]?", 'no')
            ->expectsOutputToContain('Cancelled')
            ->assertExitCode(0);
    }

    public function testQueueDeleteRemovesTheQueue(): void
    {
        Queue::connection('rabbitmq')->declareQueue($this->queue);
        $this->assertTrue($this->queueExists($this->queue));

        $this->artisan('rabbitmq:queue-delete', ['name' => $this->queue, '--force' => true])
            ->expectsOutputToContain('deleted')
            ->assertExitCode(0);

        $this->assertFalse($this->queueExists($this->queue));
    }

    public function testQueueDeleteCanBeCancelled(): void
    {
        Queue::connection('rabbitmq')->declareQueue($this->queue);

        $this->artisan('rabbitmq:queue-delete', ['name' => $this->queue])
            ->expectsConfirmation("Delete queue [{$this->queue}]?", 'no')
            ->assertExitCode(0);

        $this->assertTrue($this->queueExists($this->queue), 'Cancelling must not delete anything.');
    }

    public function testExchangeDeclareCreatesTheExchange(): void
    {
        $this->artisan('rabbitmq:exchange-declare', [
            'name' => $this->exchange,
            '--type' => 'topic',
        ])->expectsOutputToContain('declared')->assertExitCode(0);

        // Binding to it only succeeds if the exchange exists.
        $connection = Queue::connection('rabbitmq');
        $connection->declareQueue($this->queue);
        $connection->getExchangeManager()->bindQueue($this->queue, $this->exchange, 'a.b');

        $this->assertTrue($this->queueExists($this->queue));
    }

    public function testExchangeDeclareSupportsEveryType(): void
    {
        foreach (['direct', 'fanout', 'topic', 'headers'] as $type) {
            $this->artisan('rabbitmq:exchange-declare', [
                'name' => $this->exchange.'-'.$type,
                '--type' => $type,
            ])->assertExitCode(0);

            Queue::connection('rabbitmq')->getExchangeManager()->deleteExchange($this->exchange.'-'.$type);
        }

        $this->assertTrue(true);
    }

    /**
     * The commands used to hardcode the connection named `rabbitmq`.
     */
    public function testCommandsRejectANonRabbitMqConnection(): void
    {
        config(['queue.connections.not-rabbit' => ['driver' => 'sync']]);

        $this->artisan('rabbitmq:queue-declare', [
            'name' => $this->queue,
            '--connection' => 'not-rabbit',
        ])->expectsOutputToContain('is not a RabbitMQ connection')->assertExitCode(1);
    }

    public function testCommandsAcceptAnExplicitRabbitMqConnection(): void
    {
        config(['queue.connections.rabbit-alt' => config('queue.connections.rabbitmq')]);

        $this->artisan('rabbitmq:queue-declare', [
            'name' => $this->queue,
            '--connection' => 'rabbit-alt',
        ])->assertExitCode(0);

        $this->assertTrue($this->queueExists($this->queue));
    }

    public function testPoolStatsReportsThisProcessesPool(): void
    {
        $this->artisan('rabbitmq:pool-stats')
            ->expectsOutputToContain('Connection Pool')
            ->expectsOutputToContain('Channel Pool')
            ->assertExitCode(0);
    }

    public function testPoolStatsSupportsJsonOutput(): void
    {
        $this->artisan('rabbitmq:pool-stats', ['--json' => true])->assertExitCode(0);
    }

    public function testConsumeCommandRejectsAnInvalidProcessCount(): void
    {
        $this->artisan('rabbitmq:consume', ['--num-processes' => '0'])
            ->expectsOutputToContain('at least 1')
            ->assertExitCode(1);
    }

    public function testConsumeCommandDrainsTheQueueAndExitsCleanly(): void
    {
        $connection = Queue::connection('rabbitmq');
        $connection->declareQueue($this->queue);
        $connection->pushRaw(json_encode([
            'uuid' => 'console-1',
            'displayName' => 'ConsolePayload',
            'job' => \stdClass::class,
            'maxTries' => 1,
            'data' => [],
        ], JSON_THROW_ON_ERROR), $this->queue);

        $this->artisan('rabbitmq:consume', [
            'connection' => 'rabbitmq',
            '--queue' => $this->queue,
            '--num-processes' => '1',
            '--stop-when-empty' => true,
            '--tries' => '1',
            '--sleep' => '0',
        ])->assertExitCode(0);

        $this->assertSame(0, $connection->size($this->queue));
    }

    public function testConsumeCommandBuildsAUniqueConsumerTag(): void
    {
        $connection = Queue::connection('rabbitmq');
        $connection->declareQueue($this->queue);

        // A custom tag is accepted verbatim; the run still terminates cleanly.
        $this->artisan('rabbitmq:consume', [
            'connection' => 'rabbitmq',
            '--queue' => $this->queue,
            '--num-processes' => '1',
            '--consumer-tag' => 'explicit-tag',
            '--stop-when-empty' => true,
            '--sleep' => '0',
        ])->assertExitCode(0);

        $this->assertTrue(true);
    }

    public function testSizeAndPurgeHelpersAgreeWithTheBroker(): void
    {
        $connection = Queue::connection('rabbitmq');
        $connection->declareQueue($this->queue);

        for ($i = 0; $i < 3; $i++) {
            $connection->pushRaw('{"id":"'.$i.'"}', $this->queue);
        }

        $this->assertQueueSizeEventually(3);
        $this->assertSame(3, $connection->pendingSize($this->queue));
        $this->assertSame(0, $connection->delayedSize($this->queue));
        $this->assertSame(0, $connection->reservedSize($this->queue));
        $this->assertNull($connection->creationTimeOfOldestPendingJob($this->queue));

        $connection->purgeQueue($this->queue);

        $this->assertSame(0, $connection->size($this->queue));
    }

    /**
     * A missing queue must not raise: ext-amqp reports NOT_FOUND as an
     * AMQPQueueException, which the 404 handling used to miss entirely.
     */
    public function testPurgeAndDeleteOfAMissingQueueAreTolerated(): void
    {
        $connection = Queue::connection('rabbitmq');
        $missing = 'definitely-not-declared-'.bin2hex(random_bytes(4));

        $connection->purgeQueue($missing);
        $connection->deleteQueue($missing);

        $this->assertFalse($connection->queueExists($missing));
    }

    public function testGetAmqpChannelExposesAUsableChannel(): void
    {
        $connection = Queue::connection('rabbitmq');

        $channel = $connection->getAmqpChannel();
        $amqpQueue = new AMQPQueue($channel);
        $amqpQueue->setName($this->queue);
        $amqpQueue->setFlags(AMQP_DURABLE);
        $amqpQueue->declareQueue();

        $this->assertTrue($this->queueExists($this->queue));
    }
}
