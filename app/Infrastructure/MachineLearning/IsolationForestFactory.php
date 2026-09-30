<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineLearning;

use App\Domain\Anomaly\AnomalyDetector;
use App\Domain\Anomaly\IsolationForestDetectorFactory;
use App\Domain\Anomaly\IsolationForestParameters;

final class IsolationForestFactory implements IsolationForestDetectorFactory
{
    public function create(IsolationForestParameters $parameters): AnomalyDetector
    {
        return new IsolationForestDetector($parameters, new SeededRandom($parameters->seed));
    }
}
