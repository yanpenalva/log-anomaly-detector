<?php

declare(strict_types=1);

namespace App\Infrastructure\Log;

use RuntimeException;

final class CsvDatasetException extends RuntimeException
{
    public static function fileNotFound(string $path): self
    {
        return new self(sprintf('Dataset file not found or unreadable: %s', $path));
    }

    public static function invalidHeader(string $path, string $reason): self
    {
        return new self(sprintf('Invalid dataset header in %s: %s', $path, $reason));
    }

    public static function invalidRow(string $path, int $line, string $reason): self
    {
        return new self(sprintf('Invalid dataset row in %s at line %d: %s', $path, $line, $reason));
    }

    public static function emptyDataset(string $path): self
    {
        return new self(sprintf('Dataset %s contains no data rows', $path));
    }
}
