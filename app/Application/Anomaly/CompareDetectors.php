<?php

declare(strict_types=1);

namespace App\Application\Anomaly;

use App\Domain\Anomaly\AnomalyDetectorFactory;
use App\Domain\Anomaly\ClusteringMetrics;
use App\Domain\Anomaly\ConsensusRule;
use App\Domain\Anomaly\DetectionAlgorithm;
use App\Domain\Anomaly\DetectionResult;
use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\FeatureExtractor;
use App\Domain\Anomaly\FeatureVector;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\IsolationForestDetectorFactory;
use App\Domain\Anomaly\IsolationForestParameters;
use App\Domain\Anomaly\KMeansDetectorFactory;
use App\Domain\Anomaly\KMeansParameters;
use App\Domain\Anomaly\LofDetectorFactory;
use App\Domain\Anomaly\LofParameters;
use App\Domain\Anomaly\Normalizer;
use InvalidArgumentException;

/**
 * Runs four detector families (density, centroid, local-density, isolation)
 * over the same extracted + normalized vectors and reports family-fit
 * metrics plus the DBSCAN+LOF+iForest majority vote. Study-only: nothing is
 * persisted and the product anomaly semantics (DBSCAN noise) stay untouched.
 */
final readonly class CompareDetectors
{
    public function __construct(
        private readonly FeatureExtractor $featureExtractor,
        private readonly Normalizer $normalizer,
        private readonly AnomalyDetectorFactory $dbscanFactory,
        private readonly KMeansDetectorFactory $kmeansFactory,
        private readonly LofDetectorFactory $lofFactory,
        private readonly IsolationForestDetectorFactory $forestFactory,
    ) {
    }

    /**
     * @param list<HttpLogEntry> $entries
     *
     * @throws InvalidArgumentException On empty input
     */
    public function execute(
        DbscanParameters $dbscan,
        KMeansParameters $kmeans,
        LofParameters $lof,
        IsolationForestParameters $forest,
        array $entries
    ): ComparisonReport {
        if ($entries === []) {
            throw new InvalidArgumentException('At least one log entry is required for a comparison');
        }

        $vectors = $this->normalize($entries);
        $timings = [];
        $run = function (string $key, callable $detect) use (&$timings): DetectionResult {
            $started = hrtime(true);
            $result = $detect();
            $timings[$key] = (hrtime(true) - $started) / 1_000_000;

            return $result;
        };

        $dbscanResult = $run('dbscan', fn (): DetectionResult => $this->dbscanFactory->create($dbscan)->detect($vectors));
        $kmeansResult = $run('kmeans', fn (): DetectionResult => $this->kmeansFactory->create($kmeans)->detect($vectors));
        $lofResult = $run('lof', fn (): DetectionResult => $this->lofFactory->create($lof)->detect($vectors));
        $forestResult = $run('isolation_forest', fn (): DetectionResult => $this->forestFactory->create($forest)->detect($vectors));

        $rule = new ConsensusRule();
        $votes = [$dbscanResult, $lofResult, $forestResult];
        $consensus = $rule->apply($votes);

        return new ComparisonReport(count($vectors), [
            $this->report(DetectionAlgorithm::Dbscan, $dbscanResult, $vectors, $timings['dbscan']),
            $this->report(DetectionAlgorithm::KMeans, $kmeansResult, $vectors, $timings['kmeans']),
            $this->report(DetectionAlgorithm::Lof, $lofResult, $vectors, $timings['lof']),
            $this->report(DetectionAlgorithm::IsolationForest, $forestResult, $vectors, $timings['isolation_forest']),
        ], new ConsensusReport(
            $consensus->sampleCount(),
            $consensus->anomalyCount(),
            $rule->unanimousCount($votes),
            $rule->majorityOnlyCount($votes)
        ));
    }

    /**
     * @param list<HttpLogEntry> $entries
     *
     * @return list<FeatureVector>
     */
    private function normalize(array $entries): array
    {
        $rawVectors = array_map(fn (HttpLogEntry $entry) => $this->featureExtractor->extract($entry), $entries);
        $fitted = $this->normalizer->fit($rawVectors);

        return array_map(static fn ($vector) => $fitted->transform($vector), $rawVectors);
    }

    /**
     * @param list<FeatureVector> $vectors
     */
    private function report(DetectionAlgorithm $algorithm, DetectionResult $result, array $vectors, float $elapsedMs): DetectorReport
    {
        return new DetectorReport(
            $algorithm,
            $result->clusterCount(),
            $result->anomalyCount(),
            ClusteringMetrics::noiseRatio($result),
            ClusteringMetrics::silhouette($vectors, $result),
            ClusteringMetrics::inertia($vectors, $result),
            $elapsedMs,
        );
    }
}
