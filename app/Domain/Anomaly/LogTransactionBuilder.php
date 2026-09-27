<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

/**
 * Discretizes a log entry into categorical items for association mining.
 */
final readonly class LogTransactionBuilder
{
    private const SLOW_RESPONSE_MS = 1000;
    private const MEDIUM_RESPONSE_MS = 100;
    private const LARGE_REQUEST_BYTES = 5000;
    private const MEDIUM_REQUEST_BYTES = 500;

    /**
     * @return list<string>
     */
    public function toTransaction(HttpLogEntry $entry): array
    {
        return [
            'method=' . $entry->method->value,
            'endpoint=' . $entry->endpoint,
            'status=' . $entry->statusCode,
            'hour=' . sprintf('%02d', $entry->hour),
            'time=' . self::responseBucket($entry->responseTime),
            'size=' . self::sizeBucket($entry->requestSize),
        ];
    }

    private static function responseBucket(float $responseTime): string
    {
        return match (true) {
            $responseTime >= self::SLOW_RESPONSE_MS => 'slow',
            $responseTime >= self::MEDIUM_RESPONSE_MS => 'medium',
            default => 'fast',
        };
    }

    private static function sizeBucket(int $requestSize): string
    {
        return match (true) {
            $requestSize >= self::LARGE_REQUEST_BYTES => 'large',
            $requestSize >= self::MEDIUM_REQUEST_BYTES => 'medium',
            default => 'small',
        };
    }
}
