<?php

namespace iamfarhad\LaravelRabbitMQ\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Base test case for pure unit tests that don't require Laravel application context
 * or RabbitMQ connections. These tests should be fast and isolated.
 */
abstract class UnitTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Skip test if AMQP extension is loaded (prevents mocking conflicts)
     */
    protected function skipIfAmqpExtensionLoaded(): void
    {
        if (extension_loaded('amqp')) {
            $this->markTestSkipped('Test skipped: AMQP extension is loaded which prevents proper mocking of AMQP classes');
        }
    }

    protected function tearDown(): void
    {
        if (class_exists(\Mockery::class)) {
            \Mockery::close();
        }
        parent::tearDown();
    }
}
