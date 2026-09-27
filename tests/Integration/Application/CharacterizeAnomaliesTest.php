<?php

declare(strict_types=1);

namespace Tests\Integration\Application;

use App\Application\Anomaly\CharacterizeAnomalies;
use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\FeatureExtractor;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\HttpMethod;
use App\Domain\Anomaly\LogTransactionBuilder;
use App\Infrastructure\MachineLearning\LogCategoricalEncoder;
use App\Infrastructure\MachineLearning\MinMaxNormalizer;
use App\Infrastructure\MachineLearning\PhpMlAprioriMiner;
use App\Infrastructure\MachineLearning\PhpMlDetectorFactory;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

class CharacterizeAnomaliesTest extends TestCase
{
    private CharacterizeAnomalies $useCase;

    protected function setUp(): void
    {
        $this->useCase = new CharacterizeAnomalies(
            new FeatureExtractor(new LogCategoricalEncoder(16)),
            new MinMaxNormalizer(),
            new PhpMlDetectorFactory(),
            new PhpMlAprioriMiner(0.3, 0.5),
            new LogTransactionBuilder()
        );
    }

    public function testMinesAnomalySignaturesAbsentFromNormalTraffic(): void
    {
        $report = $this->useCase->execute(DbscanParameters::fromRaw(0.5, 5), $this->entries());

        self::assertSame(63, $report->sampleCount);
        self::assertSame(3, $report->anomalyCount);
        self::assertNotSame([], $report->findings);

        foreach ($report->findings as $finding) {
            self::assertGreaterThanOrEqual(0.3, $finding->rule->support);
            self::assertGreaterThanOrEqual(0.5, $finding->rule->confidence);
            self::assertGreaterThanOrEqual(1, $finding->anomalyCount);
            self::assertGreaterThanOrEqual(0.0, $finding->normalRate);
            self::assertLessThanOrEqual(1.0, $finding->normalRate);
        }

        $specific = array_filter($report->findings, static fn ($f) => $f->normalRate === 0.0);
        self::assertNotSame([], $specific, 'probe signatures must be absent from normal traffic');
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
