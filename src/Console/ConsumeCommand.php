<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Console;

use Illuminate\Queue\Console\WorkCommand;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'rabbitmq:consume')]
final class ConsumeCommand extends WorkCommand
{
    /**
     * The console command signature.
     *
     * This overrides Laravel's `queue:work` signature, so it must define every
     * option the inherited WorkCommand reads — `gatherWorkerOptions()` and
     * `outputUsingJson()` look options up by name and Symfony throws for any
     * that is undefined, even when it was never passed on the command line.
     * Options that only exist on newer frameworks (`--stop-when-empty-for`,
     * `--json`) are still declared on Laravel 10/11, where they are simply
     * accepted and ignored by the parent.
     *
     * @var string
     */
    protected $signature = 'rabbitmq:consume
                            {connection? : The name of the queue connection to work}
                            {--name=default : The name of the consumer}
                            {--queue= : The names of the queues to work}
                            {--once : Only process the next job on the queue}
                            {--stop-when-empty : Stop when the queue is empty}
                            {--stop-when-empty-for=0 : Stop when no jobs have been processed for the given number of seconds}
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
                            {--json : Output the queue worker information as JSON}
                            {--max-priority=null : Maximum priority level to consume}
                            {--consumer-tag= : Custom RabbitMQ consumer tag}
                            {--consume-mode= : Consumer mode: poll or consume}
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

        if ($numProcesses === 1) {
            return $this->consume();
        }

        if (! extension_loaded('pcntl')) {
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
                exit($this->consume());
            }

            $childPids[] = $pid;
            $this->info("Started worker process $pid");
        }

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function () use (&$childPids) {
                foreach ($childPids as $pid) {
                    if (function_exists('posix_kill')) {
                        posix_kill($pid, SIGTERM);
                    }
                }
            });
        }

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

            if (! $consumer) {
                throw new RuntimeException('Worker instance not initialized');
            }

            $consumer->setContainer($this->laravel);
            $consumer->setName((string) $this->option('name'));
            $consumer->setConsumerTag($this->generateConsumerTag());
            $consumer->setConsumeMode($this->consumeMode());

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

    private function consumeMode(): string
    {
        $mode = $this->option('consume-mode');

        if ($mode !== null && $mode !== '') {
            return (string) $mode;
        }

        // Read from the connection actually being worked, falling back to the
        // package's default connection block for single-connection setups.
        $connection = (string) ($this->argument('connection') ?: config('queue.default', 'rabbitmq'));

        $configured = config("queue.connections.{$connection}.options.queue.consume_mode")
            ?? config('queue.connections.rabbitmq.options.queue.consume_mode');

        // A key present but explicitly null yields null from config(), not the
        // default, so normalise rather than handing back an empty string.
        return is_string($configured) && $configured !== '' ? $configured : 'poll';
    }

    /**
     * Generate a unique consumer tag.
     */
    private function generateConsumerTag(): string
    {
        $consumerTag = $this->option('consumer-tag');
        if ($consumerTag !== null && $consumerTag !== false && $consumerTag !== '') {
            return (string) $consumerTag;
        }

        $appName = config('app.name', 'laravel');
        $consumerName = $this->option('name');
        $uniqueId = md5(serialize($this->options()).Str::random(16).getmypid());

        $consumerTag = implode(
            '_',
            [
                Str::slug($appName),
                Str::slug((string) $consumerName),
                $uniqueId,
            ]
        );

        return Str::substr($consumerTag, 0, 255);
    }
}
