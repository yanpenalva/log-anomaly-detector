<?php

declare(strict_types=1);

namespace App\Command;

use Ahc\Cli\IO\Interactor;
use App\Application\Anomaly\AnalyzeLogs;
use App\Domain\Anomaly\AnalysisResult;
use App\Domain\Anomaly\ClassifiedLogEntry;
use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\FeatureExtractor;
use App\Domain\Anomaly\HttpLogLoader;
use App\Infrastructure\Log\CsvHttpLogLoader;
use App\Infrastructure\Log\NginxAccessLogLoader;
use App\Infrastructure\MachineLearning\LogCategoricalEncoder;
use App\Infrastructure\MachineLearning\MinMaxNormalizer;
use App\Infrastructure\MachineLearning\PhpMlDetectorFactory;
use App\Infrastructure\Persistence\SqliteAnalysisResultRepository;
use App\Utils\Config;
use App\Utils\DatabaseFactory;
use App\Utils\Env;
use flight\commands\AbstractBaseCommand;
use RuntimeException;
use Throwable;

/**
 * Batch anomaly analysis CLI:
 *   php runway analyze <file> [--epsilon=0.35] [--minimum-samples=5] [--limit=20]
 * Supported datasets: .csv (development dataset) and .log (nginx combined).
 *
 * @property mixed $file
 * @property mixed $epsilon
 * @property mixed $minimumSamples
 * @property mixed $limit
 */
class AnalyzeCommand extends AbstractBaseCommand
{
    private const DEFAULT_ANOMALY_ROWS = 20;

    public function __construct(array $config)
    {
        parent::__construct('analyze', 'Run batch anomaly analysis over a CSV or nginx access.log file', $config);
        $this->argument('<file>', 'Dataset path: .csv or .log (nginx combined format)');
        $this->option('-e --epsilon epsilon', 'DBSCAN epsilon (default: config anomaly.epsilon)');
        $this->option('-m --minimum-samples samples', 'DBSCAN minimum samples (default: config anomaly.minimum_samples)');
        $this->option('-l --limit limit', 'Max anomaly rows printed', null, self::DEFAULT_ANOMALY_ROWS);
    }

    public function execute(): void
    {
        $io = $this->io();

        try {
            $config = $this->loadAppConfig();
            $file = (string) ($this->file ?? '');
            $loader = self::loaderFor($file);
            $parameters = self::resolveParameters($config, $this->epsilon, $this->minimumSamples);

            $entries = $loader->load($file);
            $io->info(sprintf('Loaded %d entries from %s', count($entries), $file), true);

            $result = $this->buildUseCase($config)->execute($parameters, $entries);

            $io->green(sprintf(
                'run %d · dbscan · samples %d · clusters %d · anomalies %d',
                $result->runId ?? 0,
                $result->sampleCount,
                $result->clusterCount,
                $result->anomalyCount
            ), true);

            $this->printAnomalies($io, $result, max(1, (int) ($this->limit ?? self::DEFAULT_ANOMALY_ROWS)));
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

    public static function loaderFor(string $path): HttpLogLoader
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'csv' => new CsvHttpLogLoader(),
            'log' => new NginxAccessLogLoader(),
            default => throw new RuntimeException('Unsupported dataset format "' . $path . '"; use .csv or .log'),
        };
    }

    public static function resolveParameters(Config $config, ?string $epsilon, ?string $minimumSamples): DbscanParameters
    {
        return DbscanParameters::fromRaw(
            $epsilon ?? $config->get('anomaly.epsilon', 0.35),
            $minimumSamples ?? $config->get('anomaly.minimum_samples', 5)
        );
    }

    private function buildUseCase(Config $config): AnalyzeLogs
    {
        $db = DatabaseFactory::create($config);
        $buckets = (int) $config->get('anomaly.endpoint_hash_buckets', 16);

        return new AnalyzeLogs(
            new FeatureExtractor(new LogCategoricalEncoder($buckets)),
            new MinMaxNormalizer(),
            new PhpMlDetectorFactory(),
            new SqliteAnalysisResultRepository($db)
        );
    }

    private function printAnomalies(Interactor $io, AnalysisResult $result, int $limit): void
    {
        $anomalies = array_slice(
            array_values(array_filter($result->classified, static fn (ClassifiedLogEntry $e): bool => $e->isAnomaly)),
            0,
            $limit
        );

        if ($anomalies === []) {
            return;
        }

        $io->info(sprintf('anomalies (first %d of %d):', min($limit, count($anomalies)), $result->anomalyCount), true);
        foreach ($anomalies as $anomaly) {
            $io->comment('  ' . self::formatRow($anomaly), true);
        }
    }

    private static function formatRow(ClassifiedLogEntry $anomaly): string
    {
        $entry = $anomaly->entry;

        return sprintf(
            '%-7s %-40s %d %dms %dB @ %02dh',
            $entry->method->value,
            $entry->endpoint,
            $entry->statusCode,
            (int) round($entry->responseTime),
            $entry->requestSize,
            $entry->hour
        );
    }
}
