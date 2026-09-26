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
 * PHP-ML DBSCAN behind the domain port. Euclidean distance, strict "<"
 * epsilon, batch-only, membership only (no confidence). Cluster ids follow
 * discovery order and are deterministic for a fixed input order.
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
     * PHP-ML renumbers member keys inside cluster(), losing original sample
     * indices. Identical vectors always receive identical labels, so a
     * multiset match per cluster restores dataset-order assignments.
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
