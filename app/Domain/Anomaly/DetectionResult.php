<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

/**
 * Outcome of one clustering pass (DBSCAN or K-Means). No confidence is
 * produced: cluster id per sample, null for an anomaly — noise for DBSCAN,
 * a too-sparse cluster for K-Means.
 */
final readonly class DetectionResult
{
    /**
     * @param list<int|null> $clusterAssignments
     */
    public function __construct(private array $clusterAssignments)
    {
    }

    /**
     * @return list<int|null>
     */
    public function assignments(): array
    {
        return $this->clusterAssignments;
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
