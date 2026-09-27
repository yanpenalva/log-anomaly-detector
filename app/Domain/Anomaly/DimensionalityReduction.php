<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

/**
 * Port to a lossy dimensionality reducer fitted once per batch.
 */
interface DimensionalityReduction
{
    /**
     * @param list<FeatureVector> $vectors
     *
     * @return list<FeatureVector>
     */
    public function reduce(array $vectors): array;
}
