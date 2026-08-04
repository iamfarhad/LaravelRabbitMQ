<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Feature;

use Exception;
use iamfarhad\LaravelRabbitMQ\Console\ConsumeCommand;
use iamfarhad\LaravelRabbitMQ\Support\TransactionManager;
use iamfarhad\LaravelRabbitMQ\Tests\TestCase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * The parts of ConsumeCommand and TransactionManager that the command-level and
 * happy-path tests do not reach.
 *
 * The `--num-processes > 1` fork path is deliberately not exercised: forking
 * inside PHPUnit gives the children the runner's state and its result writers,
 * which corrupts the run. It is covered by the end-to-end harness instead.
 */
class ConsumeCommandInternalsTest extends TestCase
{
    private function command(array $parameters = []): ConsumeCommand
    {
        $command = $this->app->make(ConsumeCommand::class);
        $command->setLaravel($this->app);

        $definition = clone $command->getDefinition();
        $input = new ArrayInput($parameters, $definition);

        (new ReflectionMethod($command, 'initialize'))->invoke($command, $input, new NullOutput);

        $inputProperty = new \ReflectionProperty(Command::class, 'input');
        $inputProperty->setValue($command, $input);

        return $command;
    }

    private function invoke(ConsumeCommand $command, string $method): mixed
    {
        return (new ReflectionMethod(ConsumeCommand::class, $method))->invoke($command);
    }

    public function testGeneratedConsumerTagIsSluggedScopedAndBounded(): void
    {
        config(['app.name' => 'My Shop']);

        $tag = $this->invoke($this->command(['--name' => 'orders worker']), 'generateConsumerTag');

        $this->assertStringStartsWith('my-shop_orders-worker_', $tag);
        $this->assertLessThanOrEqual(255, strlen($tag), 'A consumer tag longer than 255 is refused by the broker.');
        $this->assertMatchesRegularExpression('/^[a-z0-9_-]+$/', $tag);
    }

    public function testGeneratedConsumerTagsAreUniquePerInvocation(): void
    {
        $first = $this->invoke($this->command(['--name' => 'w']), 'generateConsumerTag');
        $second = $this->invoke($this->command(['--name' => 'w']), 'generateConsumerTag');

        $this->assertNotSame($first, $second, 'Two workers must not share a consumer tag.');
    }

    public function testAnExplicitConsumerTagIsUsedVerbatim(): void
    {
        $tag = $this->invoke($this->command(['--consumer-tag' => 'explicit-tag']), 'generateConsumerTag');

        $this->assertSame('explicit-tag', $tag);
    }

    public function testConsumeModeComesFromTheOptionWhenGiven(): void
    {
        $this->assertSame('consume', $this->invoke($this->command(['--consume-mode' => 'consume']), 'consumeMode'));
    }

    public function testConsumeModeFallsBackToTheWorkedConnectionsConfiguration(): void
    {
        config([
            'queue.connections.rabbitmq.options.queue.consume_mode' => 'poll',
            'queue.connections.rabbit_hot' => array_merge(
                config('queue.connections.rabbitmq'),
                ['options' => ['queue' => ['consume_mode' => 'consume']]]
            ),
        ]);

        $this->assertSame('consume', $this->invoke($this->command(['connection' => 'rabbit_hot']), 'consumeMode'));
        $this->assertSame('poll', $this->invoke($this->command(['connection' => 'rabbitmq']), 'consumeMode'));
    }

    public function testConsumeModeDefaultsToPollWhenNothingIsConfigured(): void
    {
        config(['queue.connections.rabbitmq.options.queue.consume_mode' => null]);

        $this->assertSame('poll', $this->invoke($this->command(), 'consumeMode'));
    }

    /**
     * A commit or rollback can fail — a dropped connection, a channel the broker
     * has closed. The manager must report it and leave no phantom transaction
     * behind, or every later commit would be refused as "no active transaction".
     */
    public function testCommitFailureIsReportedAndClearsTheTransaction(): void
    {
        $connection = Queue::connection('rabbitmq');
        $manager = new TransactionManager($connection->getChannel());

        $manager->begin();

        // Drop the channel underneath the open transaction.
        $connection->getChannel()->close();

        try {
            $manager->commit();
            $this->fail('Committing on a closed channel must fail.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('Failed to commit transaction', $exception->getMessage());
        }

        $this->assertFalse($manager->inTransaction(), 'A failed commit must not leave a phantom transaction.');
    }

    public function testRollbackFailureIsReportedAndClearsTheTransaction(): void
    {
        $connection = Queue::connection('rabbitmq');
        $manager = new TransactionManager($connection->getChannel());

        $manager->begin();
        $connection->getChannel()->close();

        try {
            $manager->rollback();
            $this->fail('Rolling back on a closed channel must fail.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('Failed to rollback transaction', $exception->getMessage());
        }

        $this->assertFalse($manager->inTransaction());
    }
}
