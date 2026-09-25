<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Anomaly\FeatureExtractor;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\HttpMethod;
use App\Infrastructure\MachineLearning\LogCategoricalEncoder;
use App\Infrastructure\MachineLearning\MinMaxNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Locks the geometric behavior of the one-hot + hashing feature space:
 * categorical mismatches (method, endpoint bucket, status class) move
 * points far apart, while pure numeric jitter keeps them close. This is
 * WHY DBSCAN with a sane epsilon separates traffic profiles — do not
 * weaken or weight these distances without evidence.
 */
class FeatureGeometryTest extends TestCase
{
    private const EPSILON = 0.5;

    private FeatureExtractor $extractor;

    /** @var \App\Infrastructure\MachineLearning\FittedMinMaxNormalizer */
    private \App\Infrastructure\MachineLearning\FittedMinMaxNormalizer $fitted;

    /**
     * Reference scale used for min-max fitting so numeric features stay bounded.
     */
    private const SCALE = 10_000.0;

    protected function setUp(): void
    {
        $this->extractor = new FeatureExtractor(new LogCategoricalEncoder(16));

        // Fitting set must span every categorical block (both methods, both
        // endpoint buckets, both status classes) and the numeric corners,
        // exactly like a real batch would.
        $scale = [];
        foreach ([HttpMethod::Get, HttpMethod::Post] as $method) {
            foreach (['/users', '/health'] as $endpoint) {
                foreach ([200, 500] as $status) {
                    $scale[] = new HttpLogEntry($method, $endpoint, $status, 0.0, 0, 0);
                    $scale[] = new HttpLogEntry(
                        $method,
                        $endpoint,
                        $status,
                        self::SCALE,
                        (int) self::SCALE,
                        23
                    );
                }
            }
        }

        $fitted = (new MinMaxNormalizer())->fit(
            array_map(fn (HttpLogEntry $e) => $this->extractor->extract($e), $scale)
        );
        assert($fitted instanceof \App\Infrastructure\MachineLearning\FittedMinMaxNormalizer);
        $this->fitted = $fitted;
    }

    /**
     * @return list<float>
     */
    private function normalized(HttpLogEntry $entry): array
    {
        return $this->fitted->transform($this->extractor->extract($entry))->values();
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private function distance(array $a, array $b): float
    {
        return sqrt(array_sum(array_map(
            static fn (float $x, float $y): float => ($x - $y) ** 2,
            $a,
            $b
        )));
    }

    public function testSameEndpointAndMethodWithNumericJitterAreNeighbors(): void
    {
        $a = $this->normalized(new HttpLogEntry(HttpMethod::Get, '/users', 200, 118.0, 1024, 10));
        $b = $this->normalized(new HttpLogEntry(HttpMethod::Get, '/users', 200, 124.0, 1080, 10));

        $distance = $this->distance($a, $b);

        self::assertLessThan(self::EPSILON, $distance);
    }

    public function testDifferentEndpointBucketIsOutsideEpsilon(): void
    {
        // /users and /health hash to different buckets (verified via distinct encodings)
        $encoder = new LogCategoricalEncoder(16);
        self::assertNotSame($encoder->encodeEndpoint('/users'), $encoder->encodeEndpoint('/health'));

        $a = $this->normalized(new HttpLogEntry(HttpMethod::Get, '/users', 200, 118.0, 1024, 10));
        $b = $this->normalized(new HttpLogEntry(HttpMethod::Get, '/health', 200, 118.0, 1024, 10));

        $distance = $this->distance($a, $b);

        // one bucket flip contributes sqrt(2) ≈ 1.41 before scaling
        self::assertGreaterThan(self::EPSILON, $distance);
        self::assertGreaterThanOrEqual(1.0, $distance);
    }

    public function testDifferentHttpMethodIsOutsideEpsilon(): void
    {
        $a = $this->normalized(new HttpLogEntry(HttpMethod::Get, '/users', 200, 118.0, 1024, 10));
        $b = $this->normalized(new HttpLogEntry(HttpMethod::Post, '/users', 200, 118.0, 1024, 10));

        $distance = $this->distance($a, $b);

        // one-hot flip over 9 verbs: two dims change → sqrt(2) on raw one-hot,
        // scaled by 1/1 (min-max over fitted scale keeps categorical dims at full range)
        self::assertGreaterThan(self::EPSILON, $distance);
        self::assertGreaterThanOrEqual(1.0, $distance);
    }

    public function testStatusClassesShareClassWithinAndSplitAcrossClasses(): void
    {
        $ok1 = $this->normalized(new HttpLogEntry(HttpMethod::Get, '/users', 200, 100.0, 900, 10));
        $ok2 = $this->normalized(new HttpLogEntry(HttpMethod::Get, '/users', 201, 100.0, 900, 10));
        $error = $this->normalized(new HttpLogEntry(HttpMethod::Get, '/users', 500, 100.0, 900, 10));

        self::assertLessThan(self::EPSILON, $this->distance($ok1, $ok2), '200 vs 201 = same class');
        self::assertGreaterThan(self::EPSILON, $this->distance($ok1, $error), '2xx vs 5xx = separate class');
    }
}
