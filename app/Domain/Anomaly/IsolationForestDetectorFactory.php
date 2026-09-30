<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

interface IsolationForestDetectorFactory
{
    public function create(IsolationForestParameters $parameters): AnomalyDetector;
}
