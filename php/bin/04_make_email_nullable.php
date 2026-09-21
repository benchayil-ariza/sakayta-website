<?php declare(strict_types=1);

/**
 * SakayTa — 04) Idempotent migration: make users.email nullable.
 *
 * Only touches users.email. Preserves unique index uq_users_email,
 * all existing records, and mobile column / unique index.
 */

namespace Sakayta\Db;

require __DIR__ . '/../autoload.php';

use Sakayta\Config\Config;
use Sakayta\Db\Database;
use PDO;

Config::bootstrap();

try {
    $pdo = Database::connection();

    $stmt = $pdo->prepare(
        "SELECT COLUMN_NAME, IS_NULLABLE, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
         FROM information_schema.columns
         WHERE table_schema = :db
         AND table_name = 'users'
         AND column_name = 'email'"
    );
    $stmt->execute(['db' => Config::database()['name']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        fwrite(STDERR, "[migration] users.email column not found.\n");
        exit(1);
    }

    if ($row['IS_NULLABLE'] === 'YES') {
        echo "[migration] users.email already nullable (IS_NULLABLE=YES). Skipping.\n";
        exit(0);
    }

    echo "[migration] users.email is NOT NULL. Applying ALTER TABLE ...\n";
    $pdo->exec("ALTER TABLE users MODIFY COLUMN email VARCHAR(191) NULL DEFAULT NULL");
    echo "[migration] OK — users.email is now nullable.\n";
    exit(0);

} catch (\Throwable $e) {
    fwrite(STDERR, "[migration] Failed: " . $e->getMessage() . "\n");
    exit(1);
}
