<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineLearning;

use App\Domain\Anomaly\AnomalyDetector;
use App\Domain\Anomaly\AnomalyDetectorFactory;
use App\Domain\Anomaly\DbscanParameters;

final class PhpMlDetectorFactory implements AnomalyDetectorFactory
{
    public function create(DbscanParameters $parameters): AnomalyDetector
    {
        return new PhpMlDbscanDetector($parameters);
    }
}
