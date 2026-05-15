<?php

declare(strict_types=1);

use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Illuminate\Queue\Jobs\Job;

class RabbitMQJobTest extends UnitTestCase
{
    public function testValidatesJobClassExists(): void
    {
        $this->assertTrue(class_exists(RabbitMQJob::class));
    }

    public function testJobClassImplementsCorrectInterface(): void
    {
        $reflection = new ReflectionClass(RabbitMQJob::class);

        $this->assertTrue($reflection->isSubclassOf(Job::class));
    }

    public function testJobClassHasRequiredMethods(): void
    {
        $reflection = new ReflectionClass(RabbitMQJob::class);

        $this->assertTrue($reflection->hasMethod('getRawBody'));
        $this->assertTrue($reflection->hasMethod('delete'));
        $this->assertTrue($reflection->hasMethod('release'));
        $this->assertTrue($reflection->hasMethod('attempts'));
        $this->assertTrue($reflection->hasMethod('getJobId'));
        $this->assertTrue($reflection->hasMethod('headers'));
        $this->assertTrue($reflection->hasMethod('exchangeName'));
        $this->assertTrue($reflection->hasMethod('routingKey'));
        $this->assertTrue($reflection->hasMethod('deliveryTag'));
    }

    public function testJobClassMethodsArePublic(): void
    {
        $reflection = new ReflectionClass(RabbitMQJob::class);

        $methods = [
            'getRawBody',
            'delete',
            'release',
            'attempts',
            'getJobId',
            'headers',
            'exchangeName',
            'routingKey',
            'deliveryTag',
        ];

        foreach ($methods as $methodName) {
            $method = $reflection->getMethod($methodName);
            $this->assertTrue($method->isPublic(), "Method {$methodName} should be public");
        }
    }

    public function testJobClassIsInstantiableAndExtensible(): void
    {
        $reflection = new ReflectionClass(RabbitMQJob::class);

        $this->assertFalse($reflection->isAbstract());
        $this->assertFalse($reflection->isFinal());
        $this->assertTrue($reflection->isInstantiable());
    }

    public function testConfiguredCustomJobClassCanExtendRabbitMQJob(): void
    {
        $this->assertTrue(is_a(CustomRabbitMQJobForTest::class, RabbitMQJob::class, true));
    }
}

class CustomRabbitMQJobForTest extends RabbitMQJob
{
}
