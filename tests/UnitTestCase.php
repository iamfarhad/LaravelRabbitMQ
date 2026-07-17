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

    /**
     * Skip test if the AMQP extension is missing (for tests that construct
     * real AMQP objects instead of mocking them)
     */
    protected function skipIfAmqpExtensionNotLoaded(): void
    {
        if (! extension_loaded('amqp')) {
            $this->markTestSkipped('Test skipped: AMQP extension is required to construct real AMQP objects');
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
