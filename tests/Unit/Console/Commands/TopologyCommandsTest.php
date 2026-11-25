<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Console\Commands;

use iamfarhad\LaravelRabbitMQ\Console\ConsumeCommand;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use iamfarhad\LaravelRabbitMQ\Support\ExchangeManager;
use iamfarhad\LaravelRabbitMQ\Tests\TestCase;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Worker;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class TopologyCommandsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function mockRabbitQueue(): RabbitQueue
    {
        $rabbitQueue = Mockery::mock(RabbitQueue::class);
        $rabbitQueue->shouldReceive('setConnectionName')->andReturnSelf();
        $rabbitQueue->shouldReceive('setConfig')->andReturnSelf();
        $rabbitQueue->shouldReceive('setContainer')->andReturnSelf();

        return $rabbitQueue;
    }

    public function testQueueDeclareCommandPassesOptionsToRabbitQueue(): void
    {
        $rabbitQueue = $this->mockRabbitQueue();
        $rabbitQueue->shouldReceive('declareQueue')
            ->once()
            ->with('orders', true, false, [
                'x-queue-mode' => 'lazy',
                'x-max-priority' => 10,
            ]);

        $this->app['queue']->addConnector('mock_rmq', fn () => new class($rabbitQueue) implements ConnectorInterface
        {
            public function __construct(private RabbitQueue $queue) {}

            public function connect(array $config)
            {
                return $this->queue;
            }
        });

        config(['queue.connections.rabbitmq_test' => ['driver' => 'mock_rmq']]);

        $this->artisan('rabbitmq:queue-declare', [
            'name' => 'orders',
            '--connection' => 'rabbitmq_test',
            '--lazy' => '1',
            '--priority' => '10',
        ])->expectsOutputToContain('Queue [orders] declared.')->assertExitCode(0);
    }

    public function testQueueDeclareCommandSupportsQuorumAndRejectsNonRabbitConnection(): void
    {
        $rabbitQueue = $this->mockRabbitQueue();
        $rabbitQueue->shouldReceive('declareQueue')
            ->once()
            ->with('orders-q', true, false, ['x-queue-type' => 'quorum']);

        $this->app['queue']->addConnector('mock_rmq', fn () => new class($rabbitQueue) implements ConnectorInterface
        {
            public function __construct(private RabbitQueue $queue) {}

            public function connect(array $config)
            {
                return $this->queue;
            }
        });

        config(['queue.connections.rabbitmq_test' => ['driver' => 'mock_rmq']]);

        $this->artisan('rabbitmq:queue-declare', [
            'name' => 'orders-q',
            '--connection' => 'rabbitmq_test',
            '--quorum' => '1',
        ])->assertExitCode(0);

        config(['queue.connections.sync_conn' => ['driver' => 'sync']]);
        $this->artisan('rabbitmq:queue-declare', [
            'name' => 'orders',
            '--connection' => 'sync_conn',
        ])->expectsOutputToContain('is not a RabbitMQ connection')->assertExitCode(1);

        $this->artisan('rabbitmq:queue-declare', [
            'name' => 'orders',
            '--connection' => 'missing-conn',
        ])->expectsOutputToContain('Could not resolve queue connection')->assertExitCode(1);
    }

    public function testQueuePurgeAndDeleteCommandsHandleForceAndCancellation(): void
    {
        $rabbitQueue = $this->mockRabbitQueue();
        $rabbitQueue->shouldReceive('purgeQueue')->once()->with('orders')->andReturn(3);
        $rabbitQueue->shouldReceive('deleteQueue')->once()->with('orders')->andReturn(0);

        $this->app['queue']->addConnector('mock_rmq', fn () => new class($rabbitQueue) implements ConnectorInterface
        {
            public function __construct(private RabbitQueue $queue) {}

            public function connect(array $config)
            {
                return $this->queue;
            }
        });

        config(['queue.connections.rabbitmq_test' => ['driver' => 'mock_rmq']]);

        $this->artisan('rabbitmq:queue-purge', ['name' => 'orders', '--connection' => 'rabbitmq_test'])
            ->expectsConfirmation('Purge queue [orders]?', 'no')
            ->expectsOutputToContain('Cancelled.')
            ->assertExitCode(0);

        $this->artisan('rabbitmq:queue-purge', ['name' => 'orders', '--connection' => 'rabbitmq_test', '--force' => true])
            ->expectsOutputToContain('Queue [orders] purged.')
            ->assertExitCode(0);

        $this->artisan('rabbitmq:queue-delete', ['name' => 'orders', '--connection' => 'rabbitmq_test'])
            ->expectsConfirmation('Delete queue [orders]?', 'no')
            ->expectsOutputToContain('Cancelled.')
            ->assertExitCode(0);

        $this->artisan('rabbitmq:queue-delete', ['name' => 'orders', '--connection' => 'rabbitmq_test', '--force' => true])
            ->expectsOutputToContain('Queue [orders] deleted.')
            ->assertExitCode(0);
    }

    public function testExchangeDeclareCommandDeclaresAllExchangeTypesAndRejectsNonRabbitConnection(): void
    {
        $exchangeManager = Mockery::mock(ExchangeManager::class);
        $exchangeManager->shouldReceive('declareExchange')
            ->once()
            ->with('events', AMQP_EX_TYPE_TOPIC, false, true)
            ->andReturn(Mockery::mock(\AMQPExchange::class));

        $rabbitQueue = $this->mockRabbitQueue();
        $rabbitQueue->shouldReceive('getExchangeManager')->once()->andReturn($exchangeManager);

        $this->app['queue']->addConnector('mock_rmq', fn () => new class($rabbitQueue) implements ConnectorInterface
        {
            public function __construct(private RabbitQueue $queue) {}

            public function connect(array $config)
            {
                return $this->queue;
            }
        });

        config(['queue.connections.rabbitmq_test' => ['driver' => 'mock_rmq']]);

        $this->artisan('rabbitmq:exchange-declare', [
            'name' => 'events',
            '--connection' => 'rabbitmq_test',
            '--type' => 'topic',
            '--durable' => '0',
            '--auto-delete' => '1',
        ])->expectsOutputToContain('Exchange [events] declared.')->assertExitCode(0);

        config(['queue.connections.sync_conn' => ['driver' => 'sync']]);
        $this->artisan('rabbitmq:exchange-declare', [
            'name' => 'events',
            '--connection' => 'sync_conn',
        ])->assertExitCode(1);

        $this->artisan('rabbitmq:queue-purge', ['name' => 'q', '--connection' => 'sync_conn', '--force' => true])->assertExitCode(1);
        $this->artisan('rabbitmq:queue-delete', ['name' => 'q', '--connection' => 'sync_conn', '--force' => true])->assertExitCode(1);
    }

    public function testConsumeCommandConsumeModeAndTagResolutionAndErrorHandling(): void
    {
        $worker = Mockery::mock(Worker::class);
        $command = new ConsumeCommand($worker, Mockery::mock(CacheRepository::class));
        $command->setLaravel($this->app);

        // Bind input with explicit consumer tag & consume mode.
        $input = new ArrayInput([
            '--consumer-tag' => 'custom-tag',
            '--consume-mode' => 'consume',
            '--num-processes' => '1',
        ], $command->getDefinition());
        (new \ReflectionProperty($command, 'input'))->setValue($command, $input);

        $this->assertSame('consume', (new \ReflectionMethod($command, 'consumeMode'))->invoke($command));
        $this->assertSame('custom-tag', (new \ReflectionMethod($command, 'generateConsumerTag'))->invoke($command));

        // Without explicit options, reads config and generates unique bounded tag.
        config(['queue.connections.rabbitmq.options.queue.consume_mode' => 'consume']);
        $defaultInput = new ArrayInput(['--num-processes' => '1'], $command->getDefinition());
        (new \ReflectionProperty($command, 'input'))->setValue($command, $defaultInput);

        $this->assertSame('consume', (new \ReflectionMethod($command, 'consumeMode'))->invoke($command));
        $generated = (new \ReflectionMethod($command, 'generateConsumerTag'))->invoke($command);
        $this->assertNotEmpty($generated);
        $this->assertLessThanOrEqual(255, strlen($generated));

        // handle() with --num-processes=1 catches worker errors and returns 1.
        $output = new OutputStyle($defaultInput, new BufferedOutput);
        (new \ReflectionProperty($command, 'output'))->setValue($command, $output);
        $worker->shouldReceive('setContainer')->andThrow(new \RuntimeException('worker boom'));
        $this->assertSame(1, $command->handle());
    }
}
