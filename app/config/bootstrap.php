<?php

declare(strict_types=1);

$ds = DIRECTORY_SEPARATOR;
$projectRoot = dirname(__DIR__, 2);

require $projectRoot . $ds . 'vendor' . $ds . 'autoload.php';

use App\Utils\Config;
use App\Utils\Env;

Env::load($projectRoot . $ds . '.env');

$configPath = __DIR__ . $ds . 'config.php';
if (file_exists($configPath) === false) {
    Flight::halt(
        500,
        'Config file not found. Copy app/config/config_sample.php to app/config/config.php'
    );
}

$app = Flight::app();

$fileConfig = require $configPath;
$merged = Config::mergeEnv($fileConfig, $_ENV);
$config = new Config($merged);

$timezone = (string) $config->get('app.timezone', 'UTC');
if ($timezone !== '') {
    date_default_timezone_set($timezone);
}

if (function_exists('mb_internal_encoding') === true) {
    mb_internal_encoding('UTF-8');
}

error_reporting(E_ALL);

$app->set('flight.base_url', $config->baseUrl());
$app->set('flight.case_sensitive', false);
$app->set('flight.log_errors', true);
$app->set('flight.handle_errors', $config->isDebug() === false);
$app->set('flight.views.path', __DIR__ . $ds . '..' . $ds . 'views');
$app->set('flight.views.extension', '.twig');
$app->set('flight.content_length', false);

$nonce = bin2hex(random_bytes(16));
$app->set('csp_nonce', $nonce);

$app->set('app.project_root', $projectRoot);

require __DIR__ . $ds . 'services.php';

$router = $app->router();
require __DIR__ . $ds . 'routes.php';

$app->start();
