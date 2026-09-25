<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

/**
 * Builds the numeric feature vector for one HTTP log entry.
 *
 * Layout (order is part of the contract with FittedNormalizer and the
 * detector):
 *   [ method one-hot ... ][ endpoint hash buckets ... ]
 *   [ status_code, response_time, request_size, hour ]
 *
 * Raw numerics are left unscaled here; scaling is the normalizer's job.
 * `hour` is kept as a single linear feature: with min-max + Euclidean
 * distance, cyclical sin/cos encoding spreads same-profile hours further
 * apart than epsilon and fragments density (documented in README).
 */
final readonly class FeatureExtractor
{
    public function __construct(private readonly CategoricalEncoder $encoder)
    {
    }

    public function extract(HttpLogEntry $entry): FeatureVector
    {
        return new FeatureVector(
            [
                ...$this->encoder->encodeMethod($entry->method),
                ...$this->encoder->encodeEndpoint($entry->endpoint),
                (float) $entry->statusCode,
                $entry->responseTime,
                (float) $entry->requestSize,
                (float) $entry->hour,
            ],
            [
                ...$this->methodNames(),
                ...$this->endpointNames(),
                'status_code',
                'response_time',
                'request_size',
                'hour',
            ]
        );
    }

    /**
     * @return list<string>
     */
    private function methodNames(): array
    {
        $names = [];
        foreach (HttpMethod::cases() as $index => $case) {
            $names[] = 'method_' . strtolower($case->value) . '_' . $index;
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function endpointNames(): array
    {
        $names = [];
        for ($i = 0; $i < $this->encoder->endpointDimension(); $i++) {
            $names[] = 'endpoint_hash_' . $i;
        }

        return $names;
    }
}
