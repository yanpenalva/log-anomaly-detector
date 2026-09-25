<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

use InvalidArgumentException;

/**
 * DBSCAN hyper-parameters.
 *
 * epsilon: neighborhood radius (Euclidean distance on normalized feature
 *          vectors). Points closer than epsilon are neighbors. PHP-ML uses
 *          a strict "<" comparison.
 * minimumSamples: minimum number of points inside a point's epsilon
 *          neighborhood (including itself) for it to be a core point.
 */
final readonly class DbscanParameters
{
    public const MIN_EPSILON = 0.001;
    public const MAX_EPSILON = 1000.0;
    public const MIN_MINIMUM_SAMPLES = 1;
    public const MAX_MINIMUM_SAMPLES = 10_000;
    private const DECIMAL_PATTERN = '/^\d+(\.\d+)?$/';
    private const INTEGER_PATTERN = '/^\d+$/';

    public function __construct(
        public readonly float $epsilon,
        public readonly int $minimumSamples,
    ) {
    }

    public static function fromRaw(mixed $epsilon, mixed $minimumSamples): self
    {
        return new self(
            self::epsilon($epsilon),
            self::minimumSamples($minimumSamples)
        );
    }

    private static function epsilon(mixed $value): float
    {
        $epsilon = self::coerceFloat($value);

        if ($epsilon === null || $epsilon < self::MIN_EPSILON || $epsilon > self::MAX_EPSILON) {
            throw new InvalidArgumentException(sprintf(
                'epsilon must be a number between %s and %s',
                (string) self::MIN_EPSILON,
                (string) self::MAX_EPSILON
            ));
        }

        return $epsilon;
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

    private static function coerceFloat(mixed $value): ?float
    {
        return match (true) {
            is_int($value), is_float($value) => (float) $value,
            is_string($value) && preg_match(self::DECIMAL_PATTERN, $value) === 1 => (float) $value,
            default => null,
        };
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
