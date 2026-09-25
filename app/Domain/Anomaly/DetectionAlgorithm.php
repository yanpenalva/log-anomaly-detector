<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

enum DetectionAlgorithm: string
{
    case Dbscan = 'dbscan';
}
