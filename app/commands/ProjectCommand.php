<?php

declare(strict_types=1);

namespace App\Command;

use Ahc\Cli\IO\Interactor;
use App\Application\Anomaly\ProjectionReport;
use App\Application\Anomaly\ProjectSamples;
use App\Domain\Anomaly\DimensionalityReduction;
use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\FeatureExtractor;
use App\Infrastructure\MachineLearning\LogCategoricalEncoder;
use App\Infrastructure\MachineLearning\MinMaxNormalizer;
use App\Infrastructure\MachineLearning\PhpMlDetectorFactory;
use App\Infrastructure\MachineLearning\PhpMlPcaTransformer;
use App\Utils\Config;
use App\Utils\Env;
use flight\commands\AbstractBaseCommand;
use RuntimeException;
use Throwable;

/**
 * @property mixed $file
 * @property mixed $epsilon
 * @property mixed $minimumSamples
 * @property mixed $dimensions
 * @property mixed $output
 */
class ProjectCommand extends AbstractBaseCommand
{
    private const DEFAULT_DIMENSIONS = 2;

    public function __construct(array $config)
    {
        parent::__construct('project', 'Project a DBSCAN analysis into 2D/3D space with PCA', $config);
        $this->argument('<file>', 'Dataset path: .csv or .log (nginx combined format)');
        $this->option('-e --epsilon epsilon', 'DBSCAN epsilon (default: config anomaly.epsilon)');
        $this->option('-m --minimum-samples samples', 'DBSCAN minimum samples (default: config anomaly.minimum_samples)');
        $this->option('-d --dimensions dimensions', 'PCA target dimensions (default: 2)', null, self::DEFAULT_DIMENSIONS);
        $this->option('-o --output output', 'Write CSV to this file instead of stdout');
    }

    public function execute(): void
    {
        $io = $this->io();

        try {
            $config = $this->loadAppConfig();
            $file = (string) ($this->file ?? '');
            $loader = AnalyzeCommand::loaderFor($file);
            $parameters = AnalyzeCommand::resolveParameters($config, $this->epsilon, $this->minimumSamples);
            $dimensions = (int) ($this->dimensions ?? self::DEFAULT_DIMENSIONS);

            $entries = $loader->load($file);
            $io->info(sprintf('Loaded %d entries from %s', count($entries), $file), true);

            $report = $this->buildUseCase($config, $dimensions)->execute($parameters, $entries);
            $csv = self::toCsv($report);
            $this->deliver($io, $csv, (string) ($this->output ?? ''));
        } catch (Throwable $e) {
            $io->error($e->getMessage(), true);
        }
    }

    public static function toCsv(ProjectionReport $report): string
    {
        $lines = ['index,pc1,pc2,cluster,anomaly'];

        foreach ($report->samples as $index => $sample) {
            $lines[] = sprintf(
                '%d,%.6f,%.6f,%s,%d',
                $index,
                $sample->coordinates[0],
                $sample->coordinates[1],
                $sample->cluster ?? 'noise',
                (int) $sample->isAnomaly
            );
        }

        return implode("\n", $lines) . "\n";
    }

    private function deliver(Interactor $io, string $csv, string $output): void
    {
        if ($output !== '') {
            file_put_contents($output, $csv);
            $io->green(sprintf('projection written to %s', $output), true);

            return;
        }

        $io->comment('index,pc1,pc2,cluster,anomaly', true);
        foreach (array_slice(explode("\n", trim($csv)), 1, 20) as $line) {
            $io->comment($line, true);
        }
        $io->info('(showing first 20 rows; use --output=<file> for the full CSV)', true);
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

    private function buildUseCase(Config $config, int $dimensions): ProjectSamples
    {
        $buckets = (int) $config->get('anomaly.endpoint_hash_buckets', 16);

        return new ProjectSamples(
            new FeatureExtractor(new LogCategoricalEncoder($buckets)),
            new MinMaxNormalizer(),
            new PhpMlDetectorFactory(),
            $this->reduction($dimensions)
        );
    }

    private function reduction(int $dimensions): DimensionalityReduction
    {
        return new PhpMlPcaTransformer($dimensions);
    }
}
