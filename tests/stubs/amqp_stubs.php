<?php

/**
 * Minimal stand-ins for ext-amqp exception classes and constants so unit
 * tests can exercise error-handling paths on machines without the extension.
 * Every definition is guarded, making this file a no-op when ext-amqp is
 * loaded (as it is in CI and production).
 *
 * The AMQP* classes themselves (AMQPChannel, AMQPQueue, ...) are intentionally
 * NOT stubbed here: tests create them through Mockery, and a real class
 * definition would prevent `overload:` instance mocking.
 */

declare(strict_types=1);

if (! class_exists('AMQPException')) {
    class AMQPException extends Exception {}
}

if (! class_exists('AMQPConnectionException')) {
    class AMQPConnectionException extends AMQPException {}
}

if (! class_exists('AMQPChannelException')) {
    class AMQPChannelException extends AMQPException {}
}

if (! class_exists('AMQPQueueException')) {
    class AMQPQueueException extends AMQPException {}
}

if (! class_exists('AMQPExchangeException')) {
    class AMQPExchangeException extends AMQPException {}
}

if (! class_exists('AMQPEnvelopeException')) {
    class AMQPEnvelopeException extends AMQPException {}
}

if (! defined('AMQP_NOPARAM')) {
    define('AMQP_NOPARAM', 0);
}

if (! defined('AMQP_DURABLE')) {
    define('AMQP_DURABLE', 2);
}

if (! defined('AMQP_PASSIVE')) {
    define('AMQP_PASSIVE', 4);
}

if (! defined('AMQP_EXCLUSIVE')) {
    define('AMQP_EXCLUSIVE', 8);
}

if (! defined('AMQP_AUTODELETE')) {
    define('AMQP_AUTODELETE', 16);
}

if (! defined('AMQP_AUTOACK')) {
    define('AMQP_AUTOACK', 128);
}

if (! defined('AMQP_REQUEUE')) {
    define('AMQP_REQUEUE', 16384);
}

if (! defined('AMQP_EX_TYPE_DIRECT')) {
    define('AMQP_EX_TYPE_DIRECT', 'direct');
}

if (! defined('AMQP_EX_TYPE_FANOUT')) {
    define('AMQP_EX_TYPE_FANOUT', 'fanout');
}

if (! defined('AMQP_EX_TYPE_TOPIC')) {
    define('AMQP_EX_TYPE_TOPIC', 'topic');
}

if (! defined('AMQP_EX_TYPE_HEADERS')) {
    define('AMQP_EX_TYPE_HEADERS', 'headers');
}
