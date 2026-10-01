<?php

declare(strict_types=1);

namespace App\Application\Anomaly;

/**
 * Majority-vote outcome across the independent detector families.
 */
final readonly class ConsensusReport
{
    public function __construct(
        public readonly int $sampleCount,
        public readonly int $anomalyCount,
        public readonly int $unanimousCount,
        public readonly int $majorityOnlyCount,
    ) {
    }
}
