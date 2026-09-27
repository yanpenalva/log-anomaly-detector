<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\MachineLearning;

use App\Domain\Anomaly\AssociationRule;
use App\Infrastructure\MachineLearning\PhpMlAprioriMiner;
use PHPUnit\Framework\TestCase;

class PhpMlAprioriMinerTest extends TestCase
{
    public function testMinesConfidentRulesSortedBySupport(): void
    {
        $miner = new PhpMlAprioriMiner(0.5, 0.5);

        $rules = $miner->mine([
            ['method=GET', 'status=404'],
            ['method=GET', 'status=404'],
            ['method=GET', 'status=200'],
        ]);

        self::assertNotSame([], $rules);
        $rule = self::findByItems($rules, ['status=404'], ['method=GET']);
        self::assertNotNull($rule, 'status=404 must imply method=GET with total confidence');
        self::assertSame(1.0, $rule->confidence);
        self::assertSame(2 / 3, $rule->support);
        self::assertSame(2 / 3, $rules[0]->support, 'rules sorted by support desc');
        self::assertGreaterThanOrEqual($rules[count($rules) - 1]->support, $rules[0]->support);
    }

    public function testEmptyTransactionsReturnNoRules(): void
    {
        $miner = new PhpMlAprioriMiner(0.5, 0.5);

        self::assertSame([], $miner->mine([]));
    }

    /**
     * @param list<AssociationRule> $rules
     * @param list<string> $antecedent
     * @param list<string> $consequent
     */
    private static function findByItems(array $rules, array $antecedent, array $consequent): ?AssociationRule
    {
        foreach ($rules as $rule) {
            if ($rule->antecedent === $antecedent && $rule->consequent === $consequent) {
                return $rule;
            }
        }

        return null;
    }
}
