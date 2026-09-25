<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

final readonly class HttpLogEntry
{
    private const MAX_ENDPOINT_LENGTH = 512;
    private const MAX_RESPONSE_TIME_MS = 86_400_000;
    private const MAX_REQUEST_SIZE_BYTES = 1_073_741_824;
    private const MIN_STATUS_CODE = 100;
    private const MAX_STATUS_CODE = 599;
    private const MAX_HOUR = 23;
    private const CONTROL_CHARS_PATTERN = '/[\x00-\x1F\x7F]/';
    private const INTEGER_PATTERN = '/^\d+$/';
    private const DECIMAL_PATTERN = '/^\d+(\.\d+)?$/';

    /**
     * Field order defines the canonical public representation (see toArray).
     */
    private const REQUIRED_FIELDS = ['method', 'endpoint', 'status_code', 'response_time', 'request_size', 'hour'];

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
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $data)) {
                throw InvalidHttpLogEntry::missingField($field);
            }
        }

        return new self(
            self::methodField($data),
            self::endpointField($data),
            self::intField($data, 'status_code', self::MIN_STATUS_CODE, self::MAX_STATUS_CODE),
            self::floatField($data, 'response_time', 0.0, self::MAX_RESPONSE_TIME_MS),
            self::intField($data, 'request_size', 0, self::MAX_REQUEST_SIZE_BYTES),
            self::intField($data, 'hour', 0, self::MAX_HOUR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_combine(
            self::REQUIRED_FIELDS,
            [
                $this->method->value,
                $this->endpoint,
                $this->statusCode,
                $this->responseTime,
                $this->requestSize,
                $this->hour,
            ]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function methodField(array $data): HttpMethod
    {
        $value = $data['method'];
        if (!is_string($value)) {
            throw InvalidHttpLogEntry::invalidField('method', $value, 'must be a string');
        }

        return HttpMethod::fromString($value);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function endpointField(array $data): string
    {
        $value = $data['endpoint'];
        if (!is_string($value)) {
            throw InvalidHttpLogEntry::invalidField('endpoint', $value, 'must be a string');
        }
        if ($value === '' || strlen($value) > self::MAX_ENDPOINT_LENGTH) {
            throw InvalidHttpLogEntry::invalidField('endpoint', $value, sprintf(
                'must be between 1 and %d characters',
                self::MAX_ENDPOINT_LENGTH
            ));
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw InvalidHttpLogEntry::invalidField('endpoint', $value, 'must be valid UTF-8');
        }
        if (preg_match(self::CONTROL_CHARS_PATTERN, $value) === 1) {
            throw InvalidHttpLogEntry::invalidField('endpoint', $value, 'must not contain control characters');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function intField(array $data, string $field, int $min, int $max): int
    {
        $value = self::coerceInt($data[$field]);

        if ($value === null || $value < $min || $value > $max) {
            throw InvalidHttpLogEntry::invalidField($field, $data[$field], sprintf(
                'must be an integer between %d and %d',
                $min,
                $max
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function floatField(array $data, string $field, float $min, float $max): float
    {
        $value = self::coerceFloat($data[$field]);

        if ($value === null || !is_finite($value) || $value < $min || $value > $max) {
            throw InvalidHttpLogEntry::invalidField($field, $data[$field], sprintf(
                'must be a finite number between %s and %s',
                (string) $min,
                (string) $max
            ));
        }

        return $value;
    }

    private static function coerceInt(mixed $value): ?int
    {
        return match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match(self::INTEGER_PATTERN, $value) === 1 => (int) $value,
            default => null,
        };
    }

    private static function coerceFloat(mixed $value): ?float
    {
        return match (true) {
            is_int($value), is_float($value) => (float) $value,
            is_string($value) && preg_match(self::DECIMAL_PATTERN, $value) === 1 => (float) $value,
            default => null,
        };
    }
}
