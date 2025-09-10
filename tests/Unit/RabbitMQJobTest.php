<?php

declare(strict_types=1);

// Test only basic job structure without AMQP connections
// Connection-dependent tests are covered in Feature tests

use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;

// RabbitMQJob tests require real AMQPEnvelope objects which need connections
// All job functionality is thoroughly tested in Feature tests with real RabbitMQ
class RabbitMQJobTest extends UnitTestCase
{
    public function testValidatesJobClassExists(): void
    {
        $this->assertTrue(class_exists(RabbitMQJob::class));
    }

    public function testJobClassImplementsCorrectInterface(): void
    {
        $reflection = new \ReflectionClass(RabbitMQJob::class);

        // Check that it extends the correct base class
        $this->assertTrue($reflection->isSubclassOf(\Illuminate\Queue\Jobs\Job::class));
    }

    public function testJobClassHasRequiredMethods(): void
    {
        $reflection = new \ReflectionClass(RabbitMQJob::class);

        // Check for required methods
        $this->assertTrue($reflection->hasMethod('getRawBody'));
        $this->assertTrue($reflection->hasMethod('delete'));
        $this->assertTrue($reflection->hasMethod('release'));
        $this->assertTrue($reflection->hasMethod('attempts'));
        $this->assertTrue($reflection->hasMethod('getJobId'));
    }

    public function testJobClassMethodsArePublic(): void
    {
        $reflection = new \ReflectionClass(RabbitMQJob::class);

        $methods = ['getRawBody', 'delete', 'release', 'attempts', 'getJobId'];

        foreach ($methods as $methodName) {
            if ($reflection->hasMethod($methodName)) {
                $method = $reflection->getMethod($methodName);
                $this->assertTrue($method->isPublic(), "Method {$methodName} should be public");
            }
        }
    }

    public function testJobClassIsInstantiable(): void
    {
        $reflection = new \ReflectionClass(RabbitMQJob::class);

        // The class should be instantiable (not abstract)
        $this->assertFalse($reflection->isAbstract());
        $this->assertTrue($reflection->isInstantiable());
    }
}
