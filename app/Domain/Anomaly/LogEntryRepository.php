<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

interface LogEntryRepository
{
    /**
     * @param list<ClassifiedLogEntry> $entries
     */
    public function insertMany(int $runId, array $entries): void;

    /**
     * @return list<ClassifiedLogEntry> Anomalous entries of one run
     */
    public function anomaliesForRun(int $runId, int $limit = 100): array;

    public function countForRun(int $runId): int;
}
