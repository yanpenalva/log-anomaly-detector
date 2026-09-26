<?php

declare(strict_types=1);

namespace App\Application\Anomaly;

/**
 * Side-by-side outcome of one comparison run. Both detectors saw the exact
 * same extracted and normalized vectors.
 */
final readonly class ComparisonReport
{
    public function __construct(
        public readonly int $sampleCount,
        public readonly DetectorReport $dbscan,
        public readonly DetectorReport $kmeans,
    ) {
    }
}
