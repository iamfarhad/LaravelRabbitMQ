<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Support;

use AMQPChannel;
use AMQPChannelException;
use AMQPException;
use Exception;

class PublisherConfirms
{
    private bool $confirmMode = false;

    /**
     * Correlation IDs of publishes awaiting a broker ACK, keyed by the channel
     * publish sequence number the broker will confirm them with.
     *
     * @var array<int, string>
     */
    private array $pendingConfirms = [];

    private int $nextPublishSeqNo = 1;

    /**
     * The broker NACK observed by the confirm callback, if any. Consumable
     * state: the next wait takes it, clears it, and reports it as a failure,
     * so a NACK can only ever fail the wait it was captured for.
     */
    private ?string $lastNack = null;

    /**
     * A basic.return observed for a mandatory publish, i.e. the broker had
     * nowhere to route the message. Consumable in the same way as $lastNack.
     */
    private ?string $lastReturn = null;

    private bool $callbacksRegistered = false;

    public function __construct(
        private readonly AMQPChannel $channel,
        private readonly int $timeout = 5
    ) {}

    /**
     * Enable publisher confirms mode
     */
    public function enable(): void
    {
        if ($this->confirmMode) {
            return;
        }

        try {
            // ext-amqp refuses to process an incoming basic.ack/basic.nack
            // unless a confirm callback is installed ("Unhandled basic.ack
            // method from server received."), so the callbacks must be in
            // place before confirm mode is switched on.
            $this->registerConfirmCallbacks();

            $this->channel->confirmSelect();
            $this->confirmMode = true;
        } catch (AMQPChannelException $e) {
            throw new Exception('Failed to enable publisher confirms: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Disable publisher confirms mode
     *
     * Note that AMQP provides no way to leave confirm mode on a live channel:
     * this only resets local bookkeeping. The channel itself stays in confirm
     * mode, which is why the pool retires it rather than reusing it.
     */
    public function disable(): void
    {
        $this->confirmMode = false;
        $this->pendingConfirms = [];
        $this->nextPublishSeqNo = 1;
        $this->lastNack = null;
        $this->lastReturn = null;
    }

    /**
     * Wait for all pending confirms
     */
    public function waitForConfirms(): bool
    {
        if (! $this->confirmMode) {
            throw new Exception('Publisher confirms not enabled');
        }

        try {
            $this->channel->waitForConfirm($this->timeout);
        } catch (AMQPException $e) {
            // Never let a NACK or return captured during this wait survive into
            // the next one; the failure is already being reported here.
            $this->takeLastNack();
            $this->takeLastReturn();
            $this->clearPending();

            throw new Exception('Failed to wait for confirms: '.$e->getMessage(), 0, $e);
        }

        // An unroutable mandatory publish is the failure that publisher confirms
        // alone cannot surface: RabbitMQ ACKs it after returning it.
        if (($return = $this->takeLastReturn()) !== null) {
            $this->takeLastNack();
            $this->clearPending();

            throw new Exception('Message was returned as unroutable by broker: '.$return);
        }

        if (($nack = $this->takeLastNack()) !== null) {
            $this->clearPending();

            throw new Exception('Message was nacked by broker: '.$nack);
        }

        return true;
    }

    /**
     * Wait for a specific number of confirms
     */
    public function waitForConfirmsOrDie(int $count = 1): void
    {
        if (! $this->confirmMode) {
            throw new Exception('Publisher confirms not enabled');
        }

        for ($i = 0; $i < $count; $i++) {
            if (! $this->waitForConfirms()) {
                throw new Exception("Failed to confirm message {$i}");
            }
        }
    }

    /**
     * Register a pending confirm.
     *
     * Must be called once per publish on this channel while confirm mode is on,
     * immediately before the publish, so the local sequence number stays in step
     * with the broker's.
     */
    public function registerPendingConfirm(string $correlationId): int
    {
        $seqNo = $this->nextPublishSeqNo++;
        $this->pendingConfirms[$seqNo] = $correlationId;

        return $seqNo;
    }

    /**
     * Confirm a message by sequence number
     */
    public function confirmMessage(int $seqNo): ?string
    {
        if (! isset($this->pendingConfirms[$seqNo])) {
            return null;
        }

        $correlationId = $this->pendingConfirms[$seqNo];
        unset($this->pendingConfirms[$seqNo]);

        return $correlationId;
    }

    /**
     * Get pending confirms count
     */
    public function getPendingCount(): int
    {
        return count($this->pendingConfirms);
    }

    /**
     * Check if publisher confirms are enabled
     */
    public function isEnabled(): bool
    {
        return $this->confirmMode;
    }

    /**
     * Clear all pending confirms
     */
    public function clearPending(): void
    {
        $this->pendingConfirms = [];
        $this->lastNack = null;
        $this->lastReturn = null;
    }

    /**
     * Whether a broker NACK is waiting to be reported by the next wait.
     */
    public function hasPendingNack(): bool
    {
        return $this->lastNack !== null;
    }

    /**
     * Whether an unroutable-message return is waiting to be reported.
     */
    public function hasPendingReturn(): bool
    {
        return $this->lastReturn !== null;
    }

    /**
     * Install the ACK/NACK/return callbacks exactly once per instance, so
     * repeated enable()/disable() cycles never stack conflicting handlers.
     */
    private function registerConfirmCallbacks(): void
    {
        if ($this->callbacksRegistered) {
            return;
        }

        $this->channel->setConfirmCallback(
            fn (int $deliveryTag, bool $multiple = false): bool => $this->handleAck($deliveryTag, $multiple),
            fn (int $deliveryTag, bool $multiple = false, bool $requeue = false): bool => $this->handleNack($deliveryTag, $multiple)
        );

        $this->channel->setReturnCallback(
            fn (
                int $replyCode,
                string $replyText,
                string $exchange,
                string $routingKey,
                $properties = null,
                string $body = ''
            ): bool => $this->handleReturn($replyCode, $replyText, $exchange, $routingKey)
        );

        $this->callbacksRegistered = true;
    }

    /**
     * ext-amqp keeps blocking inside waitForConfirm() while the callback
     * returns true, so stop as soon as nothing is outstanding.
     */
    private function handleAck(int $deliveryTag, bool $multiple): bool
    {
        $this->confirmDelivery($deliveryTag, $multiple);

        return $this->pendingConfirms !== [];
    }

    /**
     * A NACK always ends the wait: waitForConfirms() turns it into a failed
     * confirmation for the caller rather than blocking for the remainder.
     */
    private function handleNack(int $deliveryTag, bool $multiple): bool
    {
        $correlationIds = array_filter($this->confirmDelivery($deliveryTag, $multiple));

        $this->lastNack = $correlationIds !== []
            ? implode(', ', $correlationIds)
            : (string) $deliveryTag;

        return false;
    }

    /**
     * A returned message ends the wait for the same reason a NACK does.
     */
    private function handleReturn(int $replyCode, string $replyText, string $exchange, string $routingKey): bool
    {
        $this->lastReturn = sprintf(
            '%d %s (exchange [%s], routing key [%s])',
            $replyCode,
            $replyText,
            $exchange === '' ? '(default)' : $exchange,
            $routingKey
        );

        return false;
    }

    /**
     * @return array<int, string|null>
     */
    private function confirmDelivery(int $deliveryTag, bool $multiple): array
    {
        if ($multiple) {
            $confirmed = [];

            foreach (array_keys($this->pendingConfirms) as $seqNo) {
                if ($seqNo > $deliveryTag) {
                    continue;
                }

                $confirmed[] = $this->confirmMessage($seqNo);
            }

            return $confirmed;
        }

        if (isset($this->pendingConfirms[$deliveryTag])) {
            return [$this->confirmMessage($deliveryTag)];
        }

        // A tag we never registered — a raw publish elsewhere on this channel
        // shifted the broker's sequence. The broker still confirms in publish
        // order, so resolve the oldest outstanding entry instead of leaving the
        // ledger permanently unbalanced and deadlocking every later wait.
        $oldest = array_key_first($this->pendingConfirms);

        return $oldest === null ? [] : [$this->confirmMessage($oldest)];
    }

    /**
     * Take the stored NACK, leaving the instance clean for the next publish.
     */
    private function takeLastNack(): ?string
    {
        $nack = $this->lastNack;
        $this->lastNack = null;

        return $nack;
    }

    private function takeLastReturn(): ?string
    {
        $return = $this->lastReturn;
        $this->lastReturn = null;

        return $return;
    }
}
