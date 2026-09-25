<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\MachineLearning;

use App\Domain\Anomaly\DetectionResult;
use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\FeatureVector;
use App\Infrastructure\MachineLearning\PhpMlDbscanDetector;
use PHPUnit\Framework\TestCase;

class PhpMlDbscanDetectorTest extends TestCase
{
    /**
     * @param list<list<float>> $rawSamples
     */
    private function detect(DbscanParameters $parameters, array $rawSamples): DetectionResult
    {
        $vectors = array_map(
            static fn (int $i, array $values) => new FeatureVector(
                array_map(static fn (float $v) => $v, $values),
                array_map(static fn (int $d) => 'd' . $d, array_keys($values))
            ),
            array_keys($rawSamples),
            array_values($rawSamples)
        );

        return (new PhpMlDbscanDetector($parameters))->detect($vectors);
    }

    public function testTwoDenseBlobsAndFarOutlier(): void
    {
        $result = $this->detect(
            DbscanParameters::fromRaw(1.5, 3),
            [
                [0.0, 0.0],
                [0.1, 0.0],
                [0.0, 0.1],
                [0.2, 0.0],
                [10.0, 10.0],
                [10.1, 10.0],
                [10.0, 10.1],
                [10.2, 10.0],
                [50.0, 50.0],
            ]
        );

        self::assertSame(9, $result->sampleCount());
        self::assertSame(2, $result->clusterCount());
        self::assertSame(1, $result->anomalyCount());
        self::assertTrue($result->isNoise(8), 'the isolated point must be noise');

        self::assertSame($result->clusterOf(0), $result->clusterOf(1));
        self::assertSame($result->clusterOf(0), $result->clusterOf(3));
        self::assertNotSame($result->clusterOf(0), $result->clusterOf(4));
        self::assertFalse($result->isNoise(4));
    }

    public function testDuplicateVectorsMapBackToCorrectIndices(): void
    {
        // exercises the multiset reconstruction: identical vectors appear
        // many times and PHP-ML does not return original indices
        $result = $this->detect(
            DbscanParameters::fromRaw(0.5, 3),
            [
                [1.0, 1.0],
                [100.0, 100.0],
                [1.0, 1.0],
                [1.0, 1.0],
                [1.0, 1.0],
                [1.0, 1.0],
                [1.0, 1.0],
                [1.0, 1.0],
            ]
        );

        self::assertSame(1, $result->clusterCount());
        self::assertSame(1, $result->anomalyCount());
        self::assertTrue($result->isNoise(1), 'the single far vector must be noise');
        for ($i = 0; $i < 8; $i++) {
            if ($i === 1) {
                continue;
            }
            self::assertFalse($result->isNoise($i), "index $i belongs to the dense cluster");
        }
    }

    public function testDeterministicForSameInput(): void
    {
        $samples = [
            [0.0, 0.0], [0.1, 0.1], [0.2, 0.0], [5.0, 5.0], [5.1, 5.0], [42.0, 42.0],
        ];
        $parameters = DbscanParameters::fromRaw(0.5, 2);

        $first = $this->detect($parameters, $samples);
        $second = $this->detect($parameters, $samples);

        self::assertSame($first->clusterCount(), $second->clusterCount());
        for ($i = 0; $i < count($samples); $i++) {
            self::assertSame($first->clusterOf($i), $second->clusterOf($i));
        }
    }

    public function testEmptyInputThrows(): void
    {
        $detector = new PhpMlDbscanDetector(DbscanParameters::fromRaw(0.5, 5));

        $this->expectException(\RuntimeException::class);
        $detector->detect([]);
    }
}
