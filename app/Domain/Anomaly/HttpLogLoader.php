<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

/**
 * Port for dataset readers — filesystem access stays in infrastructure.
 */
interface HttpLogLoader
{
    /**
     * @return list<HttpLogEntry>
     *
     * @throws \RuntimeException When the dataset cannot be read or is invalid
     */
    public function load(string $path): array;
}
