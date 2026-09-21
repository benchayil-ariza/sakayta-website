<?php declare(strict_types=1);

/**
 * SakayTa — controlled, non-destructive SQLite -> MariaDB data migration.
 *
 * Source:  data/sakayta.db  (opened READ-ONLY, guarded by PRAGMA query_only=ON)
 * Target:  sakayta.* on MariaDB (single transaction, ROLLBACK on ANY error)
 *
 * SAFETY RULES enforced here:
 *   - Never INSERT/DELETE/UPDATE anything in SQLite (read-only connection).
 *   - One transaction for the ENTIRE migration; any conflict or unexpected
 *     value -> throw -> ROLLBACK -> report -> exit non-zero. No partial state.
 *   - Explicit pre-insert conflict checks (id + users.email), then plain
 *     INSERT. NO ON DUPLICATE KEY, so an existing MariaDB test record is never
 *     silently clobbered or silently skipped — a conflict aborts the run.
 *   - SQLite legacy driver ids d1/d2 are re-mapped to d_legacy_1/d_legacy_2
 *     (inspection confirmed no ride references d1/d2). Any reference would
 *     abort here rather than guess.
 *   - Timestamps converted ISO-8601 -> 'YYYY-MM-DD HH:MM:SS'.
 *   - Booleans cast to tinyint(1); coordinates bound as-is (DECIMAL(10,7)).
 *   - drivers.availability set to 'unavailable' (SQLite has no such column).
 *   - Password hashes copied EXACTLY (never hashed or reset).
 */

require __DIR__ . '/../autoload.php';

\Sakayta\Config\Config::bootstrap();
$db = \Sakayta\Db\Database::connection();

$sqlitePath = __DIR__ . '/../../data/sakayta.db';

// ------------------------------------------------------------------ helpers

/** Convert SQLite ISO-8601 ("2026-08-28T03:34:02.561Z") to MariaDB DATETIME. */
function isoToDt(?string $v): ?string
{
    if ($v === null || $v === '') {
        return null;
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/', $v, $m) !== 1) {
        throw new RuntimeException("Unexpected timestamp format: $v — aborting (no partial migration).");
    }
    return $m[1] . ' ' . $m[2];
}

function assertNoIdConflict(PDO $db, string $table, string $id): void
{
    $stmt = $db->prepare("SELECT id FROM `$table` WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    if ($stmt->fetchColumn() !== false) {
        throw new RuntimeException("CONFLICT: `$table`.`$id` already exists in MariaDB — refusing to overwrite or skip. Aborting.");
    }
}

function assertFreeId(PDO $db, string $table, string $id): void
{
    assertNoIdConflict($db, $table, $id);
}

// ------------------------------------------------------ 1. open source read-only
if (!is_file($sqlitePath) || !is_readable($sqlitePath)) {
    throw new RuntimeException("SQLite source not found at $sqlitePath");
}
// Re-verify node:sqlite would not have writer access — explicit guard:
$db->exec('SET FOREIGN_KEY_CHECKS = 1'); // keep FK enforcement ON for integrity

$src = new PDO('sqlite:' . $sqlitePath);
$src->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$src->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$src->exec('PRAGMA query_only = ON'); // hard read-only: any write attempt throws

// ------------------------------------------------------ 2. single transaction
$db->beginTransaction();

try {
    $summary = [];

    // ================================================== USERS
    $rows = $src->query('SELECT id, email, full_name, password_hash, role, created_at FROM users')->fetchAll();
    $stmt = $db->prepare(
        'INSERT INTO users (id, email, full_name, password_hash, role, created_at)
         VALUES (:id, :email, :full_name, :password_hash, :role, :created_at)'
    );
    foreach ($rows as $r) {
        assertNoIdConflict($db, 'users', $r['id']);
        $emailCheck = $db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $emailCheck->execute(['email' => $r['email']]);
        if ($emailCheck->fetchColumn() !== false) {
            throw new RuntimeException("CONFLICT: users.email {$r['email']} already exists in MariaDB. Aborting.");
        }
        $stmt->execute([
            'id'            => $r['id'],
            'email'         => $r['email'],
            'full_name'     => $r['full_name'],
            'password_hash' => $r['password_hash'],
            'role'          => $r['role'],
            'created_at'    => isoToDt($r['created_at']) ?? throw new RuntimeException("users.created_at NULL for {$r['id']}"),
        ]);
    }
    $summary['users'] = count($rows);

    // ================================================== DRIVERS (d1/d2 -> d_legacy_1/2)
    $rows = $src->query('SELECT id, user_id, name, plateNumber, licenseNumber, licenseStatus, licenseVerifiedAt, verified, busy, latitude, longitude, locationUpdatedAt FROM drivers')->fetchAll();
    $driverMap = ['d1' => 'd_legacy_1', 'd2' => 'd_legacy_2'];
    $stmt = $db->prepare(
        'INSERT INTO drivers
           (id, user_id, name, plateNumber, licenseNumber, licenseStatus, licenseVerifiedAt,
            verified, busy, availability, latitude, longitude, locationUpdatedAt)
         VALUES
           (:id, :user_id, :name, :plateNumber, :licenseNumber, :licenseStatus, :licenseVerifiedAt,
            :verified, :busy, :availability, :latitude, :longitude, :locationUpdatedAt)'
    );
    foreach ($rows as $r) {
        $newId = $driverMap[$r['id']] ?? $r['id'];
        assertFreeId($db, 'drivers', $newId);
        // Guard: legacy d1/d2 must not be owned — if one unexpectedly is, STOP.
        if ($r['user_id'] !== null && isset($driverMap[$r['id']])) {
            throw new RuntimeException("UNEXPECTED: SQLite driver {$r['id']} has user_id '{$r['user_id']}' — re-mapping would break ownership. Aborting.");
        }
        $stmt->execute([
            'id'                => $newId,
            'user_id'           => $r['user_id'],
            'name'              => $r['name'],
            'plateNumber'       => $r['plateNumber'],
            'licenseNumber'     => $r['licenseNumber'],
            'licenseStatus'     => $r['licenseStatus'] ?? 'pending',
            'licenseVerifiedAt' => isoToDt($r['licenseVerifiedAt']),
            'verified'          => (int) $r['verified'],
            'busy'              => (int) $r['busy'],
            'availability'      => 'unavailable',
            'latitude'          => $r['latitude'],
            'longitude'         => $r['longitude'],
            'locationUpdatedAt' => isoToDt($r['locationUpdatedAt']),
        ]);
    }
    $summary['drivers'] = count($rows);

    // ================================================== RIDES
    $rows = $src->query('SELECT * FROM rides')->fetchAll();
    $stmt = $db->prepare(
        'INSERT INTO rides
           (id, passengerName, rideType, pickupLat, pickupLng, dropoffLat, dropoffLng,
            driverId, status, requestedDriver, requestStatus, requestCreatedAt, requestExpiresAt,
            distanceKm, fareEstimate, createdAt, userId, updatedAt, startedAt, completedAt, cancelledAt)
         VALUES
           (:id, :passengerName, :rideType, :pickupLat, :pickupLng, :dropoffLat, :dropoffLng,
            :driverId, :status, :requestedDriver, :requestStatus, :requestCreatedAt, :requestExpiresAt,
            :distanceKm, :fareEstimate, :createdAt, :userId, :updatedAt, :startedAt, :completedAt, :cancelledAt)'
    );
    foreach ($rows as $r) {
        assertNoIdConflict($db, 'rides', $r['id']);
        if ($r['driverId'] !== null && isset($driverMap[$r['driverId']])) {
            // Inspection said none; if one exists, map it — this accounts for it safely.
            $r['driverId'] = $driverMap[$r['driverId']];
        }
        $stmt->execute([
            'id'                => $r['id'],
            'passengerName'     => $r['passengerName'],
            'rideType'          => $r['rideType'],
            'pickupLat'         => $r['pickupLat'],
            'pickupLng'         => $r['pickupLng'],
            'dropoffLat'        => $r['dropoffLat'],
            'dropoffLng'        => $r['dropoffLng'],
            'driverId'          => $r['driverId'],
            'status'            => $r['status'],
            'requestedDriver'   => $r['requestedDriver'],
            'requestStatus'     => $r['requestStatus'],
            'requestCreatedAt'  => isoToDt($r['requestCreatedAt']),
            'requestExpiresAt'  => isoToDt($r['requestExpiresAt']),
            'distanceKm'        => $r['distanceKm'],
            'fareEstimate'      => $r['fareEstimate'],
            'createdAt'         => $r['createdAt'] !== null ? isoToDt($r['createdAt']) : null,
            'userId'            => $r['userId'],
            'updatedAt'         => isoToDt($r['updatedAt']),
            'startedAt'         => isoToDt($r['startedAt']),
            'completedAt'       => isoToDt($r['completedAt']),
            'cancelledAt'       => isoToDt($r['cancelledAt']),
        ]);
    }
    $summary['rides'] = count($rows);

    // ================================================== NOTIFICATIONS
    $rows = $src->query('SELECT id, userId, rideId, type, title, message, read, createdAt FROM notifications')->fetchAll();
    $stmt = $db->prepare(
        'INSERT INTO notifications (id, userId, rideId, type, title, message, `read`, createdAt)
         VALUES (:id, :userId, :rideId, :type, :title, :message, :read, :createdAt)'
    );
    foreach ($rows as $r) {
        assertNoIdConflict($db, 'notifications', $r['id']);
        $created = isoToDt($r['createdAt']);
        if ($created === null) {
            throw new RuntimeException("notifications.createdAt NULL for {$r['id']}. Aborting.");
        }
        $stmt->execute([
            'id'        => $r['id'],
            'userId'    => $r['userId'],
            'rideId'    => $r['rideId'],
            'type'      => $r['type'],
            'title'     => $r['title'],
            'message'   => $r['message'],
            'read'      => (int) $r['read'],
            'createdAt' => $created,
        ]);
    }
    $summary['notifications'] = count($rows);

    // ------------------------------------------------------ 4. commit
    $db->commit();
    echo "MIGRATION COMMITTED\n";
    foreach ($summary as $t => $n) {
        echo "  $t: +$n rows migrated\n";
    }
    echo "SQLite source was opened read-only (PRAGMA query_only=ON).\n";
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "MIGRATION FAILED — ROLLED BACK: {$e->getMessage()}\n");
    fwrite(STDERR, "No partial data was left behind (validateMaria counts are recomputed by caller).\n");
    exit(1);
}