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

    private int $retryDelay;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->maxRetries = $config['pool']['max_retries'] ?? 3;
        $this->retryDelay = $config['pool']['retry_delay'] ?? 1000; // milliseconds
    }

    /**
     * Create a new AMQP connection with retry strategy
     *
     * @throws ConnectionException
     */
    public function createConnection(): AMQPConnection
    {
        $connectionConfig = $this->buildConnectionConfig();

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
                    usleep($this->retryDelay * 1000); // Convert to microseconds
                    $this->retryDelay *= 2; // Exponential backoff
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
     * Build connection configuration array
     */
    private function buildConnectionConfig(): array
    {
        $config = [
            'host' => $this->config['hosts']['host'] ?? '127.0.0.1',
            'port' => $this->config['hosts']['port'] ?? 5672,
            'login' => $this->config['hosts']['user'] ?? 'guest',
            'password' => $this->config['hosts']['password'] ?? 'guest',
            'vhost' => $this->config['hosts']['vhost'] ?? '/',
        ];

        // Add optional connection parameters (but be selective about which ones)
        $optionalParams = [
            'heartbeat' => 'heartbeat',
            // Note: timeouts can cause connection issues with some AMQP configurations
            // 'read_timeout' => 'read_timeout',
            // 'write_timeout' => 'write_timeout',
            // 'connect_timeout' => 'connect_timeout',
        ];

        foreach ($optionalParams as $configKey => $amqpKey) {
            if (isset($this->config['hosts'][$configKey]) && $this->config['hosts'][$configKey] > 0) {
                $config[$amqpKey] = $this->config['hosts'][$configKey];
            }
        }

        return $config;
    }

    /**
     * Test if connection is alive
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
     * Close connection safely
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
