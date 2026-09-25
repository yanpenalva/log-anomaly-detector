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

    public function __construct(
        public readonly float $epsilon,
        public readonly int $minimumSamples,
    ) {
    }

    public static function fromRaw(mixed $epsilon, mixed $minimumSamples): self
    {
        $epsilonValue = self::coerceFloat($epsilon);
        if ($epsilonValue === null || $epsilonValue < self::MIN_EPSILON || $epsilonValue > self::MAX_EPSILON) {
            throw new InvalidArgumentException(sprintf(
                'epsilon must be a number between %s and %s',
                (string) self::MIN_EPSILON,
                (string) self::MAX_EPSILON
            ));
        }

        $minSamplesValue = self::coerceInt($minimumSamples);
        if ($minSamplesValue === null
            || $minSamplesValue < self::MIN_MINIMUM_SAMPLES
            || $minSamplesValue > self::MAX_MINIMUM_SAMPLES) {
            throw new InvalidArgumentException(sprintf(
                'minimum_samples must be an integer between %d and %d',
                self::MIN_MINIMUM_SAMPLES,
                self::MAX_MINIMUM_SAMPLES
            ));
        }

        return new self($epsilonValue, $minSamplesValue);
    }

    private static function coerceFloat(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && preg_match('/^\d+(\.\d+)?$/', $value) === 1) {
            return (float) $value;
        }

        return null;
    }

    private static function coerceInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
