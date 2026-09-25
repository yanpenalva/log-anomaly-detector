<?php

/**
 * Project defaults — literal array only (Runway-safe).
 *
 * Copy to config.php (composer create-project does this for you).
 * Secrets and deploy-specific values belong in .env (see .env.example).
 *
 * Do NOT put $_ENV expressions here: `runway config:set` rewrites this
 * file as static literals and would bake resolved secrets into the file.
 *
 * Side effects (timezone, error_reporting, flight.* settings) live in
 * bootstrap.php so Runway only rewrites the data block.
 */
return [
    'app' => [
        'env' => 'development',
        'debug' => true,
        // Trailing slash is normalized at runtime (Config::baseUrl()).
        // Use '/' for app root, or a subpath like '/myapp' / '/myapp/'.
        'base_url' => '/',
        'timezone' => 'UTC',
    ],
    'database' => [
        // sqlite (default — works after create-project with no MySQL)
        // or mysql. Empty string disables DB registration and analysis routes.
        'driver' => 'sqlite',
        'host' => 'localhost',
        'dbname' => '',
        'user' => '',
        'password' => '',
        'file_path' => __DIR__ . '/../../database.sqlite',
        'charset' => 'utf8mb4',
    ],
    'session' => [
        'prefix' => 'flight_sess_',
        // null = system temp /flight_sessions (see flightphp/session docs)
        'save_path' => null,
    ],
    'anomaly' => [
        // DBSCAN defaults (overridable per request via POST /api/v1/analyze)
        'epsilon' => 0.35,
        'minimum_samples' => 5,
        // Deterministic feature-hashing bucket count for endpoints
        'endpoint_hash_buckets' => 16,
        // Request limits
        'max_payload_bytes' => 2097152,
        'max_logs_per_request' => 10000,
        'max_anomalies_in_response' => 100,
    ],
    'runway' => [
        'index_root' => 'public/index.php',
        'app_root' => 'app/',
    ],
];
