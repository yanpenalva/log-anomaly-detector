<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\MachineLearning;

use App\Domain\Anomaly\DetectionResult;
use App\Domain\Anomaly\FeatureVector;
use App\Domain\Anomaly\KMeansParameters;
use App\Infrastructure\MachineLearning\PhpMlKMeansDetector;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PhpMlKMeansDetectorTest extends TestCase
{
    public function testTwoFarBlobsWithKTwo(): void
    {
        $result = $this->detect(KMeansParameters::fromRaw(2, 3), [
            [0.0, 0.0], [0.1, 0.0], [0.0, 0.1], [0.2, 0.0],
            [10.0, 10.0], [10.1, 10.0], [10.0, 10.1], [10.2, 10.0],
        ]);

        self::assertSame(8, $result->sampleCount());
        self::assertSame(2, $result->clusterCount());
        self::assertSame(0, $result->anomalyCount(), 'both clusters are dense enough');
        self::assertSame($result->clusterOf(0), $result->clusterOf(3));
        self::assertSame($result->clusterOf(4), $result->clusterOf(7));
        self::assertNotSame($result->clusterOf(0), $result->clusterOf(4));
    }

    public function testSparseClusterMembersBecomeAnomalies(): void
    {
        $result = $this->detect(KMeansParameters::fromRaw(2, 3), [
            [0.0, 0.0], [0.1, 0.0], [0.0, 0.1], [0.2, 0.0],
            [10.0, 10.0], [10.1, 10.0],
        ]);

        self::assertSame(6, $result->sampleCount());
        self::assertSame(1, $result->clusterCount(), 'only the dense cluster counts');
        self::assertSame(2, $result->anomalyCount());
        self::assertTrue($result->isNoise(4) && $result->isNoise(5), 'the far pair is below minimum_samples');
        self::assertFalse($result->isNoise(0));
    }

    public function testMembershipStableForSeparatedData(): void
    {
        $samples = [
            [0.0, 0.0], [0.1, 0.1], [0.0, 0.2],
            [5.0, 5.0], [5.1, 5.0], [5.0, 5.1],
            [9.0, 0.0], [9.1, 0.0], [9.0, 0.1],
        ];
        $parameters = KMeansParameters::fromRaw(3, 2);

        $first = $this->detect($parameters, $samples);
        $second = $this->detect($parameters, $samples);

        self::assertSame($first->clusterCount(), $second->clusterCount());
        self::assertSame($first->anomalyCount(), $second->anomalyCount());
        for ($i = 0; $i < count($samples); $i++) {
            $sameAsFirst = $first->clusterOf($i) === $first->clusterOf(0);
            $sameAsSecond = $second->clusterOf($i) === $second->clusterOf(0);
            self::assertSame($sameAsFirst, $sameAsSecond, "partition of index $i must be stable");
        }
    }

    public function testEmptyInputThrows(): void
    {
        $detector = new PhpMlKMeansDetector(KMeansParameters::fromRaw(2, 5));

        $this->expectException(RuntimeException::class);
        $detector->detect([]);
    }

    /**
     * @param list<list<float>> $rawSamples
     */
    private function detect(KMeansParameters $parameters, array $rawSamples): DetectionResult
    {
        $vectors = array_map(
            static fn (array $values) => new FeatureVector(
                $values,
                array_map(static fn (int $d) => 'd' . $d, array_keys($values))
            ),
            $rawSamples
        );

        return (new PhpMlKMeansDetector($parameters))->detect($vectors);
    }
}
