<?php

declare(strict_types=1);

namespace App\Application\Anomaly;

use App\Domain\Anomaly\AnomalyDetectorFactory;
use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\FeatureExtractor;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\KDistanceAnalyzer;
use App\Domain\Anomaly\Normalizer;
use InvalidArgumentException;

/**
 * Re-runs the detection pipeline per time window (hour bucket) so drift
 * becomes visible: the same traffic shape can be normal at 03h and anomalous
 * at 14h. Each window gets its own extraction, normalization, DBSCAN pass
 * and knee epsilon.
 */
final readonly class AnalyzeWindows
{
    public function __construct(
        private readonly FeatureExtractor $featureExtractor,
        private readonly Normalizer $normalizer,
        private readonly AnomalyDetectorFactory $detectorFactory,
        private readonly KDistanceAnalyzer $kDistance,
    ) {
    }

    /**
     * @param list<HttpLogEntry> $entries
     *
     * @throws InvalidArgumentException On empty input
     */
    public function execute(DbscanParameters $parameters, array $entries): WindowAnalysisReport
    {
        if ($entries === []) {
            throw new InvalidArgumentException('At least one log entry is required for window analysis');
        }

        $grouped = [];
        foreach ($entries as $entry) {
            $grouped[$entry->hour][] = $entry;
        }
        ksort($grouped);

        $windows = [];
        foreach ($grouped as $hour => $windowEntries) {
            $windows[] = $this->analyzeWindow($parameters, (int) $hour, $windowEntries);
        }

        return new WindowAnalysisReport(count($entries), $windows);
    }

    /**
     * @param list<HttpLogEntry> $entries
     */
    private function analyzeWindow(DbscanParameters $parameters, int $hour, array $entries): WindowSlice
    {
        $rawVectors = array_map(fn (HttpLogEntry $entry) => $this->featureExtractor->extract($entry), $entries);
        $fitted = $this->normalizer->fit($rawVectors);
        $vectors = array_map(static fn ($vector) => $fitted->transform($vector), $rawVectors);

        $detection = $this->detectorFactory->create($parameters)->detect($vectors);
        $summary = $this->kDistance->analyze($vectors, max(1, $parameters->minimumSamples - 1));

        return new WindowSlice(
            $hour,
            count($entries),
            $detection->clusterCount(),
            $detection->anomalyCount(),
            $detection->sampleCount() > 0 ? $detection->anomalyCount() / $detection->sampleCount() : 0.0,
            $summary->suggestedEpsilon
        );
    }
}
