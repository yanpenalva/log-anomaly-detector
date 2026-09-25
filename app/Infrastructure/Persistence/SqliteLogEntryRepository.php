<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Anomaly\ClassifiedLogEntry;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\LogEntryRepository;
use DateTimeImmutable;
use flight\database\SimplePdo;
use flight\util\Collection;
use Throwable;

final readonly class SqliteLogEntryRepository implements LogEntryRepository
{
    private const TIMESTAMP_FORMAT = DATE_ATOM;

    public function __construct(private readonly SimplePdo $db)
    {
    }

    public function insertMany(int $runId, array $entries): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO log_entries
                (analysis_run_id, method, endpoint, status_code, response_time, request_size, hour,
                 is_anomaly, cluster, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $createdAt = (new DateTimeImmutable('now'))->format(self::TIMESTAMP_FORMAT);

        $this->db->beginTransaction();
        try {
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
                    $createdAt,
                ]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
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

        $anomalies = [];
        foreach ($rows as $row) {
            $data = $row instanceof Collection ? $row->getData() : (array) $row;
            $cluster = $data['cluster'];
            $anomalies[] = new ClassifiedLogEntry(
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

        return $anomalies;
    }

    public function countForRun(int $runId): int
    {
        return (int) $this->db->fetchField(
            'SELECT COUNT(*) FROM log_entries WHERE analysis_run_id = ?',
            [$runId]
        );
    }
}
