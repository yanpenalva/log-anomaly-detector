<?php

declare(strict_types=1);

/**
 * Services + Dice DI wiring.
 *
 * Bootstrap (web) provides:
 *   @var \flight\Engine $app
 *   @var \App\Utils\Config      $config
 *   @var string                 $projectRoot
 *   @var string                 $ds
 *   @var string                 $nonce
 *
 * Runway 1.x also requires this file after loading config.php, with $config as
 * the raw array and no $app. Skip full wiring in that CLI context.
 */

use App\Application\Anomaly\AnalyzeLogs;
use App\Domain\Anomaly\AnalysisResultRepository;
use App\Domain\Anomaly\AnalysisRunRepository;
use App\Domain\Anomaly\FeatureExtractor;
use App\Domain\Anomaly\LogEntryRepository;
use App\Infrastructure\MachineLearning\LogCategoricalEncoder;
use App\Infrastructure\MachineLearning\MinMaxNormalizer;
use App\Infrastructure\MachineLearning\PhpMlDetectorFactory;
use App\Infrastructure\Persistence\SqliteAnalysisResultRepository;
use App\Infrastructure\Persistence\SqliteAnalysisRunRepository;
use App\Infrastructure\Persistence\SqliteLogEntryRepository;
use App\Utils\Config;
use App\Utils\DatabaseFactory;
use Dice\Dice;
use flight\database\SimplePdo;
use flight\debug\tracy\TracyExtensionLoader;
use flight\Engine;
use Tracy\Debugger;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

if (!isset($app) || !($app instanceof Engine) || !($config instanceof Config)) {
    return;
}

$logDir = $projectRoot . $ds . 'app' . $ds . 'log';
if (!is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}

Debugger::enable(match ($config->isDebug()) {
    true => Debugger::Development,
    false => Debugger::Production,
});
Debugger::$logDirectory = $logDir;
Debugger::$strictMode = true;

if (Debugger::$showBar === true && PHP_SAPI !== 'cli' && class_exists(TracyExtensionLoader::class)) {
    new TracyExtensionLoader($app);
}

$db = null;
if (DatabaseFactory::isEnabled($config)) {
    $db = DatabaseFactory::create($config);
    $app->map('db', function () use ($db) {
        return $db;
    });
}

$viewsPath = $projectRoot . $ds . 'app' . $ds . 'views';
$twigCache = $projectRoot . $ds . 'app' . $ds . 'cache' . $ds . 'twig';
if (!is_dir($twigCache)) {
    mkdir($twigCache, 0775, true);
}

$loader = new FilesystemLoader($viewsPath);
$twig = new Environment($loader, [
    'cache' => $config->isDebug() ? false : $twigCache,
    'debug' => $config->isDebug(),
    'auto_reload' => $config->isDebug(),
]);

$twig->addGlobal('csp_nonce', $nonce);
$twig->addGlobal('app_env', $config->env());
// Always trailing slash so {{ base_url }}health joins correctly under subpaths
$twig->addGlobal('base_url', $config->baseUrl());

// Map Flight render → Twig (single documented view path)
$app->map('render', function (string $template, array $data = []) use ($app, $twig) {
    if (substr($template, -5) !== '.twig') {
        $template .= '.twig';
    }
    $app->response()->write($twig->render($template, $data));
});

$anomalySubstitutions = [];
if ($db instanceof SimplePdo) {
    $encoder = new LogCategoricalEncoder(
        (int) $config->get('anomaly.endpoint_hash_buckets', 16)
    );
    $anomalySubstitutions[AnalysisRunRepository::class] = new SqliteAnalysisRunRepository($db);
    $anomalySubstitutions[LogEntryRepository::class] = new SqliteLogEntryRepository($db);
    $anomalySubstitutions[AnalysisResultRepository::class] = new SqliteAnalysisResultRepository($db);
    $anomalySubstitutions[AnalyzeLogs::class] = new AnalyzeLogs(
        new FeatureExtractor($encoder),
        new MinMaxNormalizer(),
        new PhpMlDetectorFactory(),
        $anomalySubstitutions[AnalysisResultRepository::class]
    );
}

// Critical: reuse the same Engine instance; do not construct a new one
$container = new Dice();

$substitutions = [
    Engine::class => $app,
    Config::class => $config,
    Environment::class => $twig,
];

if ($db instanceof SimplePdo) {
    $substitutions[SimplePdo::class] = $db;
}

$substitutions = array_merge($substitutions, $anomalySubstitutions);

$container = $container->addRule('*', [
    'substitutions' => $substitutions,
]);

$container = $container->addRule(Config::class, ['shared' => true]);
$container = $container->addRule(Environment::class, ['shared' => true]);
if ($db instanceof SimplePdo) {
    $container = $container->addRule(SimplePdo::class, ['shared' => true]);
}

$app->registerContainerHandler(function ($class, $params) use ($container) {
    return $container->create($class, $params);
});

$app->map('make', function ($class, $params = []) use ($container) {
    return $container->create($class, $params);
});
