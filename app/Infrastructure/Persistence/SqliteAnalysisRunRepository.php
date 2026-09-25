<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Anomaly\AnalysisRun;
use App\Domain\Anomaly\AnalysisRunRepository;
use App\Domain\Anomaly\DetectionAlgorithm;
use DateTimeImmutable;
use flight\database\SimplePdo;
use flight\util\Collection;

/**
 * SQLite implementation via SimplePdo prepared statements.
 */
final readonly class SqliteAnalysisRunRepository implements AnalysisRunRepository
{
    private const TIMESTAMP_FORMAT = DATE_ATOM;

    public function __construct(private readonly SimplePdo $db)
    {
    }

    public function insert(AnalysisRun $run): AnalysisRun
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

    public function findById(int $id): ?AnalysisRun
    {
        $rows = $this->db->fetchAll('SELECT * FROM analysis_runs WHERE id = ?', [$id]);
        $row = $rows[0] ?? null;

        return $row === null ? null : $this->hydrate($this->rowToArray($row));
    }

    public function list(int $limit = 50): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM analysis_runs ORDER BY id DESC LIMIT ?',
            [max(1, $limit)]
        );

        $runs = [];
        foreach ($rows as $row) {
            $runs[] = $this->hydrate($this->rowToArray($row));
        }

        return $runs;
    }

    /**
     * SimplePdo::fetchAll wraps each row in a Collection.
     *
     * @return array<string, mixed>
     */
    private function rowToArray(mixed $row): array
    {
        if ($row instanceof Collection) {
            return $row->getData();
        }

        return (array) $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): AnalysisRun
    {
        return new AnalysisRun(
            (int) $row['id'],
            DetectionAlgorithm::from((string) $row['algorithm']),
            (float) $row['epsilon'],
            (int) $row['minimum_samples'],
            (int) $row['sample_count'],
            (int) $row['cluster_count'],
            (int) $row['anomaly_count'],
            new DateTimeImmutable((string) $row['started_at']),
            new DateTimeImmutable((string) $row['finished_at']),
        );
    }
}
