<?php

declare(strict_types=1);

namespace App\Infrastructure\Log;

use RuntimeException;

final class AccessLogException extends RuntimeException
{
    public static function fileNotFound(string $path): self
    {
        return new self(sprintf('Access log file not found or unreadable: %s', $path));
    }

    public static function emptyDataset(string $path): self
    {
        return new self(sprintf('Access log %s contains no parseable lines', $path));
    }
}
