<?php

declare(strict_types=1);

namespace App\Application\Anomaly;

/**
 * Hour-bucketed detection drift over one batch.
 */
final readonly class WindowAnalysisReport
{
    /**
     * @param list<WindowSlice> $windows
     */
    public function __construct(
        public readonly int $sampleCount,
        public readonly array $windows,
    ) {
    }
}
