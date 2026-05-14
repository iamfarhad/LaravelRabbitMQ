<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Console\Commands;

use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

class QueueDeleteCommand extends Command
{
    protected $signature = 'rabbitmq:queue-delete {name : Queue name} {--force : Skip confirmation}';

    protected $description = 'Delete a RabbitMQ queue';

    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if (! $this->option('force') && ! $this->confirm("Delete queue [{$name}]?")) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $connection = Queue::connection('rabbitmq');

        if (! $connection instanceof RabbitQueue) {
            $this->error('The rabbitmq queue connection is not configured.');

            return self::FAILURE;
        }

        $connection->deleteQueue($name);
        $this->info("Queue [{$name}] deleted.");

        return self::SUCCESS;
    }
}
