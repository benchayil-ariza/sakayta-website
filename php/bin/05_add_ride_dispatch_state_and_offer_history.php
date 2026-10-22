<?php declare(strict_types=1);

/**
 * SakayTa — 05) Add durable MariaDB ride dispatch state and offer history.
 *
 * This migration is additive and rerunnable. MariaDB DDL can implicitly
 * commit, so a failure may leave some DDL applied; rerun after reviewing the
 * failure. Legacy pending rides are marked manual_review because notifications
 * cannot establish their complete offer history. No ride or notification
 * fields are rewritten.
 */

namespace Sakayta\Db;

require __DIR__ . '/../autoload.php';

use PDO;
use Sakayta\Config\Config;
use Sakayta\Db\Database;

Config::bootstrap();

try {
    $pdo = Database::connection();
    $dbName = Config::database()['name'];

    $tableInfo = $pdo->prepare(
        'SELECT ENGINE FROM information_schema.tables
         WHERE table_schema = :db AND table_name = :table'
    );
    $columnInfo = $pdo->prepare(
        'SELECT COLUMN_TYPE, IS_NULLABLE, CHARACTER_SET_NAME, COLLATION_NAME
         FROM information_schema.columns
         WHERE table_schema = :db AND table_name = :table AND column_name = :column'
    );

    $requireInnoDb = static function (string $table) use ($tableInfo, $dbName): void {
        $tableInfo->execute(['db' => $dbName, 'table' => $table]);
        $engine = $tableInfo->fetchColumn();
        if ($engine === false) {
            throw new \RuntimeException("Required table '{$table}' is missing.");
        }
        if (strcasecmp((string) $engine, 'InnoDB') !== 0) {
            throw new \RuntimeException("Required table '{$table}' must use InnoDB.");
        }
    };

    $requireColumn = static function (
        string $table,
        string $column,
        string $expectedType,
        string $expectedNullability,
        ?string $expectedCharset = null,
        ?string $expectedCollation = null
    ) use ($columnInfo, $dbName): void {
        $columnInfo->execute(['db' => $dbName, 'table' => $table, 'column' => $column]);
        $actual = $columnInfo->fetch(PDO::FETCH_ASSOC);
        if (!$actual) {
            throw new \RuntimeException("Required column '{$table}.{$column}' is missing.");
        }
        if (strtolower((string) $actual['COLUMN_TYPE']) !== strtolower($expectedType)
            || $actual['IS_NULLABLE'] !== $expectedNullability) {
            throw new \RuntimeException("Column '{$table}.{$column}' has an incompatible definition.");
        }
        if ($expectedCharset !== null
            && (strtolower((string) $actual['CHARACTER_SET_NAME']) !== strtolower($expectedCharset)
                || strtolower((string) $actual['COLLATION_NAME']) !== strtolower((string) $expectedCollation))) {
            throw new \RuntimeException("Column '{$table}.{$column}' has an incompatible character set or collation.");
        }
    };

    foreach (['rides', 'drivers', 'notifications'] as $table) {
        $requireInnoDb($table);
    }
    $requireColumn('rides', 'id', 'varchar(50)', 'NO', 'utf8mb4', 'utf8mb4_unicode_ci');
    $requireColumn('drivers', 'id', 'varchar(50)', 'NO', 'utf8mb4', 'utf8mb4_unicode_ci');
    $requireColumn('notifications', 'rideId', 'varchar(50)', 'YES', 'utf8mb4', 'utf8mb4_unicode_ci');

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS ride_offer_attempts (
  offerId      VARCHAR(50) NOT NULL,
  rideId       VARCHAR(50) NOT NULL,
  driverId     VARCHAR(50) NOT NULL,
  offeredAt    DATETIME    NOT NULL,
  expiresAt    DATETIME    NOT NULL,
  outcome      ENUM('pending','accepted','declined','expired','superseded') NOT NULL,
  respondedAt  DATETIME    DEFAULT NULL,
  activeSlot   TINYINT     DEFAULT NULL,
  PRIMARY KEY (offerId),
  UNIQUE KEY uq_roa_ride_driver (rideId, driverId),
  UNIQUE KEY uq_roa_ride_active (rideId, activeSlot),
  KEY idx_roa_expiry (outcome, expiresAt),
  KEY idx_roa_ride_history (rideId, offeredAt, offerId),
  CONSTRAINT fk_roa_ride
    FOREIGN KEY (rideId) REFERENCES rides(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_roa_driver
    FOREIGN KEY (driverId) REFERENCES drivers(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS ride_dispatch_state (
  rideId         VARCHAR(50) NOT NULL,
  dispatchStatus ENUM('queued','retry_wait','offered','stopped','manual_review') NOT NULL,
  nextAttemptAt  DATETIME    DEFAULT NULL,
  attemptCount   INT UNSIGNED NOT NULL DEFAULT 0,
  lastAttemptAt  DATETIME    DEFAULT NULL,
  lastErrorCode  VARCHAR(50) DEFAULT NULL,
  updatedAt      DATETIME    NOT NULL,
  PRIMARY KEY (rideId),
  KEY idx_rds_due (dispatchStatus, nextAttemptAt, rideId),
  CONSTRAINT fk_rds_ride
    FOREIGN KEY (rideId) REFERENCES rides(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $requireInnoDb('ride_offer_attempts');
    $requireInnoDb('ride_dispatch_state');

    $requireColumn('ride_offer_attempts', 'offerId', 'varchar(50)', 'NO', 'utf8mb4', 'utf8mb4_unicode_ci');
    $requireColumn('ride_offer_attempts', 'rideId', 'varchar(50)', 'NO', 'utf8mb4', 'utf8mb4_unicode_ci');
    $requireColumn('ride_offer_attempts', 'driverId', 'varchar(50)', 'NO', 'utf8mb4', 'utf8mb4_unicode_ci');
    $requireColumn('ride_offer_attempts', 'offeredAt', 'datetime', 'NO');
    $requireColumn('ride_offer_attempts', 'expiresAt', 'datetime', 'NO');
    $requireColumn('ride_offer_attempts', 'outcome', "enum('pending','accepted','declined','expired','superseded')", 'NO');
    $requireColumn('ride_offer_attempts', 'respondedAt', 'datetime', 'YES');
    $requireColumn('ride_offer_attempts', 'activeSlot', 'tinyint(4)', 'YES');
    $requireColumn('ride_dispatch_state', 'rideId', 'varchar(50)', 'NO', 'utf8mb4', 'utf8mb4_unicode_ci');
    $requireColumn('ride_dispatch_state', 'dispatchStatus', "enum('queued','retry_wait','offered','stopped','manual_review')", 'NO');
    $requireColumn('ride_dispatch_state', 'nextAttemptAt', 'datetime', 'YES');
    $requireColumn('ride_dispatch_state', 'attemptCount', 'int(10) unsigned', 'NO');
    $requireColumn('ride_dispatch_state', 'lastAttemptAt', 'datetime', 'YES');
    $requireColumn('ride_dispatch_state', 'lastErrorCode', 'varchar(50)', 'YES', 'utf8mb4', 'utf8mb4_unicode_ci');
    $requireColumn('ride_dispatch_state', 'updatedAt', 'datetime', 'NO');

    $ensureIndex = static function (string $table, string $index, array $columns, bool $unique) use ($pdo, $dbName): void {
        $definitions = [
            'ride_offer_attempts.PRIMARY' => 'PRIMARY KEY (offerId)',
            'ride_offer_attempts.uq_roa_ride_driver' => 'UNIQUE KEY uq_roa_ride_driver (rideId, driverId)',
            'ride_offer_attempts.uq_roa_ride_active' => 'UNIQUE KEY uq_roa_ride_active (rideId, activeSlot)',
            'ride_offer_attempts.idx_roa_expiry' => 'KEY idx_roa_expiry (outcome, expiresAt)',
            'ride_offer_attempts.idx_roa_ride_history' => 'KEY idx_roa_ride_history (rideId, offeredAt, offerId)',
            'ride_dispatch_state.PRIMARY' => 'PRIMARY KEY (rideId)',
            'ride_dispatch_state.idx_rds_due' => 'KEY idx_rds_due (dispatchStatus, nextAttemptAt, rideId)',
            'notifications.uq_notifications_offer' => 'UNIQUE KEY uq_notifications_offer (offerId)',
        ];
        $stmt = $pdo->prepare(
            'SELECT NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ",") AS indexed_columns
             FROM information_schema.statistics
             WHERE table_schema = :db AND table_name = :table AND index_name = :index
             GROUP BY NON_UNIQUE'
        );
        $stmt->execute(['db' => $dbName, 'table' => $table, 'index' => $index]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $definitionKey = $table . '.' . $index;
            if (!isset($definitions[$definitionKey])) {
                throw new \RuntimeException("No migration definition exists for index '{$definitionKey}'.");
            }
            $pdo->exec("ALTER TABLE `{$table}` ADD {$definitions[$definitionKey]}");
            $stmt->execute(['db' => $dbName, 'table' => $table, 'index' => $index]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new \RuntimeException("Required index '{$definitionKey}' could not be verified after creation.");
            }
        }
        $expectedNonUnique = $unique ? 0 : 1;
        if ((int) $row['NON_UNIQUE'] !== $expectedNonUnique
            || explode(',', (string) $row['indexed_columns']) !== $columns) {
            throw new \RuntimeException("Index '{$table}.{$index}' has an incompatible definition.");
        }
    };

    $ensureIndex('ride_offer_attempts', 'PRIMARY', ['offerId'], true);
    $ensureIndex('ride_offer_attempts', 'uq_roa_ride_driver', ['rideId', 'driverId'], true);
    $ensureIndex('ride_offer_attempts', 'uq_roa_ride_active', ['rideId', 'activeSlot'], true);
    $ensureIndex('ride_offer_attempts', 'idx_roa_expiry', ['outcome', 'expiresAt'], false);
    $ensureIndex('ride_offer_attempts', 'idx_roa_ride_history', ['rideId', 'offeredAt', 'offerId'], false);
    $ensureIndex('ride_dispatch_state', 'PRIMARY', ['rideId'], true);
    $ensureIndex('ride_dispatch_state', 'idx_rds_due', ['dispatchStatus', 'nextAttemptAt', 'rideId'], false);

    $columnInfo->execute(['db' => $dbName, 'table' => 'notifications', 'column' => 'offerId']);
    $offerColumn = $columnInfo->fetch(PDO::FETCH_ASSOC);
    if (!$offerColumn) {
        $pdo->exec('ALTER TABLE notifications ADD COLUMN offerId VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER rideId');
        $columnInfo->execute(['db' => $dbName, 'table' => 'notifications', 'column' => 'offerId']);
        $offerColumn = $columnInfo->fetch(PDO::FETCH_ASSOC);
    }
    if (!$offerColumn
        || strtolower((string) $offerColumn['COLUMN_TYPE']) !== 'varchar(50)'
        || $offerColumn['IS_NULLABLE'] !== 'YES'
        || strtolower((string) $offerColumn['CHARACTER_SET_NAME']) !== 'utf8mb4'
        || strtolower((string) $offerColumn['COLLATION_NAME']) !== 'utf8mb4_unicode_ci') {
        throw new \RuntimeException('Column notifications.offerId has an incompatible definition.');
    }

    $ensureIndex('notifications', 'uq_notifications_offer', ['offerId'], true);

    $ensureForeignKey = static function (
        string $table,
        string $constraint,
        string $column,
        string $referencedTable,
        string $referencedColumn
    ) use ($pdo, $dbName): void {
        $stmt = $pdo->prepare(
            'SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
                    r.DELETE_RULE, r.UPDATE_RULE
             FROM information_schema.key_column_usage k
             JOIN information_schema.referential_constraints r
               ON r.constraint_schema = k.constraint_schema
              AND r.constraint_name = k.constraint_name
              AND r.table_name = k.table_name
             WHERE k.constraint_schema = :db AND k.table_name = :table AND k.constraint_name = :constraint'
        );
        $stmt->execute(['db' => $dbName, 'table' => $table, 'constraint' => $constraint]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            if (count($rows) !== 1
                || $rows[0]['COLUMN_NAME'] !== $column
                || $rows[0]['REFERENCED_TABLE_NAME'] !== $referencedTable
                || $rows[0]['REFERENCED_COLUMN_NAME'] !== $referencedColumn
                || $rows[0]['DELETE_RULE'] !== 'RESTRICT'
                || $rows[0]['UPDATE_RULE'] !== 'CASCADE') {
                throw new \RuntimeException("Foreign key '{$table}.{$constraint}' has an incompatible definition.");
            }
            return;
        }

        $nameCheck = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.table_constraints
             WHERE constraint_schema = :db AND table_name = :table AND constraint_name = :constraint'
        );
        $nameCheck->execute(['db' => $dbName, 'table' => $table, 'constraint' => $constraint]);
        if ((int) $nameCheck->fetchColumn() !== 0) {
            throw new \RuntimeException("Constraint name '{$constraint}' is already used incompatibly.");
        }

        $pdo->exec("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` FOREIGN KEY (`{$column}`) REFERENCES `{$referencedTable}` (`{$referencedColumn}`) ON DELETE RESTRICT ON UPDATE CASCADE");
    };

    $ensureForeignKey('ride_offer_attempts', 'fk_roa_ride', 'rideId', 'rides', 'id');
    $ensureForeignKey('ride_offer_attempts', 'fk_roa_driver', 'driverId', 'drivers', 'id');
    $ensureForeignKey('ride_dispatch_state', 'fk_rds_ride', 'rideId', 'rides', 'id');
    $ensureForeignKey('notifications', 'fk_notifications_offer', 'offerId', 'ride_offer_attempts', 'offerId');

    // DDL above may implicitly commit. This DML section is independently atomic.
    $pdo->beginTransaction();
    $pdo->exec(
        "INSERT INTO ride_dispatch_state
             (rideId, dispatchStatus, nextAttemptAt, attemptCount, lastAttemptAt, lastErrorCode, updatedAt)
         SELECT r.id,
                CASE WHEN r.status = 'pending' THEN 'manual_review' ELSE 'stopped' END,
                NULL, 0, NULL,
                CASE WHEN r.status = 'pending' THEN 'legacy_history_unverified' ELSE NULL END,
                COALESCE(r.updatedAt, r.createdAt, UTC_TIMESTAMP())
         FROM rides r
         LEFT JOIN ride_dispatch_state s ON s.rideId = r.id
         WHERE s.rideId IS NULL"
    );
    $pdo->commit();

    echo "[migration] Dispatch state and offer history schema verified. Legacy pending rides are manual_review; other existing rides are stopped.\n";
    echo "[migration] No offer history was inferred from notifications.\n";
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[migration] Failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

exit(0);
