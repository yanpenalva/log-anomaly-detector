<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Utils\Config;
use flight\Engine;

final readonly class HealthController
{
    public function __construct(private readonly Engine $app, private readonly Config $config)
    {
    }

    public function health(): void
    {
        $this->app->json([
            'data' => [
                'status' => 'ok',
                'env' => $this->config->env(),
                'time' => date('c'),
            ],
        ]);
    }
}
