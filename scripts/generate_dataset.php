<?php

declare(strict_types=1);

/**
 * Deterministic development dataset generator for the DBSCAN pipeline.
 *
 * Produces realistic multi-cluster HTTP traffic plus injected anomalies so
 * DBSCAN behavior (clusters + noise) can be validated meaningfully.
 *
 * Usage: php scripts/generate_dataset.php [rows] > datasets/development.csv
 * Same seed → same dataset.
 */

$seed = 42;
$rowTarget = isset($argv[1]) ? max(50, (int) $argv[1]) : 1400;

mt_srand($seed);

function gauss(float $mean, float $stdDev): float
{
    // Box-Muller (uniform pair from mt_rand)
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
 * Traffic profiles: [method, endpoint fn, status, rt mean/std, size mean/std, hours]
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

echo 'method,endpoint,status_code,response_time,request_size,hour' . PHP_EOL;

$written = 0;
while ($written < $rowTarget) {
    if (mt_rand() / mt_getrandmax() < $anomalyRate) {
        // Injected anomalies: many sparse sub-kinds (endpoint x status x
        // parameter range) so none of them becomes dense enough to form
        // its own DBSCAN cluster — they should surface as noise points.
        $kind = mt_rand(1, 10);
        if ($kind <= 2) {
            // error bursts spread over endpoints and server statuses
            $method = 'POST';
            $endpoint = ['/payments', '/api/search', '/users'][mt_rand(0, 2)];
            $status = [500, 502, 503, 504][mt_rand(0, 3)];
            $rt = clamp(gauss(4500.0, 1800.0), 2500.0, 9500.0);
            $size = clamp(gauss(2400.0, 1000.0), 500.0, 5500.0);
            $hour = mt_rand(0, 23);
        } elseif ($kind <= 4) {
            // massive payload floods
            $method = 'POST';
            $endpoint = ['/payments', '/api/search', '/users'][mt_rand(0, 2)];
            $status = [200, 201, 413][mt_rand(0, 2)];
            $rt = clamp(gauss(2600.0, 1500.0), 700.0, 7500.0);
            $size = clamp(gauss(170000.0, 80000.0), 55000.0, 340000.0);
            $hour = mt_rand(0, 23);
        } elseif ($kind <= 7) {
            // vulnerability scanner at odd hours
            $method = 'GET';
            $endpoint = ['/wp-admin.php', '/.env', '/admin/config.php', '/phpmyadmin/', '/.git/config', '/setup.php', '/shell.php'][mt_rand(0, 6)];
            $status = [400, 403, 404][mt_rand(0, 2)];
            $rt = clamp(gauss(12.0, 9.0), 1.0, 60.0);
            $size = clamp(gauss(70.0, 50.0), 5.0, 250.0);
            $hour = mt_rand(0, 5);
        } else {
            // slowloris-like slow requests
            $method = 'GET';
            $endpoint = ['/users', '/users/' . mt_rand(1, 5000), '/products/' . mt_rand(1, 900)][mt_rand(0, 2)];
            $status = 200;
            $rt = clamp(gauss(9500.0, 3000.0), 5500.0, 16000.0);
            $size = clamp(gauss(1000.0, 450.0), 100.0, 2800.0);
            $hour = mt_rand(0, 23);
        }
    } else {
        $profile = $profiles[mt_rand(0, count($profiles) - 1)];
        [$method, $endpointFn, $status, $rtMean, $rtStd, $sizeMean, $sizeStd, $hours] = $profile;
        $endpoint = $endpointFn();
        if (mt_rand(0, 100) < 2) {
            // occasional client error inside normal traffic (still dense region)
            $status = 404;
        }
        $rt = max(1.0, gauss($rtMean, $rtStd));
        $size = max(1.0, gauss($sizeMean, $sizeStd));
        $hour = $hours[mt_rand(0, count($hours) - 1)];
    }

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
