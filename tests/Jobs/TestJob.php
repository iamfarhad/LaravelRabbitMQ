<?php

namespace Tests\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Exception;

class TestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60;
    public $tries = 3;
    public $maxExceptions = 1;

    protected $payload;
    protected $shouldFail;

    /**
     * Create a new job instance.
     *
     * @param mixed $payload
     * @param bool $shouldFail
     */
    public function __construct($payload, bool $shouldFail = false)
    {
        $this->payload = $payload;
        $this->shouldFail = $shouldFail;
    }

    /**
     * Execute the job.
     *
     * @return void
     * @throws Exception
     */
    public function handle()
    {
        if ($this->shouldFail) {
            throw new Exception('Test job intentionally failed: ' . $this->payload);
        }

        // Simulate some work
        usleep(100000); // 0.1 seconds

        // Log the job execution for testing purposes
        if (function_exists('logger')) {
            logger('TestJob executed with payload: ' . $this->payload);
        }
    }

    /**
     * Handle a job failure.
     *
     * @param Exception $exception
     * @return void
     */
    public function failed(Exception $exception)
    {
        // Log the failure for testing purposes
        if (function_exists('logger')) {
            logger('TestJob failed: ' . $exception->getMessage());
        }
    }

    /**
     * Get the payload for testing.
     *
     * @return mixed
     */
    public function getPayload()
    {
        return $this->payload;
    }

    /**
     * Determine if the job should fail.
     *
     * @return bool
     */
    public function getShouldFail(): bool
    {
        return $this->shouldFail;
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return int
     */
    public function retryAfter()
    {
        return 3; // Retry after 3 seconds
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array
     */
    public function tags()
    {
        return ['test', 'rabbitmq', $this->payload];
    }
}
