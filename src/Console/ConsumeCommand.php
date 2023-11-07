<?php

namespace iamfarhad\LaravelRabbitMQ\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Illuminate\Queue\Console\WorkCommand;
use Illuminate\Support\Str;

#[AsCommand(name: 'rabbitmq:consume')]
final class ConsumeCommand extends WorkCommand
{
    protected $signature = 'rabbitmq:consume
                            {connection? : The name of the queue connection to work}
                            {--name=default : The name of the consumer}
                            {--queue= : The names of the queues to work}
                            {--once : Only process the next job on the queue}
                            {--stop-when-empty : Stop when the queue is empty}
                            {--delay=0 : The number of seconds to delay failed jobs (Deprecated)}
                            {--backoff=0 : The number of seconds to wait before retrying a job that encountered an uncaught exception}
                            {--max-jobs=0 : The number of jobs to process before stopping}
                            {--max-time=0 : The maximum number of seconds the worker should run}
                            {--force : Force the worker to run even in maintenance mode}
                            {--memory=128 : The memory limit in megabytes}
                            {--sleep=3 : Number of seconds to sleep when no job is available}
                            {--timeout=60 : The number of seconds a child process can run}
                            {--tries=1 : Number of times to attempt a job before logging it failed}
                            {--rest=0 : Number of seconds to rest between jobs}
                            {--max-priority=}
                            {--consumer-tag}
                            {--prefetch-size=0}
                            {--prefetch-count=1000}
                            {--num-processes=2 : Number of processes to run in parallel}
                           ';

    protected $description = 'Consume messages';


    public function handle(): int|null
    {
        $numProcesses = $this->option('num-processes');
        
        for ($i = 0; $i < $numProcesses; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                // Error handling
                echo "Could not fork process \n";
                exit(1);
            } elseif ($pid === 0) {
                // This is the child process
                $this->consume();
                exit(0);
            }
        }

        // Wait for all child processes to finish
        while (pcntl_waitpid(0, $status) !== -1) {
            // Handle exit status if needed
        }

        return 0;;
    }


    private function consume(): void
    {
        $consumer = $this->worker;

        $consumer->setContainer($this->laravel);
        $consumer->setName($this->option('name'));
        $consumer->setConsumerTag($this->consumerTag());
        $consumer->setMaxPriority((int) $this->option('max-priority'));
        $consumer->setPrefetchSize((int) $this->option('prefetch-size'));
        $consumer->setPrefetchCount((int) $this->option('prefetch-count'));

        parent::handle();
    }

    private function consumerTag(): string
    {
        if ($consumerTag = $this->option('consumer-tag')) {
            return $consumerTag;
        }

        $consumerTag = implode(
            '_',
            [
                Str::slug(config('app.name', 'laravel')),
                Str::slug($this->option('name')),
                md5(serialize($this->options()) . Str::random(16) . getmypid()),
            ]
        );

        return Str::substr($consumerTag, 0, 255);
    }
}
