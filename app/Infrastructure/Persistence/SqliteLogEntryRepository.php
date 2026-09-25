<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Anomaly\ClassifiedLogEntry;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\LogEntryRepository;
use flight\database\SimplePdo;
use flight\util\Collection;

/**
 * Read-side log entry queries. Writes belong to the atomic
 * SqliteAnalysisResultRepository.
 */
final readonly class SqliteLogEntryRepository implements LogEntryRepository
{
    public function __construct(private readonly SimplePdo $db)
    {
    }

    public function anomaliesForRun(int $runId, int $limit = 100): array
    {
        $rows = $this->db->fetchAll(
            'SELECT method, endpoint, status_code, response_time, request_size, hour, cluster
             FROM log_entries
             WHERE analysis_run_id = ? AND is_anomaly = 1
             ORDER BY id ASC
             LIMIT ?',
            [$runId, max(1, $limit)]
        );

        return array_map(
            fn (mixed $row): ClassifiedLogEntry => $this->hydrateAnomaly($this->rowToArray($row)),
            is_array($rows) ? $rows : iterator_to_array($rows)
        );
    }

    public function countForRun(int $runId): int
    {
        return (int) $this->db->fetchField(
            'SELECT COUNT(*) FROM log_entries WHERE analysis_run_id = ?',
            [$runId]
        );
    }

    /**
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
     * @param array<string, mixed> $data
     */
    private function hydrateAnomaly(array $data): ClassifiedLogEntry
    {
        $cluster = $data['cluster'];

        return new ClassifiedLogEntry(
            HttpLogEntry::fromArray([
                'method' => (string) $data['method'],
                'endpoint' => (string) $data['endpoint'],
                'status_code' => (int) $data['status_code'],
                'response_time' => (float) $data['response_time'],
                'request_size' => (int) $data['request_size'],
                'hour' => (int) $data['hour'],
            ]),
            $cluster === null ? null : (int) $cluster,
            true
        );
    }
}
