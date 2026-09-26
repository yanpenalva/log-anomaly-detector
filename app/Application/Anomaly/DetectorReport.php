<?php

declare(strict_types=1);

namespace App\Application\Anomaly;

use App\Domain\Anomaly\DetectionAlgorithm;

/**
 * Metrics for one detector over the shared normalized feature space.
 */
final readonly class DetectorReport
{
    public function __construct(
        public readonly DetectionAlgorithm $algorithm,
        public readonly int $clusterCount,
        public readonly int $anomalyCount,
        public readonly float $noiseRatio,
        public readonly ?float $silhouette,
        public readonly float $inertia,
        public readonly float $elapsedMs,
    ) {
    }
}
