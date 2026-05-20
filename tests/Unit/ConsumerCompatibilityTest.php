<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit;

use iamfarhad\LaravelRabbitMQ\Consumer;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Illuminate\Queue\Worker;
use ReflectionMethod;

class ConsumerCompatibilityTest extends UnitTestCase
{
    public function testConsumerDaemonReturnTypeMatchesLaravelWorkerWhenDeclared(): void
    {
        $this->assertCompatibleReturnType(Worker::class, Consumer::class, 'daemon');
    }

    public function testConsumerStopReturnTypeMatchesLaravelWorkerWhenDeclared(): void
    {
        $this->assertCompatibleReturnType(Worker::class, Consumer::class, 'stop');
    }

    public function testCurrentJobPropertyRemainsCompatibleWithLaravelWorker(): void
    {
        $parentProperty = new \ReflectionProperty(Worker::class, 'currentJob');
        $consumerProperty = new \ReflectionProperty(Consumer::class, 'currentJob');

        $this->assertSame($parentProperty->hasType(), $consumerProperty->hasType());

        if ($parentProperty->hasType()) {
            $this->assertSame(
                (string) $parentProperty->getType(),
                (string) $consumerProperty->getType()
            );
        }
    }

    private function assertCompatibleReturnType(string $parentClass, string $childClass, string $method): void
    {
        $parentMethod = new ReflectionMethod($parentClass, $method);
        $childMethod = new ReflectionMethod($childClass, $method);

        if (! $parentMethod->hasReturnType()) {
            $this->assertTrue(true);

            return;
        }

        $this->assertTrue(
            $childMethod->hasReturnType(),
            sprintf('%s::%s must declare a return type when %s::%s does.', $childClass, $method, $parentClass, $method)
        );

        $this->assertSame(
            (string) $parentMethod->getReturnType(),
            (string) $childMethod->getReturnType()
        );
    }
}
