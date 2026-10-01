<?php

declare(strict_types=1);

namespace Tests\Integration\Application;

use App\Application\Anomaly\AnalyzeWindows;
use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\FeatureExtractor;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\HttpMethod;
use App\Domain\Anomaly\KDistanceAnalyzer;
use App\Infrastructure\MachineLearning\LogCategoricalEncoder;
use App\Infrastructure\MachineLearning\MinMaxNormalizer;
use App\Infrastructure\MachineLearning\PhpMlDetectorFactory;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

class AnalyzeWindowsTest extends TestCase
{
    private AnalyzeWindows $useCase;

    protected function setUp(): void
    {
        $this->useCase = new AnalyzeWindows(
            new FeatureExtractor(new LogCategoricalEncoder(16)),
            new MinMaxNormalizer(),
            new PhpMlDetectorFactory(),
            new KDistanceAnalyzer()
        );
    }

    public function testSlicesByHourAndDetectsPerWindow(): void
    {
        $report = $this->useCase->execute(DbscanParameters::fromRaw(0.5, 5), $this->entries());

        self::assertSame(63, $report->sampleCount);
        self::assertCount(3, $report->windows);
        self::assertSame([3, 10, 12], array_map(static fn ($w) => $w->hour, $report->windows));

        $night = $report->windows[0];
        self::assertSame(3, $night->sampleCount);
        self::assertSame(3, $night->anomalyCount, 'the /-.env probes are isolated in their own hour');
        self::assertSame(0, $night->clusterCount);

        $day = $report->windows[1];
        self::assertSame(30, $day->sampleCount);
        self::assertSame(0, $day->anomalyCount);
        self::assertNotNull($day->suggestedEpsilon);
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
        $entries[] = new HttpLogEntry(HttpMethod::Get, '/.env', 403, 6.0, 41, 3);
        $entries[] = new HttpLogEntry(HttpMethod::Get, '/.env', 404, 4.0, 42, 3);

        return $entries;
    }
}
