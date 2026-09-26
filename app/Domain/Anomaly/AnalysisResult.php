<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

/**
 * Persisted analysis run plus the per-log classification it produced.
 */
final readonly class AnalysisResult
{
    /**
     * @param list<ClassifiedLogEntry> $classified
     */
    public function __construct(
        public readonly ?int $runId,
        public readonly DetectionAlgorithm $algorithm,
        public readonly DbscanParameters $parameters,
        public readonly int $sampleCount,
        public readonly int $clusterCount,
        public readonly int $anomalyCount,
        public readonly array $classified,
    ) {
    }
}
