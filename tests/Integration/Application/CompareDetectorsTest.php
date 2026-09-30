<?php

declare(strict_types=1);

namespace Tests\Integration\Application;

use App\Application\Anomaly\CompareDetectors;
use App\Domain\Anomaly\DetectionAlgorithm;
use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\FeatureExtractor;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\HttpMethod;
use App\Domain\Anomaly\IsolationForestParameters;
use App\Domain\Anomaly\KMeansParameters;
use App\Domain\Anomaly\LofParameters;
use App\Infrastructure\MachineLearning\LogCategoricalEncoder;
use App\Infrastructure\MachineLearning\MinMaxNormalizer;
use App\Infrastructure\MachineLearning\PhpMlDetectorFactory;
use App\Infrastructure\MachineLearning\PhpMlKMeansFactory;
use App\Infrastructure\MachineLearning\LofFactory;
use App\Infrastructure\MachineLearning\IsolationForestFactory;
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
            new PhpMlKMeansFactory(),
            new LofFactory(),
            new IsolationForestFactory()
        );
    }

    public function testFourFamiliesSeeSameSamplesWithFamilyFitMetrics(): void
    {
        $report = $this->useCase->execute(
            DbscanParameters::fromRaw(0.5, 5),
            KMeansParameters::fromRaw(2, 5),
            LofParameters::fromRaw(5, 1.5),
            IsolationForestParameters::fromRaw(50, 32, 0.6, 42),
            $this->entries()
        );

        self::assertSame(63, $report->sampleCount);
        self::assertCount(4, $report->detectors);
        self::assertSame(
            [DetectionAlgorithm::Dbscan, DetectionAlgorithm::KMeans, DetectionAlgorithm::Lof, DetectionAlgorithm::IsolationForest],
            array_map(static fn ($d) => $d->algorithm, $report->detectors)
        );

        [$dbscan, $kmeans, $lof, $forest] = $report->detectors;

        self::assertSame(2, $dbscan->clusterCount);
        self::assertSame(3, $dbscan->anomalyCount);
        self::assertSame(3 / 63, $dbscan->noiseRatio);

        self::assertSame(0.0, $kmeans->noiseRatio, 'K-Means never produces noise');
        self::assertGreaterThanOrEqual(1, $kmeans->clusterCount);
        self::assertLessThanOrEqual(2, $kmeans->clusterCount);

        self::assertSame(1, $lof->clusterCount, 'LOF labels normals with the single normal cluster');
        self::assertGreaterThanOrEqual(0, $lof->anomalyCount);
        self::assertLessThanOrEqual(63, $lof->anomalyCount);

        self::assertSame(1, $forest->clusterCount);
        self::assertGreaterThanOrEqual(0, $forest->anomalyCount);

        foreach ([$dbscan, $kmeans] as $detector) {
            self::assertNotNull($detector->silhouette);
        }

        foreach ($report->detectors as $detector) {
            self::assertTrue($detector->silhouette === null || abs($detector->silhouette) <= 1.0);
            self::assertGreaterThanOrEqual(0.0, $detector->inertia);
            self::assertGreaterThanOrEqual(0.0, $detector->elapsedMs);
        }
    }

    public function testSeededForestIsDeterministicAcrossRuns(): void
    {
        $parameters = fn (): array => [
            DbscanParameters::fromRaw(0.5, 5),
            KMeansParameters::fromRaw(2, 5),
            LofParameters::fromRaw(5, 1.5),
            IsolationForestParameters::fromRaw(50, 32, 0.6, 7),
            $this->entries(),
        ];

        $first = $this->useCase->execute(...$parameters());
        $second = $this->useCase->execute(...$parameters());

        self::assertSame(
            array_map(static fn ($d) => [$d->algorithm->value, $d->anomalyCount], $first->detectors),
            array_map(static fn ($d) => [$d->algorithm->value, $d->anomalyCount], $second->detectors)
        );
    }

    public function testEmptyEntryListThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->useCase->execute(
            DbscanParameters::fromRaw(0.5, 5),
            KMeansParameters::fromRaw(2, 5),
            LofParameters::fromRaw(5, 1.5),
            IsolationForestParameters::fromRaw(10, 16, 0.6),
            []
        );
    }

    /**
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
