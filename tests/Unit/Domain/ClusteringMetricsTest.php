<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Anomaly\ClusteringMetrics;
use App\Domain\Anomaly\DetectionResult;
use App\Domain\Anomaly\FeatureVector;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ClusteringMetricsTest extends TestCase
{
    public function testSilhouettePerfectForFarBlobs(): void
    {
        $result = new DetectionResult([0, 0, 1, 1]);

        $silhouette = ClusteringMetrics::silhouette(self::vectors([[0.0], [0.1], [10.0], [10.1]]), $result);

        self::assertNotNull($silhouette);
        self::assertGreaterThan(0.98, $silhouette);
    }

    public function testSilhouetteNullForSingleCluster(): void
    {
        $result = new DetectionResult([0, 0, 0]);

        self::assertNull(ClusteringMetrics::silhouette(self::vectors([[0.0], [0.1], [0.2]]), $result));
    }

    public function testSilhouetteAveragesOverAllSamplesIncludingNoise(): void
    {
        $result = new DetectionResult([0, 0, 1, null]);
        $vectors = self::vectors([[0.0], [0.1], [10.0], [50.0]]);

        $silhouette = ClusteringMetrics::silhouette($vectors, $result);

        self::assertNotNull($silhouette);
        self::assertGreaterThan(0.4, $silhouette);
        self::assertLessThan(0.6, $silhouette);
    }

    public function testInertiaKnownValue(): void
    {
        $result = new DetectionResult([0, 0, 1]);

        $inertia = ClusteringMetrics::inertia(self::vectors([[0.0], [2.0], [10.0]]), $result);

        self::assertSame(2.0, $inertia);
    }

    public function testNoiseRatioAndClusterSizes(): void
    {
        $result = new DetectionResult([0, 0, 0, 1, 1, null]);

        self::assertSame(1 / 6, ClusteringMetrics::noiseRatio($result));
        self::assertSame([0 => 3, 1 => 2], ClusteringMetrics::clusterSizes($result));
    }

    public function testMismatchedVectorCountThrows(): void
    {
        $result = new DetectionResult([0, 0]);

        $this->expectException(InvalidArgumentException::class);
        ClusteringMetrics::silhouette(self::vectors([[0.0]]), $result);
    }

    /**
     * @param list<list<float>> $rawSamples
     *
     * @return list<FeatureVector>
     */
    private static function vectors(array $rawSamples): array
    {
        return array_map(
            static fn (array $values) => new FeatureVector(
                $values,
                array_map(static fn (int $d) => 'd' . $d, array_keys($values))
            ),
            $rawSamples
        );
    }
}
