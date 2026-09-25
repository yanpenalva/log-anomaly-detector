<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Api;

use App\Application\Anomaly\AnalyzeLogs;
use App\Controller\Api\AnalysisController;
use App\Domain\Anomaly\FeatureExtractor;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\HttpMethod;
use App\Infrastructure\MachineLearning\LogCategoricalEncoder;
use App\Infrastructure\MachineLearning\MinMaxNormalizer;
use App\Infrastructure\MachineLearning\PhpMlDetectorFactory;
use App\Infrastructure\Persistence\SqliteAnalysisRunRepository;
use App\Infrastructure\Persistence\SqliteLogEntryRepository;
use App\Utils\Config;
use flight\Engine;
use flight\net\Request;
use flight\util\Collection;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\TestDatabase;

class AnalysisControllerTest extends TestCase
{
    /** @var Engine&\PHPUnit\Framework\MockObject\MockObject */
    private Engine $app;

    /** @var array<int, mixed> */
    private array $jsonCalls = [];

    private AnalysisController $controller;

    private string $dbPath;

    private SqliteLogEntryRepository $logEntries;

    private SqliteAnalysisRunRepository $runs;

    protected function setUp(): void
    {
        $database = TestDatabase::create();
        $this->dbPath = $database->path;
        $this->jsonCalls = [];

        $this->app = $this->getMockBuilder(Engine::class)
            ->disableOriginalConstructor()
            ->addMethods(['json', 'request'])
            ->getMock();

        $this->logEntries = new SqliteLogEntryRepository($database->pdo);
        $this->runs = new SqliteAnalysisRunRepository($database->pdo);

        $this->controller = new AnalysisController(
            $this->app,
            $this->config(),
            new AnalyzeLogs(
                new FeatureExtractor(new LogCategoricalEncoder(16)),
                new MinMaxNormalizer(),
                new PhpMlDetectorFactory(),
                $this->runs,
                $this->logEntries
            ),
            $this->runs,
            $this->logEntries
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
        $this->givenBody((string) json_encode(['logs' => [$this->log()]]));
        $this->controller->analyze();
        $runId = $this->jsonCalls[0][0]['data']['run_id'];

        // seed an anomaly row for the run
        $this->logEntries->insertMany($runId, [
            new \App\Domain\Anomaly\ClassifiedLogEntry(
                new HttpLogEntry(HttpMethod::Get, '/.env', 404, 9.0, 60, 3),
                null,
                true
            ),
        ]);

        $this->controller->show((string) $runId);

        [$payload, $status] = $this->jsonCalls[1];
        self::assertSame(200, $status);
        self::assertSame($runId, $payload['data']['id']);
        // 1 analyzed log (which is noise on its own: DBSCAN needs
        // minimumSamples neighbors) + 1 seeded anomaly
        self::assertSame(2, $payload['data']['entries_count']);
        self::assertCount(2, $payload['data']['anomalies']);
        self::assertContains('/.env', array_column($payload['data']['anomalies'], 'endpoint'));
    }

    public function testShowUnknownRunReturns404(): void
    {
        $this->givenBody('');
        $this->controller->show('424242');

        [$payload, $status] = $this->jsonCalls[0];
        self::assertSame(404, $status);
        self::assertSame('not_found', $payload['error']['code']);
    }
}
