<?php declare(strict_types=1);

/**
 * SakayTa — 03) Migration: Add mobile column to users.
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
         AND table_name = 'users'
         AND column_name = 'mobile'"
    );
    $stmt->execute(['db' => Config::database()['name']]);

    if ($stmt->fetch()) {
        echo "[migration] Column 'mobile' already exists in 'users'. Skipping.\n";
        exit(0);
    }

    echo "[migration] Adding 'mobile' column to 'users'...\n";
    $pdo->exec("ALTER TABLE users ADD COLUMN mobile VARCHAR(20) NULL AFTER email");
    echo "[migration] OK.\n";

    // Check if unique index already exists
    $idxStmt = $pdo->prepare(
        "SELECT INDEX_NAME
         FROM information_schema.statistics
         WHERE table_schema = :db
         AND table_name = 'users'
         AND index_name = 'uq_users_mobile'"
    );
    $idxStmt->execute(['db' => Config::database()['name']]);

    if ($idxStmt->fetch()) {
        echo "[migration] Unique index 'uq_users_mobile' already exists on 'users'. Skipping.\n";
        exit(0);
    }

    echo "[migration] Adding unique index 'uq_users_mobile' on 'users'...\n";
    $pdo->exec("ALTER TABLE users ADD UNIQUE KEY uq_users_mobile (mobile)");
    echo "[migration] OK.\n";

} catch (\Throwable $e) {
    fwrite(STDERR, "[migration] Failed: " . $e->getMessage() . "\n");
    exit(1);
}
exit(0);