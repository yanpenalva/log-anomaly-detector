<?php

/**
 * Application routes.
 *
 * @var \flight\net\Router $router
 * @var \flight\Engine $app
 * @var \App\Utils\Config $config
 */

use App\Controller\Api\AnalysisController;
use App\Controller\Api\HealthController;
use App\Controller\HomeController;
use App\Middleware\SecurityHeadersMiddleware;
use App\Utils\DatabaseFactory;
use flight\net\Router;

$router->group('', function (Router $router) use ($config) {
    $router->get('/', [HomeController::class, 'index']);

    $router->group('/api/v1', function (Router $router) use ($config) {
        $router->get('/health', [HealthController::class, 'health']);

        // Analysis routes require SimplePdo — skipped when the driver is empty
        if (DatabaseFactory::isEnabled($config)) {
            $router->post('/analyze', [AnalysisController::class, 'analyze']);
            $router->post('/detect', [AnalysisController::class, 'detect']);
            $router->get('/analysis', [AnalysisController::class, 'index']);
            $router->get('/analysis/@id:[0-9]+', [AnalysisController::class, 'show']);
        }
    });
}, [SecurityHeadersMiddleware::class]);
