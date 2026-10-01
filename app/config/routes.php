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
use App\Middleware\CsrfMiddleware;
use App\Middleware\RateLimitMiddleware;
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
            $router->post('/project', [AnalysisController::class, 'project']);
            $router->post('/compare', [AnalysisController::class, 'compare']);
            $router->post('/knee', [AnalysisController::class, 'knee']);
            $router->post('/detect', [AnalysisController::class, 'detect']);
            $router->get('/analysis', [AnalysisController::class, 'index']);
            $router->get('/analysis/@id:[0-9]+', [AnalysisController::class, 'show']);
            $router->get('/analysis/@id:[0-9]+/export', [AnalysisController::class, 'export']);
        }
    });
}, [SecurityHeadersMiddleware::class, CsrfMiddleware::class, RateLimitMiddleware::class]);
