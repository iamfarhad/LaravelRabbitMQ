<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Console\Commands;

use iamfarhad\LaravelRabbitMQ\Connectors\RabbitMQConnector;
use Illuminate\Console\Command;

class PoolStatsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'rabbitmq:pool-stats 
                           {--json : Output stats in JSON format}
                           {--watch : Continuously watch stats (press Ctrl+C to stop)}
                           {--interval=5 : Refresh interval in seconds when watching}';

    /**
     * The console command description.
     */
    protected $description = 'Display RabbitMQ connection and channel pool statistics';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('watch')) {
            return $this->watchStats();
        }

        return $this->showStats();
    }

    /**
     * Show pool stats once
     */
    private function showStats(): int
    {
        $poolManager = RabbitMQConnector::getPoolManager();

        if (! $poolManager) {
            $this->error('No active RabbitMQ pool manager found. Make sure a RabbitMQ connection is active.');

            return 1;
        }

        $stats = $poolManager->getStats();

        if ($this->option('json')) {
            $this->line(json_encode($stats, JSON_PRETTY_PRINT));

            return 0;
        }

        $this->displayFormattedStats($stats);

        // Show health status
        $this->newLine();
        if ($poolManager->isHealthy()) {
            $this->info('🟢 Pool Status: Healthy');
        } else {
            $this->warn('🟡 Pool Status: Warning - Check connection count');
        }

        return 0;
    }

    /**
     * Watch pool stats continuously
     */
    private function watchStats(): int
    {
        $interval = (int) $this->option('interval');

        $this->info("Watching RabbitMQ pool stats (refresh every {$interval} seconds)");
        $this->info('Press Ctrl+C to stop');
        $this->newLine();

        try {
            while (true) {
                // Clear screen
                system('clear');

                $this->info('RabbitMQ Pool Statistics - '.now()->format('Y-m-d H:i:s'));
                $this->info(str_repeat('=', 60));

                $poolManager = RabbitMQConnector::getPoolManager();

                if (! $poolManager) {
                    $this->error('No active RabbitMQ pool manager found.');
                } else {
                    $stats = $poolManager->getStats();
                    $this->displayFormattedStats($stats);

                    // Show health status
                    $this->newLine();
                    if ($poolManager->isHealthy()) {
                        $this->info('🟢 Pool Status: Healthy');
                    } else {
                        $this->warn('🟡 Pool Status: Warning - Check connection count');
                    }
                }

                sleep($interval);
            }
        } catch (\Exception $e) {
            $this->error('Error watching stats: '.$e->getMessage());

            return 1;
        }

        return 0; // This will never be reached, but satisfies the linter
    }

    /**
     * Display formatted statistics
     */
    private function displayFormattedStats(array $stats): void
    {
        // Connection Pool Stats
        $this->info('📡 Connection Pool');
        $this->line('├─ Max Connections: '.$stats['connection_pool']['max_connections']);
        $this->line('├─ Min Connections: '.$stats['connection_pool']['min_connections']);
        $this->line('├─ Current Connections: '.$stats['connection_pool']['current_connections']);
        $this->line('├─ Active Connections: '.$stats['connection_pool']['active_connections']);
        $this->line('└─ Available Connections: '.$stats['connection_pool']['available_connections']);

        $this->newLine();

        // Channel Pool Stats
        $this->info('🔀 Channel Pool');
        $this->line('├─ Max Channels/Connection: '.$stats['channel_pool']['max_channels_per_connection']);
        $this->line('├─ Current Channels: '.$stats['channel_pool']['current_channels']);
        $this->line('├─ Active Channels: '.$stats['channel_pool']['active_channels']);
        $this->line('└─ Available Channels: '.$stats['channel_pool']['available_channels']);

        $this->newLine();

        // Configuration
        $this->info('⚙️ Configuration');
        $this->line('├─ Max Retries: '.$stats['config']['max_retries']);
        $this->line('├─ Retry Delay: '.$stats['config']['retry_delay'].'ms');
        $this->line('├─ Health Check: '.($stats['config']['health_check_enabled'] ? 'Enabled' : 'Disabled'));
        $this->line('└─ Health Check Interval: '.$stats['config']['health_check_interval'].'s');

        $this->newLine();

        // Health Check Status
        if ($stats['connection_pool']['health_check_enabled'] || $stats['channel_pool']['health_check_enabled']) {
            $this->info('🏥 Health Checks');

            if ($stats['connection_pool']['last_health_check'] > 0) {
                $lastCheck = date('Y-m-d H:i:s', $stats['connection_pool']['last_health_check']);
                $this->line('├─ Connection Last Check: '.$lastCheck);
            }

            if ($stats['channel_pool']['last_health_check'] > 0) {
                $lastCheck = date('Y-m-d H:i:s', $stats['channel_pool']['last_health_check']);
                $this->line('└─ Channel Last Check: '.$lastCheck);
            }
        }

        // Utilization
        $this->newLine();
        $this->info('📊 Utilization');

        $connUtilization = $stats['connection_pool']['current_connections'] > 0
            ? round(($stats['connection_pool']['active_connections'] / $stats['connection_pool']['current_connections']) * 100, 1)
            : 0;
        $this->line('├─ Connection Utilization: '.$connUtilization.'%');

        $maxConnUtilization = round(($stats['connection_pool']['current_connections'] / $stats['connection_pool']['max_connections']) * 100, 1);
        $this->line('├─ Pool Capacity Used: '.$maxConnUtilization.'%');

        $channelUtilization = $stats['channel_pool']['current_channels'] > 0
            ? round(($stats['channel_pool']['active_channels'] / $stats['channel_pool']['current_channels']) * 100, 1)
            : 0;
        $this->line('└─ Channel Utilization: '.$channelUtilization.'%');
    }
}
