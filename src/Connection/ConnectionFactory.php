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
        $this->retryDelay = $config['pool']['retry_delay'] ?? 1000;
    }

    public function createConnection(): AMQPConnection
    {
        // Resolved once per call (shuffled when multiple hosts are configured)
        // and cycled through by attempt, so a retry targets a different host
        // instead of hammering the one that just failed.
        $hostConfigs = $this->resolveHostConfigsForFailover();
        $retryDelay = $this->retryDelay;
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxRetries) {
            $hostConfig = $hostConfigs[$attempt % count($hostConfigs)];

            try {
                $connection = new AMQPConnection($this->buildConnectionConfig($hostConfig));
                $connection->connect();

                return $connection;
            } catch (AMQPConnectionException $e) {
                $lastException = $e;
                $attempt++;

                if ($attempt < $this->maxRetries) {
                    usleep($this->retryDelayInMicroseconds($retryDelay));
                    $retryDelay *= 2;
                }
            }
        }

        throw new ConnectionException(
            sprintf('Failed to connect to RabbitMQ after %d attempts. Last error: %s', $this->maxRetries, $lastException?->getMessage() ?? 'Unknown error'),
            $lastException?->getCode() ?? 0,
            $lastException
        );
    }

    public function buildConnectionConfigForTesting(): array
    {
        return $this->buildConnectionConfig($this->resolveHostConfigsForFailover()[0]);
    }

    private function buildConnectionConfig(array $hostConfig): array
    {
        $options = $this->config['options'] ?? [];
        $transport = $this->resolveTransport($hostConfig);

        $config = [
            'host' => $hostConfig['host'] ?? '127.0.0.1',
            'port' => $hostConfig['port'] ?? ($transport === 'tcp' ? 5672 : 5671),
            'login' => $hostConfig['user'] ?? 'guest',
            'password' => $hostConfig['password'] ?? 'guest',
            'vhost' => $hostConfig['vhost'] ?? '/',
        ];

        if ($transport !== 'tcp') {
            $config['connection_name'] = $transport;
            $config['ssl'] = true;
            $config['cacert'] = $options['ssl_options']['cafile'] ?? null;
            $config['cert'] = $options['ssl_options']['local_cert'] ?? null;
            $config['key'] = $options['ssl_options']['local_key'] ?? null;
            $config['verify'] = $options['ssl_options']['verify_peer'] ?? true;
        }

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

        return array_filter($config, static fn ($value) => $value !== null);
    }

    private function resolveTransport(array $hostConfig): string
    {
        $transport = strtolower((string) ($hostConfig['transport'] ?? $hostConfig['protocol'] ?? $this->config['transport'] ?? $this->config['protocol'] ?? 'tcp'));

        if (($hostConfig['secure'] ?? false) === true && $transport === 'tcp') {
            return 'ssl';
        }

        return match ($transport) {
            'ssl', 'tls' => $transport,
            default => 'tcp',
        };
    }

    /**
     * Resolve every candidate host config for this connection attempt,
     * shuffled so concurrent connects don't all pile onto the same host
     * first. createConnection() cycles through the returned list by
     * attempt number so retries fail over to the next host instead of
     * repeatedly targeting the one that just failed.
     *
     * @return list<array>
     */
    private function resolveHostConfigsForFailover(): array
    {
        $hosts = $this->config['hosts'] ?? [];

        if ($hosts === []) {
            return [[]];
        }

        if (! $this->isListOfHosts($hosts)) {
            return [$hosts];
        }

        $availableHosts = array_values(array_filter($hosts, 'is_array'));

        if ($availableHosts === []) {
            return [[]];
        }

        shuffle($availableHosts);

        return $availableHosts;
    }

    private function isListOfHosts(array $hosts): bool
    {
        return array_is_list($hosts) && isset($hosts[0]) && is_array($hosts[0]);
    }

    private function retryDelayInMicroseconds(int|float $retryDelay): int
    {
        return max(0, (int) round($retryDelay * 1000));
    }

    public function isConnectionAlive(AMQPConnection $connection): bool
    {
        try {
            return $connection->isConnected();
        } catch (\Exception $e) {
            return false;
        }
    }

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
