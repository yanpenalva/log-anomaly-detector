<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Command\MigrateCommand;
use App\Utils\Config;
use App\Utils\DatabaseFactory;
use flight\database\SimplePdo;

/**
 * Builds a throwaway SQLite database with the real project migrations
 * applied, so persistence tests run against the actual schema.
 */
final class TestDatabase
{
    public function __construct(
        public readonly SimplePdo $pdo,
        public readonly string $path,
    ) {
    }

    public static function create(): self
    {
        $path = sys_get_temp_dir() . '/anomaly_test_' . uniqid('', true) . '.sqlite';
        $config = new Config([
            'database' => [
                'driver' => 'sqlite',
                'file_path' => $path,
            ],
        ]);

        $db = DatabaseFactory::create($config);
        $db->exec(
            'CREATE TABLE IF NOT EXISTS _migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                applied_at TEXT NOT NULL
            )'
        );

        $projectRoot = dirname(__DIR__, 3);
        $migrations = MigrateCommand::listMigrationFiles($projectRoot . '/migrations', 'sqlite');
        if ($migrations === []) {
            throw new \RuntimeException('No SQLite migrations found in ' . $projectRoot . '/migrations');
        }

        foreach ($migrations as $file) {
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new \RuntimeException('Unable to read migration: ' . $file);
            }
            $db->exec($sql);
        }

        return new self($db, $path);
    }
}
