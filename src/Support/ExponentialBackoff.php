<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Support;

class ExponentialBackoff
{
    private int $attempt = 0;

    public function __construct(
        private readonly int $baseDelay = 1000,
        private readonly int $maxDelay = 60000,
        private readonly float $multiplier = 2.0,
        private readonly bool $jitter = true
    ) {}

    /**
     * Calculate the delay for the current attempt
     */
    public function getDelay(): int
    {
        $delay = min(
            $this->baseDelay * pow($this->multiplier, $this->attempt),
            $this->maxDelay
        );

        if ($this->jitter) {
            $delay = $this->addJitter($delay);
        }

        return (int) $delay;
    }

    /**
     * Get delay for a specific attempt number
     */
    public function getDelayForAttempt(int $attempt): int
    {
        $delay = min(
            $this->baseDelay * pow($this->multiplier, $attempt),
            $this->maxDelay
        );

        if ($this->jitter) {
            $delay = $this->addJitter($delay);
        }

        return (int) $delay;
    }

    /**
     * Increment the attempt counter
     */
    public function increment(): void
    {
        $this->attempt++;
    }

    /**
     * Reset the attempt counter
     */
    public function reset(): void
    {
        $this->attempt = 0;
    }

    /**
     * Get current attempt number
     */
    public function getAttempt(): int
    {
        return $this->attempt;
    }

    /**
     * Add jitter to prevent thundering herd
     */
    private function addJitter(float $delay): float
    {
        $jitterRange = $delay * 0.1; // 10% jitter

        return $delay + (mt_rand() / mt_getrandmax() * $jitterRange * 2 - $jitterRange);
    }

    /**
     * Execute a callable with exponential backoff retry logic
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws \Exception
     */
    public function execute(callable $callback, int $maxAttempts = 3): mixed
    {
        $this->reset();
        $lastException = null;

        for ($i = 0; $i < $maxAttempts; $i++) {
            try {
                return $callback();
            } catch (\Throwable $e) {
                $lastException = $e;

                if ($i < $maxAttempts - 1) {
                    $delay = $this->getDelay();
                    usleep($delay * 1000); // Convert to microseconds
                    $this->increment();
                }
            }
        }

        throw new \Exception(
            "Failed after {$maxAttempts} attempts: ".$lastException?->getMessage(),
            0,
            $lastException
        );
    }
}
