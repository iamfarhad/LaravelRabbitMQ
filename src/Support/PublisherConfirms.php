<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Support;

use AMQPChannel;
use AMQPChannelException;
use AMQPQueueException;
use Exception;

class PublisherConfirms
{
    private bool $confirmMode = false;

    private array $pendingConfirms = [];

    private int $nextPublishSeqNo = 1;

    /**
     * The broker NACK observed by the confirm callback, if any. Consumable
     * state: the next wait takes it, clears it, and reports it as a failure,
     * so a NACK can only ever fail the wait it was captured for.
     */
    private ?string $lastNack = null;

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
     */
    public function disable(): void
    {
        $this->confirmMode = false;
        $this->pendingConfirms = [];
        $this->nextPublishSeqNo = 1;
        $this->lastNack = null;
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
        } catch (AMQPChannelException|AMQPQueueException $e) {
            // Never let a NACK captured during this wait survive into the
            // next one; the failure is already being reported here.
            $this->takeLastNack();

            throw new Exception('Failed to wait for confirms: '.$e->getMessage(), 0, $e);
        }

        $nack = $this->takeLastNack();

        if ($nack !== null) {
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
     * Register a pending confirm
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
    }

    /**
     * Whether a broker NACK is waiting to be reported by the next wait.
     */
    public function hasPendingNack(): bool
    {
        return $this->lastNack !== null;
    }

    /**
     * Install the ACK/NACK callbacks exactly once per instance, so repeated
     * enable()/disable() cycles never stack conflicting handlers.
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

    private function confirmDelivery(int $deliveryTag, bool $multiple): array
    {
        if (! $multiple) {
            return [$this->confirmMessage($deliveryTag)];
        }

        $confirmed = [];

        foreach (array_keys($this->pendingConfirms) as $seqNo) {
            if ($seqNo > $deliveryTag) {
                continue;
            }

            $confirmed[] = $this->confirmMessage($seqNo);
        }

        return $confirmed;
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
}
