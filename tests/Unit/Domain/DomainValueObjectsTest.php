<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\FeatureVector;
use App\Domain\Anomaly\DetectionResult;
use PHPUnit\Framework\TestCase;

class DomainValueObjectsTest extends TestCase
{
    public function testDbscanParametersAcceptsValidValues(): void
    {
        $parameters = DbscanParameters::fromRaw(0.35, 5);

        self::assertSame(0.35, $parameters->epsilon);
        self::assertSame(5, $parameters->minimumSamples);
    }

    public function testDbscanParametersCoercesNumericStrings(): void
    {
        $parameters = DbscanParameters::fromRaw('0.5', '8');

        self::assertSame(0.5, $parameters->epsilon);
        self::assertSame(8, $parameters->minimumSamples);
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function invalidParametersProvider(): array
    {
        return [
            'epsilon zero' => [0, 5],
            'epsilon negative' => [-0.5, 5],
            'epsilon too large' => [5000, 5],
            'epsilon not numeric' => ['wide', 5],
            'minimum samples zero' => [0.5, 0],
            'minimum samples negative' => [0.5, -3],
            'minimum samples not int' => [0.5, ' plenty'],
        ];
    }

    /**
     * @dataProvider invalidParametersProvider
     */
    public function testDbscanParametersRejectsInvalidValues(mixed $epsilon, mixed $minimumSamples): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DbscanParameters::fromRaw($epsilon, $minimumSamples);
    }

    public function testFeatureVectorHoldsValuesAndNames(): void
    {
        $vector = new FeatureVector([1.0, 0.0, 0.5], ['a', 'b', 'c']);

        self::assertSame([1.0, 0.0, 0.5], $vector->values());
        self::assertSame(['a', 'b', 'c'], $vector->names());
        self::assertSame(3, $vector->dimension());
    }

    public function testFeatureVectorRejectsLengthMismatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FeatureVector([1.0], ['a', 'b']);
    }

    public function testDetectionResultStatistics(): void
    {
        $result = new DetectionResult([0, 0, null, 1, null]);

        self::assertSame(5, $result->sampleCount());
        self::assertSame(2, $result->clusterCount());
        self::assertSame(2, $result->anomalyCount());
        self::assertSame(0, $result->clusterOf(0));
        self::assertSame(1, $result->clusterOf(3));
        self::assertNull($result->clusterOf(2));
        self::assertTrue($result->isNoise(4));
        self::assertFalse($result->isNoise(0));
    }
}
