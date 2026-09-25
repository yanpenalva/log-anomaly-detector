<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

/**
 * Port for dataset readers (CSV today, access.log in the future).
 * Filesystem access stays in infrastructure; the API never accepts paths.
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
