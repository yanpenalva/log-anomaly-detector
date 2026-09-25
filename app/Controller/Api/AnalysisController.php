<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\Anomaly\AnalyzeLogs;
use App\Domain\Anomaly\AnalysisRun;
use App\Domain\Anomaly\AnalysisRunRepository;
use App\Domain\Anomaly\DbscanParameters;
use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\InvalidHttpLogEntry;
use App\Domain\Anomaly\LogEntryRepository;
use App\Utils\Config;
use flight\Engine;
use InvalidArgumentException;
use JsonException;

/**
 * Thin HTTP boundary: Request → validation → DTO (domain objects)
 * → application use case → JSON response. No ML or SQL logic here.
 */
final readonly class AnalysisController
{
    private const MAX_JSON_DEPTH = 16;

    public function __construct(
        private readonly Engine $app,
        private readonly Config $config,
        private readonly AnalyzeLogs $analyzeLogs,
        private readonly AnalysisRunRepository $runs,
        private readonly LogEntryRepository $logEntries,
    ) {
    }

    public function analyze(): void
    {
        $body = $this->app->request()->getBody();

        $maxPayloadBytes = (int) $this->config->get('anomaly.max_payload_bytes', 2097152);
        if (strlen($body) > $maxPayloadBytes) {
            $this->error('payload_too_large', sprintf(
                'Request body exceeds the %d byte limit',
                $maxPayloadBytes
            ), 413);
            return;
        }

        try {
            $decoded = json_decode($body, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('invalid_json', 'Request body is not valid JSON', 400);
            return;
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            $this->error('invalid_request', 'Request body must be a JSON object', 400);
            return;
        }

        $rawLogs = $decoded['logs'] ?? null;
        if (!is_array($rawLogs) || $rawLogs === [] || !array_is_list($rawLogs)) {
            $this->error(
                'invalid_request',
                'Field "logs" must be a non-empty array of log objects',
                422
            );
            return;
        }

        $maxLogs = (int) $this->config->get('anomaly.max_logs_per_request', 10000);
        if (count($rawLogs) > $maxLogs) {
            $this->error('too_many_logs', sprintf(
                'Field "logs" accepts at most %d entries per request',
                $maxLogs
            ), 422);
            return;
        }

        $entries = [];
        foreach ($rawLogs as $index => $rawLog) {
            if (!is_array($rawLog)) {
                $this->error('invalid_log', sprintf('logs[%s] must be an object', (string) $index), 422);
                return;
            }
            try {
                $entries[] = HttpLogEntry::fromArray($rawLog);
            } catch (InvalidHttpLogEntry $e) {
                $this->error('invalid_log', sprintf('logs[%s]: %s', (string) $index, $e->getMessage()), 422);
                return;
            }
        }

        try {
            $parameters = DbscanParameters::fromRaw(
                $decoded['epsilon'] ?? $this->config->get('anomaly.epsilon', 0.35),
                $decoded['minimum_samples'] ?? $this->config->get('anomaly.minimum_samples', 5)
            );
        } catch (InvalidArgumentException $e) {
            $this->error('invalid_parameters', $e->getMessage(), 422);
            return;
        }

        $result = $this->analyzeLogs->execute($parameters, $entries);

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

    /**
     * DBSCAN is a batch algorithm: it has no trained state from which a
     * single new point could be classified incrementally. A technically
     * sound /detect would persist the normalized training vectors and
     * check the epsilon-neighborhood of the new point (noise when fewer
     * than minimumSamples neighbors). Until that exists, refuse honestly
     * instead of faking a prediction.
     */
    public function detect(): void
    {
        $this->error(
            'not_implemented',
            'DBSCAN provides no incremental inference. Detecting a single record requires '
            . 'persisted training vectors and an epsilon-neighborhood query; this is designed '
            . 'but not implemented yet. Use POST /api/v1/analyze for batch analysis.',
            501
        );
    }

    public function index(): void
    {
        $rawLimit = $this->app->request()->query['limit'] ?? null;
        $limit = is_numeric($rawLimit) ? (int) $rawLimit : 50;
        if ($limit < 1 || $limit > 200) {
            $limit = 50;
        }

        $data = [];
        foreach ($this->runs->list($limit) as $run) {
            $data[] = $this->runToArray($run);
        }

        $this->app->json(['data' => $data]);
    }

    public function show(string $id): void
    {
        $runId = (int) $id;
        if ($runId < 1) {
            $this->error('invalid_id', 'Analysis id must be a positive integer', 400);
            return;
        }

        $run = $this->runs->findById($runId);
        if ($run === null) {
            $this->error('not_found', sprintf('Analysis run %d not found', $runId), 404);
            return;
        }

        $maxAnomalies = (int) $this->config->get('anomaly.max_anomalies_in_response', 100);
        $anomalies = $this->logEntries->anomaliesForRun($runId, $maxAnomalies);

        $anomalyData = [];
        foreach ($anomalies as $anomaly) {
            $anomalyData[] = $anomaly->entry->toArray();
        }

        $data = $this->runToArray($run);
        $data['entries_count'] = $this->logEntries->countForRun($runId);
        $data['anomalies'] = $anomalyData;
        $data['anomalies_truncated'] = $run->anomalyCount > count($anomalyData);

        $this->app->json(['data' => $data]);
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
            'started_at' => $run->startedAt,
            'finished_at' => $run->finishedAt,
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
