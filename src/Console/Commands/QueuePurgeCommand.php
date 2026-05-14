<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Console\Commands;

use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

class QueuePurgeCommand extends Command
{
    protected $signature = 'rabbitmq:queue-purge {name : Queue name} {--force : Skip confirmation}';

    protected $description = 'Purge all ready messages from a RabbitMQ queue';

    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if (! $this->option('force') && ! $this->confirm("Purge queue [{$name}]?")) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $connection = Queue::connection('rabbitmq');

        if (! $connection instanceof RabbitQueue) {
            $this->error('The rabbitmq queue connection is not configured.');

            return self::FAILURE;
        }

        $connection->purgeQueue($name);
        $this->info("Queue [{$name}] purged.");

        return self::SUCCESS;
    }
}
