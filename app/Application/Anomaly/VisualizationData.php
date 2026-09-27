<?php

declare(strict_types=1);

namespace App\Application\Anomaly;

use App\Domain\Anomaly\KDistanceSummary;

/**
 * Projection of one detection pass plus its k-distance curve statistics.
 */
final readonly class VisualizationData
{
    public function __construct(
        public readonly ProjectionReport $projection,
        public readonly KDistanceSummary $kDistance,
    ) {
    }
}
