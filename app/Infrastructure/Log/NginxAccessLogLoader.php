<?php

declare(strict_types=1);

namespace App\Infrastructure\Log;

use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\HttpLogLoader;
use App\Domain\Anomaly\InvalidHttpLogEntry;
use DateTimeImmutable;
use SplFileObject;

/**
 * Nginx access.log reader: combined format, optionally followed by
 * $request_time in seconds (reported here in milliseconds). Lines that do
 * not match the format are skipped. Trusted callers only (CLI/tests) —
 * the HTTP API never accepts paths.
 */
final class NginxAccessLogLoader implements HttpLogLoader
{
    private const LINE_PATTERN =
        '/^(\S+) \S+ (\S+) \[([^\]]+)\] "([A-Za-z]+) (\S+)(?: [^"]*)?" (\d{3}) (\d+|-)'
        . '(?: "[^"]*" "[^"]*")?(?: (\d+(?:\.\d+)?))?\s*$/';
    private const TIME_FORMAT = 'd/M/Y:H:i:s O';
    private const BYTES_PLACEHOLDER = '-';
    private const MILLISECONDS_PER_SECOND = 1000.0;
    private const FILE_FLAGS = SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE;

    /**
     * @return list<HttpLogEntry>
     */
    public function load(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw AccessLogException::fileNotFound($path);
        }

        $file = new SplFileObject($path, 'r');
        $file->setFlags(self::FILE_FLAGS);

        $entries = [];
        foreach ($file as $line) {
            $entry = $this->parseLine(is_string($line) ? trim($line) : '');
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        if ($entries === []) {
            throw AccessLogException::emptyDataset($path);
        }

        return $entries;
    }

    private function parseLine(string $line): ?HttpLogEntry
    {
        $parsed = preg_match(self::LINE_PATTERN, $line, $matches) === 1 ? $matches : null;

        return $parsed === null ? null : $this->buildEntry($parsed);
    }

    /**
     * @param list<string> $matches Preg matches: [_, ip, user, time, method, endpoint, status, bytes, request_time?]
     */
    private function buildEntry(array $matches): ?HttpLogEntry
    {
        $time = DateTimeImmutable::createFromFormat(self::TIME_FORMAT, $matches[3]);
        if ($time === false) {
            return null;
        }

        $requestTime = $matches[8] ?? '';

        try {
            return HttpLogEntry::fromArray([
                'method' => $matches[4],
                'endpoint' => $matches[5],
                'status_code' => (int) $matches[6],
                'response_time' => $requestTime === ''
                    ? 0.0
                    : (float) $requestTime * self::MILLISECONDS_PER_SECOND,
                'request_size' => $matches[7] === self::BYTES_PLACEHOLDER ? 0 : (int) $matches[7],
                'hour' => (int) $time->format('G'),
            ]);
        } catch (InvalidHttpLogEntry) {
            return null;
        }
    }
}
