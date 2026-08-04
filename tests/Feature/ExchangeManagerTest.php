<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Feature;

use AMQPEnvelope;
use AMQPException;
use AMQPQueue;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use iamfarhad\LaravelRabbitMQ\Support\ExchangeManager;
use iamfarhad\LaravelRabbitMQ\Tests\TestCase;
use Illuminate\Support\Facades\Queue;

/**
 * ExchangeManager against a real broker.
 *
 * Every method here declares or binds real topology, so mocking would only prove
 * the driver calls ext-amqp — not that the broker accepts what it sends.
 */
class ExchangeManagerTest extends TestCase
{
    private RabbitQueue $connection;

    private ExchangeManager $exchanges;

    /**
     * @var list<string>
     */
    private array $declaredExchanges = [];

    /**
     * @var list<string>
     */
    private array $declaredQueues = [];

    protected function setUp(): void
    {
        parent::setUp();

        $connection = Queue::connection('rabbitmq');
        $this->assertInstanceOf(RabbitQueue::class, $connection);

        $this->connection = $connection;
        $this->exchanges = $connection->getExchangeManager();
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->declaredQueues as $queue) {
                $this->connection->deleteQueue($queue);
            }

            foreach ($this->declaredExchanges as $exchange) {
                $this->exchanges->deleteExchange($exchange);
            }
        } catch (\Throwable) {
            // Cleanup only.
        }

        parent::tearDown();
    }

    private function exchange(string $suffix): string
    {
        $name = 'em-test-'.$suffix.'-'.bin2hex(random_bytes(3));
        $this->declaredExchanges[] = $name;

        return $name;
    }

    private function queue(string $suffix): string
    {
        $name = 'em-queue-'.$suffix.'-'.bin2hex(random_bytes(3));
        $this->declaredQueues[] = $name;
        $this->connection->declareQueue($name);

        return $name;
    }

    private function publishAndRead(string $exchange, string $queue, string $routingKey, array $headers = []): ?string
    {
        $attributes = $headers === [] ? [] : ['headers' => $headers];

        $this->assertTrue($this->exchanges->publish($exchange, '{"probe":true}', $routingKey, $attributes));

        $amqpQueue = new AMQPQueue($this->connection->getChannel());
        $amqpQueue->setName($queue);

        for ($attempt = 0; $attempt < 40; $attempt++) {
            $envelope = $amqpQueue->get(AMQP_AUTOACK);

            if ($envelope instanceof AMQPEnvelope) {
                return $envelope->getBody();
            }

            usleep(50_000);
        }

        return null;
    }

    public function testDeclaresEachExchangeTypeAndRoutesThroughIt(): void
    {
        foreach ([
            ExchangeManager::TYPE_DIRECT => 'rk',
            ExchangeManager::TYPE_TOPIC => 'orders.created',
            ExchangeManager::TYPE_FANOUT => '',
        ] as $type => $routingKey) {
            $exchange = $this->exchange('type');
            $queue = $this->queue('type');

            $this->exchanges->declareExchange($exchange, $type);
            $this->exchanges->bindQueue($queue, $exchange, $type === ExchangeManager::TYPE_TOPIC ? 'orders.*' : $routingKey);

            $this->assertNotNull(
                $this->publishAndRead($exchange, $queue, $routingKey),
                sprintf('A message published to a %s exchange should reach the bound queue.', $type)
            );
        }
    }

    public function testHeadersExchangeRoutesOnHeaderMatch(): void
    {
        $exchange = $this->exchange('headers');
        $queue = $this->queue('headers');

        $this->exchanges->setupHeadersExchange($exchange, [
            $queue => ['x-match' => 'all', 'format' => 'pdf'],
        ]);

        $this->assertNotNull(
            $this->publishAndRead($exchange, $queue, '', ['format' => 'pdf']),
            'A matching header set must route.'
        );
    }

    public function testHeadersExchangeDoesNotRouteOnAMismatch(): void
    {
        $exchange = $this->exchange('headers-miss');
        $queue = $this->queue('headers-miss');

        $this->exchanges->setupHeadersExchange($exchange, [
            $queue => ['x-match' => 'all', 'format' => 'pdf'],
        ]);

        $this->exchanges->publish($exchange, '{"probe":true}', '', ['headers' => ['format' => 'docx']]);

        $this->assertSame(0, $this->connection->size($queue), 'A non-matching header set must not route.');
    }

    public function testSetupTopicExchangeBindsEveryRoutingKey(): void
    {
        $exchange = $this->exchange('topic-multi');
        $queue = $this->queue('topic-multi');

        $this->exchanges->setupTopicExchange($exchange, [
            $queue => ['orders.created', 'orders.cancelled'],
        ]);

        $this->assertNotNull($this->publishAndRead($exchange, $queue, 'orders.created'));
        $this->assertNotNull($this->publishAndRead($exchange, $queue, 'orders.cancelled'));
    }

    public function testSetupFanoutExchangeReachesEveryQueue(): void
    {
        $exchange = $this->exchange('fanout-multi');
        $first = $this->queue('fanout-a');
        $second = $this->queue('fanout-b');

        $this->exchanges->setupFanoutExchange($exchange, [$first, $second]);
        $this->exchanges->publish($exchange, '{"probe":true}');

        foreach ([$first, $second] as $queue) {
            $amqpQueue = new AMQPQueue($this->connection->getChannel());
            $amqpQueue->setName($queue);

            $received = null;
            for ($attempt = 0; $attempt < 40 && $received === null; $attempt++) {
                $envelope = $amqpQueue->get(AMQP_AUTOACK);
                $received = $envelope instanceof AMQPEnvelope ? $envelope->getBody() : null;
                $received === null && usleep(50_000);
            }

            $this->assertNotNull($received, "Fanout must reach [{$queue}].");
        }
    }

    public function testUnbindStopsRouting(): void
    {
        $exchange = $this->exchange('unbind');
        $queue = $this->queue('unbind');

        $this->exchanges->declareExchange($exchange, ExchangeManager::TYPE_DIRECT);
        $this->exchanges->bindQueue($queue, $exchange, 'rk');
        $this->assertNotNull($this->publishAndRead($exchange, $queue, 'rk'));

        $this->exchanges->unbindQueue($queue, $exchange, 'rk');
        $this->exchanges->publish($exchange, '{"probe":true}', 'rk');

        $this->assertSame(0, $this->connection->size($queue), 'An unbound queue must stop receiving.');
    }

    public function testExchangeToExchangeBindingForwardsMessages(): void
    {
        $source = $this->exchange('e2e-source');
        $destination = $this->exchange('e2e-destination');
        $queue = $this->queue('e2e');

        $this->exchanges->declareExchange($source, ExchangeManager::TYPE_DIRECT);
        $this->exchanges->declareExchange($destination, ExchangeManager::TYPE_DIRECT);
        $this->exchanges->bindExchange($destination, $source, 'rk');
        $this->exchanges->bindQueue($queue, $destination, 'rk');

        $this->assertNotNull(
            $this->publishAndRead($source, $queue, 'rk'),
            'A message published to the source exchange must reach the queue bound to the destination.'
        );
    }

    public function testDeleteExchangeRemovesIt(): void
    {
        $exchange = $this->exchange('delete');

        $this->exchanges->declareExchange($exchange, ExchangeManager::TYPE_DIRECT);
        $this->exchanges->deleteExchange($exchange);

        // Redeclaring passively is the only way to ask "does it exist"; a plain
        // redeclare would simply recreate it, so assert the delete did not throw
        // and that a fresh declare of a *different* type now succeeds.
        $this->exchanges->declareExchange($exchange, ExchangeManager::TYPE_TOPIC);

        $this->assertTrue(true, 'Deleting then redeclaring with a new type proves the original was gone.');
    }

    public function testSetupDeadLetterExchangeWiresTheQueueToItsDlq(): void
    {
        $dlx = $this->exchange('dlx');
        $queue = 'em-dlx-source-'.bin2hex(random_bytes(3));
        $this->declaredQueues[] = $queue;
        $this->declaredQueues[] = $queue.'.dlq';

        // Must run before the source queue exists: queue arguments are immutable.
        $this->exchanges->setupDeadLetterExchange($queue, $dlx);

        $amqpQueue = new AMQPQueue($this->connection->getChannel());
        $amqpQueue->setName($queue);
        $amqpQueue->setFlags(AMQP_PASSIVE);
        $amqpQueue->declareQueue();

        $this->connection->pushRaw('{"dead":true}', $queue, ['exchange' => '']);

        // Rejecting without requeue hands the message to the DLX.
        $job = $this->connection->pop($queue);
        $this->assertNotNull($job);
        $this->connection->reject($job);

        $dlq = $queue.'.dlq';
        $size = 0;
        for ($attempt = 0; $attempt < 40 && $size === 0; $attempt++) {
            $size = $this->connection->size($dlq);
            $size === 0 && usleep(50_000);
        }

        $this->assertSame(1, $size, 'A rejected message must land in the dead-letter queue.');
    }

    public function testSetupDeadLetterExchangeHonoursASuffixAndRetention(): void
    {
        $dlx = $this->exchange('dlx-custom');
        $queue = 'em-dlx-custom-'.bin2hex(random_bytes(3));
        $this->declaredQueues[] = $queue;
        $this->declaredQueues[] = $queue.'.dead';

        $this->exchanges->setupDeadLetterExchange($queue, $dlx, ExchangeManager::TYPE_DIRECT, null, '.dead', 60000);

        $amqpQueue = new AMQPQueue($this->connection->getChannel());
        $amqpQueue->setName($queue.'.dead');
        $amqpQueue->setFlags(AMQP_PASSIVE);

        $this->assertSame(0, $amqpQueue->declareQueue(), 'The suffixed dead-letter queue must exist.');
    }

    /**
     * Redeclaring an existing queue with different arguments is refused with 406,
     * which setupDeadLetterExchange() tolerates so it can be called repeatedly.
     */
    public function testSetupDeadLetterExchangeToleratesAnAlreadyDeclaredQueue(): void
    {
        $dlx = $this->exchange('dlx-existing');
        $queue = $this->queue('dlx-existing'); // declared first, without DLX arguments
        $this->declaredQueues[] = $queue.'.dlq';

        $this->exchanges->setupDeadLetterExchange($queue, $dlx);

        $this->assertTrue(true, 'A precondition failure must not escape.');
    }

    /**
     * The delayed-message plugin is not installed on the test broker, so the
     * broker refuses the exchange type. That is the real behaviour for anyone who
     * enables the plugin path without the plugin, and it must surface rather than
     * be mistaken for an already-exists condition.
     */
    public function testSetupDelayedExchangeSurfacesAMissingPlugin(): void
    {
        $exchange = $this->exchange('delayed');

        $this->expectException(AMQPException::class);

        $this->exchanges->setupDelayedExchange($exchange, ExchangeManager::TYPE_DIRECT);
    }

    public function testPublishToExchangeGoesThroughTheDriverHelper(): void
    {
        $exchange = $this->exchange('helper');
        $queue = $this->queue('helper');

        $this->exchanges->declareExchange($exchange, ExchangeManager::TYPE_DIRECT);
        $this->exchanges->bindQueue($queue, $exchange, 'rk');

        $this->assertTrue(
            $this->connection->publishToExchange($exchange, '{"via":"helper"}', 'rk', ['source' => 'test'])
        );

        $amqpQueue = new AMQPQueue($this->connection->getChannel());
        $amqpQueue->setName($queue);

        $envelope = null;
        for ($attempt = 0; $attempt < 40 && $envelope === null; $attempt++) {
            $candidate = $amqpQueue->get(AMQP_AUTOACK);
            $envelope = $candidate instanceof AMQPEnvelope ? $candidate : null;
            $envelope === null && usleep(50_000);
        }

        $this->assertNotNull($envelope);
        $this->assertSame('{"via":"helper"}', $envelope->getBody());
        $this->assertSame('test', $envelope->getHeader('source'));
    }
}
