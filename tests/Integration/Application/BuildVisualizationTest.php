<?php

declare(strict_types=1);

namespace Tests\Integration\Application;

use App\Application\Anomaly\BuildVisualization;
use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\FeatureExtractor;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\HttpMethod;
use App\Domain\Anomaly\KDistanceAnalyzer;
use App\Infrastructure\MachineLearning\LogCategoricalEncoder;
use App\Infrastructure\MachineLearning\MinMaxNormalizer;
use App\Infrastructure\MachineLearning\PhpMlDetectorFactory;
use App\Infrastructure\MachineLearning\PhpMlPcaTransformer;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

class BuildVisualizationTest extends TestCase
{
    private BuildVisualization $useCase;

    protected function setUp(): void
    {
        $this->useCase = new BuildVisualization(
            new FeatureExtractor(new LogCategoricalEncoder(16)),
            new MinMaxNormalizer(),
            new PhpMlDetectorFactory(),
            new PhpMlPcaTransformer(2),
            new KDistanceAnalyzer()
        );
    }

    public function testCombinesProjectionWithEpsilonHint(): void
    {
        $data = $this->useCase->execute(DbscanParameters::fromRaw(0.5, 5), $this->entries());

        self::assertSame(63, $data->projection->sampleCount);
        self::assertCount(63, $data->projection->samples);

        foreach ($data->projection->samples as $sample) {
            self::assertCount(2, $sample->coordinates);
            self::assertSame($sample->cluster === null, $sample->isAnomaly);
        }

        self::assertSame(3, count(array_filter($data->projection->samples, static fn ($s) => $s->isAnomaly)));
        self::assertNotNull($data->kDistance->suggestedEpsilon);
        self::assertGreaterThan(0.0, $data->kDistance->suggestedEpsilon);
        self::assertLessThan(1.0, $data->kDistance->suggestedEpsilon);
        self::assertSame(63, count($data->kDistance->sortedDistances));
    }

    public function testEmptyEntryListThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->useCase->execute(DbscanParameters::fromRaw(0.5, 5), []);
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
