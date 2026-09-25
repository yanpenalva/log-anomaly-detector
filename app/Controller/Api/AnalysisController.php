<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\Anomaly\AnalyzeLogs;
use App\Domain\Anomaly\AnalysisRun;
use App\Domain\Anomaly\AnalysisRunRepository;
use App\Domain\Anomaly\ClassifiedLogEntry;
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
    private const DEFAULT_MAX_PAYLOAD_BYTES = 2097152;
    private const DEFAULT_MAX_LOGS_PER_REQUEST = 10000;
    private const DEFAULT_MAX_ANOMALIES_IN_RESPONSE = 100;
    private const DEFAULT_ANALYSES_LIMIT = 50;
    private const MIN_ANALYSES_LIMIT = 1;
    private const MAX_ANALYSES_LIMIT = 200;

    private const ERROR_INVALID_JSON = 'invalid_json';
    private const ERROR_INVALID_REQUEST = 'invalid_request';
    private const ERROR_INVALID_LOG = 'invalid_log';
    private const ERROR_INVALID_PARAMETERS = 'invalid_parameters';
    private const ERROR_PAYLOAD_TOO_LARGE = 'payload_too_large';
    private const ERROR_TOO_MANY_LOGS = 'too_many_logs';
    private const ERROR_NOT_IMPLEMENTED = 'not_implemented';
    private const ERROR_NOT_FOUND = 'not_found';
    private const ERROR_INVALID_ID = 'invalid_id';

    private const LOGS_FIELD = 'logs';
    private const EPSILON_FIELD = 'epsilon';
    private const MINIMUM_SAMPLES_FIELD = 'minimum_samples';

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
    private function validatedPayload(): ?array
    {
        $raw = $this->decodedBody();
        $entries = $raw === null ? null : $this->validatedEntries($raw);
        $parameters = $raw === null ? null : $this->validatedParameters($raw);

        return match (true) {
            $entries === null || $parameters === null => null,
            default => ['entries' => $entries, 'parameters' => $parameters],
        };
    }

    /**
     * Raw request body as a JSON object, with transport-level guards.
     *
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
    private function validatedEntries(array $raw): ?array
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

        $maxLogs = $this->intConfig('anomaly.max_logs_per_request', self::DEFAULT_MAX_LOGS_PER_REQUEST);
        if (count($logs) > $maxLogs) {
            $this->error(
                self::ERROR_TOO_MANY_LOGS,
                sprintf('Field "%s" accepts at most %d entries per request', self::LOGS_FIELD, $maxLogs),
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
