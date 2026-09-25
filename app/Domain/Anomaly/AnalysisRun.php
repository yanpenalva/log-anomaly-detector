<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

/**
 * Row of the analysis_runs table.
 */
final readonly class AnalysisRun
{
    public function __construct(
        public readonly ?int $id,
        public readonly DetectionAlgorithm $algorithm,
        public readonly float $epsilon,
        public readonly int $minimumSamples,
        public readonly int $sampleCount,
        public readonly int $clusterCount,
        public readonly int $anomalyCount,
        public readonly string $startedAt,
        public readonly string $finishedAt,
    ) {
    }

    public function withId(int $id): self
    {
        return new self(
            $id,
            $this->algorithm,
            $this->epsilon,
            $this->minimumSamples,
            $this->sampleCount,
            $this->clusterCount,
            $this->anomalyCount,
            $this->startedAt,
            $this->finishedAt,
        );
    }
}
