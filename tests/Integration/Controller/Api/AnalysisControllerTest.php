<?php

declare(strict_types=1);

namespace Tests\Integration\Controller\Api;

use App\Application\Anomaly\AnalyzeLogs;
use App\Application\Anomaly\BuildVisualization;
use App\Application\Anomaly\CompareDetectors;
use App\Application\Anomaly\EstimateEpsilon;
use App\Controller\Api\AnalysisController;
use App\Domain\Anomaly\AnalysisResultRepository;
use App\Domain\Anomaly\ClassifiedLogEntry;
use App\Domain\Anomaly\FeatureExtractor;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\HttpMethod;
use App\Domain\Anomaly\KDistanceAnalyzer;
use App\Infrastructure\MachineLearning\LogCategoricalEncoder;
use App\Infrastructure\MachineLearning\IsolationForestFactory;
use App\Infrastructure\MachineLearning\LofFactory;
use App\Infrastructure\MachineLearning\MinMaxNormalizer;
use App\Infrastructure\MachineLearning\PhpMlDetectorFactory;
use App\Infrastructure\MachineLearning\PhpMlKMeansFactory;
use App\Infrastructure\MachineLearning\PhpMlPcaTransformer;
use App\Infrastructure\Persistence\SqliteAnalysisResultRepository;
use App\Infrastructure\Persistence\SqliteAnalysisRunRepository;
use App\Infrastructure\Persistence\SqliteLogEntryRepository;
use App\Utils\Config;
use flight\database\SimplePdo;
use flight\Engine;
use flight\net\Request;
use flight\util\Collection;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\TestDatabase;

class AnalysisControllerTest extends TestCase
{
    /** @var Engine&\PHPUnit\Framework\MockObject\MockObject */
    private Engine $app;

    /** @var array<int, mixed> */
    private array $jsonCalls = [];

    private AnalysisController $controller;

    private AnalysisResultRepository $results;

    private SqliteAnalysisRunRepository $runs;

    private SimplePdo $pdo;

    private string $dbPath;

    protected function setUp(): void
    {
        $database = TestDatabase::create();
        $this->dbPath = $database->path;
        $this->pdo = $database->pdo;
        $this->jsonCalls = [];

        $this->app = $this->getMockBuilder(Engine::class)
            ->disableOriginalConstructor()
            ->addMethods(['json', 'request', 'response'])
            ->getMock();

        $this->results = new SqliteAnalysisResultRepository($database->pdo);
        $this->runs = new SqliteAnalysisRunRepository($database->pdo);

        $this->controller = new AnalysisController(
            $this->app,
            $this->config(),
            new AnalyzeLogs(
                new FeatureExtractor(new LogCategoricalEncoder(16)),
                new MinMaxNormalizer(),
                new PhpMlDetectorFactory(),
                $this->results
            ),
            new BuildVisualization(
                new FeatureExtractor(new LogCategoricalEncoder(16)),
                new MinMaxNormalizer(),
                new PhpMlDetectorFactory(),
                new PhpMlPcaTransformer(2),
                new KDistanceAnalyzer()
            ),
            new CompareDetectors(
                new FeatureExtractor(new LogCategoricalEncoder(16)),
                new MinMaxNormalizer(),
                new PhpMlDetectorFactory(),
                new PhpMlKMeansFactory(),
                new LofFactory(),
                new IsolationForestFactory()
            ),
            new EstimateEpsilon(
                new FeatureExtractor(new LogCategoricalEncoder(16)),
                new MinMaxNormalizer(),
                new KDistanceAnalyzer()
            ),
            $this->runs,
            new SqliteLogEntryRepository($database->pdo)
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    private function config(): Config
    {
        return new Config([
            'anomaly' => [
                'epsilon' => 0.5,
                'minimum_samples' => 5,
                'max_payload_bytes' => 8192,
                'max_logs_per_request' => 5,
                'max_anomalies_in_response' => 100,
            ],
        ]);
    }

    private function givenBody(string $body): void
    {
        $request = new Request([
            'url' => '/api/v1/analyze',
            'base' => '',
            'method' => 'POST',
            'type' => 'application/json',
            'query' => new Collection(),
            'data' => new Collection(),
            'cookies' => new Collection(),
            'files' => new Collection(),
            'body' => $body,
        ]);

        $this->app->method('request')->willReturn($request);
        $this->app->method('json')->willReturnCallback(function (array $payload, int $code = 200): void {
            $this->jsonCalls[] = [$payload, $code];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function log(int $i = 0): array
    {
        return [
            'method' => 'GET',
            'endpoint' => '/users',
            'status_code' => 200,
            'response_time' => 100 + $i,
            'request_size' => 900 + $i,
            'hour' => 10,
        ];
    }

    public function testAnalyzeReturnsSummary(): void
    {
        $logs = [];
        for ($i = 0; $i < 4; $i++) {
            $logs[] = $this->log($i);
        }
        $logs[] = [
            'method' => 'POST', 'endpoint' => '/payments', 'status_code' => 201,
            'response_time' => 340, 'request_size' => 1500, 'hour' => 12,
        ];
        $this->givenBody((string) json_encode(['logs' => $logs]));

        $this->controller->analyze();

        self::assertCount(1, $this->jsonCalls);
        [$payload, $status] = $this->jsonCalls[0];
        self::assertSame(200, $status);
        self::assertSame(5, $payload['data']['samples']);
        self::assertArrayHasKey('clusters', $payload['data']);
        self::assertArrayHasKey('anomalies', $payload['data']);
        self::assertGreaterThan(0, $payload['data']['run_id']);
        self::assertSame('dbscan', $payload['data']['algorithm']);
        self::assertSame(0.5, $payload['data']['epsilon']);
    }

    public function testAnalyzePersistsRun(): void
    {
        $this->givenBody((string) json_encode(['logs' => [$this->log()]]));
        $this->controller->analyze();

        self::assertCount(1, $this->runs->list());
    }

    public function testInvalidJsonReturns400(): void
    {
        $this->givenBody('{not json');
        $this->controller->analyze();

        [$payload, $status] = $this->jsonCalls[0];
        self::assertSame(400, $status);
        self::assertSame('invalid_json', $payload['error']['code']);
    }

    public function testNonObjectBodyReturns400(): void
    {
        $this->givenBody('[1,2,3]');
        $this->controller->analyze();

        [$payload, $status] = $this->jsonCalls[0];
        self::assertSame(400, $status);
        self::assertSame('invalid_request', $payload['error']['code']);
    }

    public function testMissingLogsReturns422(): void
    {
        $this->givenBody((string) json_encode(['other' => 1]));
        $this->controller->analyze();

        [$payload, $status] = $this->jsonCalls[0];
        self::assertSame(422, $status);
        self::assertSame('invalid_request', $payload['error']['code']);
    }

    public function testTooManyLogsReturns422(): void
    {
        $logs = [];
        for ($i = 0; $i < 6; $i++) {
            $logs[] = $this->log($i);
        }
        $this->givenBody((string) json_encode(['logs' => $logs]));
        $this->controller->analyze();

        [$payload, $status] = $this->jsonCalls[0];
        self::assertSame(422, $status);
        self::assertSame('too_many_logs', $payload['error']['code']);
    }

    public function testOversizedPayloadReturns413(): void
    {
        $this->givenBody(str_repeat('x', 9000));
        $this->controller->analyze();

        [$payload, $status] = $this->jsonCalls[0];
        self::assertSame(413, $status);
        self::assertSame('payload_too_large', $payload['error']['code']);
    }

    public function testInvalidLogReturns422WithIndex(): void
    {
        $bad = $this->log();
        $bad['status_code'] = 9999;
        $this->givenBody((string) json_encode(['logs' => [$this->log(), $bad]]));
        $this->controller->analyze();

        [$payload, $status] = $this->jsonCalls[0];
        self::assertSame(422, $status);
        self::assertSame('invalid_log', $payload['error']['code']);
        self::assertStringContainsString('logs[1]', $payload['error']['message']);
    }

    public function testInvalidEpsilonReturns422(): void
    {
        $this->givenBody((string) json_encode(['logs' => [$this->log()], 'epsilon' => -3]));
        $this->controller->analyze();

        [$payload, $status] = $this->jsonCalls[0];
        self::assertSame(422, $status);
        self::assertSame('invalid_parameters', $payload['error']['code']);
    }

    public function testLogThatIsNotAnObjectReturns422(): void
    {
        $this->givenBody((string) json_encode(['logs' => ['nope']]));
        $this->controller->analyze();

        [$payload, $status] = $this->jsonCalls[0];
        self::assertSame(422, $status);
        self::assertSame('invalid_log', $payload['error']['code']);
    }

    public function testProjectReturnsPointsAndEpsilonHint(): void
    {
        $logs = [];
        for ($i = 0; $i < 20; $i++) {
            $logs[] = $this->log($i);
        }
        for ($i = 0; $i < 15; $i++) {
            $logs[] = [
                'method' => 'POST', 'endpoint' => '/payments', 'status_code' => 201,
                'response_time' => 340 + $i, 'request_size' => 1500 + $i, 'hour' => 12,
            ];
        }
        for ($i = 0; $i < 4; $i++) {
            $logs[] = [
                'method' => 'GET', 'endpoint' => '/.env', 'status_code' => 404,
                'response_time' => 9 + $i, 'request_size' => 60 + $i, 'hour' => 3,
            ];
        }
        $this->givenBody((string) json_encode(['logs' => $logs]));

        $this->controller->project();

        [$payload, $status] = $this->jsonCalls[0];
        self::assertSame(200, $status);
        self::assertSame(39, $payload['data']['sample_count']);
        self::assertCount(39, $payload['data']['points']);
        self::assertCount(2, $payload['data']['points'][0]['coordinates']);
        self::assertNotNull($payload['data']['suggested_epsilon']);
        self::assertSame(4, $payload['data']['anomalies']);
        self::assertSame(2, $payload['data']['clusters']);
        self::assertArrayHasKey('curve', $payload['data']);
        self::assertCount(39, $payload['data']['curve']);
        self::assertSame(['index', 'distance'], array_keys($payload['data']['curve'][0]));
    }

    public function testKneeReturnsSuggestionAndCurve(): void
    {
        $logs = [];
        for ($i = 0; $i < 4; $i++) {
            $logs[] = $this->log($i);
        }
        $logs[] = [
            'method' => 'GET', 'endpoint' => '/.env', 'status_code' => 404,
            'response_time' => 9, 'request_size' => 60, 'hour' => 3,
        ];
        $this->givenBody((string) json_encode(['logs' => $logs]));

        $this->controller->knee();

        [$payload, $status] = $this->jsonCalls[0];
        self::assertSame(200, $status);
        self::assertSame(5, $payload['data']['sample_count']);
        self::assertSame(4, $payload['data']['k']);
        self::assertNotNull($payload['data']['suggested_epsilon']);
        self::assertSame(
            ['max', 'p99', 'p90', 'median', 'min'],
            array_keys($payload['data']['quantiles'])
        );
        self::assertCount(5, $payload['data']['curve']);
    }

    public function testAnalyzeAcceptsInlineCsvText(): void
    {
        $csv = "method,endpoint,status_code,response_time,request_size,hour\n"
            . "GET,/users,200,100,900,10\n"
            . "POST,/payments,201,340,1500,12\n";
        $this->givenBody((string) json_encode(['text' => $csv, 'format' => 'csv']));

        $this->controller->analyze();

        [$payload, $status] = $this->jsonCalls[0];
        self::assertSame(200, $status);
        self::assertSame(2, $payload['data']['samples']);
    }

    public function testProjectAcceptsInlineNginxTextWithAutoFormat(): void
    {
        $log = implode("\n", array_map(static fn (int $i): string => sprintf(
            '127.0.0.1 - - [10/Oct/2026:13:%02d:00 -0300] "GET /users HTTP/1.1" 200 %d',
            $i,
            900 + $i
        ), range(0, 39)));
        $log .= "\n" . '127.0.0.1 - - [10/Oct/2026:14:00:00 -0300] "GET /.env HTTP/1.1" 404 60 0.005';
        $this->givenBody((string) json_encode(['text' => $log]));

        $this->controller->project();

        [$payload, $status] = $this->jsonCalls[0];
        self::assertSame(200, $status);
        self::assertSame(41, $payload['data']['sample_count']);
        self::assertSame(1, $payload['data']['anomalies']);
    }

    public function testTextWithBadCsvReturnsInvalidText(): void
    {
        $this->givenBody((string) json_encode(['text' => 'not,a,valid,header,row', 'format' => 'csv']));

        $this->controller->analyze();

        [$payload, $status] = $this->jsonCalls[0];
        self::assertSame(422, $status);
        self::assertSame('invalid_text', $payload['error']['code']);
    }

    public function testCompareReturnsFourDetectorReports(): void
    {
        $logs = [];
        for ($i = 0; $i < 4; $i++) {
            $logs[] = $this->log($i);
        }
        $logs[] = [
            'method' => 'GET', 'endpoint' => '/.env', 'status_code' => 404,
            'response_time' => 9, 'request_size' => 60, 'hour' => 3,
        ];
        $this->givenBody((string) json_encode(['logs' => $logs]));

        $this->controller->compare();

        [$payload, $status] = $this->jsonCalls[0];
        self::assertSame(200, $status);
        self::assertSame(5, $payload['data']['sample_count']);
        self::assertCount(4, $payload['data']['detectors']);
        self::assertSame(
            ['dbscan', 'kmeans', 'lof', 'isolation_forest'],
            array_column($payload['data']['detectors'], 'algorithm')
        );
        self::assertSame(['dbscan', 'lof', 'isolation_forest'], $payload['data']['consensus']['voters']);
        self::assertGreaterThanOrEqual(0, $payload['data']['consensus']['anomalies']);
        self::assertArrayHasKey('unanimous', $payload['data']['consensus']);
        foreach ($payload['data']['detectors'] as $detector) {
            self::assertArrayHasKey('silhouette', $detector);
            self::assertArrayHasKey('elapsed_ms', $detector);
        }
    }

    public function testCompareRejectsInvalidForestThreshold(): void
    {
        $this->givenBody((string) json_encode([
            'logs' => [$this->log()],
            'forest_threshold' => 9.9,
        ]));

        $this->controller->compare();

        [$payload, $status] = $this->jsonCalls[0];
        self::assertSame(422, $status);
        self::assertSame('invalid_parameters', $payload['error']['code']);
    }

    public function testExportReturnsAnomaliesCsv(): void
    {
        $saved = $this->results->save($this->seededRun(), [
            new ClassifiedLogEntry(
                new HttpLogEntry(HttpMethod::Get, '/users', 200, 118.0, 1024, 10),
                0,
                false
            ),
            new ClassifiedLogEntry(
                new HttpLogEntry(HttpMethod::Get, '/.env', 404, 9.0, 60, 3),
                null,
                true
            ),
        ]);

        $response = new \flight\net\Response();
        $this->app->method('response')->willReturn($response);

        $this->givenBody('');
        $this->controller->export((string) ($saved->id ?? 0));

        self::assertSame([], $this->jsonCalls, 'export must not emit JSON');
        self::assertSame('text/csv; charset=utf-8', $response->getHeader('Content-Type'));
        self::assertSame('attachment; filename="run-' . ($saved->id ?? 0) . '-anomalies.csv"', $response->getHeader('Content-Disposition'));
        self::assertStringContainsString('method,endpoint,status_code,response_time,request_size,hour', $response->getBody());
        self::assertStringContainsString('GET,/.env,404,9,60,3', $response->getBody());
    }

    public function testProjectRejectsMoreThanCap(): void
    {
        $controller = $this->controllerWithBigPayloadLimit();
        $logs = [];
        for ($i = 0; $i < 2001; $i++) {
            $logs[] = $this->log($i % 50);
        }
        $this->givenBody((string) json_encode(['logs' => $logs]));

        $controller->project();

        [$payload, $status] = $this->jsonCalls[0];
        self::assertSame(422, $status);
        self::assertSame('too_many_logs', $payload['error']['code']);
        self::assertStringContainsString('2000', $payload['error']['message']);
    }

    private function controllerWithBigPayloadLimit(): AnalysisController
    {
        $config = new Config([
            'anomaly' => [
                'epsilon' => 0.5,
                'minimum_samples' => 5,
                'max_payload_bytes' => 1048576,
                'max_logs_per_request' => 5000,
                'max_anomalies_in_response' => 100,
            ],
        ]);

        return new AnalysisController(
            $this->app,
            $config,
            new AnalyzeLogs(
                new FeatureExtractor(new LogCategoricalEncoder(16)),
                new MinMaxNormalizer(),
                new PhpMlDetectorFactory(),
                $this->results
            ),
            new BuildVisualization(
                new FeatureExtractor(new LogCategoricalEncoder(16)),
                new MinMaxNormalizer(),
                new PhpMlDetectorFactory(),
                new PhpMlPcaTransformer(2),
                new KDistanceAnalyzer()
            ),
            new CompareDetectors(
                new FeatureExtractor(new LogCategoricalEncoder(16)),
                new MinMaxNormalizer(),
                new PhpMlDetectorFactory(),
                new PhpMlKMeansFactory(),
                new LofFactory(),
                new IsolationForestFactory()
            ),
            new EstimateEpsilon(
                new FeatureExtractor(new LogCategoricalEncoder(16)),
                new MinMaxNormalizer(),
                new KDistanceAnalyzer()
            ),
            $this->runs,
            new SqliteLogEntryRepository($this->pdo)
        );
    }

    public function testDetectReturnsHonest501(): void
    {
        $this->givenBody('{}');
        $this->controller->detect();

        self::assertCount(1, $this->jsonCalls);
        [$payload, $status] = $this->jsonCalls[0];
        self::assertSame(501, $status);
        self::assertSame('not_implemented', $payload['error']['code']);
        self::assertStringContainsStringIgnoringCase('dbscan', $payload['error']['message']);
    }

    public function testIndexListsRuns(): void
    {
        $this->givenBody((string) json_encode(['logs' => [$this->log()]]));
        $this->controller->analyze();
        $this->controller->index();

        [$payload, $status] = $this->jsonCalls[1];
        self::assertSame(200, $status);
        self::assertCount(1, $payload['data']);
        self::assertSame('dbscan', $payload['data'][0]['algorithm']);
        self::assertSame(1, $payload['data'][0]['samples']);
    }

    public function testShowReturnsRunWithAnomalies(): void
    {
        $saved = $this->results->save($this->seededRun(), [
            new ClassifiedLogEntry(
                new HttpLogEntry(HttpMethod::Get, '/users', 200, 118.0, 1024, 10),
                0,
                false
            ),
            new ClassifiedLogEntry(
                new HttpLogEntry(HttpMethod::Get, '/.env', 404, 9.0, 60, 3),
                null,
                true
            ),
        ]);

        $this->givenBody('');
        $this->controller->show((string) ($saved->id ?? 0));

        [$payload, $status] = $this->jsonCalls[0];
        self::assertSame(200, $status);
        self::assertSame($saved->id, $payload['data']['id']);
        self::assertSame(2, $payload['data']['entries_count']);
        self::assertSame(['/.env'], array_column($payload['data']['anomalies'], 'endpoint'));
    }

    public function testShowUnknownRunReturns404(): void
    {
        $this->givenBody('');
        $this->controller->show('424242');

        [$payload, $status] = $this->jsonCalls[0];
        self::assertSame(404, $status);
        self::assertSame('not_found', $payload['error']['code']);
    }

    private function seededRun(): \App\Domain\Anomaly\AnalysisRun
    {
        return new \App\Domain\Anomaly\AnalysisRun(
            null,
            \App\Domain\Anomaly\DetectionAlgorithm::Dbscan,
            0.5,
            5,
            2,
            1,
            1,
            new \DateTimeImmutable(),
            new \DateTimeImmutable()
        );
    }
}
