<?php

declare(strict_types=1);

namespace Tests\Integration\Application;

use App\Application\Anomaly\ProjectSamples;
use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\FeatureExtractor;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\HttpMethod;
use App\Infrastructure\MachineLearning\LogCategoricalEncoder;
use App\Infrastructure\MachineLearning\MinMaxNormalizer;
use App\Infrastructure\MachineLearning\PhpMlDetectorFactory;
use App\Infrastructure\MachineLearning\PhpMlPcaTransformer;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

class ProjectSamplesTest extends TestCase
{
    private ProjectSamples $useCase;

    protected function setUp(): void
    {
        $this->useCase = new ProjectSamples(
            new FeatureExtractor(new LogCategoricalEncoder(16)),
            new MinMaxNormalizer(),
            new PhpMlDetectorFactory(),
            new PhpMlPcaTransformer(2)
        );
    }

    public function testProjectsEverySampleWithDetectionLabels(): void
    {
        $report = $this->useCase->execute(DbscanParameters::fromRaw(0.5, 5), $this->entries());

        self::assertSame(63, $report->sampleCount);
        self::assertCount(63, $report->samples);

        foreach ($report->samples as $sample) {
            self::assertCount(2, $sample->coordinates);
            self::assertIsFloat($sample->coordinates[0]);
            self::assertIsFloat($sample->coordinates[1]);
            self::assertSame($sample->cluster === null, $sample->isAnomaly);
        }

        self::assertSame(3, count(array_filter($report->samples, static fn ($s) => $s->isAnomaly)));
        self::assertSame(2, count(array_unique(array_filter(
            array_map(static fn ($s) => $s->cluster, $report->samples),
            static fn ($c) => $c !== null
        ))));
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
