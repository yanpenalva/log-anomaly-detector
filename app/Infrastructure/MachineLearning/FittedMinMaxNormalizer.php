<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineLearning;

use App\Domain\Anomaly\FeatureVector;
use App\Domain\Anomaly\FittedNormalizer;
use InvalidArgumentException;

final readonly class FittedMinMaxNormalizer implements FittedNormalizer
{
    /**
     * @param list<float> $mins
     * @param list<float> $maxs
     */
    public function __construct(private array $mins, private array $maxs)
    {
        if (count($mins) !== count($maxs)) {
            throw new InvalidArgumentException('min/max parameter length mismatch');
        }
    }

    public function transform(FeatureVector $vector): FeatureVector
    {
        $values = $vector->values();
        if (count($values) !== count($this->mins)) {
            throw new InvalidArgumentException(sprintf(
                'Feature vector dimension %d does not match fitted dimension %d',
                count($values),
                count($this->mins)
            ));
        }

        $scaled = [];
        foreach ($values as $i => $value) {
            $range = $this->maxs[$i] - $this->mins[$i];
            $scaled[] = $range === 0.0 ? 0.0 : ($value - $this->mins[$i]) / $range;
        }

        return new FeatureVector($scaled, $vector->names());
    }

    public function parameters(): array
    {
        $params = [];
        foreach ($this->mins as $i => $min) {
            $params[] = ['min' => $min, 'max' => $this->maxs[$i]];
        }

        return $params;
    }
}
