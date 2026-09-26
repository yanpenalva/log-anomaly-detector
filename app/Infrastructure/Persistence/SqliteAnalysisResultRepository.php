<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Anomaly\AnalysisRun;
use App\Domain\Anomaly\AnalysisResultRepository;
use App\Domain\Anomaly\ClassifiedLogEntry;
use App\Domain\Anomaly\DetectionAlgorithm;
use DateTimeImmutable;
use flight\database\SimplePdo;
use Throwable;

/**
 * Atomic persistence: BEGIN → run → entries → COMMIT; ROLLBACK on failure.
 */
final readonly class SqliteAnalysisResultRepository implements AnalysisResultRepository
{
    private const TIMESTAMP_FORMAT = DATE_ATOM;

    public function __construct(private readonly SimplePdo $db)
    {
    }

    public function save(AnalysisRun $run, array $entries): AnalysisRun
    {
        $this->db->beginTransaction();

        try {
            $persisted = $this->insertRun($run);
            $this->insertEntries($persisted->id ?? 0, $entries, $run->startedAt);
            $this->db->commit();

            return $persisted;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    private function insertRun(AnalysisRun $run): AnalysisRun
    {
        $statement = $this->db->prepare(
            'INSERT INTO analysis_runs
                (algorithm, epsilon, minimum_samples, sample_count, cluster_count, anomaly_count, started_at, finished_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $run->algorithm->value,
            $run->epsilon,
            $run->minimumSamples,
            $run->sampleCount,
            $run->clusterCount,
            $run->anomalyCount,
            $run->startedAt->format(self::TIMESTAMP_FORMAT),
            $run->finishedAt->format(self::TIMESTAMP_FORMAT),
        ]);

        return $run->withId((int) $this->db->lastInsertId());
    }

    /**
     * @param list<ClassifiedLogEntry> $entries
     */
    private function insertEntries(int $runId, array $entries, DateTimeImmutable $createdAt): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO log_entries
                (analysis_run_id, method, endpoint, status_code, response_time, request_size, hour,
                 is_anomaly, cluster, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $createdAtValue = $createdAt->format(self::TIMESTAMP_FORMAT);

        foreach ($entries as $classified) {
            $statement->execute([
                $runId,
                $classified->entry->method->value,
                $classified->entry->endpoint,
                $classified->entry->statusCode,
                $classified->entry->responseTime,
                $classified->entry->requestSize,
                $classified->entry->hour,
                $classified->isAnomaly ? 1 : 0,
                $classified->cluster,
                $createdAtValue,
            ]);
        }
    }
}
