<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

/**
 * Port to frequent-itemset mining over discrete transactions.
 */
interface AssociationMiner
{
    /**
     * @param list<list<string>> $transactions
     *
     * @return list<AssociationRule>
     */
    public function mine(array $transactions): array;
}
