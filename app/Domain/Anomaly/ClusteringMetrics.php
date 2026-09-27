<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

use InvalidArgumentException;

final readonly class ClusteringMetrics
{
    /**
     * @param list<FeatureVector> $vectors Same order as the DetectionResult
     *
     * @throws InvalidArgumentException When vector count mismatches the result
     */
    public static function silhouette(array $vectors, DetectionResult $result): ?float
    {
        self::assertSameCount($vectors, $result);
        $sizes = self::clusterSizes($result);

        if (count($sizes) < 2) {
            return null;
        }

        $total = 0.0;
        foreach ($sizes as $clusterId => $size) {
            $total += array_sum(self::clusterSilhouettes($vectors, $result, (int) $clusterId, $sizes));
        }

        return $total / $result->sampleCount();
    }

    /**
     * @param list<FeatureVector> $vectors
     */
    public static function inertia(array $vectors, DetectionResult $result): float
    {
        self::assertSameCount($vectors, $result);
        $centroids = self::centroids($vectors, $result);

        $total = 0.0;
        foreach ($vectors as $index => $vector) {
            $clusterId = $result->clusterOf((int) $index);
            if ($clusterId === null) {
                continue;
            }

            $total += self::squaredDistance($vector->values(), $centroids[$clusterId]);
        }

        return $total;
    }

    public static function noiseRatio(DetectionResult $result): float
    {
        return match ($result->sampleCount()) {
            0 => 0.0,
            default => $result->anomalyCount() / $result->sampleCount(),
        };
    }

    /**
     * @return array<int, int>
     */
    public static function clusterSizes(DetectionResult $result): array
    {
        $sizes = [];

        foreach ($result->assignments() as $clusterId) {
            if ($clusterId === null) {
                continue;
            }

            $sizes[$clusterId] = ($sizes[$clusterId] ?? 0) + 1;
        }

        return $sizes;
    }

    /**
     * @param list<FeatureVector> $vectors
     * @param array<int, int> $sizes
     *
     * @return list<float>
     */
    private static function clusterSilhouettes(array $vectors, DetectionResult $result, int $clusterId, array $sizes): array
    {
        $values = [];

        foreach ($vectors as $index => $vector) {
            if ($result->clusterOf((int) $index) !== $clusterId) {
                continue;
            }

            $values[] = self::sampleSilhouette($vectors, $result, (int) $index, $sizes);
        }

        return $values;
    }

    /**
     * @param list<FeatureVector> $vectors
     * @param array<int, int> $sizes
     */
    private static function sampleSilhouette(array $vectors, DetectionResult $result, int $index, array $sizes): float
    {
        $own = $result->clusterOf($index);

        if ($own === null || $sizes[$own] < 2) {
            return 0.0;
        }

        $a = self::meanIntraClusterDistance($vectors, $result, $index, $own);
        $b = self::nearestOtherClusterDistance($vectors, $result, $index, $sizes, $own);

        return ($b - $a) / max($a, $b);
    }

    /**
     * @param list<FeatureVector> $vectors
     */
    private static function meanIntraClusterDistance(array $vectors, DetectionResult $result, int $index, int $clusterId): float
    {
        $total = 0.0;
        $count = 0;

        foreach ($vectors as $other => $vector) {
            if ((int) $other === $index || $result->clusterOf((int) $other) !== $clusterId) {
                continue;
            }

            $total += self::distance($vectors[$index]->values(), $vector->values());
            $count++;
        }

        return $total / $count;
    }

    /**
     * @param list<FeatureVector> $vectors
     * @param array<int, int> $sizes
     */
    private static function nearestOtherClusterDistance(array $vectors, DetectionResult $result, int $index, array $sizes, int $own): float
    {
        $totals = [];
        $counts = [];

        foreach ($vectors as $other => $vector) {
            $clusterId = $result->clusterOf((int) $other);

            if ($clusterId === null) {
                continue;
            }

            $totals[$clusterId] = ($totals[$clusterId] ?? 0.0)
                + self::distance($vectors[$index]->values(), $vector->values());
            $counts[$clusterId] = ($counts[$clusterId] ?? 0) + 1;
        }

        $means = [];
        foreach ($totals as $clusterId => $total) {
            $means[$clusterId] = $total / $counts[$clusterId];
        }
        unset($means[$own]);

        return min($means);
    }

    /**
     * Non-noise cluster id => centroid coordinates.
     *
     * @param list<FeatureVector> $vectors
     *
     * @return array<int, list<float>>
     */
    private static function centroids(array $vectors, DetectionResult $result): array
    {
        $sums = [];
        $counts = [];

        foreach ($vectors as $index => $vector) {
            $clusterId = $result->clusterOf((int) $index);

            if ($clusterId === null) {
                continue;
            }

            foreach ($vector->values() as $dimension => $value) {
                $sums[$clusterId][$dimension] = ($sums[$clusterId][$dimension] ?? 0.0) + $value;
            }
            $counts[$clusterId] = ($counts[$clusterId] ?? 0) + 1;
        }

        $centroids = [];
        foreach ($sums as $clusterId => $sum) {
            $centroids[$clusterId] = array_map(static fn (float $value) => $value / $counts[$clusterId], $sum);
        }

        return $centroids;
    }

    /**
     * @param list<float> $left
     * @param list<float> $right
     */
    private static function distance(array $left, array $right): float
    {
        return self::squaredDistance($left, $right) ** 0.5;
    }

    /**
     * @param list<float> $left
     * @param list<float> $right
     */
    private static function squaredDistance(array $left, array $right): float
    {
        $total = 0.0;

        foreach ($left as $dimension => $value) {
            $difference = $value - $right[$dimension];
            $total += $difference * $difference;
        }

        return $total;
    }

    /**
     * @param list<FeatureVector> $vectors
     */
    private static function assertSameCount(array $vectors, DetectionResult $result): void
    {
        if (count($vectors) !== $result->sampleCount()) {
            throw new InvalidArgumentException('Vector count must match DetectionResult sample count');
        }
    }
}
