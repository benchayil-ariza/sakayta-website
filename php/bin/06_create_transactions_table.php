<?php declare(strict_types=1);

/**
 * SakayTa — 06) Create cash transaction receipt snapshots.
 *
 * Additive and rerunnable: creates the table only when absent. An existing
 * table is validated and left untouched; an incompatible table stops the
 * migration for manual review. MariaDB DDL may implicitly commit.
 * This script is isolated-test-only: it requires explicit SAKAYTA_TEST_DB_*
 * configuration and refuses the application's default database name.
 */

namespace Sakayta\Db;

require __DIR__ . '/../autoload.php';

use PDO;
use Sakayta\Config\Config;
use Sakayta\Db\Database;

try {
    // Do not bootstrap the application .env or use its DB_* defaults here.
    // All connection settings must be explicitly supplied for a separately
    // provisioned disposable database and least-privilege test account.
    if (getenv('SAKAYTA_MIGRATION_MODE') !== 'isolated-test') {
        throw new \RuntimeException('Migration refused: set SAKAYTA_MIGRATION_MODE=isolated-test for an isolated test run.');
    }

    $testConfig = [];
    foreach ([
        'SAKAYTA_TEST_DB_HOST',
        'SAKAYTA_TEST_DB_PORT',
        'SAKAYTA_TEST_DB_NAME',
        'SAKAYTA_TEST_DB_USER',
        'SAKAYTA_TEST_DB_PASSWORD',
    ] as $key) {
        $value = getenv($key);
        if ($value === false || trim($value) === '') {
            throw new \RuntimeException("Migration refused: required isolated-test setting {$key} is missing.");
        }
        $testConfig[$key] = $key === 'SAKAYTA_TEST_DB_PASSWORD' ? $value : trim($value);
    }

    $host = $testConfig['SAKAYTA_TEST_DB_HOST'];
    if (filter_var($host, FILTER_VALIDATE_IP) === false
        && preg_match('/\\A(?:localhost|[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?)\\z/iD', $host) !== 1) {
        throw new \RuntimeException('Migration refused: isolated-test host is invalid.');
    }

    $port = filter_var($testConfig['SAKAYTA_TEST_DB_PORT'], FILTER_VALIDATE_INT);
    if ($port === false || $port < 1 || $port > 65535) {
        throw new \RuntimeException('Migration refused: isolated-test port is invalid.');
    }

    $dbName = $testConfig['SAKAYTA_TEST_DB_NAME'];
    if (preg_match('/\\A[A-Za-z0-9_]+\\z/D', $dbName) !== 1
        || strcasecmp($dbName, 'sakayta') === 0) {
        throw new \RuntimeException('Migration refused: isolated-test database name is invalid or is the application database.');
    }

    if (strcasecmp($testConfig['SAKAYTA_TEST_DB_USER'], 'root') === 0) {
        throw new \RuntimeException('Migration refused: use a separately provisioned non-root test account.');
    }

    // Override the names consumed by Config::database() with the required
    // test-only values. No application .env fallback is loaded in this script.
    foreach ([
        'DB_HOST' => $host,
        'DB_PORT' => (string) $port,
        'DB_NAME' => $dbName,
        'DB_USER' => $testConfig['SAKAYTA_TEST_DB_USER'],
        'DB_PASSWORD' => $testConfig['SAKAYTA_TEST_DB_PASSWORD'],
        'DB_CHARSET' => 'utf8mb4',
    ] as $key => $value) {
        putenv("{$key}={$value}");
    }

    $config = Config::database();
    if ($config['host'] !== $host
        || $config['port'] !== $port
        || $config['name'] !== $dbName
        || $config['user'] !== $testConfig['SAKAYTA_TEST_DB_USER']
        || $config['password'] !== $testConfig['SAKAYTA_TEST_DB_PASSWORD']
        || $config['charset'] !== 'utf8mb4'
        || strcasecmp($config['name'], 'sakayta') === 0) {
        throw new \RuntimeException('Migration refused: resolved database does not match the explicit isolated-test target.');
    }

    $pdo = Database::connection();
    $connectedDatabase = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!is_string($connectedDatabase)
        || $connectedDatabase !== $dbName
        || strcasecmp($connectedDatabase, 'sakayta') === 0) {
        throw new \RuntimeException('Migration refused: connected database identity does not match the isolated-test target.');
    }

    // CHECK_CONSTRAINTS is required to verify the fare rule. Fail before any
    // DDL if this server does not expose the metadata this migration needs.
    try {
        $pdo->query('SELECT CONSTRAINT_NAME, TABLE_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE 1 = 0');
    } catch (\Throwable $e) {
        throw new \RuntimeException('Migration refused before DDL: CHECK_CONSTRAINTS metadata is unavailable.', 0, $e);
    }

    $tableInfo = $pdo->prepare(
        'SELECT ENGINE FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table'
    );
    $columnInfo = $pdo->prepare(
        'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
                CHARACTER_SET_NAME, COLLATION_NAME, EXTRA
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );

    $requireParent = static function (string $table, string $columnName) use ($pdo, $tableInfo, $columnInfo, $dbName): void {
        $tableInfo->execute(['db' => $dbName, 'table' => $table]);
        if (strcasecmp((string) $tableInfo->fetchColumn(), 'InnoDB') !== 0) {
            throw new \RuntimeException("Required InnoDB table '{$table}' is missing or incompatible.");
        }
        $columnInfo->execute(['db' => $dbName, 'table' => $table, 'column' => $columnName]);
        $column = $columnInfo->fetch(PDO::FETCH_ASSOC);
        if (!$column || strtolower((string) $column['COLUMN_TYPE']) !== 'varchar(50)'
            || strtolower((string) $column['CHARACTER_SET_NAME']) !== 'utf8mb4'
            || strtolower((string) $column['COLLATION_NAME']) !== 'utf8mb4_unicode_ci') {
            throw new \RuntimeException("Referenced key '{$table}.{$columnName}' is incompatible.");
        }

        $parentKey = $pdo->prepare(
            'SELECT NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, INDEX_TYPE
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND INDEX_NAME = \'PRIMARY\'
             ORDER BY SEQ_IN_INDEX'
        );
        $parentKey->execute(['db' => $dbName, 'table' => $table]);
        $keyColumns = $parentKey->fetchAll(PDO::FETCH_ASSOC);
        if (count($keyColumns) !== 1
            || (int) ($keyColumns[0]['NON_UNIQUE'] ?? -1) !== 0
            || (int) ($keyColumns[0]['SEQ_IN_INDEX'] ?? 0) !== 1
            || ($keyColumns[0]['COLUMN_NAME'] ?? null) !== $columnName
            || ($keyColumns[0]['SUB_PART'] ?? null) !== null
            || strtoupper((string) ($keyColumns[0]['INDEX_TYPE'] ?? '')) !== 'BTREE') {
            throw new \RuntimeException("Referenced key '{$table}.{$columnName}' must be the full single-column primary key before DDL.");
        }
    };

    foreach ([['rides', 'id'], ['users', 'id'], ['drivers', 'id']] as [$parentTable, $parentColumn]) {
        $requireParent($parentTable, $parentColumn);
    }

    $tableInfo->execute(['db' => $dbName, 'table' => 'transactions']);
    $engine = $tableInfo->fetchColumn();
    if ($engine === false) {
        $pdo->exec(<<<'SQL'
CREATE TABLE `transactions` (
  id                VARCHAR(50)   NOT NULL,
  rideId            VARCHAR(50)   NOT NULL,
  passengerUserId   VARCHAR(50)   DEFAULT NULL,
  driverId          VARCHAR(50)   DEFAULT NULL,
  fareAmount        DECIMAL(10,2) NOT NULL,
  currency          CHAR(3)       NOT NULL DEFAULT 'PHP',
  paymentMethod     ENUM('cash')  NOT NULL DEFAULT 'cash',
  rideType          VARCHAR(50)   NOT NULL,
  distanceKm        DECIMAL(10,2) DEFAULT NULL,
  passengerName     VARCHAR(255) NOT NULL,
  driverName        VARCHAR(255) NOT NULL,
  pickupLat         DECIMAL(10,7) DEFAULT NULL,
  pickupLng         DECIMAL(10,7) DEFAULT NULL,
  dropoffLat        DECIMAL(10,7) DEFAULT NULL,
  dropoffLng        DECIMAL(10,7) DEFAULT NULL,
  completedAt       DATETIME      NOT NULL,
  createdAt         DATETIME      NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_transactions_ride (rideId),
  KEY idx_transactions_passenger_created (passengerUserId, createdAt),
  KEY idx_transactions_driver_created (driverId, createdAt),
  CONSTRAINT fk_transactions_ride
    FOREIGN KEY (rideId) REFERENCES rides(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_transactions_passenger
    FOREIGN KEY (passengerUserId) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_transactions_driver
    FOREIGN KEY (driverId) REFERENCES drivers(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_transactions_fare_nonnegative CHECK (fareAmount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        echo "[migration] Created transactions table.\n";
    } elseif (strcasecmp((string) $engine, 'InnoDB') !== 0) {
        throw new \RuntimeException('Existing transactions table is not InnoDB; no changes were made.');
    }

    $expectedColumns = [
        'id' => ['varchar(50)', 'NO', 'utf8mb4', 'utf8mb4_unicode_ci', null],
        'rideId' => ['varchar(50)', 'NO', 'utf8mb4', 'utf8mb4_unicode_ci', null],
        'passengerUserId' => ['varchar(50)', 'YES', 'utf8mb4', 'utf8mb4_unicode_ci', null],
        'driverId' => ['varchar(50)', 'YES', 'utf8mb4', 'utf8mb4_unicode_ci', null],
        'fareAmount' => ['decimal(10,2)', 'NO', null, null, null],
        'currency' => ['char(3)', 'NO', 'utf8mb4', 'utf8mb4_unicode_ci', 'PHP'],
        'paymentMethod' => ["enum('cash')", 'NO', 'utf8mb4', 'utf8mb4_unicode_ci', 'cash'],
        'rideType' => ['varchar(50)', 'NO', 'utf8mb4', 'utf8mb4_unicode_ci', null],
        'distanceKm' => ['decimal(10,2)', 'YES', null, null, null],
        'passengerName' => ['varchar(255)', 'NO', 'utf8mb4', 'utf8mb4_unicode_ci', null],
        'driverName' => ['varchar(255)', 'NO', 'utf8mb4', 'utf8mb4_unicode_ci', null],
        'pickupLat' => ['decimal(10,7)', 'YES', null, null, null],
        'pickupLng' => ['decimal(10,7)', 'YES', null, null, null],
        'dropoffLat' => ['decimal(10,7)', 'YES', null, null, null],
        'dropoffLng' => ['decimal(10,7)', 'YES', null, null, null],
        'completedAt' => ['datetime', 'NO', null, null, null],
        'createdAt' => ['datetime', 'NO', null, null, null],
    ];
    foreach ($expectedColumns as $columnName => [$type, $nullable, $charset, $collation, $default]) {
        $columnInfo->execute(['db' => $dbName, 'table' => 'transactions', 'column' => $columnName]);
        $actual = $columnInfo->fetch(PDO::FETCH_ASSOC);
        $actualExtra = is_array($actual) && array_key_exists('EXTRA', $actual)
            ? trim((string) $actual['EXTRA'])
            : null;
        $actualDefault = $actual['COLUMN_DEFAULT'] ?? null;
        if (is_string($actualDefault) && strtoupper($actualDefault) === 'NULL') {
            $actualDefault = null;
        }
        if (is_string($actualDefault) && strlen($actualDefault) >= 2
            && $actualDefault[0] === "'" && $actualDefault[strlen($actualDefault) - 1] === "'") {
            $actualDefault = str_replace("''", "'", substr($actualDefault, 1, -1));
        }
        if (!$actual || strtolower((string) $actual['COLUMN_TYPE']) !== $type
            || $actual['IS_NULLABLE'] !== $nullable
            || ($charset !== null && strtolower((string) $actual['CHARACTER_SET_NAME']) !== $charset)
            || ($collation !== null && strtolower((string) $actual['COLLATION_NAME']) !== $collation)
            || $actualDefault !== $default
            || $actualExtra !== '') {
            throw new \RuntimeException("Existing transactions.{$columnName} has an incompatible definition; no corrective alteration was attempted.");
        }
    }

    // Column order is not used by this application (all INSERT/SELECT calls
    // name columns), but the declared column set must be exact: extra required
    // columns could make inserts fail or change the intended record shape.
    $actualColumnNames = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table'
    );
    $actualColumnNames->execute(['db' => $dbName, 'table' => 'transactions']);
    $actualColumnNames = $actualColumnNames->fetchAll(PDO::FETCH_COLUMN);
    $expectedColumnNames = array_keys($expectedColumns);
    sort($actualColumnNames);
    sort($expectedColumnNames);
    if ($actualColumnNames !== $expectedColumnNames) {
        throw new \RuntimeException('Existing transactions table has missing or unexpected columns.');
    }

    $indexInfo = $pdo->prepare(
        'SELECT NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, INDEX_TYPE
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND INDEX_NAME = :indexName
         ORDER BY SEQ_IN_INDEX'
    );

    $requireIndex = static function (string $name, array $columns, bool $unique) use ($indexInfo, $dbName): void {
        $indexInfo->execute(['db' => $dbName, 'table' => 'transactions', 'indexName' => $name]);
        $actual = $indexInfo->fetchAll(PDO::FETCH_ASSOC);
        $expectedNonUnique = $unique ? 0 : 1;
        if (count($actual) !== count($columns)) {
            throw new \RuntimeException("Index '{$name}' has a missing, extra, or ambiguous column definition.");
        }

        foreach ($columns as $offset => $column) {
            $row = $actual[$offset];
            if ((int) ($row['NON_UNIQUE'] ?? -1) !== $expectedNonUnique
                || (int) ($row['SEQ_IN_INDEX'] ?? 0) !== $offset + 1
                || ($row['COLUMN_NAME'] ?? null) !== $column
                || ($row['SUB_PART'] ?? null) !== null
                || strtoupper((string) ($row['INDEX_TYPE'] ?? '')) !== 'BTREE') {
                throw new \RuntimeException("Index '{$name}' has an incompatible uniqueness, order, prefix, or type definition.");
            }
        }
    };

    $requireIndex('PRIMARY', ['id'], true);
    $requireIndex('uq_transactions_ride', ['rideId'], true);
    $requireIndex('idx_transactions_passenger_created', ['passengerUserId', 'createdAt'], false);
    $requireIndex('idx_transactions_driver_created', ['driverId', 'createdAt'], false);

    $foreignKeys = [
        'fk_transactions_ride' => ['rideId', 'rides', 'id', 'RESTRICT'],
        'fk_transactions_passenger' => ['passengerUserId', 'users', 'id', 'SET NULL'],
        'fk_transactions_driver' => ['driverId', 'drivers', 'id', 'SET NULL'],
    ];
    $actualForeignKeyNames = $pdo->prepare(
        'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = :db AND TABLE_NAME = :table
           AND CONSTRAINT_TYPE = \'FOREIGN KEY\'
         ORDER BY CONSTRAINT_NAME'
    );
    $actualForeignKeyNames->execute(['db' => $dbName, 'table' => 'transactions']);
    $actualForeignKeyNames = $actualForeignKeyNames->fetchAll(PDO::FETCH_COLUMN);
    $expectedForeignKeyNames = array_keys($foreignKeys);
    sort($actualForeignKeyNames);
    sort($expectedForeignKeyNames);
    if ($actualForeignKeyNames !== $expectedForeignKeyNames) {
        throw new \RuntimeException('Existing transactions table has missing or unexpected foreign keys.');
    }

    $fkQuery = $pdo->prepare(
        'SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
                r.DELETE_RULE, r.UPDATE_RULE
         FROM information_schema.KEY_COLUMN_USAGE k
         JOIN information_schema.REFERENTIAL_CONSTRAINTS r
           ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
          AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
          AND r.TABLE_NAME = k.TABLE_NAME
         WHERE k.CONSTRAINT_SCHEMA = :db AND k.TABLE_NAME = :table
           AND k.CONSTRAINT_NAME = :constraintName'
    );
    foreach ($foreignKeys as $name => [$column, $parent, $parentColumn, $deleteRule]) {
        $fkQuery->execute(['db' => $dbName, 'table' => 'transactions', 'constraintName' => $name]);
        $actual = $fkQuery->fetchAll(PDO::FETCH_ASSOC);
        if (count($actual) !== 1 || $actual[0]['COLUMN_NAME'] !== $column
            || $actual[0]['REFERENCED_TABLE_NAME'] !== $parent
            || $actual[0]['REFERENCED_COLUMN_NAME'] !== $parentColumn
            || $actual[0]['DELETE_RULE'] !== $deleteRule
            || $actual[0]['UPDATE_RULE'] !== 'CASCADE') {
            throw new \RuntimeException("Foreign key '{$name}' is missing or incompatible.");
        }
    }

    $checkQuery = $pdo->prepare(
        'SELECT CONSTRAINT_NAME, CHECK_CLAUSE
         FROM information_schema.CHECK_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = :db AND TABLE_NAME = :table
         ORDER BY CONSTRAINT_NAME'
    );
    $checkQuery->execute(['db' => $dbName, 'table' => 'transactions']);
    $checkConstraints = $checkQuery->fetchAll(PDO::FETCH_ASSOC);
    $normalizeCheck = static function (mixed $clause): string {
        $normalized = strtolower((string) $clause);
        return preg_replace('/[\\s`()]+/', '', $normalized) ?? '';
    };
    if (count($checkConstraints) !== 1
        || ($checkConstraints[0]['CONSTRAINT_NAME'] ?? null) !== 'chk_transactions_fare_nonnegative'
        || $normalizeCheck($checkConstraints[0]['CHECK_CLAUSE'] ?? null) !== 'fareamount>=0') {
        throw new \RuntimeException('Existing transactions table must define exactly CHECK (fareAmount >= 0).');
    }

    echo "[migration] transactions schema verified; existing compatible tables were left unchanged.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, '[migration] Failed: ' . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
