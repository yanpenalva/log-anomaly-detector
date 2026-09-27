<?php

declare(strict_types=1);

namespace App\Application\Anomaly;

/**
 * Projection of one detection pass into the reduced feature space.
 */
final readonly class ProjectionReport
{
    /**
     * @param list<ProjectedSample> $samples
     */
    public function __construct(
        public readonly int $sampleCount,
        public readonly array $samples,
    ) {
    }
}
