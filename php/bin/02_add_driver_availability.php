<?php declare(strict_types=1);

/**
 * SakayTa — 02) Migration: Add availability to drivers.
 *
 * Idempotent migration. MariaDB 10.4 does not support "ADD COLUMN IF NOT EXISTS",
 * so we check the information_schema.columns table first.
 */

namespace Sakayta\Db;

require __DIR__ . '/../autoload.php';

use Sakayta\Config\Config;
use Sakayta\Db\Database;
use PDO;

Config::bootstrap();

try {
    $pdo = Database::connection();

    // Check if column already exists
    $stmt = $pdo->prepare(
        "SELECT COLUMN_NAME
         FROM information_schema.columns
         WHERE table_schema = :db
         AND table_name = 'drivers'
         AND column_name = 'availability'"
    );
    $stmt->execute(['db' => Config::database()['name']]);

    if ($stmt->fetch()) {
        echo "[migration] Column 'availability' already exists in 'drivers'. Skipping.\n";
        exit(0);
    }

    echo "[migration] Adding 'availability' column to 'drivers'...\n";
    $pdo->exec("ALTER TABLE drivers ADD COLUMN availability ENUM('available','unavailable') NOT NULL DEFAULT 'unavailable'");
    echo "[migration] OK.\n";

} catch (\Throwable $e) {
    fwrite(STDERR, "[migration] Failed: " . $e->getMessage() . "\n");
    exit(1);
}
exit(0);