<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Anomaly\AnalyzeLogs;
use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\HttpMethod;
use App\Infrastructure\MachineLearning\LogCategoricalEncoder;
use App\Infrastructure\MachineLearning\MinMaxNormalizer;
use App\Infrastructure\MachineLearning\PhpMlDetectorFactory;
use App\Infrastructure\Persistence\SqliteAnalysisRunRepository;
use App\Infrastructure\Persistence\SqliteLogEntryRepository;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\TestDatabase;

class AnalyzeLogsTest extends TestCase
{
    private AnalyzeLogs $useCase;

    private string $dbPath;

    private SqliteLogEntryRepository $logEntries;

    protected function setUp(): void
    {
        $database = TestDatabase::create();
        $this->dbPath = $database->path;
        $this->logEntries = new SqliteLogEntryRepository($database->pdo);
        $this->useCase = new AnalyzeLogs(
            new \App\Domain\Anomaly\FeatureExtractor(new LogCategoricalEncoder(16)),
            new MinMaxNormalizer(),
            new PhpMlDetectorFactory(),
            new SqliteAnalysisRunRepository($database->pdo),
            $this->logEntries
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    /**
     * Two dense blobs (different endpoint+verb+timing) and three identical
     * far-away scanner probes. Geometry is deterministic: 2 clusters,
     * exactly the 3 probes as noise.
     *
     * @return list<HttpLogEntry>
     */
    private function entries(): array
    {
        $entries = [];
        for ($i = 0; $i < 30; $i++) {
            $entries[] = new HttpLogEntry(HttpMethod::Get, '/users', 200, 100.0 + $i * 0.2, 1000 + $i, 10);
        }
        for ($i = 0; $i < 30; $i++) {
            $entries[] = new HttpLogEntry(HttpMethod::Post, '/payments', 201, 300.0 + $i * 0.2, 1500 + $i, 12);
        }
        $entries[] = new HttpLogEntry(HttpMethod::Get, '/.env', 403, 5.0, 40, 3);
        $entries[] = new HttpLogEntry(HttpMethod::Get, '/.env', 403, 6.0, 41, 4);
        $entries[] = new HttpLogEntry(HttpMethod::Get, '/.env', 404, 4.0, 42, 2);

        return $entries;
    }

    public function testExecuteProducesDeterministicAnalysisAndPersists(): void
    {
        $result = $this->useCase->execute(DbscanParameters::fromRaw(0.5, 5), $this->entries());

        self::assertSame(63, $result->sampleCount);
        self::assertSame(2, $result->clusterCount);
        self::assertSame(3, $result->anomalyCount);
        self::assertNotNull($result->runId);

        $anomalies = array_filter($result->classified, static fn ($c) => $c->isAnomaly);
        self::assertCount(3, $anomalies);
        foreach ($anomalies as $anomaly) {
            self::assertSame('/.env', $anomaly->entry->endpoint);
            self::assertNull($anomaly->cluster);
        }

        // noise entries must be clustered with their own blob
        $usersCluster = $result->classified[0]->cluster;
        self::assertNotNull($usersCluster);
        for ($i = 1; $i < 30; $i++) {
            self::assertSame($usersCluster, $result->classified[$i]->cluster);
        }
        self::assertNotNull($result->classified[30]->cluster);
        self::assertNotSame($usersCluster, $result->classified[30]->cluster);
    }

    public function testExecutePersistsRunAndEntries(): void
    {
        $result = $this->useCase->execute(DbscanParameters::fromRaw(0.5, 5), $this->entries());
        $runId = $result->runId ?? 0;

        self::assertSame(63, $this->logEntries->countForRun($runId));
        self::assertCount(3, $this->logEntries->anomaliesForRun($runId));
    }

    public function testRepeatedExecutionIsDeterministic(): void
    {
        $parameters = DbscanParameters::fromRaw(0.5, 5);

        $first = $this->useCase->execute($parameters, $this->entries());
        $second = $this->useCase->execute($parameters, $this->entries());

        self::assertSame($first->clusterCount, $second->clusterCount);
        self::assertSame($first->anomalyCount, $second->anomalyCount);
        self::assertSame($first->sampleCount, $second->sampleCount);

        foreach ($first->classified as $i => $classified) {
            self::assertSame($classified->cluster, $second->classified[$i]->cluster);
        }
    }

    public function testEmptyEntryListThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->execute(DbscanParameters::fromRaw(0.5, 5), []);
    }
}
