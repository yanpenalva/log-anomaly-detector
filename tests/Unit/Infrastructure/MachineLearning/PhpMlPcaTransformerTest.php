<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\MachineLearning;

use App\Domain\Anomaly\FeatureVector;
use App\Infrastructure\MachineLearning\PhpMlPcaTransformer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PhpMlPcaTransformerTest extends TestCase
{
    public function testReducesToRequestedDimensionsKeepingOrder(): void
    {
        $transformer = new PhpMlPcaTransformer(1);
        $vectors = self::vectors([
            [0.0, 0.0],
            [1.0, 0.1],
            [2.0, 0.0],
            [3.0, 0.1],
        ]);

        $projected = $transformer->reduce($vectors);

        self::assertCount(4, $projected);
        self::assertCount(1, $projected[0]->values());
        self::assertSame('pc1', $projected[0]->names()[0]);

        $pc1 = array_map(static fn ($vector) => $vector->values()[0], $projected);
        $increasing = $pc1[0] < $pc1[1] && $pc1[1] < $pc1[2] && $pc1[2] < $pc1[3];
        $decreasing = $pc1[0] > $pc1[1] && $pc1[1] > $pc1[2] && $pc1[2] > $pc1[3];
        self::assertTrue(
            $increasing || $decreasing,
            'pc1 must order samples monotonically (eigen vector sign is arbitrary)'
        );
    }

    public function testProjectionIsDeterministic(): void
    {
        $transformer = new PhpMlPcaTransformer(1);
        $vectors = self::vectors([[0.0, 0.0], [1.0, 0.1], [2.0, 0.0], [3.0, 0.1]]);

        $first = $transformer->reduce($vectors);
        $second = (new PhpMlPcaTransformer(1))->reduce($vectors);

        for ($i = 0; $i < 4; $i++) {
            self::assertSame($first[$i]->values(), $second[$i]->values());
        }
    }

    public function testRejectsDimensionsNotSmallerThanInputDimension(): void
    {
        $transformer = new PhpMlPcaTransformer(2);

        $this->expectException(RuntimeException::class);
        $transformer->reduce(self::vectors([[0.0, 1.0], [1.0, 0.0]]));
    }

    public function testEmptyInputThrows(): void
    {
        $transformer = new PhpMlPcaTransformer(1);

        $this->expectException(RuntimeException::class);
        $transformer->reduce([]);
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
