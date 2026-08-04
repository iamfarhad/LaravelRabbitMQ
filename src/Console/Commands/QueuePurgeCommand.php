<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Console\Commands;

use iamfarhad\LaravelRabbitMQ\Console\Commands\Concerns\ResolvesRabbitQueue;
use Illuminate\Console\Command;

class QueuePurgeCommand extends Command
{
    use ResolvesRabbitQueue;

    protected $signature = 'rabbitmq:queue-purge
                            {name : Queue name}
                            {--connection= : The RabbitMQ queue connection to use}
                            {--force : Skip confirmation}';

    protected $description = 'Purge all ready messages from a RabbitMQ queue';

    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if (! $this->option('force') && ! $this->confirm("Purge queue [{$name}]?")) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $connection = $this->resolveRabbitQueue();

        if ($connection === null) {
            return self::FAILURE;
        }

        $connection->purgeQueue($name);
        $this->info("Queue [{$name}] purged.");

        return self::SUCCESS;
    }
}
