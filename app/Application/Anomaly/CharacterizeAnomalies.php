<?php

declare(strict_types=1);

namespace App\Application\Anomaly;

use App\Domain\Anomaly\AnomalyDetectorFactory;
use App\Domain\Anomaly\AssociationMiner;
use App\Domain\Anomaly\AssociationRule;
use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\DetectionResult;
use App\Domain\Anomaly\FeatureExtractor;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\LogTransactionBuilder;
use App\Domain\Anomaly\Normalizer;
use InvalidArgumentException;

final readonly class CharacterizeAnomalies
{
    public function __construct(
        private readonly FeatureExtractor $featureExtractor,
        private readonly Normalizer $normalizer,
        private readonly AnomalyDetectorFactory $detectorFactory,
        private readonly AssociationMiner $miner,
        private readonly LogTransactionBuilder $transactions,
    ) {
    }

    /**
     * @param list<HttpLogEntry> $entries
     *
     * @throws InvalidArgumentException On empty input
     */
    public function execute(DbscanParameters $parameters, array $entries): SignatureReport
    {
        if ($entries === []) {
            throw new InvalidArgumentException('At least one log entry is required for signature analysis');
        }

        $rawVectors = array_map(fn (HttpLogEntry $entry) => $this->featureExtractor->extract($entry), $entries);
        $fitted = $this->normalizer->fit($rawVectors);
        $vectors = array_map(static fn ($vector) => $fitted->transform($vector), $rawVectors);

        $detection = $this->detectorFactory->create($parameters)->detect($vectors);

        return $this->report($entries, $detection);
    }

    /**
     * @param list<HttpLogEntry> $entries
     */
    private function report(array $entries, DetectionResult $detection): SignatureReport
    {
        $anomalyTransactions = [];
        $normalTransactions = [];

        foreach ($entries as $index => $entry) {
            $transaction = $this->transactions->toTransaction($entry);
            if ($detection->isNoise((int) $index)) {
                $anomalyTransactions[] = $transaction;

                continue;
            }

            $normalTransactions[] = $transaction;
        }

        $findings = array_map(
            fn (AssociationRule $rule): SignatureFinding => $this->finding($rule, $anomalyTransactions, $normalTransactions),
            $this->miner->mine($anomalyTransactions)
        );

        return new SignatureReport(count($entries), count($anomalyTransactions), $findings);
    }

    /**
     * @param list<list<string>> $anomalyTransactions
     * @param list<list<string>> $normalTransactions
     */
    private function finding(AssociationRule $rule, array $anomalyTransactions, array $normalTransactions): SignatureFinding
    {
        $items = $rule->items();

        return new SignatureFinding(
            $rule,
            $this->frequency($items, $anomalyTransactions),
            $this->rate($items, $normalTransactions)
        );
    }

    /**
     * @param list<string> $items
     * @param list<list<string>> $transactions
     */
    private function rate(array $items, array $transactions): float
    {
        if ($transactions === []) {
            return 0.0;
        }

        return $this->frequency($items, $transactions) / count($transactions);
    }

    /**
     * @param list<string> $items
     * @param list<list<string>> $transactions
     */
    private function frequency(array $items, array $transactions): int
    {
        return count(array_filter(
            $transactions,
            static fn (array $transaction): bool => count(array_diff($items, $transaction)) === 0
        ));
    }
}
