<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Support;

use AMQPChannel;
use AMQPChannelException;
use Exception;

class PublisherConfirms
{
    private bool $confirmMode = false;

    private array $pendingConfirms = [];

    private int $nextPublishSeqNo = 1;

    private ?string $lastNack = null;

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
            $this->channel->setConfirmCallback(
                fn (int $deliveryTag, bool $multiple = false): bool => $this->handleAck($deliveryTag, $multiple),
                fn (int $deliveryTag, bool $multiple = false): bool => $this->handleNack($deliveryTag, $multiple)
            );
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

            if ($this->lastNack !== null) {
                throw new Exception('Message was nacked by broker: '.$this->lastNack);
            }

            return true;
        } catch (AMQPChannelException $e) {
            throw new Exception('Failed to wait for confirms: '.$e->getMessage(), 0, $e);
        }
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
    }

    private function handleAck(int $deliveryTag, bool $multiple): bool
    {
        $this->confirmDelivery($deliveryTag, $multiple);

        return true;
    }

    private function handleNack(int $deliveryTag, bool $multiple): bool
    {
        $correlationIds = $this->confirmDelivery($deliveryTag, $multiple);
        $this->lastNack = implode(', ', array_filter($correlationIds)) ?: (string) $deliveryTag;

        return true;
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
}
