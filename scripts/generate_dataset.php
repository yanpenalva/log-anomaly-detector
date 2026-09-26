<?php

declare(strict_types=1);

/**
 * Deterministic development dataset generator for the DBSCAN pipeline.
 * Usage: php scripts/generate_dataset.php [rows] > datasets/development.csv
 * Same seed → same dataset.
 */

$seed = 42;
$rowTarget = isset($argv[1]) ? max(50, (int) $argv[1]) : 1400;

mt_srand($seed);

function gauss(float $mean, float $stdDev): float
{
    $u1 = mt_rand() / mt_getrandmax();
    $u2 = mt_rand() / mt_getrandmax();
    if ($u1 < 1e-12) {
        $u1 = 1e-12;
    }

    return $mean + $stdDev * sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
}

function clamp(float $value, float $min, float $max): float
{
    return min($max, max($min, $value));
}

/**
 * Traffic profiles: [method, endpoint fn, status, rt mean/std, size mean/std, hours].
 * Each profile is a density blob DBSCAN should discover as one cluster.
 */
$profiles = [
    ['GET', static fn (): string => '/users', 200, 120.0, 15.0, 1024.0, 80.0, range(8, 18)],
    ['GET', static fn (): string => '/users/' . mt_rand(1, 5000), 200, 95.0, 12.0, 780.0, 60.0, range(8, 20)],
    ['POST', static fn (): string => '/payments', 201, 340.0, 40.0, 1500.0, 120.0, range(10, 22)],
    ['GET', static fn (): string => '/health', 200, 12.0, 3.0, 150.0, 10.0, range(0, 23)],
    ['POST', static fn (): string => '/api/search', 200, 260.0, 30.0, 980.0, 90.0, range(12, 23)],
    ['GET', static fn (): string => '/products/' . mt_rand(1, 900), 200, 130.0, 18.0, 950.0, 70.0, range(9, 21)],
];

$anomalyRate = 0.025;

/**
 * @param list<array{string, callable(): string, int, float, float, float, float, list<int>}> $profiles
 *
 * @return array{string, string, int, float, float, int}
 */
function normalRow(array $profiles): array
{
    [$method, $endpointFn, $status, $rtMean, $rtStd, $sizeMean, $sizeStd, $hours] =
        $profiles[mt_rand(0, count($profiles) - 1)];

    $endpoint = $endpointFn();
    if (mt_rand(0, 100) < 2) {
        $status = 404;
    }

    return [
        $method,
        $endpoint,
        $status,
        max(1.0, gauss($rtMean, $rtStd)),
        max(1.0, gauss($sizeMean, $sizeStd)),
        $hours[mt_rand(0, count($hours) - 1)],
    ];
}

/** Sparse anomaly sub-kinds — each stays too rare to form its own DBSCAN cluster. */
function anomalyRow(): array
{
    return match (mt_rand(1, 10)) {
        1, 2 => errorBurstRow(),
        3, 4 => payloadFloodRow(),
        5, 6, 7 => scannerRow(),
        default => slowlorisRow(),
    };
}

/** @return array{string, string, int, float, float, int} */
function errorBurstRow(): array
{
    return [
        'POST',
        ['/payments', '/api/search', '/users'][mt_rand(0, 2)],
        [500, 502, 503, 504][mt_rand(0, 3)],
        clamp(gauss(4500.0, 1800.0), 2500.0, 9500.0),
        clamp(gauss(2400.0, 1000.0), 500.0, 5500.0),
        mt_rand(0, 23),
    ];
}

/** @return array{string, string, int, float, float, int} */
function payloadFloodRow(): array
{
    return [
        'POST',
        ['/payments', '/api/search', '/users'][mt_rand(0, 2)],
        [200, 201, 413][mt_rand(0, 2)],
        clamp(gauss(2600.0, 1500.0), 700.0, 7500.0),
        clamp(gauss(170000.0, 80000.0), 55000.0, 340000.0),
        mt_rand(0, 23),
    ];
}

/** @return array{string, string, int, float, float, int} */
function scannerRow(): array
{
    return [
        'GET',
        ['/wp-admin.php', '/.env', '/admin/config.php', '/phpmyadmin/', '/.git/config', '/setup.php', '/shell.php'][mt_rand(0, 6)],
        [400, 403, 404][mt_rand(0, 2)],
        clamp(gauss(12.0, 9.0), 1.0, 60.0),
        clamp(gauss(70.0, 50.0), 5.0, 250.0),
        mt_rand(0, 5),
    ];
}

/** @return array{string, string, int, float, float, int} */
function slowlorisRow(): array
{
    return [
        'GET',
        ['/users', '/users/' . mt_rand(1, 5000), '/products/' . mt_rand(1, 900)][mt_rand(0, 2)],
        200,
        clamp(gauss(9500.0, 3000.0), 5500.0, 16000.0),
        clamp(gauss(1000.0, 450.0), 100.0, 2800.0),
        mt_rand(0, 23),
    ];
}

echo 'method,endpoint,status_code,response_time,request_size,hour' . PHP_EOL;

$written = 0;
while ($written < $rowTarget) {
    [$method, $endpoint, $status, $rt, $size, $hour] = match (
        mt_rand() / mt_getrandmax() < $anomalyRate
    ) {
        true => anomalyRow(),
        false => normalRow($profiles),
    };

    printf(
        "%s,%s,%d,%d,%d,%d\n",
        $method,
        $endpoint,
        $status,
        (int) round($rt),
        (int) round($size),
        $hour
    );
    $written++;
}
