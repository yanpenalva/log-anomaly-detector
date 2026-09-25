<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

/**
 * Port for deterministic categorical-to-numeric encoding.
 *
 * Implementations must be stateless (or carry fitted state) so the exact
 * same encoding used during analysis can be reused for any future
 * per-sample transformation without recomputing category tables.
 *
 * Deliberately NOT an ordinal mapping (GET=1, POST=2, ...): one-hot and
 * feature hashing introduce no fake ordering between categories.
 */
interface CategoricalEncoder
{
    /**
     * One-hot encoding over a fixed, stable list of HTTP verbs.
     *
     * @return list<float>
     */
    public function encodeMethod(HttpMethod $method): array;

    /**
     * Feature hashing into a fixed number of buckets (hashing trick).
     * Deterministic; unseen endpoints still encode consistently.
     *
     * @return list<float>
     */
    public function encodeEndpoint(string $endpoint): array;

    public function methodDimension(): int;

    public function endpointDimension(): int;
}
