<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineLearning;

use App\Domain\Anomaly\CategoricalEncoder;
use App\Domain\Anomaly\HttpMethod;
use InvalidArgumentException;

/**
 * Deterministic categorical encoding:
 *  - method: one-hot over HttpMethod::cases() declaration order (stable);
 *  - endpoint: feature hashing (crc32 mod bucket count) into a fixed
 *    vector after normalizing the path (numeric segments collapse to
 *    "{n}", so /users/1912 and /users/1769 hash to the same bucket).
 *    No ordinal relations are introduced; unseen endpoints still encode
 *    consistently. Bucket collisions are a documented trade-off of the
 *    hashing trick.
 */
final readonly class LogCategoricalEncoder implements CategoricalEncoder
{
    public const MIN_BUCKETS = 1;
    public const MAX_BUCKETS = 256;

    /** @var list<string> */
    private array $methodValues;

    public function __construct(private int $endpointBuckets = 16)
    {
        if ($endpointBuckets < self::MIN_BUCKETS || $endpointBuckets > self::MAX_BUCKETS) {
            throw new InvalidArgumentException(sprintf(
                'endpoint buckets must be between %d and %d',
                self::MIN_BUCKETS,
                self::MAX_BUCKETS
            ));
        }

        $this->methodValues = array_map(static fn (HttpMethod $case) => $case->value, HttpMethod::cases());
    }

    public function encodeMethod(HttpMethod $method): array
    {
        $oneHot = array_fill(0, count($this->methodValues), 0.0);
        $index = array_search($method->value, $this->methodValues, true);

        if ($index !== false) {
            $oneHot[$index] = 1.0;
        }

        return $oneHot;
    }

    public function encodeEndpoint(string $endpoint): array
    {
        $buckets = array_fill(0, $this->endpointBuckets, 0.0);
        $buckets[crc32(self::normalizeEndpoint($endpoint)) % $this->endpointBuckets] = 1.0;

        return $buckets;
    }

    public function methodDimension(): int
    {
        return count($this->methodValues);
    }

    public function endpointDimension(): int
    {
        return $this->endpointBuckets;
    }

    /**
     * Collapse parameterized path segments so parameterized URLs share a
     * bucket instead of fragmenting density: /users/1912 → /users/{n}.
     */
    public static function normalizeEndpoint(string $endpoint): string
    {
        return (string) preg_replace('/\d+/', '{n}', $endpoint);
    }
}
