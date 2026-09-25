<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineLearning;

use App\Domain\Anomaly\AnomalyDetector;
use App\Domain\Anomaly\DetectionResult;
use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\FeatureVector;
use Phpml\Clustering\DBSCAN;
use Phpml\Math\Distance\Euclidean;
use RuntimeException;

/**
 * PHP-ML DBSCAN wrapped behind the domain port.
 *
 * How DBSCAN is used here (and what it genuinely provides):
 *  - A point is a CORE point when its epsilon-neighborhood (Euclidean,
 *    strict "<") contains at least minimumSamples points (itself included).
 *  - Core points and everything density-reachable from them form clusters.
 *  - Points that end up in no cluster are NOISE. In this domain, a noise
 *    point is an anomaly: its neighborhood is too sparse to belong to any
 *    known traffic pattern.
 *
 * DBSCAN provides no probability, confidence or score — only membership.
 * Cluster ids are the order in which clusters were discovered (0, 1, 2, ...)
 * and are deterministic for a fixed input order.
 *
 * This is a BATCH algorithm: every call re-clusters the given samples.
 * There is no incremental "classify one new point" inference.
 */
final readonly class PhpMlDbscanDetector implements AnomalyDetector
{
    public function __construct(private readonly DbscanParameters $parameters)
    {
    }

    public function detect(array $vectors): DetectionResult
    {
        if ($vectors === []) {
            throw new RuntimeException('DBSCAN requires at least one feature vector');
        }

        $samples = array_map(static fn (FeatureVector $vector) => $vector->values(), $vectors);

        $clusterer = new DBSCAN(
            $this->parameters->epsilon,
            $this->parameters->minimumSamples,
            new Euclidean()
        );

        /** @var array<int, array<int, list<float>>> $clusters clusterId => members */
        $clusters = $clusterer->cluster($samples);

        return new DetectionResult($this->reconstructAssignments($samples, $clusters));
    }

    /**
     * PHP-ML's DBSCAN::cluster() renumbers member keys per cluster
     * (array_merge inside groupByCluster), so the original sample index
     * is lost in the output. Recover it via multiset matching: identical
     * vectors always receive identical DBSCAN labels (same distances to
     * every point), so each cluster owns a disjoint multiset of vectors
     * and dataset-order consumption is unambiguous.
     *
     * @param list<list<float>> $samples
     * @param array<int, array<int, list<float>>> $clusters
     *
     * @return list<int|null>
     */
    private function reconstructAssignments(array $samples, array $clusters): array
    {
        /** @var array<string, array<int, int>> $remaining serialized sample => clusterId => count */
        $remaining = [];
        foreach ($clusters as $clusterId => $members) {
            foreach ($members as $member) {
                $key = self::sampleKey($member);
                $remaining[$key][(int) $clusterId] = ($remaining[$key][(int) $clusterId] ?? 0) + 1;
            }
        }

        $assignments = [];
        foreach ($samples as $sample) {
            $key = self::sampleKey($sample);
            $assigned = null;
            foreach ($remaining[$key] ?? [] as $clusterId => $count) {
                if ($count > 0) {
                    $assigned = $clusterId;
                    $remaining[$key][$clusterId] = $count - 1;
                    break;
                }
            }
            $assignments[] = $assigned;
        }

        return $assignments;
    }

    /**
     * @param list<float> $sample
     */
    private static function sampleKey(array $sample): string
    {
        return serialize($sample);
    }
}
