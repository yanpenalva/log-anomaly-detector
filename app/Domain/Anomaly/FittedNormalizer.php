<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

interface FittedNormalizer
{
    /**
     * @throws \InvalidArgumentException When dimensions do not match the fitted state
     */
    public function transform(FeatureVector $vector): FeatureVector;

    /**
     * @return list<array{min: float, max: float}>
     */
    public function parameters(): array;
}
