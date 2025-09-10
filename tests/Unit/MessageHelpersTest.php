<?php

use iamfarhad\LaravelRabbitMQ\Support\MessageHelpers;

it('generates correlation id', function () {
    $correlationId = MessageHelpers::generateCorrelationId();

    expect($correlationId)->toBeString()->not->toBeEmpty();
});

it('extracts correlation id from valid payload', function () {
    $id = 'test-id-123';
    $payload = json_encode(['id' => $id, 'job' => 'TestJob']);

    $extractedId = MessageHelpers::extractCorrelationId($payload);

    expect($extractedId)->toBe($id);
});

it('returns null for payload without id', function () {
    $payload = json_encode(['job' => 'TestJob', 'data' => []]);

    $extractedId = MessageHelpers::extractCorrelationId($payload);

    expect($extractedId)->toBeNull();
});

it('returns null for invalid json', function () {
    $payload = 'invalid-json';

    $extractedId = MessageHelpers::extractCorrelationId($payload);

    expect($extractedId)->toBeNull();
});

it('validates valid json', function () {
    $payload = json_encode(['job' => 'TestJob']);

    $isValid = MessageHelpers::isValidJson($payload);

    expect($isValid)->toBeTrue();
});

it('invalidates invalid json', function () {
    $payload = 'invalid-json';

    $isValid = MessageHelpers::isValidJson($payload);

    expect($isValid)->toBeFalse();
});
