<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

use InvalidArgumentException;

/**
 * K-Means hyper-parameters: clusters (k, the fixed centroid count) and
 * minimumSamples, the density bridge to DBSCAN — clusters smaller than this
 * are reported as anomalies because no DBSCAN-style "noise" exists here.
 */
final readonly class KMeansParameters
{
    public const MIN_CLUSTERS = 1;
    public const MAX_CLUSTERS = 10_000;
    public const MIN_MINIMUM_SAMPLES = 1;
    public const MAX_MINIMUM_SAMPLES = 10_000;
    private const INTEGER_PATTERN = '/^\d+$/';

    public function __construct(
        public readonly int $clusters,
        public readonly int $minimumSamples,
    ) {
    }

    public static function fromRaw(mixed $clusters, mixed $minimumSamples): self
    {
        return new self(
            self::clusters($clusters),
            self::minimumSamples($minimumSamples)
        );
    }

    private static function clusters(mixed $value): int
    {
        $clusters = self::coerceInt($value);

        if ($clusters === null
            || $clusters < self::MIN_CLUSTERS
            || $clusters > self::MAX_CLUSTERS) {
            throw new InvalidArgumentException(sprintf(
                'clusters must be an integer between %d and %d',
                self::MIN_CLUSTERS,
                self::MAX_CLUSTERS
            ));
        }

        return $clusters;
    }

    private static function minimumSamples(mixed $value): int
    {
        $minimumSamples = self::coerceInt($value);

        if ($minimumSamples === null
            || $minimumSamples < self::MIN_MINIMUM_SAMPLES
            || $minimumSamples > self::MAX_MINIMUM_SAMPLES) {
            throw new InvalidArgumentException(sprintf(
                'minimum_samples must be an integer between %d and %d',
                self::MIN_MINIMUM_SAMPLES,
                self::MAX_MINIMUM_SAMPLES
            ));
        }

        return $minimumSamples;
    }

    private static function coerceInt(mixed $value): ?int
    {
        return match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match(self::INTEGER_PATTERN, $value) === 1 => (int) $value,
            default => null,
        };
    }
}
