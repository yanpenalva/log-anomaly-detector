<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

use InvalidArgumentException;

/**
 * Isolation Forest hyper-parameters: ensemble size, per-tree subsample size
 * and the isolation-score cutoff for the binary anomaly decision. An
 * optional seed makes runs reproducible (null = random seeding).
 */
final readonly class IsolationForestParameters
{
    public const MIN_TREES = 1;
    public const MAX_TREES = 1_000;
    public const MIN_SUBSAMPLE = 2;
    public const MAX_SUBSAMPLE = 10_000;
    public const MIN_THRESHOLD = 0.1;
    public const MAX_THRESHOLD = 1.0;
    public const DEFAULT_THRESHOLD = 0.6;
    private const INTEGER_PATTERN = '/^\d+$/';
    private const DECIMAL_PATTERN = '/^\d+(\.\d+)?$/';

    public function __construct(
        public readonly int $trees,
        public readonly int $subsampleSize,
        public readonly float $threshold,
        public readonly ?int $seed = null,
    ) {
    }

    public static function fromRaw(mixed $trees, mixed $subsampleSize, mixed $threshold, mixed $seed = null): self
    {
        return new self(
            self::trees($trees),
            self::subsampleSize($subsampleSize),
            self::threshold($threshold),
            self::seed($seed)
        );
    }

    private static function trees(mixed $value): int
    {
        $trees = self::coerceInt($value);

        if ($trees === null
            || $trees < self::MIN_TREES
            || $trees > self::MAX_TREES) {
            throw new InvalidArgumentException(sprintf(
                'trees must be an integer between %d and %d',
                self::MIN_TREES,
                self::MAX_TREES
            ));
        }

        return $trees;
    }

    private static function subsampleSize(mixed $value): int
    {
        $subsample = self::coerceInt($value);

        if ($subsample === null
            || $subsample < self::MIN_SUBSAMPLE
            || $subsample > self::MAX_SUBSAMPLE) {
            throw new InvalidArgumentException(sprintf(
                'subsample_size must be an integer between %d and %d',
                self::MIN_SUBSAMPLE,
                self::MAX_SUBSAMPLE
            ));
        }

        return $subsample;
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

    private static function seed(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $seed = self::coerceInt($value);

        return match ($seed) {
            null => throw new InvalidArgumentException('seed must be an integer or null'),
            default => $seed,
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

    private static function coerceInt(mixed $value): ?int
    {
        return match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match(self::INTEGER_PATTERN, $value) === 1 => (int) $value,
            default => null,
        };
    }
}
