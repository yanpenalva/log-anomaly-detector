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
    private const ACTIVE_FEATURE = 1.0;
    private const INACTIVE_FEATURE = 0.0;
    private const NUMERIC_SEGMENT_PLACEHOLDER = '{n}';

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
        $oneHot = array_fill(0, count($this->methodValues), self::INACTIVE_FEATURE);
        $index = array_search($method->value, $this->methodValues, true);

        if ($index !== false) {
            $oneHot[$index] = self::ACTIVE_FEATURE;
        }

        return $oneHot;
    }

    public function encodeEndpoint(string $endpoint): array
    {
        $buckets = array_fill(0, $this->endpointBuckets, self::INACTIVE_FEATURE);
        $buckets[crc32(self::normalizeEndpoint($endpoint)) % $this->endpointBuckets] = self::ACTIVE_FEATURE;

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
     * Deterministic endpoint normalization for hashing:
     *  - drops the query string (/products?page=2 → /products) so query
     *    parameters never fragment equivalent endpoints;
     *  - replaces only segments whose ENTIRE content is numeric with {n}
     *    (/users/1912 → /users/{n}), keeping versioned paths intact
     *    (/v2/users, /oauth2/callback are untouched).
     */
    public static function normalizeEndpoint(string $endpoint): string
    {
        $path = parse_url($endpoint, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : $endpoint;

        $segments = array_map(
            static fn (string $segment): string => ctype_digit($segment)
                ? self::NUMERIC_SEGMENT_PLACEHOLDER
                : $segment,
            explode('/', $path)
        );

        return implode('/', $segments);
    }
}
