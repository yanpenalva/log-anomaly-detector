<?php

declare(strict_types=1);

namespace App\Utils;

/**
 * Minimal .env loader for bootstrap only — never in controllers/middleware/models.
 */
final class Env
{
    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            if (strpos($line, 'export ') === 0) {
                $line = trim(substr($line, 7));
            }

            $eqPos = strpos($line, '=');
            if ($eqPos === false) {
                continue;
            }

            $name = trim(substr($line, 0, $eqPos));
            $value = trim(substr($line, $eqPos + 1));

            if ($name === '') {
                continue;
            }

            $value = self::stripQuotes($value);

            // Real environment wins over .env file
            $existing = getenv($name);
            if ($existing !== false && $existing !== '') {
                if (!array_key_exists($name, $_ENV)) {
                    $_ENV[$name] = $existing;
                }
                continue;
            }

            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    private static function stripQuotes(string $value): string
    {
        $len = strlen($value);
        if ($len < 2) {
            return $value;
        }

        $first = $value[0];
        $last = $value[$len - 1];

        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
