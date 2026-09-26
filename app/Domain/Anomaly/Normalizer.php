<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

/**
 * Port for feature scaling: fit learns once per batch; the returned fitted
 * normalizer must be reused for every transform.
 */
interface Normalizer
{
    /**
     * @param list<FeatureVector> $vectors
     */
    public function fit(array $vectors): FittedNormalizer;
}
