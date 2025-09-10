<?php

use iamfarhad\LaravelRabbitMQ\Support\MessageHelpers;

// Test only the message helpers and other non-connection dependent functionality
// Connection-dependent tests are covered in Feature tests

it('tests correlation id generation', function () {
    $correlationId = MessageHelpers::generateCorrelationId();
    expect($correlationId)->toBeString()->not->toBeEmpty();
});

it('extracts correlation id from valid payload', function () {
    $payload = json_encode(['id' => 'test-123', 'job' => 'TestJob']);
    $extractedId = MessageHelpers::extractCorrelationId($payload);
    expect($extractedId)->toBe('test-123');
});

it('returns null for payload without id', function () {
    $payload = json_encode(['job' => 'TestJob', 'data' => []]);
    $extractedId = MessageHelpers::extractCorrelationId($payload);
    expect($extractedId)->toBeNull();
});

it('handles invalid json gracefully in helper', function () {
    $invalidPayload = 'invalid-json';
    $extractedId = MessageHelpers::extractCorrelationId($invalidPayload);
    expect($extractedId)->toBeNull();
});

it('validates json correctly', function () {
    $validJson = json_encode(['test' => 'data']);
    expect(MessageHelpers::isValidJson($validJson))->toBeTrue();

    $invalidJson = 'not-json';
    expect(MessageHelpers::isValidJson($invalidJson))->toBeFalse();
});
