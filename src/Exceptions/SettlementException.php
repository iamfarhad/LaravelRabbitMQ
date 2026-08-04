<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Exceptions;

use Throwable;

/**
 * Raised when a delivery could not be settled with the broker — the ack or
 * reject never reached RabbitMQ, so the broker still owns the delivery and
 * will redeliver it once the delivering channel closes.
 *
 * Callers must treat this as "settlement outcome unknown/failed", never as a
 * completed ack or reject. The original AMQP failure, when there is one, is
 * available through getPrevious(); its message is also folded into this
 * exception's message so Laravel's lost-connection detection (which only
 * inspects the outermost message) keeps working.
 */
final class SettlementException extends RabbitMQException
{
    /**
     * The delivering channel is gone, so the delivery tag cannot be settled
     * anywhere: tags are scoped to the channel that delivered the message.
     */
    public static function channelUnusable(string $operation, string $queue): self
    {
        return new self(sprintf(
            'Cannot %s delivery on queue [%s]: the channel that delivered the message is no longer usable. '
            .'The broker still owns this delivery and will redeliver it once the channel closes.',
            $operation,
            $queue
        ));
    }

    /**
     * Without a delivery tag there is nothing to settle — the message did not
     * come from a broker delivery.
     */
    public static function missingDeliveryTag(string $operation, string $queue): self
    {
        return new self(sprintf(
            'Cannot %s delivery on queue [%s]: the message carries no delivery tag.',
            $operation,
            $queue
        ));
    }

    /**
     * The broker or the extension refused the settlement.
     */
    public static function brokerRefused(string $operation, string $queue, Throwable $previous): self
    {
        return new self(
            sprintf(
                'Failed to %s delivery on queue [%s]: %s',
                $operation,
                $queue,
                $previous->getMessage()
            ),
            (int) $previous->getCode(),
            $previous
        );
    }
}
