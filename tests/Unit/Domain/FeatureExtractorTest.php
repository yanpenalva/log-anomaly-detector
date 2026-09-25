<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Anomaly\FeatureExtractor;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\HttpMethod;
use App\Infrastructure\MachineLearning\LogCategoricalEncoder;
use PHPUnit\Framework\TestCase;

class FeatureExtractorTest extends TestCase
{
    private FeatureExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new FeatureExtractor(new LogCategoricalEncoder(8));
    }

    private function entry(): HttpLogEntry
    {
        return new HttpLogEntry(HttpMethod::Get, '/users', 200, 118.0, 1024, 10);
    }

    public function testLayoutNamesAndValues(): void
    {
        $vector = $this->extractor->extract($this->entry());
        $names = $vector->names();
        $values = $vector->values();

        // 9 method one-hot + 8 endpoint buckets + 5 status classes + 3 numerics
        $expectedDimension = 9 + 8 + 5 + 3;
        self::assertCount($expectedDimension, $names);
        self::assertCount($expectedDimension, $values);

        // one-hot block: GET is the first enum case
        self::assertSame('method_get', $names[0]);
        self::assertSame(1.0, $values[0]);
        self::assertSame(0.0, $values[1]);

        // endpoint hash block
        self::assertSame('endpoint_hash_0', $names[9]);
        self::assertSame('endpoint_hash_7', $names[16]);
        self::assertSame(1.0, array_sum(array_slice($values, 9, 8)));

        // status class one-hot: 200 → status_2xx (index 18)
        self::assertSame('status_1xx', $names[17]);
        self::assertSame('status_2xx', $names[18]);
        self::assertSame('status_5xx', $names[21]);
        self::assertSame(1.0, $values[18]);
        self::assertSame(1.0, array_sum(array_slice($values, 17, 5)));

        // numeric tail
        self::assertSame('response_time', $names[22]);
        self::assertSame(118.0, $values[22]);
        self::assertSame('request_size', $names[23]);
        self::assertSame(1024.0, $values[23]);
        self::assertSame('hour', $names[24]);
        self::assertSame(10.0, $values[24]);
    }

    public function testStatusClassOneHotCoversAllClasses(): void
    {
        foreach ([100, 250, 301, 450, 599] as $i => $status) {
            $vector = $this->extractor->extract(
                new HttpLogEntry(HttpMethod::Get, '/x', $status, 1.0, 1, 0)
            );
            $slice = array_slice($vector->values(), 17, 5);

            self::assertSame(1.0, $slice[$i], "status $status must activate class index $i");
            self::assertSame(1.0, array_sum($slice));
        }
    }

    public function testParameterizedEndpointsShareFeatures(): void
    {
        $a = $this->extractor->extract(new HttpLogEntry(HttpMethod::Get, '/users/1912', 200, 100.0, 900, 9));
        $b = $this->extractor->extract(new HttpLogEntry(HttpMethod::Get, '/users/7', 200, 100.0, 900, 9));

        self::assertSame($a->values(), $b->values());
    }

    public function testQueryStringDoesNotChangeFeatures(): void
    {
        $plain = $this->extractor->extract(new HttpLogEntry(HttpMethod::Get, '/products', 200, 100.0, 900, 9));
        $withQuery = $this->extractor->extract(
            new HttpLogEntry(HttpMethod::Get, '/products?page=2&sort=desc', 200, 100.0, 900, 9)
        );

        self::assertSame($plain->values(), $withQuery->values());
    }

    public function testDifferentVerbsDifferOnlyOutsideMethodBlock(): void
    {
        $get = $this->extractor->extract($this->entry());
        $post = $this->extractor->extract(new HttpLogEntry(HttpMethod::Post, '/users', 200, 118.0, 1024, 10));

        self::assertNotSame($get->values(), $post->values());
        self::assertSame(
            array_slice($get->values(), 9),
            array_slice($post->values(), 9)
        );
    }
}
