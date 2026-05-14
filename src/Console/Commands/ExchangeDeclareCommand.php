<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Console\Commands;

use AMQPExchange;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

class ExchangeDeclareCommand extends Command
{
    protected $signature = 'rabbitmq:exchange-declare
                            {name : Exchange name}
                            {--type=direct : Exchange type: direct, fanout, topic, headers}
                            {--durable=1 : Declare as durable}
                            {--auto-delete=0 : Declare as auto-delete}';

    protected $description = 'Declare a RabbitMQ exchange';

    public function handle(): int
    {
        $connection = Queue::connection('rabbitmq');

        if (! $connection instanceof RabbitQueue) {
            $this->error('The rabbitmq queue connection is not configured.');

            return self::FAILURE;
        }

        $exchange = new AMQPExchange($connection->getAmqpChannel());
        $exchange->setName((string) $this->argument('name'));
        $exchange->setType($this->resolveType((string) $this->option('type')));

        $flags = $this->toBool($this->option('durable')) ? AMQP_DURABLE : AMQP_NOPARAM;
        if ($this->toBool($this->option('auto-delete'))) {
            $flags |= AMQP_AUTODELETE;
        }

        $exchange->setFlags($flags);
        $exchange->declareExchange();

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
