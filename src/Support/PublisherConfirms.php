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
}
