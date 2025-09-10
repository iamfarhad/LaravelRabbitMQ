<?php

use iamfarhad\LaravelRabbitMQ\Consumer;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\QueueManager;
use Mockery as m;

afterEach(function () {
    Mockery::close();
});

// Test only the basic Consumer class functionality without RabbitMQ connections
// Connection-dependent tests are covered in Feature tests

it('creates consumer instance', function () {
    $manager = Mockery::mock(QueueManager::class);
    $events = Mockery::mock(Dispatcher::class);
    $exceptions = Mockery::mock(ExceptionHandler::class);
    $isDownForMaintenance = fn () => false;

    $consumer = new Consumer($manager, $events, $exceptions, $isDownForMaintenance);

    expect($consumer)->toBeInstanceOf(Consumer::class);
});

it('sets container without error', function () {
    $manager = Mockery::mock(QueueManager::class);
    $events = Mockery::mock(Dispatcher::class);
    $exceptions = Mockery::mock(ExceptionHandler::class);
    $isDownForMaintenance = fn () => false;

    $consumer = new Consumer($manager, $events, $exceptions, $isDownForMaintenance);
    $container = new Container;

    $consumer->setContainer($container);

    expect(true)->toBeTrue(); // Method doesn't throw exception
});

it('sets consumer tag without error', function () {
    $manager = Mockery::mock(QueueManager::class);
    $events = Mockery::mock(Dispatcher::class);
    $exceptions = Mockery::mock(ExceptionHandler::class);
    $isDownForMaintenance = fn () => false;

    $consumer = new Consumer($manager, $events, $exceptions, $isDownForMaintenance);

    $consumer->setConsumerTag('test-consumer-tag');

    expect(true)->toBeTrue(); // Method doesn't throw exception
});

it('sets max priority without error', function () {
    $manager = Mockery::mock(QueueManager::class);
    $events = Mockery::mock(Dispatcher::class);
    $exceptions = Mockery::mock(ExceptionHandler::class);
    $isDownForMaintenance = fn () => false;

    $consumer = new Consumer($manager, $events, $exceptions, $isDownForMaintenance);

    $consumer->setMaxPriority(10);

    expect(true)->toBeTrue(); // Method doesn't throw exception
});
