<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

use InvalidArgumentException;

/**
 * Majority vote across independent detector families: a sample is an
 * anomaly when at least MIN_VOTES detectors report null (their own anomaly
 * semantics) at the same index. The combined verdict is binary like its
 * inputs — no scores, ever.
 */
final readonly class ConsensusRule
{
    public const MIN_VOTES = 2;

    /**
     * @param list<DetectionResult> $results
     *
     * @throws InvalidArgumentException On empty input or mismatched sizes
     */
    public function apply(array $results): DetectionResult
    {
        if ($results === []) {
            throw new InvalidArgumentException('Consensus requires at least one detection result');
        }

        $sampleCount = $results[0]->sampleCount();
        $assignments = [];

        for ($index = 0; $index < $sampleCount; $index++) {
            $votes = 0;
            foreach ($results as $result) {
                if ($result->sampleCount() !== $sampleCount) {
                    throw new InvalidArgumentException('Detection results must cover the same samples');
                }

                if ($result->isNoise($index)) {
                    $votes++;
                }
            }

            $assignments[] = $votes >= self::MIN_VOTES ? null : 0;
        }

        return new DetectionResult($assignments);
    }

    /**
     * Samples flagged by every input family.
     *
     * @param list<DetectionResult> $results
     */
    public function unanimousCount(array $results): int
    {
        return $this->voteCount($results, count($results));
    }

    /**
     * Samples flagged by exactly two of the input families.
     *
     * @param list<DetectionResult> $results
     */
    public function majorityOnlyCount(array $results): int
    {
        return $this->voteCount($results, self::MIN_VOTES) - $this->unanimousCount($results);
    }

    /**
     * @param list<DetectionResult> $results
     */
    private function voteCount(array $results, int $required): int
    {
        $sampleCount = $results[0]->sampleCount();
        $count = 0;

        for ($index = 0; $index < $sampleCount; $index++) {
            $votes = 0;
            foreach ($results as $result) {
                if ($result->isNoise($index)) {
                    $votes++;
                }
            }

            if ($votes === $required) {
                $count++;
            }
        }

        return $count;
    }
}
