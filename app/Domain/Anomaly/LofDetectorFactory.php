<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

interface LofDetectorFactory
{
    public function create(LofParameters $parameters): AnomalyDetector;
}
