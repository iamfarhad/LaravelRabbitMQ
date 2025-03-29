<?php

namespace iamfarhad\LaravelRabbitMQ\Tests\Mocks;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TestJobMock implements ShouldQueue
{
    use Queueable;
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    private string $name;

    public function __construct(string $name = 'farhad zand')
    {
        $this->name = $name;
    }

    public function handle(): bool
    {
        return true;
    }
}
