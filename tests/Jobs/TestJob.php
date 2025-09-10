<?php

namespace iamfarhad\LaravelRabbitMQ\Tests\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $timeout = 60;

    public $tries = 3;

    public $maxExceptions = 1;

    protected $payload;

    protected $shouldFail;

    /**
     * Create a new job instance.
     */
    public function __construct($payload, bool $shouldFail = false)
    {
        $this->payload = $payload;
        $this->shouldFail = $shouldFail;
    }

    /**
     * Execute the job.
     *
     * @throws Exception
     */
    public function handle(): void
    {
        if ($this->shouldFail) {
            throw new Exception('Test job intentionally failed: '.$this->payload);
        }

        // Simulate some work
        usleep(100000); // 0.1 seconds

        // Log the job execution for testing purposes
        if (function_exists('logger')) {
            logger('TestJob executed with payload: '.$this->payload);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Exception $exception): void
    {
        // Log the failure for testing purposes
        if (function_exists('logger')) {
            logger('TestJob failed: '.$exception->getMessage());
        }
    }

    /**
     * Get the payload for testing.
     */
    public function getPayload()
    {
        return $this->payload;
    }

    /**
     * Determine if the job should fail.
     */
    public function getShouldFail(): bool
    {
        return $this->shouldFail;
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function retryAfter(): int
    {
        return 3; // Retry after 3 seconds
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return ['test', 'rabbitmq', $this->payload];
    }
}
