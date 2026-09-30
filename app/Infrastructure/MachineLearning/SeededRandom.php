<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineLearning;

/**
 * Deterministic linear-congruential RNG so ML runs are reproducible.
 * With a null seed the initial state comes from the CSPRNG once.
 */
final class SeededRandom
{
    private const MULTIPLIER = 1_103_515_245;
    private const INCREMENT = 12_345;
    private const MODULUS = 2 ** 31;

    private int $state;

    public function __construct(?int $seed = null)
    {
        $this->state = $seed ?? random_int(0, self::MODULUS - 1);
    }

    public function nextInt(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        return $min + ($this->next() % ($max - $min + 1));
    }

    public function nextFloat(): float
    {
        return $this->next() / (self::MODULUS - 1);
    }

    /**
     * @template T
     *
     * @param list<T> $items
     *
     * @return T
     */
    public function randomElement(array $items): mixed
    {
        return $items[$this->nextInt(0, count($items) - 1)];
    }

    private function next(): int
    {
        $this->state = ($this->state * self::MULTIPLIER + self::INCREMENT) % self::MODULUS;

        return $this->state;
    }
}
