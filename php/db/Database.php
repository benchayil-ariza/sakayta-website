<?php declare(strict_types=1);

/**
 * Sakayta — PDO MySQL connection layer (FINAL SRS: MariaDB).
 *
 * A single PDO instance is reused for the lifetime of the request. All
 * queries MUST use prepared statements through this connection; raw string
 * concatenation into SQL is forbidden at every call site.
 *
 * Options chosen to be strict and predictable:
 *   - ERRMODE_EXCEPTION      -> failures surface instead of silently dying
 *   - DEFAULT_FETCH_MODE     -> ASSOC (matches the Node JSON object style)
 *   - EMULATE_PREPARES=false -> real server-side prepared statements
 *   - charset utf8mb4        -> matches the schema
 */

namespace Sakayta\Db;

use PDO;
use PDOException;
use Sakayta\Config\Config;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $cfg = Config::database();

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['name'],
            $cfg['charset']
        );

        try {
            $pdo = new PDO($dsn, $cfg['user'], $cfg['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
        } catch (PDOException $e) {
            // Never leak the DSN (contains user/password) to the client.
            throw new PDOException('Database connection failed.', (int) $e->getCode(), $e);
        }

        self::$pdo = $pdo;
        return $pdo;
    }

    /** Execute a schema/DDL file idempotently (IF NOT EXISTS statements). */
    public static function executeScript(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException("SQL file not readable: {$path}");
        }
        $sql = file_get_contents($path);
        if ($sql === false || trim($sql) === '') {
            return;
        }
        self::connection()->exec($sql);
    }

    /** For tests/scripts: force a fresh connection on next call. */
    public static function reset(): void
    {
        self::$pdo = null;
    }
}