<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Support;

use AMQPChannel;
use AMQPChannelException;
use Exception;

class TransactionManager
{
    private bool $inTransaction = false;

    public function __construct(
        private readonly AMQPChannel $channel
    ) {}

    /**
     * Start a transaction
     */
    public function begin(): void
    {
        if ($this->inTransaction) {
            throw new Exception('Transaction already started');
        }

        $this->channel->startTransaction();
        $this->inTransaction = true;
    }

    /**
     * Commit the transaction
     */
    public function commit(): void
    {
        if (! $this->inTransaction) {
            throw new Exception('No active transaction to commit');
        }

        try {
            $this->channel->commitTransaction();
            $this->inTransaction = false;
        } catch (AMQPChannelException $e) {
            $this->inTransaction = false;
            throw new Exception('Failed to commit transaction: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Rollback the transaction
     */
    public function rollback(): void
    {
        if (! $this->inTransaction) {
            throw new Exception('No active transaction to rollback');
        }

        try {
            $this->channel->rollbackTransaction();
            $this->inTransaction = false;
        } catch (AMQPChannelException $e) {
            $this->inTransaction = false;
            throw new Exception('Failed to rollback transaction: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Execute a callback within a transaction
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->begin();

        try {
            $result = $callback();
            $this->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Check if currently in a transaction
     */
    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }
}
