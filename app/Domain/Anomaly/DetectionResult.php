<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

/**
 * Outcome of one DBSCAN pass. DBSCAN produces no confidence: cluster id
 * per sample, null for noise — which this domain reports as an anomaly.
 */
final readonly class DetectionResult
{
    /**
     * @param list<int|null> $clusterAssignments
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
