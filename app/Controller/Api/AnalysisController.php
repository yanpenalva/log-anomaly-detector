<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\Anomaly\AnalyzeLogs;
use App\Application\Anomaly\BuildVisualization;
use App\Application\Anomaly\CompareDetectors;
use App\Application\Anomaly\DetectorReport;
use App\Application\Anomaly\EstimateEpsilon;
use App\Application\Anomaly\ProjectedSample;
use App\Domain\Anomaly\AnalysisRun;
use App\Domain\Anomaly\AnalysisRunRepository;
use App\Domain\Anomaly\ClassifiedLogEntry;
use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\InvalidHttpLogEntry;
use App\Domain\Anomaly\IsolationForestParameters;
use App\Domain\Anomaly\KDistanceAnalyzer;
use App\Domain\Anomaly\KMeansParameters;
use App\Domain\Anomaly\LofParameters;
use App\Domain\Anomaly\LogEntryRepository;
use App\Infrastructure\Log\CsvHttpLogLoader;
use App\Infrastructure\Log\NginxAccessLogLoader;
use App\Utils\Config;
use flight\Engine;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final readonly class AnalysisController
{
    private const MAX_JSON_DEPTH = 16;
    private const DEFAULT_MAX_PAYLOAD_BYTES = 2097152;
    private const DEFAULT_MAX_LOGS_PER_REQUEST = 10000;
    private const MAX_PROJECTION_LOGS = 2000;
    private const DEFAULT_MAX_ANOMALIES_IN_RESPONSE = 100;
    private const DEFAULT_ANALYSES_LIMIT = 50;
    private const MIN_ANALYSES_LIMIT = 1;
    private const MAX_ANALYSES_LIMIT = 200;

    private const ERROR_INVALID_JSON = 'invalid_json';
    private const ERROR_INVALID_REQUEST = 'invalid_request';
    private const ERROR_INVALID_LOG = 'invalid_log';
    private const ERROR_INVALID_PARAMETERS = 'invalid_parameters';
    private const ERROR_PAYLOAD_TOO_LARGE = 'payload_too_large';
    private const ERROR_INVALID_TEXT = 'invalid_text';
    private const ERROR_TOO_MANY_LOGS = 'too_many_logs';
    private const ERROR_NOT_IMPLEMENTED = 'not_implemented';
    private const ERROR_NOT_FOUND = 'not_found';
    private const ERROR_INVALID_ID = 'invalid_id';

    private const LOGS_FIELD = 'logs';
    private const TEXT_FIELD = 'text';
    private const FORMAT_FIELD = 'format';
    private const FORMAT_CSV = 'csv';
    private const FORMAT_NGINX = 'nginx';
    private const EPSILON_FIELD = 'epsilon';
    private const MINIMUM_SAMPLES_FIELD = 'minimum_samples';

    public function __construct(
        private readonly Engine $app,
        private readonly Config $config,
        private readonly AnalyzeLogs $analyzeLogs,
        private readonly BuildVisualization $visualization,
        private readonly CompareDetectors $compareDetectors,
        private readonly EstimateEpsilon $estimateEpsilon,
        private readonly AnalysisRunRepository $runs,
        private readonly LogEntryRepository $logEntries,
    ) {
    }

    public function analyze(): void
    {
        $payload = $this->validatedPayload();
        if ($payload === null) {
            return;
        }

        $result = $this->analyzeLogs->execute($payload['parameters'], $payload['entries']);

        $this->app->json([
            'data' => [
                'run_id' => $result->runId,
                'algorithm' => $result->algorithm->value,
                'epsilon' => $result->parameters->epsilon,
                'minimum_samples' => $result->parameters->minimumSamples,
                'samples' => $result->sampleCount,
                'clusters' => $result->clusterCount,
                'anomalies' => $result->anomalyCount,
            ],
        ]);
    }

    public function project(): void
    {
        $payload = $this->validatedPayload(self::MAX_PROJECTION_LOGS);
        if ($payload === null) {
            return;
        }

        try {
            $data = $this->visualization->execute($payload['parameters'], $payload['entries']);
        } catch (RuntimeException $e) {
            $this->error(self::ERROR_INVALID_REQUEST, $e->getMessage(), 422);
            return;
        }
        $distances = $data->kDistance->sortedDistances;

        $this->app->json(['data' => [
            'sample_count' => $data->projection->sampleCount,
            'clusters' => self::countClusters($data->projection->samples),
            'anomalies' => count(array_filter($data->projection->samples, static fn (ProjectedSample $s): bool => $s->isAnomaly)),
            'suggested_epsilon' => $data->kDistance->suggestedEpsilon,
            'epsilon_curve' => [
                'max' => KDistanceAnalyzer::quantile($distances, 0.0),
                'p99' => KDistanceAnalyzer::quantile($distances, 0.01),
                'p90' => KDistanceAnalyzer::quantile($distances, 0.1),
                'median' => KDistanceAnalyzer::quantile($distances, 0.5),
                'min' => KDistanceAnalyzer::quantile($distances, 1.0),
            ],
            'curve' => KDistanceAnalyzer::downsample($distances),
            'points' => array_map(
                static fn (ProjectedSample $sample): array => [
                    'coordinates' => $sample->coordinates,
                    'cluster' => $sample->cluster,
                    'anomaly' => $sample->isAnomaly,
                ],
                $data->projection->samples
            ),
        ]]);
    }

    /**
     * @param list<ProjectedSample> $samples
     */
    private static function countClusters(array $samples): int
    {
        return count(array_unique(array_filter(
            array_map(static fn (ProjectedSample $s): ?int => $s->cluster, $samples),
            static fn (?int $cluster): bool => $cluster !== null
        )));
    }

    public function compare(): void
    {
        $payload = $this->validatedPayload(self::MAX_PROJECTION_LOGS);
        if ($payload === null) {
            return;
        }

        $raw = (array) json_decode((string) $this->app->request()->getBody(), true);
        $minimumSamples = $payload['parameters']->minimumSamples;

        try {
            $report = $this->compareDetectors->execute(
                $payload['parameters'],
                KMeansParameters::fromRaw($raw['clusters'] ?? $this->config->get('anomaly.kmeans_clusters', 4), $minimumSamples),
                LofParameters::fromRaw($raw['lof_min_pts'] ?? $minimumSamples, $raw['lof_threshold'] ?? LofParameters::DEFAULT_THRESHOLD),
                IsolationForestParameters::fromRaw(
                    $raw['trees'] ?? 100,
                    $raw['subsample_size'] ?? 256,
                    $raw['forest_threshold'] ?? IsolationForestParameters::DEFAULT_THRESHOLD,
                    $raw['seed'] ?? null
                ),
                $payload['entries']
            );
        } catch (InvalidArgumentException $e) {
            $this->error(self::ERROR_INVALID_PARAMETERS, $e->getMessage(), 422);
            return;
        }

        $this->app->json(['data' => [
            'sample_count' => $report->sampleCount,
            'consensus' => [
                'anomalies' => $report->consensus->anomalyCount,
                'noise_ratio' => $report->consensus->sampleCount > 0
                    ? $report->consensus->anomalyCount / $report->consensus->sampleCount
                    : 0.0,
                'unanimous' => $report->consensus->unanimousCount,
                'majority_only' => $report->consensus->majorityOnlyCount,
                'voters' => ['dbscan', 'lof', 'isolation_forest'],
            ],
            'detectors' => array_map(
                static fn (DetectorReport $d): array => [
                    'algorithm' => $d->algorithm->value,
                    'clusters' => $d->clusterCount,
                    'anomalies' => $d->anomalyCount,
                    'noise_ratio' => $d->noiseRatio,
                    'silhouette' => $d->silhouette,
                    'inertia' => $d->inertia,
                    'elapsed_ms' => $d->elapsedMs,
                ],
                $report->detectors
            ),
        ]]);
    }

    public function knee(): void
    {
        $payload = $this->validatedPayload(self::MAX_PROJECTION_LOGS);
        if ($payload === null) {
            return;
        }

        $summary = $this->estimateEpsilon->execute($payload['parameters'], $payload['entries']);
        $distances = $summary->sortedDistances;

        $this->app->json(['data' => [
            'sample_count' => count($distances),
            'k' => max(1, $payload['parameters']->minimumSamples - 1),
            'suggested_epsilon' => $summary->suggestedEpsilon,
            'quantiles' => [
                'max' => KDistanceAnalyzer::quantile($distances, 0.0),
                'p99' => KDistanceAnalyzer::quantile($distances, 0.01),
                'p90' => KDistanceAnalyzer::quantile($distances, 0.1),
                'median' => KDistanceAnalyzer::quantile($distances, 0.5),
                'min' => KDistanceAnalyzer::quantile($distances, 1.0),
            ],
            'curve' => KDistanceAnalyzer::downsample($distances),
        ]]);
    }

    public function export(string $id): void
    {
        $runId = (int) $id;
        if ($runId < 1) {
            $this->error(self::ERROR_INVALID_ID, 'Analysis id must be a positive integer', 400);
            return;
        }

        $run = $this->runs->findById($runId);
        if ($run === null) {
            $this->error(self::ERROR_NOT_FOUND, sprintf('Analysis run %d not found', $runId), 404);
            return;
        }

        $anomalies = $this->logEntries->anomaliesForRun($runId, $this->intConfig(
            'anomaly.max_anomalies_in_response',
            self::DEFAULT_MAX_ANOMALIES_IN_RESPONSE
        ));

        $response = $this->app->response();
        $response->header('Content-Type', 'text/csv; charset=utf-8');
        $response->header('Content-Disposition', sprintf('attachment; filename="run-%d-anomalies.csv"', $runId));
        $response->write(self::csv($anomalies));
    }

    /**
     * @param list<ClassifiedLogEntry> $anomalies
     */
    private static function csv(array $anomalies): string
    {
        $lines = ['method,endpoint,status_code,response_time,request_size,hour'];

        foreach ($anomalies as $anomaly) {
            $entry = $anomaly->entry;
            $lines[] = sprintf(
                '%s,%s,%d,%s,%d,%d',
                $entry->method->value,
                $entry->endpoint,
                $entry->statusCode,
                (string) $entry->responseTime,
                $entry->requestSize,
                $entry->hour
            );
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * DBSCAN is batch-only: no trained state can classify a single new
     * point. Refuse honestly instead of faking a prediction.
     */
    public function detect(): void
    {
        $this->error(
            self::ERROR_NOT_IMPLEMENTED,
            'DBSCAN provides no incremental inference. Detecting a single record requires '
            . 'persisted training vectors and an epsilon-neighborhood query; this is designed '
            . 'but not implemented yet. Use POST /api/v1/analyze for batch analysis.',
            501
        );
    }

    public function index(): void
    {
        $runs = array_map(
            fn (AnalysisRun $run): array => $this->runToArray($run),
            $this->runs->list($this->analysesLimit())
        );

        $this->app->json(['data' => $runs]);
    }

    public function show(string $id): void
    {
        $runId = (int) $id;
        if ($runId < 1) {
            $this->error(self::ERROR_INVALID_ID, 'Analysis id must be a positive integer', 400);
            return;
        }

        $run = $this->runs->findById($runId);
        if ($run === null) {
            $this->error(self::ERROR_NOT_FOUND, sprintf('Analysis run %d not found', $runId), 404);
            return;
        }

        $anomalies = $this->logEntries->anomaliesForRun($runId, $this->intConfig(
            'anomaly.max_anomalies_in_response',
            self::DEFAULT_MAX_ANOMALIES_IN_RESPONSE
        ));

        $this->app->json(['data' => array_merge($this->runToArray($run), [
            'entries_count' => $this->logEntries->countForRun($runId),
            'anomalies' => array_map(
                static fn (ClassifiedLogEntry $anomaly): array => $anomaly->entry->toArray(),
                $anomalies
            ),
            'anomalies_truncated' => $run->anomalyCount > count($anomalies),
        ])]);
    }

    /**
     * @return array{entries: list<HttpLogEntry>, parameters: DbscanParameters}|null
     */
    private function validatedPayload(?int $maxLogs = null): ?array
    {
        $raw = $this->decodedBody();
        if ($raw === null) {
            return null;
        }

        $entries = array_key_exists(self::TEXT_FIELD, $raw)
            ? $this->validatedTextEntries($raw, $maxLogs)
            : $this->validatedEntries($raw, $maxLogs);
        if ($entries === null) {
            return null;
        }

        $parameters = $this->validatedParameters($raw);

        return match (true) {
            $parameters === null => null,
            default => ['entries' => $entries, 'parameters' => $parameters],
        };
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return list<HttpLogEntry>|null
     */
    private function validatedTextEntries(array $raw, ?int $maxLogs): ?array
    {
        $text = $raw[self::TEXT_FIELD] ?? null;
        if (!is_string($text) || trim($text) === '') {
            $this->error(
                self::ERROR_INVALID_REQUEST,
                sprintf('Field "%s" must be a non-empty string of CSV or nginx access.log lines', self::TEXT_FIELD),
                422
            );
            return null;
        }

        $format = $raw[self::FORMAT_FIELD] ?? null;
        if ($format !== null && $format !== self::FORMAT_CSV && $format !== self::FORMAT_NGINX) {
            $this->error(
                self::ERROR_INVALID_REQUEST,
                sprintf('Field "%s" accepts "csv" or "nginx"', self::FORMAT_FIELD),
                422
            );
            return null;
        }

        $loader = match ($format ?? $this->guessFormat($text)) {
            self::FORMAT_CSV => new CsvHttpLogLoader(),
            default => new NginxAccessLogLoader(),
        };

        try {
            $entries = $loader->loadString($text);
        } catch (RuntimeException $e) {
            $this->error(self::ERROR_INVALID_TEXT, $e->getMessage(), 422);
            return null;
        }

        $limit = $maxLogs ?? $this->intConfig('anomaly.max_logs_per_request', self::DEFAULT_MAX_LOGS_PER_REQUEST);
        if (count($entries) > $limit) {
            $this->error(
                self::ERROR_TOO_MANY_LOGS,
                sprintf('Field "%s" accepts at most %d entries per request', self::TEXT_FIELD, $limit),
                422
            );
            return null;
        }

        return $entries;
    }

    private function guessFormat(string $text): string
    {
        $firstLine = strtok($text, "\n") ?: '';

        return str_contains($firstLine, 'method') && str_contains($firstLine, ',') && str_contains($firstLine, 'endpoint')
            ? self::FORMAT_CSV
            : self::FORMAT_NGINX;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodedBody(): ?array
    {
        $body = (string) $this->app->request()->getBody();
        $maxPayloadBytes = $this->intConfig(
            'anomaly.max_payload_bytes',
            self::DEFAULT_MAX_PAYLOAD_BYTES
        );

        if (strlen($body) > $maxPayloadBytes) {
            $this->error(
                self::ERROR_PAYLOAD_TOO_LARGE,
                sprintf('Request body exceeds the %d byte limit', $maxPayloadBytes),
                413
            );
            return null;
        }

        return $this->decodedJsonObject($body);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodedJsonObject(string $body): ?array
    {
        try {
            $decoded = json_decode($body, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error(self::ERROR_INVALID_JSON, 'Request body is not valid JSON', 400);
            return null;
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            $this->error(self::ERROR_INVALID_REQUEST, 'Request body must be a JSON object', 400);
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return list<HttpLogEntry>|null
     */
    private function validatedEntries(array $raw, ?int $maxLogs = null): ?array
    {
        $logs = $raw[self::LOGS_FIELD] ?? null;
        if (!is_array($logs) || $logs === [] || !array_is_list($logs)) {
            $this->error(
                self::ERROR_INVALID_REQUEST,
                sprintf('Field "%s" must be a non-empty array of log objects', self::LOGS_FIELD),
                422
            );
            return null;
        }

        $limit = $maxLogs ?? $this->intConfig('anomaly.max_logs_per_request', self::DEFAULT_MAX_LOGS_PER_REQUEST);
        if (count($logs) > $limit) {
            $this->error(
                self::ERROR_TOO_MANY_LOGS,
                sprintf('Field "%s" accepts at most %d entries per request', self::LOGS_FIELD, $limit),
                422
            );
            return null;
        }

        return $this->buildEntries($logs);
    }

    /**
     * @param list<mixed> $logs
     *
     * @return list<HttpLogEntry>|null
     */
    private function buildEntries(array $logs): ?array
    {
        $entries = [];
        foreach ($logs as $index => $rawLog) {
            if (!is_array($rawLog)) {
                $this->error(
                    self::ERROR_INVALID_LOG,
                    sprintf('%s[%s] must be an object', self::LOGS_FIELD, (string) $index),
                    422
                );
                return null;
            }

            try {
                $entries[] = HttpLogEntry::fromArray($rawLog);
            } catch (InvalidHttpLogEntry $e) {
                $this->error(
                    self::ERROR_INVALID_LOG,
                    sprintf('%s[%s]: %s', self::LOGS_FIELD, (string) $index, $e->getMessage()),
                    422
                );
                return null;
            }
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function validatedParameters(array $raw): ?DbscanParameters
    {
        try {
            return DbscanParameters::fromRaw(
                $raw[self::EPSILON_FIELD] ?? $this->config->get('anomaly.epsilon', 0.35),
                $raw[self::MINIMUM_SAMPLES_FIELD] ?? $this->config->get('anomaly.minimum_samples', 5)
            );
        } catch (InvalidArgumentException $e) {
            $this->error(self::ERROR_INVALID_PARAMETERS, $e->getMessage(), 422);
            return null;
        }
    }

    private function intConfig(string $key, int $default): int
    {
        return (int) $this->config->get($key, $default);
    }

    private function analysesLimit(): int
    {
        $rawLimit = $this->app->request()->query['limit'] ?? null;
        $limit = is_numeric($rawLimit) ? (int) $rawLimit : self::DEFAULT_ANALYSES_LIMIT;
        $outOfRange = $limit < self::MIN_ANALYSES_LIMIT || $limit > self::MAX_ANALYSES_LIMIT;

        return match (true) {
            $outOfRange => self::DEFAULT_ANALYSES_LIMIT,
            default => $limit,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function runToArray(AnalysisRun $run): array
    {
        return [
            'id' => $run->id,
            'algorithm' => $run->algorithm->value,
            'epsilon' => $run->epsilon,
            'minimum_samples' => $run->minimumSamples,
            'samples' => $run->sampleCount,
            'clusters' => $run->clusterCount,
            'anomalies' => $run->anomalyCount,
            'started_at' => $run->startedAt->format(DATE_ATOM),
            'finished_at' => $run->finishedAt->format(DATE_ATOM),
        ];
    }

    private function error(string $code, string $message, int $status): void
    {
        $this->app->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
