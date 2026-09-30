<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineLearning;

use App\Domain\Anomaly\AnomalyDetector;
use App\Domain\Anomaly\DetectionResult;
use App\Domain\Anomaly\FeatureVector;
use App\Domain\Anomaly\IsolationForestParameters;
use RuntimeException;

/**
 * Isolation Forest (Liu et al., 2008), hand-rolled behind the detector port.
 * Random axis splits isolate points; anomalies isolate in fewer steps, so
 * their average path length (adjusted by c(n)) crosses the threshold. The
 * isolation score is internal only — the domain receives a binary decision.
 */
final readonly class IsolationForestDetector implements AnomalyDetector
{
    private const EULER_GAMMA = 0.5772156649;
    private const MIN_SPLIT_RANGE = 1e-12;

    public function __construct(
        private readonly IsolationForestParameters $parameters,
        private readonly SeededRandom $random,
    ) {
    }

    public function detect(array $vectors): DetectionResult
    {
        $count = count($vectors);
        if ($count === 0) {
            throw new RuntimeException('Isolation Forest requires at least one feature vector');
        }

        $samples = array_map(static fn (FeatureVector $vector) => $vector->values(), $vectors);
        $subsampleSize = min($this->parameters->subsampleSize, $count);
        $trees = $this->buildForest($samples, $subsampleSize);

        return new DetectionResult($this->assignments($samples, $trees, $subsampleSize));
    }

    /**
     * @param list<list<float>> $samples
     *
     * @return list<array{dim: int, split: float, left: mixed, right: mixed, size: int}>
     */
    private function buildForest(array $samples, int $subsampleSize): array
    {
        $trees = [];
        for ($tree = 0; $tree < $this->parameters->trees; $tree++) {
            $indices = $this->subsampleIndices(count($samples), $subsampleSize);
            $sub = array_map(static fn (int $i) => $samples[$i], $indices);
            $limit = (int) ceil(log($subsampleSize, 2));
            $trees[] = $this->buildNode($sub, 0, $limit);
        }

        return $trees;
    }

    /**
     * @param list<list<float>> $samples
     *
     * @return array{dim: int, split: float, left: mixed, right: mixed, size: int}
     */
    private function buildNode(array $samples, int $depth, int $limit): array
    {
        $size = count($samples);
        if ($depth >= $limit || $size <= 1) {
            return ['dim' => -1, 'split' => 0.0, 'left' => null, 'right' => null, 'size' => $size];
        }

        $dimension = $this->random->nextInt(0, count($samples[0]) - 1);
        $values = array_column($samples, $dimension);
        $min = (float) min($values);
        $max = (float) max($values);

        if (($max - $min) < self::MIN_SPLIT_RANGE) {
            return ['dim' => -1, 'split' => 0.0, 'left' => null, 'right' => null, 'size' => $size];
        }

        $split = $min + $this->random->nextFloat() * ($max - $min);
        $left = [];
        $right = [];
        foreach ($samples as $sample) {
            if ($sample[$dimension] < $split) {
                $left[] = $sample;
                continue;
            }
            $right[] = $sample;
        }

        return [
            'dim' => $dimension,
            'split' => $split,
            'left' => $left === [] ? null : $this->buildNode($left, $depth + 1, $limit),
            'right' => $right === [] ? null : $this->buildNode($right, $depth + 1, $limit),
            'size' => $size,
        ];
    }

    /**
     * @param list<list<float>> $samples
     * @param list<array{dim: int, split: float, left: mixed, right: mixed, size: int}> $trees
     *
     * @return list<int|null>
     */
    private function assignments(array $samples, array $trees, int $subsampleSize): array
    {
        $adjustment = self::averagePathLength($subsampleSize);
        $assignments = [];

        foreach ($samples as $sample) {
            $totalPath = 0.0;
            foreach ($trees as $tree) {
                $totalPath += $this->pathLength($sample, $tree, 0);
            }
            $average = $totalPath / max(1, count($trees));
            $score = 2 ** (-$average / max($adjustment, 1e-9));
            $assignments[] = $score >= $this->parameters->threshold ? null : 0;
        }

        return $assignments;
    }

    /**
     * @param list<float> $sample
     * @param array{dim: int, split: float, left: mixed, right: mixed, size: int} $node
     */
    private function pathLength(array $sample, array $node, int $depth): float
    {
        return match ($node['dim']) {
            -1 => $depth + self::averagePathLength($node['size']),
            default => $sample[$node['dim']] < $node['split']
                ? $this->pathLength($sample, $node['left'], $depth + 1)
                : $this->pathLength($sample, $node['right'], $depth + 1),
        };
    }

    public static function averagePathLength(int $size): float
    {
        return match (true) {
            $size <= 1 => 0.0,
            $size === 2 => 1.0,
            default => 2.0 * (log($size - 1) + self::EULER_GAMMA) - 2.0 * ($size - 1) / $size,
        };
    }

    /**
     * @return list<int>
     */
    private function subsampleIndices(int $count, int $size): array
    {
        $indices = range(0, $count - 1);
        $picked = [];

        for ($i = 0; $i < $size; $i++) {
            $pivot = $this->nextIntInSlice($i, $count - 1);
            [$indices[$i], $indices[$pivot]] = [$indices[$pivot], $indices[$i]];
            $picked[] = $indices[$i];
        }

        return $picked;
    }

    private function nextIntInSlice(int $min, int $max): int
    {
        return $this->random->nextInt($min, $max);
    }
}
