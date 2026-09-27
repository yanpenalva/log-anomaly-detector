<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineLearning;

use App\Domain\Anomaly\DimensionalityReduction;
use App\Domain\Anomaly\FeatureVector;
use Phpml\DimensionReduction\PCA;
use RuntimeException;

final readonly class PhpMlPcaTransformer implements DimensionalityReduction
{
    public function __construct(private readonly int $dimensions)
    {
    }

    public function reduce(array $vectors): array
    {
        if ($vectors === []) {
            throw new RuntimeException('PCA requires at least one feature vector');
        }

        $dimension = $vectors[0]->dimension();
        if ($this->dimensions < 1 || $this->dimensions >= $dimension) {
            throw new RuntimeException(sprintf(
                'PCA dimensions must be between 1 and %d',
                $dimension - 1
            ));
        }
        if (count($vectors) <= $dimension) {
            throw new RuntimeException(sprintf(
                'PCA needs more samples (%d given) than feature dimensions (%d); paste more logs',
                count($vectors),
                $dimension
            ));
        }

        $samples = array_map(static fn (FeatureVector $vector) => $vector->values(), $vectors);
        $pca = new PCA(null, $this->dimensions);
        $projected = $pca->fit($samples);

        return $this->toVectors($projected);
    }

    /**
     * @param array<int, array<int, float|int>> $projected
     *
     * @return list<FeatureVector>
     */
    private function toVectors(array $projected): array
    {
        $names = array_map(
            fn (int $index): string => 'pc' . ($index + 1),
            range(0, $this->dimensions - 1)
        );

        $vectors = [];
        foreach ($projected as $row) {
            $vectors[] = new FeatureVector(
                array_map(static fn (float|int $value): float => (float) $value, array_values($row)),
                $names
            );
        }

        return $vectors;
    }
}
