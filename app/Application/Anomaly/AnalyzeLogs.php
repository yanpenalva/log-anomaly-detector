<?php

declare(strict_types=1);

namespace App\Application\Anomaly;

use App\Domain\Anomaly\AnalysisResult;
use App\Domain\Anomaly\AnalysisResultRepository;
use App\Domain\Anomaly\AnalysisRun;
use App\Domain\Anomaly\AnomalyDetectorFactory;
use App\Domain\Anomaly\ClassifiedLogEntry;
use App\Domain\Anomaly\DetectionAlgorithm;
use App\Domain\Anomaly\DetectionResult;
use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\FeatureExtractor;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\Normalizer;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AnalyzeLogs
{
    public function __construct(
        private readonly FeatureExtractor $featureExtractor,
        private readonly Normalizer $normalizer,
        private readonly AnomalyDetectorFactory $detectorFactory,
        private readonly AnalysisResultRepository $results,
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

        $detection = $this->detect($parameters, $entries);
        $classified = $this->classify($entries, $detection);

        $run = $this->results->save(new AnalysisRun(
            null,
            DetectionAlgorithm::Dbscan,
            $parameters->epsilon,
            $parameters->minimumSamples,
            count($entries),
            $detection->clusterCount(),
            $detection->anomalyCount(),
            $startedAt,
            new DateTimeImmutable('now')
        ), $classified);

        return new AnalysisResult(
            $run->id,
            DetectionAlgorithm::Dbscan,
            $parameters,
            count($entries),
            $detection->clusterCount(),
            $detection->anomalyCount(),
            $classified,
        );
    }

    /**
     * @param list<HttpLogEntry> $entries
     */
    private function detect(DbscanParameters $parameters, array $entries): DetectionResult
    {
        $rawVectors = array_map(fn (HttpLogEntry $entry) => $this->featureExtractor->extract($entry), $entries);
        $fitted = $this->normalizer->fit($rawVectors);
        $normalizedVectors = array_map(static fn ($vector) => $fitted->transform($vector), $rawVectors);

        return $this->detectorFactory->create($parameters)->detect($normalizedVectors);
    }

    /**
     * @param list<HttpLogEntry> $entries
     *
     * @return list<ClassifiedLogEntry>
     */
    private function classify(array $entries, DetectionResult $detection): array
    {
        return array_map(
            function (HttpLogEntry $entry, int $index) use ($detection): ClassifiedLogEntry {
                $cluster = $detection->clusterOf($index);

                return new ClassifiedLogEntry($entry, $cluster, $cluster === null);
            },
            $entries,
            array_keys($entries)
        );
    }
}
