<?php

declare(strict_types=1);

namespace App\Application\Anomaly;

/**
 * Signature analysis of one detection pass.
 */
final readonly class SignatureReport
{
    /**
     * @param list<SignatureFinding> $findings
     */
    public function __construct(
        public readonly int $sampleCount,
        public readonly int $anomalyCount,
        public readonly array $findings,
    ) {
    }
}
