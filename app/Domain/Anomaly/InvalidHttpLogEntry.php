<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

use InvalidArgumentException;

final class InvalidHttpLogEntry extends InvalidArgumentException
{
    public static function invalidField(string $field, mixed $value, string $reason): self
    {
        $rendered = is_scalar($value) ? var_export($value, true) : get_debug_type($value);

        return new self(sprintf('Invalid log entry field "%s" (%s): %s', $field, $rendered, $reason));
    }

    public static function missingField(string $field): self
    {
        return new self(sprintf('Missing required log entry field "%s"', $field));
    }
}
