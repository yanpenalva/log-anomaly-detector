<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Persistence;

use App\Domain\Anomaly\AnalysisRun;
use App\Domain\Anomaly\ClassifiedLogEntry;
use App\Domain\Anomaly\DetectionAlgorithm;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\HttpMethod;
use App\Infrastructure\Persistence\SqliteAnalysisRunRepository;
use App\Infrastructure\Persistence\SqliteLogEntryRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\TestDatabase;

class SqliteLogEntryRepositoryTest extends TestCase
{
    private SqliteLogEntryRepository $repository;

    private string $dbPath;

    private int $runId;

    protected function setUp(): void
    {
        $database = TestDatabase::create();
        $this->dbPath = $database->path;
        $this->repository = new SqliteLogEntryRepository($database->pdo);

        $run = (new SqliteAnalysisRunRepository($database->pdo))->insert(new AnalysisRun(
            null,
            DetectionAlgorithm::Dbscan,
            0.35,
            5,
            3,
            1,
            1,
            new DateTimeImmutable(),
            new DateTimeImmutable()
        ));
        $this->runId = $run->id ?? 0;
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
    private function sampleEntries(): array
    {
        $normal = new ClassifiedLogEntry(
            new HttpLogEntry(HttpMethod::Get, '/users', 200, 120.0, 1024, 10),
            0,
            false
        );
        $anomaly = new ClassifiedLogEntry(
            new HttpLogEntry(HttpMethod::Post, '/payments', 503, 4800.0, 2100, 11),
            null,
            true
        );

        return [$normal, $anomaly];
    }

    public function testInsertManyPersistsAllEntries(): void
    {
        $this->repository->insertMany($this->runId, $this->sampleEntries());

        self::assertSame(2, $this->repository->countForRun($this->runId));
    }

    public function testAnomaliesForRunReturnsOnlyNoise(): void
    {
        $this->repository->insertMany($this->runId, $this->sampleEntries());

        $anomalies = $this->repository->anomaliesForRun($this->runId);

        self::assertCount(1, $anomalies);
        self::assertTrue($anomalies[0]->isAnomaly);
        self::assertNull($anomalies[0]->cluster);
        self::assertSame(HttpMethod::Post, $anomalies[0]->entry->method);
        self::assertSame(503, $anomalies[0]->entry->statusCode);
        self::assertSame(4800.0, $anomalies[0]->entry->responseTime);
    }

    public function testAnomaliesForUnknownRunReturnsEmpty(): void
    {
        self::assertSame([], $this->repository->anomaliesForRun(99999));
        self::assertSame(0, $this->repository->countForRun(99999));
    }

    public function testAnomaliesLimitIsApplied(): void
    {
        $entries = [];
        for ($i = 0; $i < 5; $i++) {
            $entries[] = new ClassifiedLogEntry(
                new HttpLogEntry(HttpMethod::Get, '/x' . $i, 500, 9000.0, 100, 2),
                null,
                true
            );
        }
        $this->repository->insertMany($this->runId, $entries);

        self::assertCount(3, $this->repository->anomaliesForRun($this->runId, 3));
    }
}
