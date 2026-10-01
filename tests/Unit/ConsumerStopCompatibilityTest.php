<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit;

use iamfarhad\LaravelRabbitMQ\Consumer;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Illuminate\Queue\Worker;
use ReflectionMethod;

class ConsumerStopCompatibilityTest extends UnitTestCase
{
    public function testConsumerInheritsTheInstalledWorkerStopImplementation(): void
    {
        $consumerStop = new ReflectionMethod(Consumer::class, 'stop');
        $workerStop = new ReflectionMethod(Worker::class, 'stop');

        $this->assertSame(
            Worker::class,
            $consumerStop->getDeclaringClass()->getName(),
            'Consumer should inherit Worker::stop() so its signature follows the installed Laravel version.'
        );

        $this->assertSame(
            $workerStop->getNumberOfParameters(),
            $consumerStop->getNumberOfParameters()
        );

        $this->assertSame(
            array_map(
                static fn ($parameter): string => $parameter->getName(),
                $workerStop->getParameters()
            ),
            array_map(
                static fn ($parameter): string => $parameter->getName(),
                $consumerStop->getParameters()
            )
        );
    }
}
