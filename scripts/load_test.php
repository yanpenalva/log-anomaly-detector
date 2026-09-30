<?php

declare(strict_types=1);

/**
 * Lightweight HTTP load test for POST /api/v1/analyze.
 * Usage: php scripts/load_test.php [requests=20] [base=http://127.0.0.1:8010]
 * Sequential by design — measures per-request latency distribution.
 */

$requests = max(1, (int) ($argv[1] ?? 20));
$base = rtrim($argv[2] ?? 'http://127.0.0.1:8010', '/');

$logs = [];
for ($i = 0; $i < 20; $i++) {
    $logs[] = ['method' => 'GET', 'endpoint' => '/users', 'status_code' => 200, 'response_time' => 100 + $i, 'request_size' => 1000 + $i, 'hour' => 10];
}
for ($i = 0; $i < 15; $i++) {
    $logs[] = ['method' => 'POST', 'endpoint' => '/payments', 'status_code' => 201, 'response_time' => 340 + $i, 'request_size' => 1500 + $i, 'hour' => 12];
}
$logs[] = ['method' => 'GET', 'endpoint' => '/.env', 'status_code' => 404, 'response_time' => 9, 'request_size' => 60, 'hour' => 3];
$logs[] = ['method' => 'GET', 'endpoint' => '/.env', 'status_code' => 403, 'response_time' => 8, 'request_size' => 61, 'hour' => 4];
$logs[] = ['method' => 'GET', 'endpoint' => '/.env', 'status_code' => 404, 'response_time' => 7, 'request_size' => 62, 'hour' => 2];

$payload = (string) json_encode(['logs' => $logs, 'epsilon' => 0.5, 'minimum_samples' => 5]);
$latencies = [];
$failures = 0;

for ($i = 0; $i < $requests; $i++) {
    $started = hrtime(true);
    [$status,] = post($base . '/api/v1/analyze', $payload);
    $latencies[] = (hrtime(true) - $started) / 1_000_000;
    if ($status !== 200) {
        $failures++;
    }
}

sort($latencies);
$count = count($latencies);

printf(
    "POST /api/v1/analyze × %d · ok %d · failed %d\n  min %.0f ms · p50 %.0f ms · p95 %.0f ms · max %.0f ms · mean %.0f ms\n",
    $count,
    $count - $failures,
    $failures,
    $latencies[0],
    $latencies[(int) floor(0.5 * ($count - 1))],
    $latencies[(int) floor(0.95 * ($count - 1))],
    $latencies[$count - 1],
    array_sum($latencies) / $count
);

/**
 * @return array{int, string}
 */
function post(string $url, string $body): array
{
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 30,
    ]]);

    $response = (string) file_get_contents($url, false, $context);
    [$version, $status] = $http_response_header === null
        ? ['', 0]
        : parseStatus((array) $http_response_header);

    return [(int) $status, $response];
}

/**
 * @param list<string> $headers
 *
 * @return array{string, int}
 */
function parseStatus(array $headers): array
{
    foreach ($headers as $header) {
        if (preg_match('#HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
            return [$header, (int) $matches[1]];
        }
    }

    return ['', 0];
}
