<?php

declare(strict_types=1);

namespace App\Command;

use Ahc\Cli\IO\Interactor;
use App\Application\Anomaly\CharacterizeAnomalies;
use App\Application\Anomaly\SignatureFinding;
use App\Application\Anomaly\SignatureReport;
use App\Domain\Anomaly\AssociationMiner;
use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\FeatureExtractor;
use App\Domain\Anomaly\LogTransactionBuilder;
use App\Infrastructure\MachineLearning\LogCategoricalEncoder;
use App\Infrastructure\MachineLearning\MinMaxNormalizer;
use App\Infrastructure\MachineLearning\PhpMlAprioriMiner;
use App\Infrastructure\MachineLearning\PhpMlDetectorFactory;
use App\Utils\Config;
use App\Utils\Env;
use flight\commands\AbstractBaseCommand;
use RuntimeException;
use Throwable;

/**
 * @property mixed $file
 * @property mixed $epsilon
 * @property mixed $minimumSamples
 * @property mixed $support
 * @property mixed $confidence
 * @property mixed $limit
 */
class CharacterizeCommand extends AbstractBaseCommand
{
    private const DEFAULT_SUPPORT = 0.3;
    private const DEFAULT_CONFIDENCE = 0.5;
    private const DEFAULT_FINDINGS = 10;

    public function __construct(array $config)
    {
        parent::__construct('characterize', 'Mine association-rule signatures of the detected anomalies', $config);
        $this->argument('<file>', 'Dataset path: .csv or .log (nginx combined format)');
        $this->option('-e --epsilon epsilon', 'DBSCAN epsilon (default: config anomaly.epsilon)');
        $this->option('-m --minimum-samples samples', 'DBSCAN minimum samples (default: config anomaly.minimum_samples)');
        $this->option('-s --support support', 'Apriori minimum support among anomalies (default: 0.3)', null, (string) self::DEFAULT_SUPPORT);
        $this->option('-c --confidence confidence', 'Apriori minimum confidence (default: 0.5)', null, (string) self::DEFAULT_CONFIDENCE);
        $this->option('-l --limit limit', 'Max signature rules printed', null, self::DEFAULT_FINDINGS);
    }

    public function execute(): void
    {
        $io = $this->io();

        try {
            $config = $this->loadAppConfig();
            $file = (string) ($this->file ?? '');
            $loader = AnalyzeCommand::loaderFor($file);
            $parameters = AnalyzeCommand::resolveParameters($config, $this->epsilon, $this->minimumSamples);

            $entries = $loader->load($file);
            $io->info(sprintf('Loaded %d entries from %s', count($entries), $file), true);

            $report = $this->buildUseCase(
                $config,
                (float) ($this->support ?? self::DEFAULT_SUPPORT),
                (float) ($this->confidence ?? self::DEFAULT_CONFIDENCE)
            )->execute($parameters, $entries);

            $this->printReport($io, $report);
        } catch (Throwable $e) {
            $io->error($e->getMessage(), true);
        }
    }

    private function loadAppConfig(): Config
    {
        Env::load($this->projectRoot . DIRECTORY_SEPARATOR . '.env');

        $configFile = $this->projectRoot . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';

        if (!is_file($configFile)) {
            throw new RuntimeException('app/config/config.php not found. Copy config_sample.php first.');
        }

        $fileConfig = require $configFile;
        if (!is_array($fileConfig)) {
            throw new RuntimeException('config.php must return an array.');
        }

        return new Config(Config::mergeEnv($fileConfig, $_ENV));
    }

    private function buildUseCase(Config $config, float $support, float $confidence): CharacterizeAnomalies
    {
        $buckets = (int) $config->get('anomaly.endpoint_hash_buckets', 16);

        return new CharacterizeAnomalies(
            new FeatureExtractor(new LogCategoricalEncoder($buckets)),
            new MinMaxNormalizer(),
            new PhpMlDetectorFactory(),
            $this->miner($support, $confidence),
            new LogTransactionBuilder()
        );
    }

    private function miner(float $support, float $confidence): AssociationMiner
    {
        return new PhpMlAprioriMiner($support, $confidence);
    }

    private function printReport(Interactor $io, SignatureReport $report): void
    {
        $io->info(sprintf(
            '%d anomalies of %d samples; signature rules (support %.2f, confidence %.2f):',
            $report->anomalyCount,
            $report->sampleCount,
            (float) ($this->support ?? self::DEFAULT_SUPPORT),
            (float) ($this->confidence ?? self::DEFAULT_CONFIDENCE)
        ), true);

        if ($report->findings === []) {
            $io->comment('no rules met the thresholds; lower --support / --confidence', true);

            return;
        }

        foreach (array_slice($report->findings, 0, max(1, (int) ($this->limit ?? self::DEFAULT_FINDINGS))) as $finding) {
            $io->green(self::formatFinding($finding), true);
        }
    }

    private static function formatFinding(SignatureFinding $finding): string
    {
        return sprintf(
            '%s => %-28s support %.2f confidence %.2f normal %.3f',
            implode(' + ', $finding->rule->antecedent),
            implode(' + ', $finding->rule->consequent),
            $finding->rule->support,
            $finding->rule->confidence,
            $finding->normalRate
        );
    }
}
