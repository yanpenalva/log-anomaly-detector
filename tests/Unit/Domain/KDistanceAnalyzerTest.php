<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Anomaly\FeatureVector;
use App\Domain\Anomaly\KDistanceAnalyzer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class KDistanceAnalyzerTest extends TestCase
{
    public function testKneeSuggestsEpsilonAtPlateauStart(): void
    {
        $analyzer = new KDistanceAnalyzer();
        $vectors = self::vectors([
            [0.0, 0.0],
            [0.1, 0.0],
            [0.0, 0.05],
            [10.0, 0.0],
        ]);

        $summary = $analyzer->analyze($vectors, 1);

        self::assertSame(4, count($summary->sortedDistances));
        self::assertSame(9.9, $summary->sortedDistances[0]);
        self::assertNotNull($summary->suggestedEpsilon);
        self::assertGreaterThanOrEqual(0.05, $summary->suggestedEpsilon);
        self::assertLessThan(9.9, $summary->suggestedEpsilon);
    }

    public function testCurveIsDescending(): void
    {
        $analyzer = new KDistanceAnalyzer();
        $vectors = self::vectors([[0.0], [0.3], [1.0], [2.0], [5.0], [9.0]]);

        $summary = $analyzer->analyze($vectors, 2);

        $distances = $summary->sortedDistances;
        for ($i = 1; $i < count($distances); $i++) {
            self::assertGreaterThanOrEqual($distances[$i], $distances[$i - 1]);
        }
    }

    public function testEmptyInputThrows(): void
    {
        $analyzer = new KDistanceAnalyzer();

        $this->expectException(InvalidArgumentException::class);
        $analyzer->analyze([], 1);
    }

    public function testNeighborsBelowOneThrows(): void
    {
        $analyzer = new KDistanceAnalyzer();

        $this->expectException(InvalidArgumentException::class);
        $analyzer->analyze(self::vectors([[0.0]]), 0);
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
