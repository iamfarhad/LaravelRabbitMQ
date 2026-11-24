<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Console\Commands;

use iamfarhad\LaravelRabbitMQ\Console\Commands\Concerns\ResolvesRabbitQueue;
use Illuminate\Console\Command;

class ExchangeDeclareCommand extends Command
{
    use ResolvesRabbitQueue;

    protected $signature = 'rabbitmq:exchange-declare
                            {name : Exchange name}
                            {--connection= : The RabbitMQ queue connection to use}
                            {--type=direct : Exchange type: direct, fanout, topic, headers}
                            {--durable=1 : Declare as durable}
                            {--auto-delete=0 : Declare as auto-delete}';

    protected $description = 'Declare a RabbitMQ exchange';

    public function handle(): int
    {
        $connection = $this->resolveRabbitQueue();

        if ($connection === null) {
            return self::FAILURE;
        }

        $connection->getExchangeManager()->declareExchange(
            (string) $this->argument('name'),
            $this->resolveType((string) $this->option('type')),
            $this->toBool($this->option('durable')),
            $this->toBool($this->option('auto-delete'))
        );

        $this->info(sprintf('Exchange [%s] declared.', $this->argument('name')));

        return self::SUCCESS;
    }

    private function resolveType(string $type): string
    {
        return match (strtolower($type)) {
            'fanout' => AMQP_EX_TYPE_FANOUT,
            'topic' => AMQP_EX_TYPE_TOPIC,
            'headers' => AMQP_EX_TYPE_HEADERS,
            default => AMQP_EX_TYPE_DIRECT,
        };
    }

    private function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
