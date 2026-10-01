<?php

declare(strict_types=1);

namespace App\Command;

use Ahc\Cli\IO\Interactor;
use App\Application\Anomaly\AnalyzeWindows;
use App\Application\Anomaly\WindowAnalysisReport;
use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\FeatureExtractor;
use App\Domain\Anomaly\KDistanceAnalyzer;
use App\Infrastructure\MachineLearning\LogCategoricalEncoder;
use App\Infrastructure\MachineLearning\MinMaxNormalizer;
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
 */
class WindowsCommand extends AbstractBaseCommand
{
    public function __construct(array $config)
    {
        parent::__construct('windows', 'Run DBSCAN per hour bucket to expose drift across the day', $config);
        $this->argument('<file>', 'Dataset path: .csv or .log (nginx combined format)');
        $this->option('-e --epsilon epsilon', 'DBSCAN epsilon (default: config anomaly.epsilon)');
        $this->option('-m --minimum-samples samples', 'DBSCAN minimum samples (default: config anomaly.minimum_samples)');
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

            $report = $this->buildUseCase($config)->execute($parameters, $entries);

            $this->printReport($io, $report);
        } catch (Throwable $e) {
            $io->error($e->getMessage(), true);
        }
    }

    private function printReport(Interactor $io, WindowAnalysisReport $report): void
    {
        $io->info(sprintf('hourly drift over %d samples:', $report->sampleCount), true);

        foreach ($report->windows as $window) {
            $io->{$window->noiseRatio >= 0.1 ? 'yellow' : 'green'}(sprintf(
                '%02dh · samples %5d · clusters %2d · anomalies %3d (%.1f%%) · knee ε %s',
                $window->hour,
                $window->sampleCount,
                $window->clusterCount,
                $window->anomalyCount,
                $window->noiseRatio * 100,
                $window->suggestedEpsilon === null ? 'n/a' : sprintf('%.3f', $window->suggestedEpsilon)
            ), true);
        }
    }

    private function buildUseCase(Config $config): AnalyzeWindows
    {
        $buckets = (int) $config->get('anomaly.endpoint_hash_buckets', 16);

        return new AnalyzeWindows(
            new FeatureExtractor(new LogCategoricalEncoder($buckets)),
            new MinMaxNormalizer(),
            new PhpMlDetectorFactory(),
            new KDistanceAnalyzer()
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
}
