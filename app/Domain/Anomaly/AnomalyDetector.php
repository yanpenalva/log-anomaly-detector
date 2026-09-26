<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

/**
 * Port to a batch clustering/detection algorithm — the domain never talks to PHP-ML.
 */
interface AnomalyDetector
{
    /**
     * @param list<FeatureVector> $vectors All samples of one analysis pass
     *
     * @throws \InvalidArgumentException On empty input or inconsistent dimensions
     */
    public function detect(array $vectors): DetectionResult;
}
