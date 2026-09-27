<?php

declare(strict_types=1);

namespace App\Application\Anomaly;

use App\Domain\Anomaly\AssociationRule;

/**
 * One mined rule plus how often its full itemset occurs in normal traffic —
 * the contrast that separates anomaly-specific signatures from patterns that
 * are common everywhere.
 */
final readonly class SignatureFinding
{
    public function __construct(
        public readonly AssociationRule $rule,
        public readonly int $anomalyCount,
        public readonly float $normalRate,
    ) {
    }
}
