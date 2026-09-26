<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

interface KMeansDetectorFactory
{
    public function create(KMeansParameters $parameters): AnomalyDetector;
}
