<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

final readonly class ClassifiedLogEntry
{
    public function __construct(
        public readonly HttpLogEntry $entry,
        public readonly ?int $cluster,
        public readonly bool $isAnomaly,
    ) {
    }
}
