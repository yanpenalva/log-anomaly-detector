<?php

declare(strict_types=1);

namespace App\Application\Anomaly;

/**
 * Side-by-side outcome of one comparison run. Every detector saw the exact
 * same extracted and normalized vectors.
 */
final readonly class ComparisonReport
{
    /**
     * @param list<DetectorReport> $detectors
     */
    public function __construct(
        public readonly int $sampleCount,
        public readonly array $detectors,
        public readonly ConsensusReport $consensus,
    ) {
    }
}
