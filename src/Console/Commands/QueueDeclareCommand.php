<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Console\Commands;

use iamfarhad\LaravelRabbitMQ\Console\Commands\Concerns\ResolvesRabbitQueue;
use Illuminate\Console\Command;

class QueueDeclareCommand extends Command
{
    use ResolvesRabbitQueue;

    protected $signature = 'rabbitmq:queue-declare
                            {name : Queue name}
                            {--connection= : The RabbitMQ queue connection to use}
                            {--durable=1 : Declare as durable}
                            {--auto-delete=0 : Declare as auto-delete}
                            {--lazy=0 : Use lazy queue mode}
                            {--priority= : Maximum priority 1-255}
                            {--quorum=0 : Declare as quorum queue}';

    protected $description = 'Declare a RabbitMQ queue';

    public function handle(): int
    {
        $connection = $this->resolveRabbitQueue();

        if ($connection === null) {
            return self::FAILURE;
        }

        $arguments = [];

        if ($this->toBool($this->option('lazy'))) {
            $arguments['x-queue-mode'] = 'lazy';
        }

        if ($this->toBool($this->option('quorum'))) {
            $arguments['x-queue-type'] = 'quorum';
        } elseif (is_numeric($this->option('priority')) && (int) $this->option('priority') > 0) {
            $arguments['x-max-priority'] = min((int) $this->option('priority'), 255);
        }

        $connection->declareQueue(
            (string) $this->argument('name'),
            $this->toBool($this->option('durable')),
            $this->toBool($this->option('auto-delete')),
            $arguments
        );

        $this->info(sprintf('Queue [%s] declared.', $this->argument('name')));

        return self::SUCCESS;
    }

    private function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
