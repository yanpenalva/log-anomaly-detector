<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Anomaly\ConsensusRule;
use App\Domain\Anomaly\DetectionResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ConsensusRuleTest extends TestCase
{
    private ConsensusRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ConsensusRule();
    }

    public function testMajorityVoteFlagsOnlySharedAnomalies(): void
    {
        // index 0: two votes; 1: one vote; 2: three votes; 3: no votes
        $consensus = $this->rule->apply([
            new DetectionResult([null, null, null, 0]),
            new DetectionResult([null, 0, null, 0]),
            new DetectionResult([0, 0, null, 0]),
        ]);

        self::assertSame([null, 0, null, 0], $consensus->assignments());
        self::assertSame(2, $consensus->anomalyCount());
        self::assertSame(4, $consensus->sampleCount());
    }

    public function testUnanimousAndMajorityOnlyCounts(): void
    {
        $results = [
            new DetectionResult([null, null, null, 0]),
            new DetectionResult([null, 0, null, 0]),
            new DetectionResult([null, 0, 0, 0]),
        ];

        self::assertSame(1, $this->rule->unanimousCount($results));
        self::assertSame(0, $this->rule->majorityOnlyCount($results));
    }

    public function testEmptyInputThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->rule->apply([]);
    }

    public function testMismatchedSizesThrow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->rule->apply([
            new DetectionResult([null, 0]),
            new DetectionResult([null]),
        ]);
    }
}
