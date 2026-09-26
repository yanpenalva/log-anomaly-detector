<?php

declare(strict_types=1);

namespace App\Utils;

final class Config
{
    /** @var array<string,mixed> */
    private $data;

    /**
     * @var array<string,string>
     */
    public const ENV_MAP = [
        'APP_ENV' => 'app.env',
        'APP_DEBUG' => 'app.debug',
        'FLIGHT_BASE_URL' => 'app.base_url',
        'APP_TIMEZONE' => 'app.timezone',
        'DB_DRIVER' => 'database.driver',
        'DB_HOST' => 'database.host',
        'DB_DATABASE' => 'database.dbname',
        'DB_USERNAME' => 'database.user',
        'DB_PASSWORD' => 'database.password',
        'DB_SQLITE_PATH' => 'database.file_path',
        'ANOMALY_EPSILON' => 'anomaly.epsilon',
        'ANOMALY_MINIMUM_SAMPLES' => 'anomaly.minimum_samples',
    ];

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $segments = explode('.', $key);
        $value = $this->data;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    public function isDebug(): bool
    {
        $debug = $this->get('app.debug', false);

        if (is_bool($debug)) {
            return $debug;
        }

        if (is_string($debug)) {
            return in_array(strtolower($debug), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $debug;
    }

    public function env(): string
    {
        return (string) $this->get('app.env', 'production');
    }

    public function baseUrl(): string
    {
        $url = (string) $this->get('app.base_url', '/');
        if ($url === '') {
            return '/';
        }

        return rtrim($url, '/') . '/';
    }

    /**
     * Env wins for mapped keys when set and non-empty.
     *
     * @param array<string,mixed> $fileConfig
     * @param array<string,mixed> $env
     * @return array<string,mixed>
     */
    public static function mergeEnv(array $fileConfig, array $env): array
    {
        $merged = $fileConfig;

        foreach (self::ENV_MAP as $envKey => $configPath) {
            if (!array_key_exists($envKey, $env)) {
                continue;
            }

            $raw = $env[$envKey];
            if ($raw === null || $raw === '') {
                continue;
            }

            $value = self::castEnvValue($configPath, (string) $raw);
            self::setPath($merged, $configPath, $value);
        }

        return $merged;
    }

    /**
     * @return mixed
     */
    private static function castEnvValue(string $path, string $raw)
    {
        if ($path === 'app.debug') {
            return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
        }

        return $raw;
    }

    /**
     * @param array<string,mixed> $data
     * @param mixed               $value
     */
    private static function setPath(array &$data, string $path, $value): void
    {
        $segments = explode('.', $path);
        $ref = &$data;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $ref[$segment] = $value;
                return;
            }

            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
    }
}
