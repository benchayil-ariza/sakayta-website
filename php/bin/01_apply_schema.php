<?php declare(strict_types=1);

/**
 * SakayTa — 01) Apply the MariaDB schema (idempotent).
 *
 * Runs php/sql/schema.sql against the application database over PDO.
 * Every CREATE statement inside schema.sql uses IF NOT EXISTS, so running
 * this repeatedly is safe. This file never drops tables and never touches
 * the existing SQLite store.
 *
 * Usage:
 *   "C:\xampp\php\php.exe" php/bin/01_apply_schema.php
 */

namespace Sakayta;

require __DIR__ . '/../autoload.php';

use PDOException;
use Sakayta\Config\Config;
use Sakayta\Db\Database;

Config::bootstrap();

$schemaFile = dirname(__DIR__) . '/sql/schema.sql';

try {
    Database::executeScript($schemaFile);
} catch (PDOException $e) {
    fwrite(STDERR, "[schema] Failed: {$e->getMessage()}\n");
    exit(1);
} catch (\RuntimeException $e) {
    fwrite(STDERR, "[schema] {$e->getMessage()}\n");
    exit(1);
}

$pdo = Database::connection();
$tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);

echo "[schema] applied " . basename($schemaFile) . "\n";
echo '[schema] tables in `' . Config::database()['name'] . '`: ' . implode(', ', $tables) . "\n";
echo "[schema] OK (idempotent; re-runs are safe no-ops).\n";
exit(0);