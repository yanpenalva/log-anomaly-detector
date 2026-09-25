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

        $expectedDimension = 9 + 8 + 4;
        self::assertCount($expectedDimension, $names);
        self::assertCount($expectedDimension, $values);

        // one-hot block: GET is the first enum case
        self::assertSame('method_get_0', $names[0]);
        self::assertSame(1.0, $values[0]);
        self::assertSame(0.0, $values[1]);

        // endpoint hash block
        self::assertSame('endpoint_hash_0', $names[9]);
        self::assertSame('endpoint_hash_7', $names[16]);
        self::assertSame(1.0, array_sum(array_slice($values, 9, 8)));

        // numeric tail
        self::assertSame('status_code', $names[17]);
        self::assertSame(200.0, $values[17]);
        self::assertSame('response_time', $names[18]);
        self::assertSame(118.0, $values[18]);
        self::assertSame('request_size', $names[19]);
        self::assertSame(1024.0, $values[19]);
        self::assertSame('hour', $names[20]);
        self::assertSame(10.0, $values[20]);
    }

    public function testParameterizedEndpointsShareFeatures(): void
    {
        $a = $this->extractor->extract(new HttpLogEntry(HttpMethod::Get, '/users/1912', 200, 100.0, 900, 9));
        $b = $this->extractor->extract(new HttpLogEntry(HttpMethod::Get, '/users/7', 200, 100.0, 900, 9));

        self::assertSame($a->values(), $b->values());
    }

    public function testDifferentVerbsDifferOnlyInOneHotBlock(): void
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
