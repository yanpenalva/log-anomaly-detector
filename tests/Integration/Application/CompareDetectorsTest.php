<?php

declare(strict_types=1);

namespace Tests\Integration\Application;

use App\Application\Anomaly\CompareDetectors;
use App\Domain\Anomaly\DetectionAlgorithm;
use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\FeatureExtractor;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\HttpMethod;
use App\Domain\Anomaly\KMeansParameters;
use App\Infrastructure\MachineLearning\LogCategoricalEncoder;
use App\Infrastructure\MachineLearning\MinMaxNormalizer;
use App\Infrastructure\MachineLearning\PhpMlDetectorFactory;
use App\Infrastructure\MachineLearning\PhpMlKMeansFactory;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

class CompareDetectorsTest extends TestCase
{
    private CompareDetectors $useCase;

    protected function setUp(): void
    {
        $this->useCase = new CompareDetectors(
            new FeatureExtractor(new LogCategoricalEncoder(16)),
            new MinMaxNormalizer(),
            new PhpMlDetectorFactory(),
            new PhpMlKMeansFactory()
        );
    }

    public function testBothDetectorsSeeSameSamplesWithFamilyFitMetrics(): void
    {
        $report = $this->useCase->execute(
            DbscanParameters::fromRaw(0.5, 5),
            KMeansParameters::fromRaw(2, 5),
            $this->entries()
        );

        self::assertSame(63, $report->sampleCount);

        self::assertSame(DetectionAlgorithm::Dbscan, $report->dbscan->algorithm);
        self::assertSame(2, $report->dbscan->clusterCount);
        self::assertSame(3, $report->dbscan->anomalyCount);
        self::assertSame(3 / 63, $report->dbscan->noiseRatio);

        self::assertSame(DetectionAlgorithm::KMeans, $report->kmeans->algorithm);
        self::assertSame(0.0, $report->kmeans->noiseRatio, 'K-Means never produces noise');
        self::assertGreaterThanOrEqual(1, $report->kmeans->clusterCount);
        self::assertLessThanOrEqual(2, $report->kmeans->clusterCount);

        foreach ([$report->dbscan, $report->kmeans] as $detector) {
            self::assertNotNull($detector->silhouette);
            self::assertGreaterThanOrEqual(-1.0, $detector->silhouette);
            self::assertLessThanOrEqual(1.0, $detector->silhouette);
            self::assertGreaterThanOrEqual(0.0, $detector->inertia);
            self::assertGreaterThanOrEqual(0.0, $detector->elapsedMs);
        }
    }

    public function testEmptyEntryListThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->useCase->execute(DbscanParameters::fromRaw(0.5, 5), KMeansParameters::fromRaw(2, 5), []);
    }

    /**
     * Two dense blobs (different endpoint+verb+timing) and three far-away
     * scanner probes — same geometry as AnalyzeLogsTest.
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
}
