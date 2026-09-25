<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Persistence;

use App\Domain\Anomaly\AnalysisRun;
use App\Domain\Anomaly\DetectionAlgorithm;
use App\Infrastructure\Persistence\SqliteAnalysisRunRepository;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\TestDatabase;

class SqliteAnalysisRunRepositoryTest extends TestCase
{
    private SqliteAnalysisRunRepository $repository;

    private string $dbPath;

    protected function setUp(): void
    {
        $database = TestDatabase::create();
        $this->dbPath = $database->path;
        $this->repository = new SqliteAnalysisRunRepository($database->pdo);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    private function sampleRun(): AnalysisRun
    {
        return new AnalysisRun(
            null,
            DetectionAlgorithm::Dbscan,
            0.35,
            5,
            100,
            4,
            17,
            '2026-09-24T10:00:00+00:00',
            '2026-09-24T10:00:02+00:00'
        );
    }

    public function testInsertAssignsId(): void
    {
        $inserted = $this->repository->insert($this->sampleRun());

        self::assertNotNull($inserted->id);
        self::assertGreaterThan(0, $inserted->id);
        self::assertSame(0.35, $inserted->epsilon);
        self::assertSame(DetectionAlgorithm::Dbscan, $inserted->algorithm);
    }

    public function testFindByIdReturnsHydratedRun(): void
    {
        $inserted = $this->repository->insert($this->sampleRun());

        $found = $this->repository->findById($inserted->id ?? 0);

        self::assertNotNull($found);
        self::assertSame($inserted->id, $found->id);
        self::assertSame(100, $found->sampleCount);
        self::assertSame(4, $found->clusterCount);
        self::assertSame(17, $found->anomalyCount);
        self::assertSame('2026-09-24T10:00:00+00:00', $found->startedAt);
    }

    public function testFindByIdReturnsNullForUnknownId(): void
    {
        self::assertNull($this->repository->findById(99999));
    }

    public function testListReturnsNewestFirst(): void
    {
        $first = $this->repository->insert($this->sampleRun());
        $second = $this->repository->insert($this->sampleRun());

        $runs = $this->repository->list();

        self::assertCount(2, $runs);
        self::assertSame($second->id, $runs[0]->id);
        self::assertSame($first->id, $runs[1]->id);
    }
}
