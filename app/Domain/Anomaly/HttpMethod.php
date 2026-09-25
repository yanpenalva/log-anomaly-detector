<?php

declare(strict_types=1);

namespace App\Domain\Anomaly;

enum HttpMethod: string
{
    case Get = 'GET';
    case Head = 'HEAD';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';
    case Delete = 'DELETE';
    case Connect = 'CONNECT';
    case Options = 'OPTIONS';
    case Trace = 'TRACE';

    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));

        return self::tryFrom($normalized)
            ?? throw InvalidHttpLogEntry::invalidField('method', $value, 'must be a known HTTP verb');
    }
}
