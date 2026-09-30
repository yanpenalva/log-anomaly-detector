<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

use InvalidArgumentException;

/**
 * K-distance analysis for epsilon selection: for every sample, the distance
 * to its k-th nearest neighbor; sorted descending, the curve falls from the
 * outliers (top) into the dense traffic plateau (bottom). The suggested
 * epsilon is the original distance right after the single steepest
 * log-space drop — the classic knee, a heuristic starting point, not truth.
 */
final readonly class KDistanceAnalyzer
{
    private const LOG_FLOOR = 1e-12;

    /**
     * Quantile q∈[0..1] from a descending-sorted distance list (q=0 → max,
     * q=1 → min).
     *
     * @param list<float> $sortedDistances
     */
    public static function quantile(array $sortedDistances, float $q): float
    {
        $count = count($sortedDistances);
        if ($count === 0) {
            return 0.0;
        }

        $index = (int) min($count - 1, (int) round($q * ($count - 1)));

        return $sortedDistances[$index];
    }

    /**
     * Downsamples a descending distance curve to at most $target points,
     * always keeping the first (max) and last (min).
     *
     * @param list<float> $sortedDistances
     *
     * @return list<array{index: int, distance: float}>
     */
    public static function downsample(array $sortedDistances, int $target = 100): array
    {
        $count = count($sortedDistances);
        if ($count === 0) {
            return [];
        }

        $step = max(1, (int) ceil($count / max(1, $target)));
        $points = [];
        for ($i = 0; $i < $count; $i += $step) {
            $points[] = ['index' => $i, 'distance' => $sortedDistances[$i]];
        }
        $last = $count - 1;
        if (($last) % $step !== 0) {
            $points[] = ['index' => $last, 'distance' => $sortedDistances[$last]];
        }

        return $points;
    }

    /**
     * @param list<FeatureVector> $vectors
     *
     * @throws InvalidArgumentException On empty input or neighbors below 1
     */
    public function analyze(array $vectors, int $neighbors): KDistanceSummary
    {
        if ($vectors === []) {
            throw new InvalidArgumentException('K-distance analysis requires at least one feature vector');
        }
        if ($neighbors < 1) {
            throw new InvalidArgumentException('neighbors must be at least 1');
        }

        $distances = $this->kthDistances($vectors, $neighbors);
        rsort($distances);

        return new KDistanceSummary($distances, $this->steepestLogStep($distances));
    }

    /**
     * @param list<FeatureVector> $vectors
     *
     * @return list<float>
     */
    private function kthDistances(array $vectors, int $neighbors): array
    {
        $kth = [];

        foreach ($vectors as $i => $vector) {
            $row = [];
            foreach ($vectors as $j => $other) {
                if ($i !== $j) {
                    $row[] = self::distance($vector->values(), $other->values());
                }
            }
            sort($row);
            $kth[] = $row[min($neighbors - 1, count($row) - 1)] ?? 0.0;
        }

        return $kth;
    }

    /**
     * Distance right after the single steepest log10 drop in the curve.
     *
     * @param list<float> $distances Descending curve
     */
    private function steepestLogStep(array $distances): ?float
    {
        $count = count($distances);
        if ($count < 2) {
            return $distances[$count - 1] ?? null;
        }

        $bestIndex = 1;
        $bestStep = -1.0;
        for ($i = 0; $i < $count - 1; $i++) {
            $step = log10(max($distances[$i], self::LOG_FLOOR))
                - log10(max($distances[$i + 1], self::LOG_FLOOR));
            if ($step > $bestStep) {
                $bestStep = $step;
                $bestIndex = $i + 1;
            }
        }

        return $distances[$bestIndex];
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
