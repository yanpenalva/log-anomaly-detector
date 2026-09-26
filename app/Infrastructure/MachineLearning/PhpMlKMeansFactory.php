<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineLearning;

use App\Domain\Anomaly\AnomalyDetector;
use App\Domain\Anomaly\KMeansDetectorFactory;
use App\Domain\Anomaly\KMeansParameters;

final class PhpMlKMeansFactory implements KMeansDetectorFactory
{
    public function create(KMeansParameters $parameters): AnomalyDetector
    {
        return new PhpMlKMeansDetector($parameters);
    }
}
