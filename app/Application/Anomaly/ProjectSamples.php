<?php

declare(strict_types=1);

namespace App\Application\Anomaly;

use App\Domain\Anomaly\AnomalyDetectorFactory;
use App\Domain\Anomaly\DetectionResult;
use App\Domain\Anomaly\DimensionalityReduction;
use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\FeatureExtractor;
use App\Domain\Anomaly\FeatureVector;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\Normalizer;
use InvalidArgumentException;

final readonly class ProjectSamples
{
    public function __construct(
        private readonly FeatureExtractor $featureExtractor,
        private readonly Normalizer $normalizer,
        private readonly AnomalyDetectorFactory $detectorFactory,
        private readonly DimensionalityReduction $reduction,
    ) {
    }

    /**
     * @param list<HttpLogEntry> $entries
     *
     * @throws InvalidArgumentException On empty input
     */
    public function execute(DbscanParameters $parameters, array $entries): ProjectionReport
    {
        if ($entries === []) {
            throw new InvalidArgumentException('At least one log entry is required for a projection');
        }

        $rawVectors = array_map(fn (HttpLogEntry $entry) => $this->featureExtractor->extract($entry), $entries);
        $fitted = $this->normalizer->fit($rawVectors);
        $vectors = array_map(static fn ($vector) => $fitted->transform($vector), $rawVectors);

        $detection = $this->detectorFactory->create($parameters)->detect($vectors);
        $projected = $this->reduction->reduce($vectors);

        return new ProjectionReport(count($projected), $this->samples($projected, $detection));
    }

    /**
     * @param list<FeatureVector> $projected
     *
     * @return list<ProjectedSample>
     */
    private function samples(array $projected, DetectionResult $detection): array
    {
        $samples = [];

        foreach ($projected as $index => $vector) {
            $cluster = $detection->clusterOf((int) $index);
            $samples[] = new ProjectedSample($vector->values(), $cluster, $cluster === null);
        }

        return $samples;
    }
}
