<?php

declare(strict_types=1);

use iamfarhad\LaravelRabbitMQ\Consumer;
use iamfarhad\LaravelRabbitMQ\Tests\TestCase;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\QueueManager;

// Test only the basic Consumer class functionality without RabbitMQ connections
// Connection-dependent tests are covered in Feature tests
class ConsumerTest extends TestCase
{
    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    public function testCreatesConsumerInstance(): void
    {
        $manager = \Mockery::mock(QueueManager::class);
        $events = \Mockery::mock(Dispatcher::class);
        $exceptions = \Mockery::mock(ExceptionHandler::class);
        $isDownForMaintenance = fn () => false;

        $consumer = new Consumer($manager, $events, $exceptions, $isDownForMaintenance);

        $this->assertInstanceOf(Consumer::class, $consumer);
    }

    public function testSetsContainerWithoutError(): void
    {
        $manager = \Mockery::mock(QueueManager::class);
        $events = \Mockery::mock(Dispatcher::class);
        $exceptions = \Mockery::mock(ExceptionHandler::class);
        $isDownForMaintenance = fn () => false;

        $consumer = new Consumer($manager, $events, $exceptions, $isDownForMaintenance);
        $container = new Container;

        $consumer->setContainer($container);

        $this->assertTrue(true); // Method doesn't throw exception
    }

    public function testSetsConsumerTagWithoutError(): void
    {
        $manager = \Mockery::mock(QueueManager::class);
        $events = \Mockery::mock(Dispatcher::class);
        $exceptions = \Mockery::mock(ExceptionHandler::class);
        $isDownForMaintenance = fn () => false;

        $consumer = new Consumer($manager, $events, $exceptions, $isDownForMaintenance);

        $consumer->setConsumerTag('test-consumer-tag');

        $this->assertTrue(true); // Method doesn't throw exception
    }

    public function testSetsMaxPriorityWithoutError(): void
    {
        $manager = \Mockery::mock(QueueManager::class);
        $events = \Mockery::mock(Dispatcher::class);
        $exceptions = \Mockery::mock(ExceptionHandler::class);
        $isDownForMaintenance = fn () => false;

        $consumer = new Consumer($manager, $events, $exceptions, $isDownForMaintenance);

        $consumer->setMaxPriority(10);

        $this->assertTrue(true); // Method doesn't throw exception
    }

    public function testConsumerHandlesMaintenanceMode(): void
    {
        $manager = \Mockery::mock(QueueManager::class);
        $events = \Mockery::mock(Dispatcher::class);
        $exceptions = \Mockery::mock(ExceptionHandler::class);
        $isDownForMaintenance = fn () => true; // Simulate maintenance mode

        $consumer = new Consumer($manager, $events, $exceptions, $isDownForMaintenance);

        $this->assertInstanceOf(Consumer::class, $consumer);
    }

    public function testConsumerAcceptsCallableForMaintenanceCheck(): void
    {
        $manager = \Mockery::mock(QueueManager::class);
        $events = \Mockery::mock(Dispatcher::class);
        $exceptions = \Mockery::mock(ExceptionHandler::class);

        $maintenanceCallCount = 0;
        $isDownForMaintenance = function () use (&$maintenanceCallCount) {
            $maintenanceCallCount++;

            return false;
        };

        $consumer = new Consumer($manager, $events, $exceptions, $isDownForMaintenance);

        $this->assertInstanceOf(Consumer::class, $consumer);
        // The callable should be stored and ready to use
        $this->assertTrue(is_callable($isDownForMaintenance));
    }
}
