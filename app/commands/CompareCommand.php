<?php

declare(strict_types=1);

namespace App\Command;

use Ahc\Cli\IO\Interactor;
use App\Application\Anomaly\CompareDetectors;
use App\Application\Anomaly\ComparisonReport;
use App\Application\Anomaly\DetectorReport;
use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\FeatureExtractor;
use App\Domain\Anomaly\IsolationForestParameters;
use App\Domain\Anomaly\KMeansParameters;
use App\Domain\Anomaly\LofParameters;
use App\Infrastructure\MachineLearning\LogCategoricalEncoder;
use App\Infrastructure\MachineLearning\IsolationForestFactory;
use App\Infrastructure\MachineLearning\LofFactory;
use App\Infrastructure\MachineLearning\MinMaxNormalizer;
use App\Infrastructure\MachineLearning\PhpMlDetectorFactory;
use App\Infrastructure\MachineLearning\PhpMlKMeansFactory;
use App\Utils\Config;
use App\Utils\Env;
use flight\commands\AbstractBaseCommand;
use RuntimeException;
use Throwable;

/**
 * @property mixed $file
 * @property mixed $epsilon
 * @property mixed $minimumSamples
 * @property mixed $clusters
 * @property mixed $lofThreshold
 * @property mixed $trees
 * @property mixed $subsample
 * @property mixed $forestThreshold
 */
class CompareCommand extends AbstractBaseCommand
{
    private const DEFAULT_CLUSTERS = 4;
    private const DEFAULT_TREES = 100;
    private const DEFAULT_SUBSAMPLE = 256;

    public function __construct(array $config)
    {
        parent::__construct('compare', 'Compare DBSCAN, K-Means, LOF and Isolation Forest with family-fit metrics', $config);
        $this->argument('<file>', 'Dataset path: .csv or .log (nginx combined format)');
        $this->option('-e --epsilon epsilon', 'DBSCAN epsilon (default: config anomaly.epsilon)');
        $this->option('-m --minimum-samples samples', 'Density threshold shared by dbscan/kmeans/lof (default: config anomaly.minimum_samples)');
        $this->option('-k --clusters clusters', 'K-Means cluster count k (default: config anomaly.kmeans_clusters)');
        $this->option('--lof-threshold lofCutoff', 'LOF anomaly cutoff (default: 1.5)', null, (string) LofParameters::DEFAULT_THRESHOLD);
        $this->option('--trees trees', 'Isolation Forest ensemble size (default: 100)', null, (string) self::DEFAULT_TREES);
        $this->option('--subsample subsample', 'Isolation Forest per-tree subsample (default: 256)', null, (string) self::DEFAULT_SUBSAMPLE);
        $this->option('--forest-threshold forestCutoff', 'Isolation Forest anomaly cutoff (default: 0.6)', null, (string) IsolationForestParameters::DEFAULT_THRESHOLD);
    }

    public function execute(): void
    {
        $io = $this->io();

        try {
            $config = $this->loadAppConfig();
            $file = (string) ($this->file ?? '');
            $loader = AnalyzeCommand::loaderFor($file);
            $dbscan = AnalyzeCommand::resolveParameters($config, $this->epsilon, $this->minimumSamples);
            $kmeans = self::resolveKMeansParameters($config, $this->clusters, $dbscan->minimumSamples);
            $lof = self::resolveLofParameters($this->lofThreshold, $dbscan->minimumSamples);
            $forest = self::resolveIsolationForestParameters($this->trees, $this->subsample, $this->forestThreshold);

            $entries = $loader->load($file);
            $io->info(sprintf('Loaded %d entries from %s', count($entries), $file), true);

            $report = $this->buildComparison($config)->execute($dbscan, $kmeans, $lof, $forest, $entries);

            $this->printReport($io, $report);
        } catch (Throwable $e) {
            $io->error($e->getMessage(), true);
        }
    }

    public static function resolveKMeansParameters(Config $config, mixed $clusters, int $minimumSamples): KMeansParameters
    {
        return KMeansParameters::fromRaw(
            $clusters ?? $config->get('anomaly.kmeans_clusters', self::DEFAULT_CLUSTERS),
            $minimumSamples
        );
    }

    public static function resolveLofParameters(mixed $threshold, int $minimumSamples): LofParameters
    {
        return LofParameters::fromRaw($minimumSamples, $threshold ?? LofParameters::DEFAULT_THRESHOLD);
    }

    public static function resolveIsolationForestParameters(mixed $trees, mixed $subsample, mixed $threshold): IsolationForestParameters
    {
        return IsolationForestParameters::fromRaw(
            $trees ?? self::DEFAULT_TREES,
            $subsample ?? self::DEFAULT_SUBSAMPLE,
            $threshold ?? IsolationForestParameters::DEFAULT_THRESHOLD
        );
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

    private function buildComparison(Config $config): CompareDetectors
    {
        $buckets = (int) $config->get('anomaly.endpoint_hash_buckets', 16);

        return new CompareDetectors(
            new FeatureExtractor(new LogCategoricalEncoder($buckets)),
            new MinMaxNormalizer(),
            new PhpMlDetectorFactory(),
            new PhpMlKMeansFactory(),
            new LofFactory(),
            new IsolationForestFactory()
        );
    }

    private function printReport(Interactor $io, ComparisonReport $report): void
    {
        $io->info(sprintf('comparison over %d shared normalized samples:', $report->sampleCount), true);

        foreach ($report->detectors as $detector) {
            $this->printDetector($io, $detector);
        }

        $io->comment('dbscan anomaly = noise; kmeans/lof/forest anomaly = threshold rule per family', true);
        $io->comment('silhouette [-1..1] shared; inertia = within-cluster sum of squares', true);
    }

    private function printDetector(Interactor $io, DetectorReport $detector): void
    {
        $io->green(sprintf(
            '%-16s · clusters %d · anomalies %d (%.1f%%) · silhouette %s · inertia %.3f · %.0f ms',
            $detector->algorithm->value,
            $detector->clusterCount,
            $detector->anomalyCount,
            $detector->noiseRatio * 100,
            self::formatSilhouette($detector->silhouette),
            $detector->inertia,
            $detector->elapsedMs,
        ), true);
    }

    private static function formatSilhouette(?float $silhouette): string
    {
        return match ($silhouette) {
            null => 'n/a',
            default => sprintf('%.3f', $silhouette),
        };
    }
}
