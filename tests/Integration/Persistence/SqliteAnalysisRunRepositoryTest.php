<?php

declare(strict_types=1);

namespace Tests\Integration\Persistence;

use App\Domain\Anomaly\AnalysisRun;
use App\Domain\Anomaly\ClassifiedLogEntry;
use App\Domain\Anomaly\DetectionAlgorithm;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\HttpMethod;
use App\Infrastructure\Persistence\SqliteAnalysisResultRepository;
use App\Infrastructure\Persistence\SqliteAnalysisRunRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\TestDatabase;

class SqliteAnalysisRunRepositoryTest extends TestCase
{
    private SqliteAnalysisRunRepository $repository;

    private SqliteAnalysisResultRepository $results;

    private string $dbPath;

    protected function setUp(): void
    {
        $database = TestDatabase::create();
        $this->dbPath = $database->path;
        $this->repository = new SqliteAnalysisRunRepository($database->pdo);
        $this->results = new SqliteAnalysisResultRepository($database->pdo);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    private function seedRun(int $sampleCount): AnalysisRun
    {
        return $this->results->save(new AnalysisRun(
            null,
            DetectionAlgorithm::Dbscan,
            0.35,
            5,
            $sampleCount,
            4,
            17,
            new DateTimeImmutable('2026-09-24T10:00:00+00:00'),
            new DateTimeImmutable('2026-09-24T10:00:02+00:00')
        ), []);
    }

    public function testFindByIdReturnsHydratedRun(): void
    {
        $inserted = $this->seedRun(100);

        $found = $this->repository->findById($inserted->id ?? 0);

        self::assertNotNull($found);
        self::assertSame($inserted->id, $found->id);
        self::assertSame(100, $found->sampleCount);
        self::assertSame(4, $found->clusterCount);
        self::assertSame(17, $found->anomalyCount);
        self::assertSame('2026-09-24T10:00:00+00:00', $found->startedAt->format(DATE_ATOM));
    }

    public function testFindByIdReturnsNullForUnknownId(): void
    {
        self::assertNull($this->repository->findById(99999));
    }

    public function testListReturnsNewestFirst(): void
    {
        $first = $this->seedRun(10);
        $second = $this->seedRun(20);

        $runs = $this->repository->list();

        self::assertCount(2, $runs);
        self::assertSame($second->id, $runs[0]->id);
        self::assertSame($first->id, $runs[1]->id);
    }
}
