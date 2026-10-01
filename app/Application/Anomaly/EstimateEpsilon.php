<?php

declare(strict_types=1);

namespace App\Application\Anomaly;

use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\FeatureExtractor;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\KDistanceAnalyzer;
use App\Domain\Anomaly\KDistanceSummary;
use App\Domain\Anomaly\Normalizer;
use InvalidArgumentException;

final readonly class EstimateEpsilon
{
    public function __construct(
        private readonly FeatureExtractor $featureExtractor,
        private readonly Normalizer $normalizer,
        private readonly KDistanceAnalyzer $kDistance,
    ) {
    }

    /**
     * @param list<HttpLogEntry> $entries
     *
     * @throws InvalidArgumentException On empty input
     */
    public function execute(DbscanParameters $parameters, array $entries): KDistanceSummary
    {
        if ($entries === []) {
            throw new InvalidArgumentException('At least one log entry is required for epsilon estimation');
        }

        $rawVectors = array_map(fn (HttpLogEntry $entry) => $this->featureExtractor->extract($entry), $entries);
        $fitted = $this->normalizer->fit($rawVectors);
        $vectors = array_map(static fn ($vector) => $fitted->transform($vector), $rawVectors);

        return $this->kDistance->analyze($vectors, max(1, $parameters->minimumSamples - 1));
    }
}
