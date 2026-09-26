<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineLearning;

use App\Domain\Anomaly\FeatureVector;
use App\Domain\Anomaly\FittedNormalizer;
use App\Domain\Anomaly\Normalizer;
use InvalidArgumentException;

/**
 * Min-max scaling to [0, 1]; parameters learned once per batch with fit().
 */
final readonly class MinMaxNormalizer implements Normalizer
{
    public function fit(array $vectors): FittedNormalizer
    {
        if ($vectors === []) {
            throw new InvalidArgumentException('Cannot fit normalizer on an empty batch');
        }

        $first = $vectors[0] ?? null;
        if (!$first instanceof FeatureVector) {
            throw new InvalidArgumentException('Expected a list of FeatureVector');
        }

        $dimension = $first->dimension();
        $mins = $first->values();
        $maxs = $first->values();

        foreach (array_slice($vectors, 1) as $vector) {
            if (!$vector instanceof FeatureVector || $vector->dimension() !== $dimension) {
                throw new InvalidArgumentException('All feature vectors must share the same dimension');
            }

            foreach ($vector->values() as $i => $value) {
                if ($value < $mins[$i]) {
                    $mins[$i] = $value;
                }
                if ($value > $maxs[$i]) {
                    $maxs[$i] = $value;
                }
            }
        }

        return new FittedMinMaxNormalizer($mins, $maxs);
    }
}
