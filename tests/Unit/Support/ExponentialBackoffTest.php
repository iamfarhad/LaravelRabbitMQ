<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Support;

use iamfarhad\LaravelRabbitMQ\Support\ExponentialBackoff;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;

class ExponentialBackoffTest extends UnitTestCase
{
    public function testGetDelayReturnsCorrectValue(): void
    {
        $backoff = new ExponentialBackoff(1000, 60000, 2.0, false);

        $this->assertEquals(1000, $backoff->getDelay());

        $backoff->increment();
        $this->assertEquals(2000, $backoff->getDelay());

        $backoff->increment();
        $this->assertEquals(4000, $backoff->getDelay());
    }

    public function testGetDelayRespectsMaxDelay(): void
    {
        $backoff = new ExponentialBackoff(1000, 5000, 2.0, false);

        $backoff->increment();
        $backoff->increment();
        $backoff->increment();

        $delay = $backoff->getDelay();
        $this->assertLessThanOrEqual(5000, $delay);
    }

    public function testGetDelayForAttempt(): void
    {
        $backoff = new ExponentialBackoff(1000, 60000, 2.0, false);

        $this->assertEquals(1000, $backoff->getDelayForAttempt(0));
        $this->assertEquals(2000, $backoff->getDelayForAttempt(1));
        $this->assertEquals(4000, $backoff->getDelayForAttempt(2));
        $this->assertEquals(8000, $backoff->getDelayForAttempt(3));
    }

    public function testReset(): void
    {
        $backoff = new ExponentialBackoff(1000, 60000, 2.0, false);

        $backoff->increment();
        $backoff->increment();
        $this->assertEquals(2, $backoff->getAttempt());

        $backoff->reset();
        $this->assertEquals(0, $backoff->getAttempt());
        $this->assertEquals(1000, $backoff->getDelay());
    }

    public function testExecuteSuccessOnFirstAttempt(): void
    {
        $backoff = new ExponentialBackoff(1000, 60000, 2.0, false);

        $result = $backoff->execute(fn () => 'success', 3);

        $this->assertEquals('success', $result);
    }

    public function testExecuteRetriesOnFailure(): void
    {
        $backoff = new ExponentialBackoff(10, 100, 2.0, false);
        $attempts = 0;

        $result = $backoff->execute(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new \Exception('Temporary failure');
            }

            return 'success';
        }, 5);

        $this->assertEquals('success', $result);
        $this->assertEquals(3, $attempts);
    }

    public function testExecuteThrowsAfterMaxAttempts(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed after 3 attempts');

        $backoff = new ExponentialBackoff(10, 100, 2.0, false);

        $backoff->execute(function () {
            throw new \Exception('Always fails');
        }, 3);
    }

    public function testJitterAddsVariation(): void
    {
        $backoff = new ExponentialBackoff(1000, 60000, 2.0, true);

        $delays = [];
        for ($i = 0; $i < 10; $i++) {
            $delays[] = $backoff->getDelay();
        }

        // With jitter, delays should vary
        $uniqueDelays = array_unique($delays);
        $this->assertGreaterThan(1, count($uniqueDelays));
    }
}
