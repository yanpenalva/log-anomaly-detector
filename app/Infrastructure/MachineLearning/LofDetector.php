<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineLearning;

use App\Domain\Anomaly\AnomalyDetector;
use App\Domain\Anomaly\DetectionResult;
use App\Domain\Anomaly\FeatureVector;
use App\Domain\Anomaly\LofParameters;
use RuntimeException;

/**
 * Local Outlier Factor (Breunig et al., 2000), hand-rolled behind the
 * detector port. Points whose local density deviates from their neighbors'
 * density exceed the threshold and are reported as anomalies (null cluster);
 * everything else joins the single "normal" label. The LOF value itself is
 * internal only — this domain carries no scores.
 */
final readonly class LofDetector implements AnomalyDetector
{
    public function __construct(private readonly LofParameters $parameters)
    {
    }

    public function detect(array $vectors): DetectionResult
    {
        if ($vectors === []) {
            throw new RuntimeException('LOF requires at least one feature vector');
        }

        $k = min($this->parameters->minPts, count($vectors) - 1);
        $kDistances = $this->kDistances($vectors, max(1, $k));
        $neighborhoods = $this->neighborhoods($vectors, max(1, $k));
        $lrds = $this->localReachabilityDensities($vectors, $kDistances, $neighborhoods);

        return new DetectionResult($this->assignments($neighborhoods, $lrds));
    }

    /**
     * @param array<int, list<int>> $neighborhoods
     * @param array<int, float> $lrds
     *
     * @return list<int|null>
     */
    private function assignments(array $neighborhoods, array $lrds): array
    {
        $assignments = [];

        foreach ($neighborhoods as $index => $neighbors) {
            $lof = $this->localOutlierFactor($lrds, (int) $index, $neighbors);
            $assignments[] = $lof > $this->parameters->threshold ? null : 0;
        }

        return $assignments;
    }

    /**
     * Mean reachability-density ratio against the neighborhood: far above 1
     * means the point is markedly sparser than its neighbors.
     *
     * @param array<int, float> $lrds
     * @param list<int> $neighbors
     */
    private function localOutlierFactor(array $lrds, int $index, array $neighbors): float
    {
        $own = $lrds[$index];

        return match (true) {
            $own === 0.0, $own === INF, $neighbors === [] => 1.0,
            default => array_sum(array_map(
                static fn (int $neighbor): float => $lrds[$neighbor] / $own,
                $neighbors
            )) / count($neighbors),
        };
    }

    /**
     * @param list<FeatureVector> $vectors
     * @param array<int, float> $kDistances
     * @param array<int, list<int>> $neighborhoods
     *
     * @return array<int, float>
     */
    private function localReachabilityDensities(array $vectors, array $kDistances, array $neighborhoods): array
    {
        $lrds = [];

        foreach ($vectors as $index => $vector) {
            $reachSum = 0.0;
            foreach ($neighborhoods[$index] as $neighbor) {
                $direct = self::distance($vector->values(), $vectors[$neighbor]->values());
                $reachSum += max($direct, $kDistances[$neighbor]);
            }

            $lrds[$index] = match ($reachSum) {
                0.0 => INF,
                default => count($neighborhoods[$index]) / $reachSum,
            };
        }

        return $lrds;
    }

    /**
     * @param list<FeatureVector> $vectors
     *
     * @return array<int, float>
     */
    private function kDistances(array $vectors, int $k): array
    {
        $kDistances = [];

        foreach ($vectors as $i => $vector) {
            $row = [];
            foreach ($vectors as $j => $other) {
                if ($i !== $j) {
                    $row[] = self::distance($vector->values(), $other->values());
                }
            }
            sort($row);
            $kDistances[$i] = $row[$k - 1] ?? ($row[count($row) - 1] ?? 0.0);
        }

        return $kDistances;
    }

    /**
     * @param list<FeatureVector> $vectors
     *
     * @return array<int, list<int>>
     */
    private function neighborhoods(array $vectors, int $k): array
    {
        $neighborhoods = [];

        foreach ($vectors as $i => $vector) {
            $candidates = [];
            foreach ($vectors as $j => $other) {
                if ($i !== $j) {
                    $candidates[$j] = self::distance($vector->values(), $other->values());
                }
            }
            asort($candidates);
            $neighborhoods[$i] = array_slice(array_keys($candidates), 0, $k);
        }

        return $neighborhoods;
    }

    /**
     * @param list<float> $left
     * @param list<float> $right
     */
    private static function distance(array $left, array $right): float
    {
        $total = 0.0;
        foreach ($left as $dimension => $value) {
            $difference = $value - $right[$dimension];
            $total += $difference * $difference;
        }

        return $total ** 0.5;
    }
}
