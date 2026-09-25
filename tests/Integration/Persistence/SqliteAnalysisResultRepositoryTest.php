<?php

declare(strict_types=1);

namespace Tests\Integration\Persistence;

use App\Domain\Anomaly\AnalysisRun;
use App\Domain\Anomaly\ClassifiedLogEntry;
use App\Domain\Anomaly\DetectionAlgorithm;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\HttpMethod;
use App\Infrastructure\Persistence\SqliteAnalysisResultRepository;
use App\Infrastructure\Persistence\SqliteLogEntryRepository;
use DateTimeImmutable;
use flight\database\SimplePdo;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\TestDatabase;

class SqliteAnalysisResultRepositoryTest extends TestCase
{
    private SqliteAnalysisResultRepository $repository;

    private SqliteLogEntryRepository $logEntries;

    private SimplePdo $db;

    private string $dbPath;

    protected function setUp(): void
    {
        $database = TestDatabase::create();
        $this->dbPath = $database->path;
        $this->db = $database->pdo;
        $this->repository = new SqliteAnalysisResultRepository($this->db);
        $this->logEntries = new SqliteLogEntryRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    /**
     * @return list<ClassifiedLogEntry>
     */
    private function entries(): array
    {
        return [
            new ClassifiedLogEntry(
                new HttpLogEntry(HttpMethod::Get, '/users', 200, 120.0, 1024, 10),
                0,
                false
            ),
            new ClassifiedLogEntry(
                new HttpLogEntry(HttpMethod::Post, '/payments', 503, 4800.0, 2100, 11),
                null,
                true
            ),
        ];
    }

    private function sampleRun(): AnalysisRun
    {
        return new AnalysisRun(
            null,
            DetectionAlgorithm::Dbscan,
            0.35,
            5,
            2,
            1,
            1,
            new DateTimeImmutable('2026-09-24T10:00:00+00:00'),
            new DateTimeImmutable('2026-09-24T10:00:02+00:00')
        );
    }

    public function testSavePersistsRunAndEntriesAtomically(): void
    {
        $saved = $this->repository->save($this->sampleRun(), $this->entries());

        self::assertNotNull($saved->id);
        self::assertSame(2, $this->logEntries->countForRun($saved->id));
        self::assertCount(1, $this->logEntries->anomaliesForRun($saved->id));
    }

    public function testFailureDuringEntriesInsertLeavesNoOrphanRun(): void
    {
        $database = TestDatabase::create();
        $repository = new SqliteAnalysisResultRepository($database->pdo);

        // break the second step of the transaction: log_entries is gone
        $database->pdo->exec('DROP TABLE log_entries');

        try {
            $repository->save($this->sampleRun(), $this->entries());
            self::fail('Expected the save to fail');
        } catch (\PDOException) {
            // expected
        } finally {
            $orphanRuns = (int) $database->pdo->fetchField('SELECT COUNT(*) FROM analysis_runs');
            self::assertSame(0, $orphanRuns, 'no analysis_runs row may survive a failed save');
            unlink($database->path);
        }
    }

    public function testForeignKeyBlocksEntriesForUnknownRun(): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO log_entries
                (analysis_run_id, method, endpoint, status_code, response_time, request_size, hour,
                 is_anomaly, cluster, created_at)
             VALUES (999999, ?, ?, ?, ?, ?, ?, 0, 1, ?)'
        );

        try {
            $statement->execute(['GET', '/users', 200, 100.0, 1024, 10, date(DATE_ATOM)]);
            self::fail('FK violation expected for unknown analysis_run_id');
        } catch (\PDOException $e) {
            self::assertStringContainsStringIgnoringCase('foreign key', $e->getMessage());
        }
    }

    public function testDeletingRunCascadesToEntries(): void
    {
        $saved = $this->repository->save($this->sampleRun(), $this->entries());
        $runId = $saved->id ?? 0;
        self::assertSame(2, $this->logEntries->countForRun($runId));

        $this->db->exec('DELETE FROM analysis_runs WHERE id = ' . $runId);

        self::assertSame(0, $this->logEntries->countForRun($runId), 'entries must cascade with their run');
    }
}
