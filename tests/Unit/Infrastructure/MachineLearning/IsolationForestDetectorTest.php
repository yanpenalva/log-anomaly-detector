<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\MachineLearning;

use App\Domain\Anomaly\DetectionResult;
use App\Domain\Anomaly\FeatureVector;
use App\Domain\Anomaly\IsolationForestParameters;
use App\Infrastructure\MachineLearning\IsolationForestDetector;
use App\Infrastructure\MachineLearning\SeededRandom;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class IsolationForestDetectorTest extends TestCase
{
    public function testFarOutlierFlaggedWhileBlobMostlyNormal(): void
    {
        $detector = $this->detector(42);
        $result = $detector->detect(self::vectors(self::samples()));

        self::assertSame(21, $result->sampleCount());
        self::assertTrue($result->isNoise(20), 'the far point must isolate quickly');
        self::assertLessThanOrEqual(4, $result->anomalyCount(), 'at most 20% of the blob may flip');
    }

    public function testSeededRunsAreDeterministic(): void
    {
        $samples = self::samples();

        $first = $this->detector(7)->detect(self::vectors($samples));
        $second = $this->detector(7)->detect(self::vectors($samples));

        self::assertSame($first->assignments(), $second->assignments());
    }

    public function testUnseededStillPartitionsEverySample(): void
    {
        $detector = new IsolationForestDetector(
            IsolationForestParameters::fromRaw(20, 16, 0.6),
            new SeededRandom()
        );

        $result = $detector->detect(self::vectors(self::samples()));

        self::assertSame(21, count($result->assignments()));
        self::assertSame(0, count(array_filter($result->assignments(), static fn ($c) => $c !== 0 && $c !== null)));
    }

    public function testEmptyInputThrows(): void
    {
        $detector = $this->detector(1);

        $this->expectException(RuntimeException::class);
        $detector->detect([]);
    }

    private function detector(int $seed): IsolationForestDetector
    {
        return new IsolationForestDetector(
            IsolationForestParameters::fromRaw(50, 16, 0.6, $seed),
            new SeededRandom($seed)
        );
    }

    /**
     * @return list<list<float>>
     */
    private static function samples(): array
    {
        $samples = [];
        for ($i = 0; $i < 20; $i++) {
            $samples[] = [($i % 5) * 0.1, (intdiv($i, 5)) * 0.1];
        }
        $samples[] = [10.0, 10.0];

        return $samples;
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
