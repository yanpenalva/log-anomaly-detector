<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

/**
 * Persists a complete analysis outcome atomically: the run row and every
 * classified log entry succeed or fail together. No orphan runs.
 */
interface AnalysisResultRepository
{
    /**
     * @param list<ClassifiedLogEntry> $entries
     */
    public function save(AnalysisRun $run, array $entries): AnalysisRun;
}
