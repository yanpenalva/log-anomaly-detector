<?php

declare(strict_types=1);

namespace App\Application\Anomaly;

/**
 * One time-window slice of the drift analysis.
 */
final readonly class WindowSlice
{
    public function __construct(
        public readonly int $hour,
        public readonly int $sampleCount,
        public readonly int $clusterCount,
        public readonly int $anomalyCount,
        public readonly float $noiseRatio,
        public readonly ?float $suggestedEpsilon,
    ) {
    }
}
