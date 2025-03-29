<?php

namespace iamfarhad\LaravelRabbitMQ\Tests;

use iamfarhad\LaravelRabbitMQ\LaravelRabbitQueueServiceProvider;
use Mockery;
use Orchestra\Testbench\TestCase as BaseTestCase;

class UnitTestCase extends BaseTestCase
{
    //    use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    protected function getPackageProviders($app): array
    {
        return [
            LaravelRabbitQueueServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $config = $this->loadConfig();

        config()->set('queue.connections.rabbitmq', $config);
    }

    private function loadConfig(): array
    {
        return require(__DIR__ . '/../config/RabbitMQConnectionConfig.php');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }
}
