<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

use LogicException;

/**
 * Builds the numeric feature vector for one HTTP log entry.
 *
 * Layout (order is part of the contract with FittedNormalizer and the
 * detector):
 *   [ method one-hot ... ][ endpoint hash buckets ... ][ numeric tail ]
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

    /**
     * Numeric feature tail. Order defines vector positions.
     *
     * @var list<string>
     */
    private const NUMERIC_FEATURES = ['status_code', 'response_time', 'request_size', 'hour'];

    public function __construct(private readonly CategoricalEncoder $encoder)
    {
    }

    public function extract(HttpLogEntry $entry): FeatureVector
    {
        return new FeatureVector(
            [
                ...$this->encoder->encodeMethod($entry->method),
                ...$this->encoder->encodeEndpoint($entry->endpoint),
                ...$this->numericValues($entry),
            ],
            [
                ...$this->methodNames(),
                ...$this->endpointNames(),
                ...self::NUMERIC_FEATURES,
            ]
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
            'status_code' => (float) $entry->statusCode,
            'response_time' => $entry->responseTime,
            'request_size' => (float) $entry->requestSize,
            'hour' => (float) $entry->hour,
            default => throw new LogicException(sprintf('Unknown numeric feature "%s"', $feature)),
        };
    }

    /**
     * @return list<string>
     */
    private function methodNames(): array
    {
        return array_map(
            fn (HttpMethod $case): string => self::METHOD_FEATURE_PREFIX
                . strtolower($case->value),
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
