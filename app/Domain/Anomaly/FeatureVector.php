<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

use InvalidArgumentException;

/**
 * Immutable, named numeric vector. Feature order is stable across the
 * whole pipeline (extraction, normalization, clustering, persistence).
 */
final readonly class FeatureVector
{
    /**
     * @param list<float> $values
     * @param list<string> $names
     */
    public function __construct(private array $values, private array $names)
    {
        if (count($values) !== count($names)) {
            throw new InvalidArgumentException('FeatureVector values and names must have the same length');
        }
    }

    /**
     * @return list<float>
     */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return $this->names;
    }

    public function dimension(): int
    {
        return count($this->values);
    }
}
