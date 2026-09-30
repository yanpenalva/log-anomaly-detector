<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\MachineLearning;

use App\Domain\Anomaly\DetectionResult;
use App\Domain\Anomaly\FeatureVector;
use App\Domain\Anomaly\LofParameters;
use App\Infrastructure\MachineLearning\LofDetector;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LofDetectorTest extends TestCase
{
    public function testFarOutlierFlaggedWhileDenseBlobSurvives(): void
    {
        $result = $this->detect(LofParameters::fromRaw(2, 1.5), [
            [0.0, 0.0], [0.1, 0.0], [0.0, 0.1], [0.1, 0.1], [0.05, 0.05], [0.0, 0.07],
            [10.0, 10.0],
        ]);

        self::assertSame(7, $result->sampleCount());
        self::assertSame(1, $result->clusterCount());
        self::assertSame(1, $result->anomalyCount());
        self::assertTrue($result->isNoise(6), 'the isolated point must be an anomaly');
    }

    public function testLocalOutlierNearLooseClusterFlagged(): void
    {
        $result = $this->detect(LofParameters::fromRaw(2, 1.4), [
            [0.0, 0.0], [0.1, 0.0], [0.0, 0.1], [0.1, 0.1],
            [3.0, 3.0], [3.2, 3.0], [3.0, 3.2], [3.2, 3.2],
            [3.1, 3.9],
        ]);

        self::assertSame(9, $result->sampleCount());
        self::assertSame(1, $result->anomalyCount(), 'the point 3x denser-neighborhood away must be flagged');
        self::assertTrue($result->isNoise(8));
    }

    public function testDeterministicForSameInput(): void
    {
        $samples = [
            [0.0, 0.0], [0.1, 0.0], [0.0, 0.1], [5.0, 5.0], [5.1, 5.0], [9.0, 9.0],
        ];
        $parameters = LofParameters::fromRaw(2, 1.5);

        $first = $this->detect($parameters, $samples);
        $second = $this->detect($parameters, $samples);

        self::assertSame($first->assignments(), $second->assignments());
    }

    public function testEmptyInputThrows(): void
    {
        $detector = new LofDetector(LofParameters::fromRaw(2, 1.5));

        $this->expectException(RuntimeException::class);
        $detector->detect([]);
    }

    /**
     * @param list<list<float>> $rawSamples
     */
    private function detect(LofParameters $parameters, array $rawSamples): DetectionResult
    {
        $vectors = array_map(
            static fn (array $values) => new FeatureVector(
                $values,
                array_map(static fn (int $d) => 'd' . $d, array_keys($values))
            ),
            $rawSamples
        );

        return (new LofDetector($parameters))->detect($vectors);
    }
}
