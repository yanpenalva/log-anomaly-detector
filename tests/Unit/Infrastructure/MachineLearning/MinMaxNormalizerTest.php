<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\MachineLearning;

use App\Domain\Anomaly\FeatureVector;
use App\Infrastructure\MachineLearning\FittedMinMaxNormalizer;
use App\Infrastructure\MachineLearning\MinMaxNormalizer;
use PHPUnit\Framework\TestCase;

class MinMaxNormalizerTest extends TestCase
{
    public function testFitAndTransformMapsToUnitRange(): void
    {
        $vectors = [
            new FeatureVector([0.0, 10.0], ['a', 'b']),
            new FeatureVector([5.0, 20.0], ['a', 'b']),
            new FeatureVector([10.0, 30.0], ['a', 'b']),
        ];

        $fitted = (new MinMaxNormalizer())->fit($vectors);

        $low = $fitted->transform($vectors[0]);
        self::assertSame([0.0, 0.0], $low->values());

        $high = $fitted->transform($vectors[2]);
        self::assertSame([1.0, 1.0], $high->values());

        $mid = $fitted->transform($vectors[1]);
        self::assertEqualsWithDelta([0.5, 0.5], $mid->values(), 1e-12);
    }

    public function testTransformKeepsFeatureNames(): void
    {
        $fitted = (new MinMaxNormalizer())->fit([
            new FeatureVector([1.0], ['x']),
            new FeatureVector([3.0], ['x']),
        ]);

        $transformed = $fitted->transform(new FeatureVector([2.0], ['x']));

        self::assertSame(['x'], $transformed->names());
    }

    public function testConstantFeatureMapsToZero(): void
    {
        $fitted = (new MinMaxNormalizer())->fit([
            new FeatureVector([7.0, 1.0], ['const', 'var']),
            new FeatureVector([7.0, 2.0], ['const', 'var']),
        ]);

        $transformed = $fitted->transform(new FeatureVector([7.0, 2.0], ['const', 'var']));

        self::assertSame(0.0, $transformed->values()[0]);
        self::assertSame(1.0, $transformed->values()[1]);
    }

    public function testParametersExposeLearnedMinMax(): void
    {
        $fitted = (new MinMaxNormalizer())->fit([
            new FeatureVector([2.0, 10.0], ['a', 'b']),
            new FeatureVector([4.0, 30.0], ['a', 'b']),
        ]);

        self::assertSame(
            [['min' => 2.0, 'max' => 4.0], ['min' => 10.0, 'max' => 30.0]],
            $fitted->parameters()
        );
    }

    public function testFittedNormalizerIsReusedNotRefitted(): void
    {
        // transforming values outside the fitted range must NOT change params
        $fitted = (new MinMaxNormalizer())->fit([
            new FeatureVector([0.0], ['a']),
            new FeatureVector([1.0], ['a']),
        ]);

        $outOfRange = $fitted->transform(new FeatureVector([9.0], ['a']));

        self::assertSame(9.0, $outOfRange->values()[0]);
        self::assertSame([['min' => 0.0, 'max' => 1.0]], $fitted->parameters());
    }

    public function testFitRejectsEmptyBatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new MinMaxNormalizer())->fit([]);
    }

    public function testTransformRejectsDimensionMismatch(): void
    {
        $fitted = (new MinMaxNormalizer())->fit([
            new FeatureVector([1.0, 2.0], ['a', 'b']),
            new FeatureVector([3.0, 4.0], ['a', 'b']),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $fitted->transform(new FeatureVector([1.0], ['a']));
    }

    public function testFittedNormalizerParameterMismatchThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FittedMinMaxNormalizer([1.0, 2.0], [3.0]);
    }
}
