<?php declare(strict_types=1);

/**
 * SakayTa — Health check endpoint (FINAL SRS foundation).
 *
 * Served through Apache. Verifies:
 *   * PHP is running
 *   * required extension are loaded (pdo_mysql, soap, openssl)
 *   * MariaDB is reachable via PDO and the configured database exists
 *   * the foundation schema has the expected tables
 *
 * Returns JSON only. CREDENTIALS ARE NEVER RETURNED — the response contains
 * no password, user, DSN, or connection string.
 */

namespace Sakayta;

require __DIR__ . '/../autoload.php';

use PDO;
use Sakayta\Config\Config;
use Sakayta\Db\Database;

Config::bootstrap();

header('Content-Type: application/json; charset=utf-8');

function out(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$requiredExtensions = ['pdo_mysql', 'soap', 'openssl'];
$extensions = [];
foreach ($requiredExtensions as $ext) {
    $extensions[$ext] = extension_loaded($ext);
}
$extensions['php'] = PHP_VERSION;

$checks = [];
$checks['php']              = true;
$checks['extensions']       = $extensions;
$checks['extensions_ok']    = !in_array(false, $extensions, true);

$database = ['reachable' => false, 'database' => Config::env('DB_NAME', 'sakayta'), 'server' => null, 'tables' => []];
$databaseOk = false;

try {
    $pdo = Database::connection();
    $database['reachable']  = true;
    $database['server']     = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $database['database']   = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $database['tables']     = $tables;

    $expected = ['users', 'drivers', 'rides', 'notifications', 'fare_config', 'fare_areas', 'admin_action_logs'];
    $missing  = array_values(array_diff($expected, $tables));
    $database['missing_tables'] = $missing;
    $databaseOk = $database['reachable'] && $missing === [];
} catch (\Throwable $e) {
    // Never surface exception internals (they can embed the DSN).
    $database['error'] = 'Database connection failed.';
}

$checks['database']     = $database;
$checks['database_ok']  = $databaseOk;
$checks['app']          = 'sakayta-php-foundation';
$checks['status']       = 'ok';
$checks['timestamp']    = gmdate('c');
$checks['hello']        = 'SakayTa PHP foundation is reachable via Apache.';

$ok = $checks['extensions_ok'] && $databaseOk;
out($ok ? 200 : 503, $checks);