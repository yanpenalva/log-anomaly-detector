<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

use InvalidArgumentException;

/**
 * LOF hyper-parameters: minPts neighbors define the local neighborhood and
 * threshold is the binary cutoff — a local outlier factor above it means
 * anomaly. The factor itself never leaves the detector (no scores in the
 * domain or API).
 */
final readonly class LofParameters
{
    public const MIN_MIN_PTS = 1;
    public const MAX_MIN_PTS = 10_000;
    public const MIN_THRESHOLD = 1.0;
    public const MAX_THRESHOLD = 100.0;
    public const DEFAULT_THRESHOLD = 1.5;
    private const INTEGER_PATTERN = '/^\d+$/';
    private const DECIMAL_PATTERN = '/^\d+(\.\d+)?$/';

    public function __construct(
        public readonly int $minPts,
        public readonly float $threshold,
    ) {
    }

    public static function fromRaw(mixed $minPts, mixed $threshold): self
    {
        return new self(
            self::minPts($minPts),
            self::threshold($threshold)
        );
    }

    private static function minPts(mixed $value): int
    {
        $minPts = self::coerceInt($value);

        if ($minPts === null
            || $minPts < self::MIN_MIN_PTS
            || $minPts > self::MAX_MIN_PTS) {
            throw new InvalidArgumentException(sprintf(
                'min_pts must be an integer between %d and %d',
                self::MIN_MIN_PTS,
                self::MAX_MIN_PTS
            ));
        }

        return $minPts;
    }

    private static function threshold(mixed $value): float
    {
        $threshold = self::coerceFloat($value);

        if ($threshold === null
            || $threshold < self::MIN_THRESHOLD
            || $threshold > self::MAX_THRESHOLD) {
            throw new InvalidArgumentException(sprintf(
                'threshold must be a number between %s and %s',
                (string) self::MIN_THRESHOLD,
                (string) self::MAX_THRESHOLD
            ));
        }

        return $threshold;
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
