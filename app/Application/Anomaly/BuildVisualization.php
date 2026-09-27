<?php

declare(strict_types=1);

namespace App\Application\Anomaly;

use App\Domain\Anomaly\AnomalyDetectorFactory;
use App\Domain\Anomaly\DimensionalityReduction;
use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\FeatureExtractor;
use App\Domain\Anomaly\FeatureVector;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\KDistanceAnalyzer;
use App\Domain\Anomaly\Normalizer;
use InvalidArgumentException;
use RuntimeException;

final readonly class BuildVisualization
{
    public function __construct(
        private readonly FeatureExtractor $featureExtractor,
        private readonly Normalizer $normalizer,
        private readonly AnomalyDetectorFactory $detectorFactory,
        private readonly DimensionalityReduction $reduction,
        private readonly KDistanceAnalyzer $kDistance,
    ) {
    }

    /**
     * @param list<HttpLogEntry> $entries
     *
     * @throws InvalidArgumentException On empty input
     * @throws RuntimeException When the input cannot be projected
     */
    public function execute(DbscanParameters $parameters, array $entries): VisualizationData
    {
        if ($entries === []) {
            throw new InvalidArgumentException('At least one log entry is required for a visualization');
        }

        $rawVectors = array_map(fn (HttpLogEntry $entry) => $this->featureExtractor->extract($entry), $entries);
        $fitted = $this->normalizer->fit($rawVectors);
        $vectors = array_map(static fn ($vector) => $fitted->transform($vector), $rawVectors);

        $detection = $this->detectorFactory->create($parameters)->detect($vectors);
        $projected = $this->reduction->reduce($vectors);

        $samples = array_map(
            fn (int $index, FeatureVector $vector): ProjectedSample => new ProjectedSample(
                $vector->values(),
                $detection->clusterOf($index),
                $detection->isNoise($index)
            ),
            array_keys($projected),
            $projected
        );

        $summary = $this->kDistance->analyze($vectors, max(1, $parameters->minimumSamples - 1));

        return new VisualizationData(
            new ProjectionReport(count($projected), array_values($samples)),
            $summary
        );
    }
}
