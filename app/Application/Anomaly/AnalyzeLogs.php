<?php

declare(strict_types=1);

namespace App\Application\Anomaly;

use App\Domain\Anomaly\AnalysisResult;
use App\Domain\Anomaly\AnalysisRun;
use App\Domain\Anomaly\AnalysisRunRepository;
use App\Domain\Anomaly\AnomalyDetectorFactory;
use App\Domain\Anomaly\ClassifiedLogEntry;
use App\Domain\Anomaly\DetectionAlgorithm;
use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\FeatureExtractor;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\LogEntryRepository;
use App\Domain\Anomaly\Normalizer;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Full analysis pipeline:
 *   entries → feature extraction → encoding (inside extraction)
 *           → normalization → DBSCAN → clusters/noise → persistence.
 */
final readonly class AnalyzeLogs
{
    public function __construct(
        private readonly FeatureExtractor $featureExtractor,
        private readonly Normalizer $normalizer,
        private readonly AnomalyDetectorFactory $detectorFactory,
        private readonly AnalysisRunRepository $runs,
        private readonly LogEntryRepository $logEntries,
    ) {
    }

    /**
     * @param list<HttpLogEntry> $entries
     *
     * @throws InvalidArgumentException On empty input
     */
    public function execute(DbscanParameters $parameters, array $entries): AnalysisResult
    {
        if ($entries === []) {
            throw new InvalidArgumentException('At least one log entry is required for an analysis');
        }

        $startedAt = new DateTimeImmutable('now');

        $rawVectors = array_map(fn (HttpLogEntry $entry) => $this->featureExtractor->extract($entry), $entries);

        $fitted = $this->normalizer->fit($rawVectors);
        $normalizedVectors = array_map(
            static fn ($vector) => $fitted->transform($vector),
            $rawVectors
        );

        $detection = $this->detectorFactory->create($parameters)->detect($normalizedVectors);

        $classified = [];
        foreach ($entries as $index => $entry) {
            $cluster = $detection->clusterOf($index);
            $classified[] = new ClassifiedLogEntry($entry, $cluster, $cluster === null);
        }

        $anomalyCount = $detection->anomalyCount();
        $run = $this->runs->insert(new AnalysisRun(
            null,
            DetectionAlgorithm::Dbscan,
            $parameters->epsilon,
            $parameters->minimumSamples,
            count($entries),
            $detection->clusterCount(),
            $anomalyCount,
            $startedAt,
            new DateTimeImmutable('now'),
        ));

        $runId = $run->id;
        if ($runId === null) {
            throw new \RuntimeException('Analysis run persistence failed to return an id');
        }

        $this->logEntries->insertMany($runId, $classified);

        return new AnalysisResult(
            $runId,
            DetectionAlgorithm::Dbscan,
            $parameters,
            count($entries),
            $detection->clusterCount(),
            $anomalyCount,
            $classified,
        );
    }
}
