<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineLearning;

use App\Domain\Anomaly\AssociationMiner;
use App\Domain\Anomaly\AssociationRule;
use Phpml\Association\Apriori;

final readonly class PhpMlAprioriMiner implements AssociationMiner
{
    public function __construct(
        private readonly float $support,
        private readonly float $confidence,
    ) {
    }

    public function mine(array $transactions): array
    {
        if ($transactions === []) {
            return [];
        }

        $apriori = new Apriori($this->support, $this->confidence);
        $apriori->train($transactions, array_fill(0, count($transactions), 'log'));

        $rules = array_map($this->toRule(...), $apriori->getRules());
        usort($rules, self::compareRules(...));

        return $rules;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function toRule(array $rule): AssociationRule
    {
        return new AssociationRule(
            array_values((array) ($rule[Apriori::ARRAY_KEY_ANTECEDENT] ?? [])),
            array_values((array) ($rule[Apriori::ARRAY_KEY_CONSEQUENT] ?? [])),
            (float) ($rule[Apriori::ARRAY_KEY_SUPPORT] ?? 0.0),
            (float) ($rule[Apriori::ARRAY_KEY_CONFIDENCE] ?? 0.0),
        );
    }

    private static function compareRules(AssociationRule $left, AssociationRule $right): int
    {
        return match (true) {
            $left->support !== $right->support => $right->support <=> $left->support,
            $left->confidence !== $right->confidence => $right->confidence <=> $left->confidence,
            default => $left->items() <=> $right->items(),
        };
    }
}
