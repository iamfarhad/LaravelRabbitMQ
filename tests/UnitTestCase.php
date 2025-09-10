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

    protected function tearDown(): void
    {
        if (class_exists(\Mockery::class)) {
            \Mockery::close();
        }
        parent::tearDown();
    }
}
