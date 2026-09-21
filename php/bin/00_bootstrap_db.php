<?php declare(strict_types=1);

/**
 * SakayTa — 00) Bootstrap bootstrap: create MariaDB database + app user.
 *
 * Idempotent. Connects as DB_ADMIN_USER (root by default) WITHOUT selecting a
 * database, then:
 *   1. CREATE DATABASE IF NOT EXISTS sakayta  (utf8mb4 / utf8mb4_unicode_ci)
 *   2. Optionally (when DB_CREATE_APP_USER=1):
 *        CREATE USER IF NOT EXISTS / GRANT for the app user
 *
 * App-user creation is OFF by default: stock XAMPP dev commonly connects as
 * root, and broken Aria system-table states make CREATE USER fail (error 176).
 *
 * Secrets come from php/config/.env or the process environment — never from
 * arguments. Running it repeatedly is a safe no-op. It never drops tables,
 * never drops the database, and never touches SQLite.
 *
 * Usage:
 *   "C:\xampp\php\php.exe" php/bin/00_bootstrap_db.php
 */

namespace Sakayta;

require __DIR__ . '/../autoload.php';

use PDO;
use PDOException;
use Sakayta\Config\Config;

Config::bootstrap();

$admin = Config::databaseAdmin();

$dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $admin['host'], $admin['port']);

try {
    $pdo = new PDO($dsn, $admin['user'], $admin['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "[bootstrap] Cannot connect as admin ({$admin['user']}): {$e->getMessage()}\n");
    fwrite(STDERR, "Check DB_ADMIN_USER / DB_ADMIN_PASSWORD in php/config/.env and that MariaDB is running.\n");
    exit(1);
}

$dbName = Config::database()['name'];

$statements = [
    "CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
];

// Dedicated app user creation is OPTIONAL. Local XAMPP development commonly
// connects as root, and some MariaDB system-table states (e.g. Aria page
// checksum errors on mysql.user) make CREATE USER/GRANT unavailable. Set
// DB_CREATE_APP_USER=1 in php/config/.env only when it can succeed; leave it
// unset/empty to use the existing DB_USER (e.g. root) against `sakayta`.
$appCfg = Config::database();
$createUserFlag = strtolower(trim(Config::env('DB_CREATE_APP_USER', '')));
$createAppUser = in_array($createUserFlag, ['1', 'true', 'yes', 'on'], true);

if ($createAppUser) {
    $userQ = $pdo->quote($appCfg['user']);
    $hostQ = $pdo->quote($appCfg['host'] === '127.0.0.1' || $appCfg['host'] === 'localhost' ? 'localhost' : $appCfg['host']);

    // CREATE USER IF NOT EXISTS does not accept bound parameters in one pass
    // on all MariaDB versions, so quote() is used for identifiers/values
    // (user + host are config-controlled, never user input).
    $statements[] = "CREATE USER IF NOT EXISTS {$userQ}@{$hostQ} IDENTIFIED BY {$pdo->quote($appCfg['password'])}";
    $statements[] = "GRANT ALL PRIVILEGES ON `{$dbName}`.* TO {$userQ}@{$hostQ}";
    $statements[] = 'FLUSH PRIVILEGES';
}

foreach ($statements as $sql) {
    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        fwrite(STDERR, "[bootstrap] Statement failed: {$e->getMessage()}\n");
        exit(1);
    }
}

printf("[bootstrap] database `%s` ready (utf8mb4).\n", $dbName);
if ($createAppUser) {
    printf("[bootstrap] app user `%s`@`localhost` granted on `%s`.*\n", $appCfg['user'], $dbName);
} else {
    echo "[bootstrap] app-user creation skipped (DB_CREATE_APP_USER unset) — app connects as {" . $appCfg['user'] . "}.\n";
}
echo "[bootstrap] OK  (idempotent; next run is a no-op).\n";
exit(0);