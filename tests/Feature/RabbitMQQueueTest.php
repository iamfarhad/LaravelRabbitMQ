<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use iamfarhad\LaravelRabbitMQ\LaravelRabbitQueueServiceProvider;
use Tests\Jobs\TestJob;

class RabbitMQQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Ensure RabbitMQ connection is available
        $this->ensureRabbitMQConnection();
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelRabbitQueueServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        // Setup the application configuration
        $app['config']->set('queue.default', 'rabbitmq');
        $app['config']->set('queue.connections.rabbitmq', [
            'driver' => 'rabbitmq',
            'queue' => env('RABBITMQ_QUEUE', 'default'),
            'hosts' => [
                [
                    'host' => env('RABBITMQ_HOST', '127.0.0.1'),
                    'port' => env('RABBITMQ_PORT', 5672),
                    'user' => env('RABBITMQ_USER', 'guest'),
                    'password' => env('RABBITMQ_PASSWORD', 'guest'),
                    'vhost' => env('RABBITMQ_VHOST', '/'),
                    'lazy' => env('RABBITMQ_LAZY_CONNECTION', true),
                    'keepalive' => env('RABBITMQ_KEEPALIVE_CONNECTION', false),
                    'heartbeat' => env('RABBITMQ_HEARTBEAT_CONNECTION', 0),
                    'secure' => env('RABBITMQ_SECURE', false),
                ]
            ],
            'options' => [
                'ssl_options' => [
                    'cafile' => env('RABBITMQ_SSL_CAFILE', null),
                    'local_cert' => env('RABBITMQ_SSL_LOCALCERT', null),
                    'local_key' => env('RABBITMQ_SSL_LOCALKEY', null),
                    'verify_peer' => env('RABBITMQ_SSL_VERIFY_PEER', true),
                    'passphrase' => env('RABBITMQ_SSL_PASSPHRASE', null),
                ],
                'queue' => [
                    'job' => \iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob::class,
                    'qos' => [
                        'prefetch_size' => 0,
                        'prefetch_count' => 10,
                        'global' => false
                    ]
                ],
            ],
        ]);
    }

    private function ensureRabbitMQConnection(): void
    {
        $maxAttempts = 10;
        $attempt = 0;
        
        while ($attempt < $maxAttempts) {
            try {
                $connection = Queue::connection('rabbitmq');
                // Try to get queue size to test connection
                $connection->size('test-connection');
                break;
            } catch (\Exception $e) {
                $attempt++;
                if ($attempt >= $maxAttempts) {
                    $this->markTestSkipped('RabbitMQ connection not available: ' . $e->getMessage());
                }
                sleep(1);
            }
        }
    }

    /** @test */
    public function it_can_connect_to_rabbitmq()
    {
        $connection = Queue::connection('rabbitmq');
        $this->assertInstanceOf(\iamfarhad\LaravelRabbitMQ\Queue\RabbitMQQueue::class, $connection);
    }

    /** @test */
    public function it_can_push_job_to_default_queue()
    {
        $job = new TestJob('test-payload');
        
        $jobId = Queue::push($job);
        
        $this->assertNotNull($jobId);
        $this->assertIsString($jobId);
    }

    /** @test */
    public function it_can_push_job_to_specific_queue()
    {
        $job = new TestJob('test-payload');
        $queueName = 'test-queue';
        
        $jobId = Queue::pushOn($queueName, $job);
        
        $this->assertNotNull($jobId);
        $this->assertIsString($jobId);
    }

    /** @test */
    public function it_can_push_delayed_job()
    {
        $job = new TestJob('delayed-payload');
        $delay = 60; // 60 seconds
        
        $jobId = Queue::later($delay, $job);
        
        $this->assertNotNull($jobId);
        $this->assertIsString($jobId);
    }

    /** @test */
    public function it_can_push_job_with_priority()
    {
        $job = new TestJob('priority-payload');
        $priority = 10;
        
        // Test priority job dispatch
        $jobId = Queue::pushRaw(
            json_encode([
                'job' => get_class($job),
                'data' => $job,
                'priority' => $priority
            ]),
            'priority-queue'
        );
        
        $this->assertNotNull($jobId);
    }

    /** @test */
    public function it_can_get_queue_size()
    {
        $queueName = 'size-test-queue';
        
        // Push a job to the queue
        Queue::pushOn($queueName, new TestJob('size-test'));
        
        // Get queue size
        $size = Queue::size($queueName);
        
        $this->assertIsInt($size);
        $this->assertGreaterThanOrEqual(0, $size);
    }

    /** @test */
    public function it_handles_connection_errors_gracefully()
    {
        // Test with invalid configuration
        config(['queue.connections.rabbitmq.hosts.0.host' => 'invalid-host']);
        
        $this->expectException(\Exception::class);
        
        Queue::connection('rabbitmq')->push(new TestJob('error-test'));
    }

    /** @test */
    public function it_can_push_bulk_jobs()
    {
        $jobs = [
            new TestJob('bulk-job-1'),
            new TestJob('bulk-job-2'),
            new TestJob('bulk-job-3'),
        ];
        
        foreach ($jobs as $job) {
            $jobId = Queue::push($job);
            $this->assertNotNull($jobId);
        }
    }

    /** @test */
    public function it_respects_queue_configuration()
    {
        $connection = Queue::connection('rabbitmq');
        
        // Test that the connection uses the correct configuration
        $this->assertEquals('rabbitmq', $connection->getConnectionName());
    }

    /** @test */
    public function it_can_handle_job_failures()
    {
        $job = new TestJob('failing-job', true); // Job that will fail
        
        try {
            Queue::push($job);
            // The job should be pushed successfully even if it will fail during processing
            $this->assertTrue(true);
        } catch (\Exception $e) {
            $this->fail('Job push should not fail: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        // Clean up any test queues or connections if needed
        try {
            // Purge test queues
            $testQueues = ['test-queue', 'priority-queue', 'size-test-queue', 'default'];
            foreach ($testQueues as $queue) {
                Queue::connection('rabbitmq')->purge($queue);
            }
        } catch (\Exception $e) {
            // Ignore cleanup errors in tests
        }
        
        parent::tearDown();
    }
}
