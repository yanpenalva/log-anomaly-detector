<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

interface AnalysisRunRepository
{
    public function findById(int $id): ?AnalysisRun;

    /**
     * @return list<AnalysisRun> Newest first
     */
    public function list(int $limit = 50): array;
}
