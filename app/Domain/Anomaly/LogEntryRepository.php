<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

interface LogEntryRepository
{
    /**
     * @return list<ClassifiedLogEntry>
     */
    public function anomaliesForRun(int $runId, int $limit = 100): array;

    public function countForRun(int $runId): int;
}
