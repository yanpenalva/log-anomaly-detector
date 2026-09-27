<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

/**
 * Sorted k-distance curve plus the epsilon suggested by its knee.
 */
final readonly class KDistanceSummary
{
    /**
     * @param list<float> $sortedDistances Descending k-th nearest-neighbor distances
     */
    public function __construct(
        public readonly array $sortedDistances,
        public readonly ?float $suggestedEpsilon,
    ) {
    }
}
