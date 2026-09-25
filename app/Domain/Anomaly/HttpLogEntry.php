<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

final readonly class HttpLogEntry
{
    private const MAX_ENDPOINT_LENGTH = 512;
    private const MAX_RESPONSE_TIME_MS = 86_400_000;
    private const MAX_REQUEST_SIZE_BYTES = 1_073_741_824;

    public function __construct(
        public readonly HttpMethod $method,
        public readonly string $endpoint,
        public readonly int $statusCode,
        public readonly float $responseTime,
        public readonly int $requestSize,
        public readonly int $hour,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidHttpLogEntry When any field is missing or out of range
     */
    public static function fromArray(array $data): self
    {
        foreach (['method', 'endpoint', 'status_code', 'response_time', 'request_size', 'hour'] as $field) {
            if (!array_key_exists($field, $data)) {
                throw InvalidHttpLogEntry::missingField($field);
            }
        }

        $method = $data['method'];
        if (!is_string($method)) {
            throw InvalidHttpLogEntry::invalidField('method', $method, 'must be a string');
        }

        $endpoint = $data['endpoint'];
        if (!is_string($endpoint)) {
            throw InvalidHttpLogEntry::invalidField('endpoint', $endpoint, 'must be a string');
        }
        if ($endpoint === '' || strlen($endpoint) > self::MAX_ENDPOINT_LENGTH) {
            throw InvalidHttpLogEntry::invalidField('endpoint', $endpoint, sprintf(
                'must be between 1 and %d characters',
                self::MAX_ENDPOINT_LENGTH
            ));
        }
        if (!mb_check_encoding($endpoint, 'UTF-8')) {
            throw InvalidHttpLogEntry::invalidField('endpoint', $endpoint, 'must be valid UTF-8');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $endpoint) === 1) {
            throw InvalidHttpLogEntry::invalidField('endpoint', $endpoint, 'must not contain control characters');
        }

        return new self(
            HttpMethod::fromString($method),
            $endpoint,
            self::intField($data, 'status_code', 100, 599),
            self::floatField($data, 'response_time', 0.0, self::MAX_RESPONSE_TIME_MS),
            self::intField($data, 'request_size', 0, self::MAX_REQUEST_SIZE_BYTES),
            self::intField($data, 'hour', 0, 23),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method->value,
            'endpoint' => $this->endpoint,
            'status_code' => $this->statusCode,
            'response_time' => $this->responseTime,
            'request_size' => $this->requestSize,
            'hour' => $this->hour,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function intField(array $data, string $field, int $min, int $max): int
    {
        $value = $data[$field];
        $intValue = self::coerceInt($value);

        if ($intValue === null || $intValue < $min || $intValue > $max) {
            throw InvalidHttpLogEntry::invalidField($field, $value, sprintf(
                'must be an integer between %d and %d',
                $min,
                $max
            ));
        }

        return $intValue;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function floatField(array $data, string $field, float $min, float $max): float
    {
        $value = $data[$field];

        if (is_int($value)) {
            $value = (float) $value;
        } elseif (is_string($value) && preg_match('/^\d+(\.\d+)?$/', $value) === 1) {
            $value = (float) $value;
        }

        if (!is_float($value)) {
            throw InvalidHttpLogEntry::invalidField($field, $value, 'must be a number');
        }

        $floatValue = $value;
        if (!is_finite($floatValue) || $floatValue < $min || $floatValue > $max) {
            throw InvalidHttpLogEntry::invalidField($field, $value, sprintf(
                'must be a finite number between %s and %s',
                (string) $min,
                (string) $max
            ));
        }

        return $floatValue;
    }

    private static function coerceInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && self::numericString($value)) {
            return (int) $value;
        }

        return null;
    }

    private static function numericString(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d+$/', $value) === 1;
    }
}
