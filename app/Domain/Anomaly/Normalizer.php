<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

/**
 * Port for feature scaling. `fit` learns parameters from a full batch;
 * the returned fitted normalizer must then be reused for every transform
 * so analysis and any later transformation share the same parameters.
 */
interface Normalizer
{
    /**
     * @param list<FeatureVector> $vectors
     */
    public function fit(array $vectors): FittedNormalizer;
}
