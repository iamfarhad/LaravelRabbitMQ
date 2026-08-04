<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Console\Commands;

use iamfarhad\LaravelRabbitMQ\Console\ConsumeCommand;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Queue\Console\WorkCommand;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Mockery;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * ConsumeCommand replaces Laravel's `queue:work` signature wholesale, so any
 * option the inherited WorkCommand reads must be declared here too — Symfony
 * throws for undefined option lookups even when the option was never passed
 * on the command line (issue #27).
 */
class ConsumeCommandTest extends UnitTestCase
{
    public function testDefinesEveryOptionTheInstalledParentCommandReads(): void
    {
        $definition = $this->makeCommand()->getDefinition();

        $missing = [];

        foreach ($this->optionsReadByParentCommand() as $option) {
            if (! $definition->hasOption($option)) {
                $missing[] = $option;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'rabbitmq:consume has drifted from Laravel\'s WorkCommand; undefined options: '.implode(', ', $missing)
        );
    }

    public function testDefinesStopWhenEmptyForAndJsonOptions(): void
    {
        $definition = $this->makeCommand()->getDefinition();

        $this->assertTrue($definition->hasOption('stop-when-empty-for'));
        $this->assertTrue($definition->hasOption('json'));

        // The pre-existing shutdown flag must keep working unchanged.
        $this->assertTrue($definition->hasOption('stop-when-empty'));
        $this->assertFalse($definition->getOption('stop-when-empty')->acceptValue());
        $this->assertSame('0', $definition->getOption('stop-when-empty-for')->getDefault());
    }

    public function testGatheringWorkerOptionsSucceedsWithoutAnyOptionsPassed(): void
    {
        $options = $this->gatherWorkerOptions();

        $this->assertInstanceOf(WorkerOptions::class, $options);
        $this->assertFalse((bool) $options->stopWhenEmpty);
    }

    public function testStopWhenEmptyForIsAcceptedAndPropagatedToWorkerOptions(): void
    {
        if (! property_exists(WorkerOptions::class, 'stopWhenEmptyFor')) {
            $this->markTestSkipped('The installed Laravel version has no stop-when-empty-for worker option.');
        }

        $options = $this->gatherWorkerOptions(['--stop-when-empty-for' => '30']);

        $this->assertSame(30, (int) $options->stopWhenEmptyFor);
    }

    public function testStopWhenEmptyStillPropagatesToWorkerOptions(): void
    {
        $options = $this->gatherWorkerOptions(['--stop-when-empty' => true]);

        $this->assertTrue((bool) $options->stopWhenEmpty);
    }

    public function testJsonOptionSelectsLaravelsJsonWorkerOutput(): void
    {
        if (! method_exists(WorkCommand::class, 'outputUsingJson')) {
            $this->markTestSkipped('The installed Laravel version has no JSON worker output.');
        }

        $command = $this->makeCommand();

        $this->bindInput($command, ['--json' => true]);
        $outputUsingJson = new ReflectionMethod($command, 'outputUsingJson');
        $this->assertTrue((bool) $outputUsingJson->invoke($command));

        $withoutJson = $this->makeCommand();
        $this->bindInput($withoutJson, []);
        $this->assertFalse((bool) (new ReflectionMethod($withoutJson, 'outputUsingJson'))->invoke($withoutJson));
    }

    /**
     * Every option name the installed WorkCommand looks up through
     * `$this->option(...)`, read straight from the framework source so the
     * assertion tracks whichever Laravel version is installed.
     *
     * @return list<string>
     */
    private function optionsReadByParentCommand(): array
    {
        $source = file_get_contents((new \ReflectionClass(WorkCommand::class))->getFileName());

        $this->assertIsString($source);

        preg_match_all("/option\('([a-z0-9-]+)'\)/", $source, $matches);

        $options = array_values(array_unique($matches[1]));

        $this->assertContains('stop-when-empty-for', $options, 'Expected the installed WorkCommand to read stop-when-empty-for.');

        return $options;
    }

    private function gatherWorkerOptions(array $parameters = []): WorkerOptions
    {
        $command = $this->makeCommand();
        $this->bindInput($command, $parameters);

        $gather = new ReflectionMethod($command, 'gatherWorkerOptions');

        return $gather->invoke($command);
    }

    private function bindInput(ConsumeCommand $command, array $parameters): void
    {
        $input = new ArrayInput($parameters, $command->getDefinition());

        $property = new ReflectionProperty($command, 'input');
        $property->setValue($command, $input);
    }

    private function makeCommand(): ConsumeCommand
    {
        return new ConsumeCommand(
            Mockery::mock(Worker::class),
            Mockery::mock(CacheRepository::class)
        );
    }
}
