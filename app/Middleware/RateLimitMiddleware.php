<?php

declare(strict_types=1);

namespace App\Middleware;

use flight\Engine;

/**
 * Fixed-window rate limiter for the heavy analysis endpoints, backed by a
 * temp-file token-bucket map (flock-guarded) so state survives the PHP
 * built-in server's per-connection processes. Dev-grade by design: a real
 * multi-worker deployment should move buckets to shared storage.
 * Disabled for non-POST requests.
 */
class RateLimitMiddleware
{
    private const DEFAULT_CAPACITY = 10;
    private const DEFAULT_REFILL_PER_SECOND = 1;
    private const STATE_FILE = 'log-anomaly-detector-rate-limits.json';
    private const LOCK_SECONDS = 2;

    public function __construct(
        private readonly Engine $app,
        private readonly \App\Utils\Config $config,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function before(array $params): void
    {
        $request = $this->app->request();

        if ($request->method !== 'POST') {
            return;
        }

        $ip = $request->ip !== '' ? $request->ip : 'unknown';
        $capacity = (int) $this->config->get('anomaly.rate_limit_capacity', self::DEFAULT_CAPACITY);
        $refill = (float) $this->config->get('anomaly.rate_limit_refill_per_second', self::DEFAULT_REFILL_PER_SECOND);

        if ($this->takeToken($ip, $capacity, $refill) === false) {
            $this->app->halt(429, (string) json_encode([
                'error' => ['code' => 'rate_limited', 'message' => 'Too many analysis requests; slow down'],
            ]));
        }
    }

    private function takeToken(string $ip, int $capacity, float $refill): bool
    {
        $path = self::statePath();
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            return true;
        }

        $deadline = microtime(true) + self::LOCK_SECONDS;
        $locked = false;
        while (microtime(true) < $deadline) {
            if (flock($handle, LOCK_EX)) {
                $locked = true;
                break;
            }
            usleep(10_000);
        }

        if ($locked === false) {
            fclose($handle);
            return true;
        }

        $now = microtime(true);
        $state = (array) json_decode((string) stream_get_contents($handle), true);
        $bucket = (array) ($state[$ip] ?? []);
        $tokens = (float) ($bucket['tokens'] ?? $capacity);
        $last = (float) ($bucket['last'] ?? $now);

        $tokens = min((float) $capacity, $tokens + ($now - $last) * $refill);
        $allowed = $tokens >= 1.0;
        $state[$ip] = [
            'tokens' => $allowed ? $tokens - 1.0 : $tokens,
            'last' => $now,
        ];

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) json_encode($state));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $allowed;
    }

    private static function statePath(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::STATE_FILE;
    }

    public static function resetState(): void
    {
        if (is_file(self::statePath())) {
            unlink(self::statePath());
        }
    }
}
