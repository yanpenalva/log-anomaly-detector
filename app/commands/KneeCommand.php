<?php

declare(strict_types=1);

namespace App\Command;

use Ahc\Cli\IO\Interactor;
use App\Domain\Anomaly\FeatureExtractor;
use App\Domain\Anomaly\FeatureVector;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\KDistanceAnalyzer;
use App\Domain\Anomaly\KDistanceSummary;
use App\Infrastructure\Log\CsvHttpLogLoader;
use App\Infrastructure\Log\NginxAccessLogLoader;
use App\Infrastructure\MachineLearning\LogCategoricalEncoder;
use App\Infrastructure\MachineLearning\MinMaxNormalizer;
use App\Domain\Anomaly\HttpLogLoader;
use App\Utils\Config;
use App\Utils\Env;
use flight\commands\AbstractBaseCommand;
use RuntimeException;
use Throwable;

/**
 * @property mixed $file
 * @property mixed $minimumSamples
 */
class KneeCommand extends AbstractBaseCommand
{
    public function __construct(array $config)
    {
        parent::__construct('knee', 'Suggest DBSCAN epsilon from the k-distance curve knee', $config);
        $this->argument('<file>', 'Dataset path: .csv or .log (nginx combined format)');
        $this->option('-m --minimum-samples samples', 'DBSCAN minimum samples; k = minimum_samples - 1 (default: config anomaly.minimum_samples)');
    }

    public function execute(): void
    {
        $io = $this->io();

        try {
            $config = $this->loadAppConfig();
            $file = (string) ($this->file ?? '');
            $loader = self::loaderFor($file);
            $parameters = AnalyzeCommand::resolveParameters($config, null, $this->minimumSamples);

            $entries = $loader->load($file);
            $io->info(sprintf('Loaded %d entries from %s', count($entries), $file), true);

            $vectors = $this->normalize($config, $entries);
            $summary = (new KDistanceAnalyzer())->analyze($vectors, max(1, $parameters->minimumSamples - 1));

            $this->printSummary($io, $summary);
        } catch (Throwable $e) {
            $io->error($e->getMessage(), true);
        }
    }

    public static function loaderFor(string $path): HttpLogLoader
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'csv' => new CsvHttpLogLoader(),
            'log' => new NginxAccessLogLoader(),
            default => throw new RuntimeException('Unsupported dataset format "' . $path . '"; use .csv or .log'),
        };
    }

    private function printSummary(Interactor $io, KDistanceSummary $summary): void
    {
        $distances = $summary->sortedDistances;
        $count = count($distances);

        $io->info(sprintf('k-distance curve over %d samples (k = minimum_samples - 1):', $count), true);
        $io->green(sprintf(
            'suggested epsilon (steepest log drop): %.4f',
            $summary->suggestedEpsilon ?? 0.0
        ), true);
        $io->comment(sprintf(
            '  max %.4f · p99 %.4f · p90 %.4f · median %.4f · min %.4f',
            KDistanceAnalyzer::quantile($distances, 0.0),
            KDistanceAnalyzer::quantile($distances, 0.01),
            KDistanceAnalyzer::quantile($distances, 0.1),
            KDistanceAnalyzer::quantile($distances, 0.5),
            KDistanceAnalyzer::quantile($distances, 1.0)
        ), true);
        $io->comment('heuristic starting point — sweep epsilon around it', true);

        foreach (array_slice($distances, 0, 10) as $index => $value) {
            $io->comment(sprintf('  %3d. %.4f', $index + 1, $value), true);
        }
    }

    /**
     * @param list<HttpLogEntry> $entries
     *
     * @return list<FeatureVector>
     */
    private function normalize(Config $config, array $entries): array
    {
        $buckets = (int) $config->get('anomaly.endpoint_hash_buckets', 16);
        $extractor = new FeatureExtractor(new LogCategoricalEncoder($buckets));
        $normalizer = new MinMaxNormalizer();

        $rawVectors = array_map(static fn ($entry) => $extractor->extract($entry), $entries);
        $fitted = $normalizer->fit($rawVectors);

        return array_map(static fn ($vector) => $fitted->transform($vector), $rawVectors);
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
}
