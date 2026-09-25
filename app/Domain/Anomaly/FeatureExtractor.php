<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

use LogicException;

/**
 * Builds the numeric feature vector for one HTTP log entry.
 *
 * Layout (order is part of the contract with FittedNormalizer and the
 * detector):
 *   [ method one-hot ... ][ endpoint hash buckets ... ][ status class one-hot ... ][ numeric tail ]
 *
 * Status codes are encoded one-hot by HTTP class (1xx..5xx): the raw code
 * is a category, not a quantity — a Euclidean metric over raw codes would
 * imply /v2 vs /oauth2-style fake relations between 404 and 500.
 *
 * Raw numerics are left unscaled here; scaling is the normalizer's job.
 * `hour` is kept as a single linear feature: with min-max + Euclidean
 * distance, cyclical sin/cos encoding spreads same-profile hours further
 * apart than epsilon and fragments density (documented in README).
 */
final readonly class FeatureExtractor
{
    private const METHOD_FEATURE_PREFIX = 'method_';
    private const ENDPOINT_FEATURE_PREFIX = 'endpoint_hash_';
    private const STATUS_CLASS_FEATURE_PREFIX = 'status_';
    private const STATUS_CLASS_COUNT = 5;
    private const STATUS_CLASS_WIDTH = 100;
    private const HOUR_FEATURE = 'hour';

    /**
     * Numeric feature tail. Order defines vector positions.
     *
     * @var list<string>
     */
    private const NUMERIC_FEATURES = ['response_time', 'request_size', self::HOUR_FEATURE];

    public function __construct(private readonly CategoricalEncoder $encoder)
    {
    }

    public function extract(HttpLogEntry $entry): FeatureVector
    {
        return new FeatureVector(
            [
                ...$this->encoder->encodeMethod($entry->method),
                ...$this->encoder->encodeEndpoint($entry->endpoint),
                ...$this->encodeStatusClass($entry->statusCode),
                ...$this->numericValues($entry),
            ],
            [
                ...$this->methodNames(),
                ...$this->endpointNames(),
                ...$this->statusClassNames(),
                ...self::NUMERIC_FEATURES,
            ]
        );
    }

    /**
     * One-hot over HTTP response classes: 200 → status_2xx, 503 → status_5xx.
     *
     * @return list<float>
     */
    private function encodeStatusClass(int $statusCode): array
    {
        $oneHot = array_fill(0, self::STATUS_CLASS_COUNT, 0.0);
        $class = intdiv($statusCode, self::STATUS_CLASS_WIDTH);
        $index = min(max($class, 1), self::STATUS_CLASS_COUNT) - 1;
        $oneHot[$index] = 1.0;

        return $oneHot;
    }

    /**
     * @return list<string>
     */
    private function statusClassNames(): array
    {
        return array_map(
            fn (int $class): string => self::STATUS_CLASS_FEATURE_PREFIX . $class . 'xx',
            range(1, self::STATUS_CLASS_COUNT)
        );
    }

    /**
     * @return list<float>
     */
    private function numericValues(HttpLogEntry $entry): array
    {
        return array_map(
            fn (string $feature): float => $this->numericValue($feature, $entry),
            self::NUMERIC_FEATURES
        );
    }

    private function numericValue(string $feature, HttpLogEntry $entry): float
    {
        return match ($feature) {
            'response_time' => $entry->responseTime,
            'request_size' => (float) $entry->requestSize,
            self::HOUR_FEATURE => (float) $entry->hour,
            default => throw new LogicException(sprintf('Unknown numeric feature "%s"', $feature)),
        };
    }

    /**
     * @return list<string>
     */
    private function methodNames(): array
    {
        return array_map(
            fn (HttpMethod $case): string => self::METHOD_FEATURE_PREFIX . strtolower($case->value),
            HttpMethod::cases()
        );
    }

    /**
     * @return list<string>
     */
    private function endpointNames(): array
    {
        return array_map(
            fn (int $bucket): string => self::ENDPOINT_FEATURE_PREFIX . $bucket,
            range(0, $this->encoder->endpointDimension() - 1)
        );
    }
}
