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
     * Skip a test that constructs real AMQP objects when the extension is
     * missing. `ext-amqp` is a hard package requirement, so this should only
     * ever trigger on an incorrectly provisioned machine.
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
