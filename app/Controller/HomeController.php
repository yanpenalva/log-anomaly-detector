<?php

declare(strict_types=1);

namespace App\Controller;

use flight\Engine;

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
