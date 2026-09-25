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
 * Read-side analysis run queries. Writes belong to the atomic
 * SqliteAnalysisResultRepository.
 */
final readonly class SqliteAnalysisRunRepository implements AnalysisRunRepository
{
    public function __construct(private readonly SimplePdo $db)
    {
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

        return array_map(
            fn (mixed $row): AnalysisRun => $this->hydrate($this->rowToArray($row)),
            is_array($rows) ? $rows : iterator_to_array($rows)
        );
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
