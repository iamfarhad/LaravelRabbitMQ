<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Connection;

use AMQPConnection;
use AMQPConnectionException;
use iamfarhad\LaravelRabbitMQ\Exceptions\ConnectionException;
use iamfarhad\LaravelRabbitMQ\Support\ExponentialBackoff;

class ConnectionFactory
{
    private array $config;

    private int $maxRetries;

    private int $retryDelay;

    public function __construct(array $config)
    {
        $this->config = $config;

        // Cast explicitly: env() only coerces true/false/null/empty, so any
        // numeric value set in .env arrives here as a string and would be a
        // TypeError against these typed properties.
        $this->maxRetries = max(1, (int) ($config['pool']['max_retries'] ?? 3));
        $this->retryDelay = max(0, (int) ($config['pool']['retry_delay'] ?? 1000));
    }

    public function createConnection(): AMQPConnection
    {
        // Resolved once per call (shuffled when multiple hosts are configured)
        // and cycled through by attempt, so a retry targets a different host
        // instead of hammering the one that just failed.
        $hostConfigs = $this->resolveHostConfigsForFailover();

        // Every configured host deserves at least one attempt, even when
        // max_retries is lower than the number of hosts.
        $maxAttempts = max($this->maxRetries, count($hostConfigs));
        $backoff = new ExponentialBackoff($this->retryDelay, 30000, 2.0, true);
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxAttempts) {
            $hostConfig = $hostConfigs[$attempt % count($hostConfigs)];

            try {
                $connection = $this->newAmqpConnection($this->buildConnectionConfig($hostConfig));
                $connection->connect();

                return $connection;
            } catch (AMQPConnectionException $e) {
                $lastException = $e;

                if (++$attempt < $maxAttempts) {
                    // Jittered so a fleet reconnecting after a broker restart
                    // does not synchronise into a thundering herd.
                    usleep(max(0, $backoff->getDelayForAttempt($attempt - 1)) * 1000);
                }
            }
        }

        throw new ConnectionException(
            sprintf('Failed to connect to RabbitMQ after %d attempts. Last error: %s', $maxAttempts, $lastException?->getMessage() ?? 'Unknown error'),
            $lastException?->getCode() ?? 0,
            $lastException
        );
    }

    /**
     * ext-amqp object-creation seam; see RabbitQueue for the rationale.
     *
     * @param  array<string, mixed>  $config
     */
    protected function newAmqpConnection(array $config): AMQPConnection
    {
        return new AMQPConnection($config);
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
            'host' => (string) ($hostConfig['host'] ?? '127.0.0.1'),
            // Cast: a port read straight from env() is a string, which ext-amqp
            // will not accept.
            'port' => (int) ($hostConfig['port'] ?? ($transport === 'tcp' ? 5672 : 5671)),
            'login' => (string) ($hostConfig['user'] ?? 'guest'),
            'password' => (string) ($hostConfig['password'] ?? 'guest'),
            'vhost' => (string) ($hostConfig['vhost'] ?? '/'),
        ];

        // The label RabbitMQ shows for this connection in the management UI and
        // in `rabbitmqctl list_connections`. Previously this was set to the
        // transport string ("ssl"), which made every TLS connection anonymous.
        $connectionName = $hostConfig['connection_name'] ?? $this->config['connection_name'] ?? null;

        if (is_string($connectionName) && $connectionName !== '') {
            $config['connection_name'] = $connectionName;
        }

        if ($transport !== 'tcp') {
            $config['ssl'] = true;
            $config['cacert'] = $options['ssl_options']['cafile'] ?? null;
            $config['cert'] = $options['ssl_options']['local_cert'] ?? null;
            $config['key'] = $options['ssl_options']['local_key'] ?? null;
            $config['verify'] = (bool) ($options['ssl_options']['verify_peer'] ?? true);
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
