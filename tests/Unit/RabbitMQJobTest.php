<?php

declare(strict_types=1);

use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Illuminate\Container\Container;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Arr;
use Mockery;

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

    public function testAttemptsFallbackToPersistedPayloadAttemptsWhenHeadersAreMissing(): void
    {
        $this->skipIfAmqpExtensionLoaded();

        $payload = json_encode([
            'id' => 'job-id',
            'job' => 'TestJob',
            'laravel' => [
                'attempts' => 2,
            ],
            'data' => [],
        ], JSON_THROW_ON_ERROR);

        $rabbitQueue = Mockery::mock(RabbitQueue::class);
        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getBody')->andReturn($payload);
        $envelope->shouldReceive('getHeaders')->andReturn([]);

        $job = new RabbitMQJob(new Container, $rabbitQueue, $envelope, 'rabbitmq', 'default');

        $this->assertSame(3, $job->attempts());
    }

    public function testReleasePersistsAttemptCountInPayloadForNextRetry(): void
    {
        $this->skipIfAmqpExtensionLoaded();

        $payload = json_encode([
            'id' => 'job-id',
            'job' => 'TestJob',
            'data' => [],
        ], JSON_THROW_ON_ERROR);

        $rabbitQueue = Mockery::mock(RabbitQueue::class);
        $rabbitQueue->shouldReceive('laterRaw')
            ->once()
            ->withArgs(function ($delay, $releasedPayload, $queue, $attempts): bool {
                $decodedPayload = json_decode($releasedPayload, true, 512, JSON_THROW_ON_ERROR);

                $this->assertSame(0, $delay);
                $this->assertSame('default', $queue);
                $this->assertSame(1, $attempts);
                $this->assertSame(1, Arr::get($decodedPayload, 'laravel.attempts'));

                return true;
            });
        $rabbitQueue->shouldReceive('ack')->once();

        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getBody')->andReturn($payload);
        $envelope->shouldReceive('getHeaders')->andReturn([]);

        $job = new RabbitMQJob(new Container, $rabbitQueue, $envelope, 'rabbitmq', 'default');

        $job->release(0);
    }
}

class CustomRabbitMQJobForTest extends RabbitMQJob {}
