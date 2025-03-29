<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Illuminate\Queue\Console\WorkCommand;
use Illuminate\Support\Str;
use RuntimeException;

#[AsCommand(name: 'rabbitmq:consume')]
final class ConsumeCommand extends WorkCommand
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'rabbitmq:consume
                            {connection? : The name of the queue connection to work}
                            {--name=default : The name of the consumer}
                            {--queue= : The names of the queues to work}
                            {--once : Only process the next job on the queue}
                            {--stop-when-empty : Stop when the queue is empty}
                            {--delay=0 : The number of seconds to delay failed jobs}
                            {--backoff=0 : The number of seconds to wait before retrying a job that encountered an uncaught exception}
                            {--max-jobs=0 : The number of jobs to process before stopping}
                            {--max-time=0 : The maximum number of seconds the worker should run}
                            {--force : Force the worker to run even in maintenance mode}
                            {--memory=128 : The memory limit in megabytes}
                            {--sleep=3 : Number of seconds to sleep when no job is available}
                            {--timeout=60 : The number of seconds a child process can run}
                            {--tries=1 : Number of times to attempt a job before logging it failed}
                            {--rest=0 : Number of seconds to rest between jobs}
                            {--max-priority=null : Maximum priority level to consume}
                            {--consumer-tag}
                            {--prefetch-size=0}
                            {--prefetch-count=1000}
                            {--num-processes=2 : Number of processes to run in parallel}
                           ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consume messages from RabbitMQ queue';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $numProcesses = (int) $this->option('num-processes');

        if ($numProcesses < 1) {
            $this->error('Number of processes must be at least 1');
            return 1;
        }

        // Skip forking if only one process is needed
        if ($numProcesses === 1) {
            return $this->consume();
        }

        // Check if pcntl extension is available
        if (!extension_loaded('pcntl')) {
            $this->error('The pcntl extension is required for parallel processing');
            return 1;
        }

        $childPids = [];

        for ($i = 0; $i < $numProcesses; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->error("Failed to fork process $i");
                continue;
            }

            if ($pid === 0) {
                // Child process
                exit($this->consume());
            } else {
                // Parent process
                $childPids[] = $pid;
                $this->info("Started worker process $pid");
            }
        }

        // Set up signal handling for graceful termination
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function () use (&$childPids) {
                foreach ($childPids as $pid) {
                    if (function_exists('posix_kill')) {
                        posix_kill($pid, SIGTERM);
                    }
                }
            });
        }

        // Wait for all child processes to finish
        foreach ($childPids as $pid) {
            pcntl_waitpid($pid, $status);

            if (pcntl_wifexited($status)) {
                $exitCode = pcntl_wexitstatus($status);
                if ($exitCode !== 0) {
                    $this->warn("Process $pid exited with code $exitCode");
                }
            } else {
                $this->warn("Process $pid terminated abnormally");
            }
        }

        return 0;
    }

    /**
     * Configure and run the consumer.
     */
    private function consume(): int
    {
        try {
            $consumer = $this->worker;

            if (!$consumer) {
                throw new RuntimeException('Worker instance not initialized');
            }

            $consumer->setContainer($this->laravel);
            $consumer->setName($this->option('name'));
            $consumer->setConsumerTag($this->generateConsumerTag());

            // Initialize prefetch size and count first
            $consumer->setPrefetchSize((int) $this->option('prefetch-size'));
            $consumer->setPrefetchCount((int) $this->option('prefetch-count'));

            // Only set max priority if it's provided and not null
            $maxPriority = $this->option('max-priority');
            if ($maxPriority !== null && $maxPriority !== '') {
                $consumer->setMaxPriority((int) $maxPriority);
            }

            return parent::handle() ?? 0;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }

    /**
     * Generate a unique consumer tag.
     */
    private function generateConsumerTag(): string
    {
        if ($consumerTag = $this->option('consumer-tag')) {
            return $consumerTag;
        }

        $appName = config('app.name', 'laravel');
        $consumerName = $this->option('name');
        $uniqueId = md5(serialize($this->options()) . Str::random(16) . getmypid());

        $consumerTag = implode(
            '_',
            [
                Str::slug($appName),
                Str::slug($consumerName),
                $uniqueId,
            ]
        );

        return Str::substr($consumerTag, 0, 255);
    }
}
