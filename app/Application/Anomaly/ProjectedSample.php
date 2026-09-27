<?php

declare(strict_types=1);

namespace App\Application\Anomaly;

/**
 * One sample projected into the reduced space, keeping its detection labels.
 */
final readonly class ProjectedSample
{
    /**
     * @param list<float> $coordinates
     */
    public function __construct(
        public readonly array $coordinates,
        public readonly ?int $cluster,
        public readonly bool $isAnomaly,
    ) {
    }
}
