<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Anomaly\AnalyzeLogs;
use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\FeatureExtractor;
use App\Infrastructure\Log\NginxAccessLogLoader;
use App\Infrastructure\MachineLearning\LogCategoricalEncoder;
use App\Infrastructure\MachineLearning\MinMaxNormalizer;
use App\Infrastructure\MachineLearning\PhpMlDetectorFactory;
use App\Infrastructure\Persistence\SqliteAnalysisResultRepository;
use App\Infrastructure\Persistence\SqliteLogEntryRepository;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\TestDatabase;

class NginxPipelineTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        $this->logPath = sys_get_temp_dir() . '/log_anomaly_pipeline_' . uniqid('', true) . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) {
            unlink($this->logPath);
        }
    }

    public function testNginxLogEndToEndThroughPipeline(): void
    {
        $lines = [
            ...$this->profileLines('GET', '/users', '200', '120', 10),
            ...$this->profileLines('POST', '/payments', '201', '340', 10),
            '10.0.0.99 - - [15/Mar/2026:02:44:00 +0000] "GET /.env HTTP/1.1" 404 8 "-" "scanner" 0.008',
            'a deliberately malformed line',
        ];
        file_put_contents($this->logPath, implode("\n", $lines));

        $entries = (new NginxAccessLogLoader())->load($this->logPath);
        self::assertCount(11, $entries);

        $database = TestDatabase::create();
        try {
            $useCase = new AnalyzeLogs(
                new FeatureExtractor(new LogCategoricalEncoder(16)),
                new MinMaxNormalizer(),
                new PhpMlDetectorFactory(),
                new SqliteAnalysisResultRepository($database->pdo)
            );

            $result = $useCase->execute(DbscanParameters::fromRaw(0.35, 3), $entries);

            self::assertSame(2, $result->clusterCount);
            self::assertSame(1, $result->anomalyCount);
            self::assertSame(
                1,
                count((new SqliteLogEntryRepository($database->pdo))->anomaliesForRun($result->runId ?? 0, 10))
            );
        } finally {
            if (is_file($database->path)) {
                unlink($database->path);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function profileLines(string $method, string $endpoint, string $status, string $ms, int $hour): array
    {
        $lines = [];
        for ($i = 0; $i < 5; $i++) {
            $lines[] = sprintf(
                '10.0.0.%d - - [15/Mar/2026:%02d:00:%02d +0000] "%s %s HTTP/1.1" %s %s "-" "agent" 0.%03d',
                $i + 1,
                $hour,
                $i,
                $method,
                $endpoint,
                $status,
                (int) $ms + $i,
                (int) $ms + $i
            );
        }

        return $lines;
    }
}
