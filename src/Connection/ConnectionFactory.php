<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Connection;

use AMQPConnection;
use AMQPConnectionException;
use iamfarhad\LaravelRabbitMQ\Exceptions\ConnectionException;

class ConnectionFactory
{
    private array $config;

    private int $maxRetries;

    private int|float $retryDelay;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->maxRetries = $config['pool']['max_retries'] ?? 3;
        $this->retryDelay = $config['pool']['retry_delay'] ?? 1000; // milliseconds
    }

    /**
     * Create a new AMQP connection with retry strategy.
     *
     * @throws ConnectionException
     */
    public function createConnection(): AMQPConnection
    {
        $connectionConfig = $this->buildConnectionConfig();
        $retryDelay = $this->retryDelay;

        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxRetries) {
            try {
                $connection = new AMQPConnection($connectionConfig);
                $connection->connect();

                return $connection;
            } catch (AMQPConnectionException $e) {
                $lastException = $e;
                $attempt++;

                if ($attempt < $this->maxRetries) {
                    usleep($this->retryDelayInMicroseconds($retryDelay));
                    $retryDelay *= 2; // Exponential backoff for this connection attempt only
                }
            }
        }

        throw new ConnectionException(
            sprintf(
                'Failed to connect to RabbitMQ after %d attempts. Last error: %s',
                $this->maxRetries,
                $lastException?->getMessage() ?? 'Unknown error'
            ),
            $lastException?->getCode() ?? 0,
            $lastException
        );
    }

    /**
     * Build connection configuration array.
     */
    private function buildConnectionConfig(): array
    {
        $hostConfig = $this->selectHostConfig();
        $options = $this->config['options'] ?? [];

        $config = [
            'host' => $hostConfig['host'] ?? '127.0.0.1',
            'port' => $hostConfig['port'] ?? 5672,
            'login' => $hostConfig['user'] ?? 'guest',
            'password' => $hostConfig['password'] ?? 'guest',
            'vhost' => $hostConfig['vhost'] ?? '/',
        ];

        $optionalParams = [
            'heartbeat' => 'heartbeat',
            'read_timeout' => 'read_timeout',
            'write_timeout' => 'write_timeout',
            'connect_timeout' => 'connect_timeout',
        ];

        foreach ($optionalParams as $configKey => $amqpKey) {
            $value = $hostConfig[$configKey] ?? $options[$configKey] ?? null;
            if (is_numeric($value) && (float) $value > 0) {
                $config[$amqpKey] = is_float($value + 0) ? (float) $value : (int) $value;
            }
        }

        return $config;
    }

    /**
     * Select a host from either the legacy associative host config or a list of hosts.
     */
    private function selectHostConfig(): array
    {
        $hosts = $this->config['hosts'] ?? [];

        if ($hosts === []) {
            return [];
        }

        if ($this->isListOfHosts($hosts)) {
            $availableHosts = array_values(array_filter($hosts, 'is_array'));

            if ($availableHosts === []) {
                return [];
            }

            shuffle($availableHosts);

            return $availableHosts[0];
        }

        return $hosts;
    }

    private function isListOfHosts(array $hosts): bool
    {
        return array_is_list($hosts) && isset($hosts[0]) && is_array($hosts[0]);
    }

    private function retryDelayInMicroseconds(int|float $retryDelay): int
    {
        return max(0, (int) round($retryDelay * 1000));
    }

    /**
     * Test if connection is alive.
     */
    public function isConnectionAlive(AMQPConnection $connection): bool
    {
        try {
            return $connection->isConnected();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Close connection safely.
     */
    public function closeConnection(AMQPConnection $connection): void
    {
        try {
            if ($connection->isConnected()) {
                $connection->disconnect();
            }
        } catch (\Exception $e) {
        }
    }
}
