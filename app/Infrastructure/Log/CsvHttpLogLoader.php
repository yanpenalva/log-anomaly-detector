<?php

declare(strict_types=1);

namespace App\Infrastructure\Log;

use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\HttpLogLoader;
use App\Domain\Anomaly\InvalidHttpLogEntry;
use SplFileObject;

/**
 * Reads CSV datasets of HTTP logs: file → validation → HttpLogEntry[].
 *
 * Expected header (column order is free, all columns required):
 *   method,endpoint,status_code,response_time,request_size,hour
 *
 * The loader only ever reads paths given by trusted callers (CLI/tests);
 * the HTTP API accepts inline logs and never a path, so no untrusted path
 * ever reaches this class.
 */
final class CsvHttpLogLoader implements HttpLogLoader
{
    private const REQUIRED_COLUMNS = ['method', 'endpoint', 'status_code', 'response_time', 'request_size', 'hour'];

    public function load(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw CsvDatasetException::fileNotFound($path);
        }

        $file = new SplFileObject($path, 'r');
        $file->setFlags(
            SplFileObject::READ_CSV
            | SplFileObject::SKIP_EMPTY
            | SplFileObject::READ_AHEAD
            | SplFileObject::DROP_NEW_LINE
        );

        $header = $this->readHeader($file, $path);
        if ($header === null) {
            throw CsvDatasetException::invalidHeader($path, 'file is empty');
        }

        $entries = [];
        foreach ($file as $row) {
            $line = (int) $file->key() + 1;

            // SplFileObject iteration restarts at the header row
            if ($line === 1 || !is_array($row) || $this->isEmptyRow($row)) {
                continue;
            }

            $entries[] = $this->parseRow($row, $header, $path, $line);
        }

        if ($entries === []) {
            throw CsvDatasetException::emptyDataset($path);
        }

        return $entries;
    }

    /**
     * @return array<string, int>|null Column name => index
     */
    private function readHeader(SplFileObject $file, string $path): ?array
    {
        $file->rewind();
        $headerRow = $file->current();

        if (!is_array($headerRow)) {
            return null;
        }

        if ($this->isEmptyRow($headerRow)) {
            return null;
        }

        $map = [];
        foreach ($headerRow as $index => $name) {
            $map[trim((string) $name)] = $index;
        }

        $missing = array_diff(self::REQUIRED_COLUMNS, array_keys($map));
        if ($missing !== []) {
            throw CsvDatasetException::invalidHeader(
                $path,
                'missing column(s): ' . implode(', ', $missing)
            );
        }

        return $map;
    }

    /**
     * @param array<int|string, mixed> $row
     */
    private function isEmptyRow(array $row): bool
    {
        if ($row === [null]) {
            return true;
        }

        foreach ($row as $value) {
            if (is_string($value) && trim($value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int|string, mixed> $row
     * @param array<string, int> $header
     */
    private function parseRow(array $row, array $header, string $path, int $line): HttpLogEntry
    {
        foreach ($header as $name => $index) {
            if (!array_key_exists($index, $row)) {
                throw CsvDatasetException::invalidRow($path, $line, sprintf('missing column "%s"', $name));
            }
        }

        $data = [];
        foreach ($header as $name => $index) {
            $data[$name] = trim((string) $row[$index]);
        }

        try {
            return HttpLogEntry::fromArray($data);
        } catch (InvalidHttpLogEntry $e) {
            throw CsvDatasetException::invalidRow($path, $line, $e->getMessage());
        }
    }
}
