<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\MachineLearning;

use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\HttpMethod;
use App\Infrastructure\MachineLearning\LogCategoricalEncoder;
use PHPUnit\Framework\TestCase;

class LogCategoricalEncoderTest extends TestCase
{
    public function testMethodDimensionMatchesEnumCases(): void
    {
        $encoder = new LogCategoricalEncoder();

        self::assertSame(count(\App\Domain\Anomaly\HttpMethod::cases()), $encoder->methodDimension());
    }

    public function testEncodeMethodIsOneHotInStablePosition(): void
    {
        $encoder = new LogCategoricalEncoder();
        $cases = HttpMethod::cases();

        foreach ($cases as $index => $case) {
            $encoded = $encoder->encodeMethod($case);

            self::assertCount($encoder->methodDimension(), $encoded);
            self::assertSame(1.0, $encoded[$index]);
            self::assertSame(
                array_fill(0, $encoder->methodDimension(), 0.0),
                array_replace($encoded, [$index => 0.0])
            );
        }
    }

    public function testParameterizedEndpointsShareBucket(): void
    {
        $encoder = new LogCategoricalEncoder();

        $first = $encoder->encodeEndpoint('/users/1912');
        $second = $encoder->encodeEndpoint('/users/1769');

        self::assertSame($first, $second);
        self::assertSame(1.0, array_sum($first));
    }

    public function testDistinctStaticEndpointsEncodeDifferently(): void
    {
        $encoder = new LogCategoricalEncoder(64);

        self::assertNotSame($encoder->encodeEndpoint('/users'), $encoder->encodeEndpoint('/payments'));
        self::assertNotSame($encoder->encodeEndpoint('/users'), $encoder->encodeEndpoint('/health'));
    }

    public function testEncodingIsDeterministic(): void
    {
        $encoder = new LogCategoricalEncoder();

        self::assertSame(
            $encoder->encodeEndpoint('/api/search'),
            $encoder->encodeEndpoint('/api/search')
        );
        self::assertSame(
            $encoder->encodeMethod(HttpMethod::Post),
            $encoder->encodeMethod(HttpMethod::Post)
        );
    }

    public function testRejectsInvalidBucketCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LogCategoricalEncoder(0);
    }

    public function testNormalizesOnlyFullyNumericSegments(): void
    {
        self::assertSame('/users/{n}', LogCategoricalEncoder::normalizeEndpoint('/users/1912'));
        self::assertSame('/users/{n}/orders/{n}', LogCategoricalEncoder::normalizeEndpoint('/users/42/orders/7'));
        self::assertSame('/products/{n}/reviews/{n}', LogCategoricalEncoder::normalizeEndpoint('/products/55/reviews/991'));
        self::assertSame('/health', LogCategoricalEncoder::normalizeEndpoint('/health'));
    }

    public function testKeepsVersionedPathsIntact(): void
    {
        self::assertSame('/v2/users', LogCategoricalEncoder::normalizeEndpoint('/v2/users'));
        self::assertSame('/oauth2/callback', LogCategoricalEncoder::normalizeEndpoint('/oauth2/callback'));
        self::assertSame('/api/v10/items', LogCategoricalEncoder::normalizeEndpoint('/api/v10/items'));
    }

    public function testDropsQueryString(): void
    {
        self::assertSame('/products', LogCategoricalEncoder::normalizeEndpoint('/products?page=2'));
        self::assertSame(
            '/products/{n}',
            LogCategoricalEncoder::normalizeEndpoint('/products/99?page=2&sort=desc')
        );
        self::assertSame('/users', LogCategoricalEncoder::normalizeEndpoint('/users'));
    }
}
