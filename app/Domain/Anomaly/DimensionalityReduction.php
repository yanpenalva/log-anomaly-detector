<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

use RuntimeException;

/**
 * Port to a lossy dimensionality reducer fitted once per batch.
 */
interface DimensionalityReduction
{
    /**
     * @param list<FeatureVector> $vectors
     *
     * @return list<FeatureVector>
     *
     * @throws RuntimeException When input cannot be reduced
     */
    public function reduce(array $vectors): array;
}
