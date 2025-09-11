<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Support;

use Illuminate\Support\Str;
use JsonException;

final class MessageHelpers
{
    /**
     * Generate a unique correlation ID.
     */
    public static function generateCorrelationId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Extract correlation ID from payload.
     */
    public static function extractCorrelationId(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

            return $data['id'] ?? null;
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * Validate JSON payload.
     */
    public static function isValidJson(string $payload): bool
    {
        try {
            json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

            return true;
        } catch (JsonException) {
            return false;
        }
    }
}
