<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

/**
 * Association rule mined from discrete transactions.
 */
final readonly class AssociationRule
{
    /**
     * @param list<string> $antecedent
     * @param list<string> $consequent
     */
    public function __construct(
        public readonly array $antecedent,
        public readonly array $consequent,
        public readonly float $support,
        public readonly float $confidence,
    ) {
    }

    /**
     * @return list<string>
     */
    public function items(): array
    {
        return array_values(array_unique(array_merge($this->antecedent, $this->consequent)));
    }
}
