<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

/**
 * Port for deterministic categorical-to-numeric encoding. Deliberately
 * not ordinal (GET=1, POST=2, ...): no fake ordering between categories.
 */
interface CategoricalEncoder
{
    /**
     * @return list<float>
     */
    public function encodeMethod(HttpMethod $method): array;

    /**
     * Feature hashing into a fixed bucket count; unseen endpoints encode consistently.
     *
     * @return list<float>
     */
    public function encodeEndpoint(string $endpoint): array;

    public function methodDimension(): int;

    public function endpointDimension(): int;
}
