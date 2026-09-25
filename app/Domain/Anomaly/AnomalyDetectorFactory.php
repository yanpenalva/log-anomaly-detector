<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

interface AnomalyDetectorFactory
{
    public function create(DbscanParameters $parameters): AnomalyDetector;
}
