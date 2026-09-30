<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineLearning;

use App\Domain\Anomaly\AnomalyDetector;
use App\Domain\Anomaly\LofDetectorFactory;
use App\Domain\Anomaly\LofParameters;

final class LofFactory implements LofDetectorFactory
{
    public function create(LofParameters $parameters): AnomalyDetector
    {
        return new LofDetector($parameters);
    }
}
