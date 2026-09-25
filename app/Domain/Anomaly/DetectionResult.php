<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

/**
 * Outcome of one DBSCAN pass over an ordered list of feature vectors.
 *
 * DBSCAN does not produce probabilities or confidence scores. A sample is
 * either assigned to a cluster (including border points) or is a noise
 * point; in this domain a noise point is an anomaly.
 */
final readonly class DetectionResult
{
    /**
     * @param list<int|null> $clusterAssignments Cluster id per sample index,
     *                                           null when the sample is noise
     */
    public function __construct(private array $clusterAssignments)
    {
    }

    public function clusterOf(int $index): ?int
    {
        return $this->clusterAssignments[$index] ?? null;
    }

    public function isNoise(int $index): bool
    {
        return $this->clusterOf($index) === null;
    }

    public function clusterCount(): int
    {
        return count(array_unique(array_filter($this->clusterAssignments, static fn ($c) => $c !== null)));
    }

    public function anomalyCount(): int
    {
        return count(array_filter($this->clusterAssignments, static fn ($c) => $c === null));
    }

    public function sampleCount(): int
    {
        return count($this->clusterAssignments);
    }
}
