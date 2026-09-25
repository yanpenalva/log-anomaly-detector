<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Anomaly\AnalyzeLogs;
use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\FeatureExtractor;
use App\Infrastructure\Log\CsvHttpLogLoader;
use App\Infrastructure\MachineLearning\LogCategoricalEncoder;
use App\Infrastructure\MachineLearning\MinMaxNormalizer;
use App\Infrastructure\MachineLearning\PhpMlDetectorFactory;
use App\Infrastructure\Persistence\SqliteAnalysisRunRepository;
use App\Infrastructure\Persistence\SqliteLogEntryRepository;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\TestDatabase;

/**
 * End-to-end pipeline over the committed deterministic development
 * dataset (seed 42). The dataset contains 6 real traffic profiles and
 * ~2.5% sparse injected anomalies plus occasional 404s.
 */
class DatasetPipelineTest extends TestCase
{
    private const DATASET = __DIR__ . '/../../datasets/development.csv';

    public function testPipelineOverDevelopmentDataset(): void
    {
        self::assertFileExists(self::DATASET);

        $database = TestDatabase::create();
        try {
            $entries = (new CsvHttpLogLoader())->load(self::DATASET);
            self::assertCount(1400, $entries);

            $useCase = new AnalyzeLogs(
                new FeatureExtractor(new LogCategoricalEncoder(16)),
                new MinMaxNormalizer(),
                new PhpMlDetectorFactory(),
                new SqliteAnalysisRunRepository($database->pdo),
                new SqliteLogEntryRepository($database->pdo)
            );

            $result = $useCase->execute(DbscanParameters::fromRaw(0.35, 5), $entries);

            // the six injected traffic profiles are recovered as clusters
            self::assertSame(6, $result->clusterCount);
            self::assertSame(1400, $result->sampleCount);

            // noise stays in a sane band: sparse anomalies + client errors,
            // with room for border points legitimately absorbed by DBSCAN
            self::assertGreaterThanOrEqual(10, $result->anomalyCount);
            self::assertLessThanOrEqual(80, $result->anomalyCount);

            // anomalies persisted
            self::assertCount(
                $result->anomalyCount,
                (new SqliteLogEntryRepository($database->pdo))->anomaliesForRun($result->runId ?? 0, 200)
            );
        } finally {
            if (is_file($database->path)) {
                unlink($database->path);
            }
        }
    }
}
