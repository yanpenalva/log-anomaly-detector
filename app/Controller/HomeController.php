<?php

declare(strict_types=1);

namespace App\Controller;

use flight\Engine;

/**
 * Renders the analysis dashboard (the only HTML surface; everything else
 * is the JSON API under /api/v1).
 */
final readonly class HomeController
{
    public function __construct(private readonly Engine $app)
    {
    }

    public function index(): void
    {
        $this->app->render('dashboard');
    }
}
