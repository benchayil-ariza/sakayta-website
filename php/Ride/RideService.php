<?php declare(strict_types=1);

/**
 * SakayTa — ride service (PHP foundation, no dispatch/FareQueue/Socket/notify).
 *
 * All rides are scoped to the authenticated commuter (claims.uid). No rideId
 * is ever taken from the request body to decide ownership. PDO prepared
 * statements only. Preserves the existing MariaDB schema (no drops).
 */

namespace Sakayta\Ride;

use PDO;
use PDOException;
use Sakayta\Db\Database;

final class RideService
{
    public const CANCELLABLE = ['pending', 'assigned'];
    public const NON_CANCELLABLE = ['en_route', 'completed', 'cancelled'];

    // ------------------------------------------------------------- Driver matching

    /** Find nearest eligible verified+available driver to pickup. */
    private function nearestEligibleDriver(float $pickupLat, float $pickupLng, ?string $excludeDriverId = null): ?array
    {
        $pdo = Database::connection();
        $sql = "SELECT d.id AS driver_id, d.user_id, d.name, d.latitude, d.longitude,
                       d.verified, d.availability, d.licenseStatus
                FROM drivers d
                WHERE d.verified = 1
                  AND d.availability = 'available'
                  AND d.licenseStatus = 'verified'
                  AND d.busy = 0
                  AND d.latitude IS NOT NULL
                  AND d.longitude IS NOT NULL";
        if ($excludeDriverId) {
            $sql .= " AND d.id != :exclude";
        }
        $sql .= " ORDER BY (6371 * ACOS(COS(RADIANS(:lat1)) * COS(RADIANS(d.latitude)) * COS(RADIANS(d.longitude) - RADIANS(:lng1)) + SIN(RADIANS(:lat2)) * SIN(RADIANS(d.latitude)))) ASC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $params = ['lat1' => $pickupLat, 'lng1' => $pickupLng, 'lat2' => $pickupLat];
        if ($excludeDriverId) $params['exclude'] = $excludeDriverId;
        try { $stmt->execute($params); } catch (\PDOException $e) { error_log('[SakayTa dispatch PDO] nearestEligibleDriver SELECT failed code='. $e->getCode() .' message='. $e->getMessage()); throw $e; }
        $row = $stmt->fetch();
        return $row ? $row : null;
    }

    /** Offer ride to nearest eligible driver; create notification; keep pending offer (no driverId yet). */
    public function offerRide(string $rideId, float $pickupLat, float $pickupLng, ?string $excludeDriverId = null): array
    {
        $ride = $this->findRideByIdInternal($rideId);
        $driver = $this->nearestEligibleDriver($pickupLat, $pickupLng, $excludeDriverId);
        if (!$driver) {
            throw new RideException('No eligible verified available driver found.', 404);
        }
        $pdo = Database::connection();
        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 seconds'));
        try {
            $pdo->prepare("UPDATE rides SET requestedDriver = :did, requestStatus = 'pending', requestCreatedAt = :now1, requestExpiresAt = :expires, updatedAt = :now2 WHERE id = :rid")
                ->execute(['did' => $driver['driver_id'], 'now1' => $now, 'expires' => $expiresAt, 'now2' => $now, 'rid' => $rideId]);
        } catch (\PDOException $e) { error_log('[SakayTa dispatch PDO] offerRide UPDATE failed code='. $e->getCode() .' message='. $e->getMessage()); throw $e; }
        $this->createNotification($driver['user_id'], $rideId, 'ride_request',
            'New ride request', 'You have a new ride request near you.');
        return $this->findRideByIdInternal($rideId);
    }

    /** Internal ride lookup by ID only (service-level, no passenger ownership check). */
    private function findRideByIdInternal(string $rideId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT id, passengerName, rideType, pickupLat, pickupLng, dropoffLat, dropoffLng,
                    driverId, status, requestedDriver, requestStatus, requestCreatedAt,
                    requestExpiresAt, distanceKm, fareEstimate, createdAt, updatedAt,
                    startedAt, completedAt, cancelledAt, userId
             FROM rides WHERE id = :id LIMIT 1'
        );
        try { $stmt->execute(['id' => $rideId]); } catch (\PDOException $e) { error_log('[SakayTa dispatch PDO] offerRide lookup failed code='. $e->getCode() .' message='. $e->getMessage()); throw $e; }
        $ride = $stmt->fetch();
        if (!$ride) {
            throw new RideException('Ride not found.', 404);
        }
        return $ride;
    }

    private function createNotification(string $userId, ?string $rideId, string $type, string $title, string $message): void
    {
        $pdo = Database::connection();
        $id = 'n_' . time() . '_' . substr(bin2hex(random_bytes(9)), 0, 9);
        try {
            $pdo->prepare('INSERT INTO notifications (id, userId, rideId, type, title, message, `read`, createdAt) VALUES (:id, :uid, :rid, :type, :title, :msg, 0, :now)')
                ->execute(['id' => $id, 'uid' => $userId, 'rid' => $rideId, 'type' => $type, 'title' => $title, 'msg' => $message, 'now' => date('Y-m-d H:i:s')]);
        } catch (\PDOException $e) { error_log('[SakayTa dispatch PDO] createNotification INSERT failed code='. $e->getCode() .' message='. $e->getMessage()); throw $e; }
    }
    /** @return array<string,mixed> */
    public function createRide(
        string $userId,
        string $passengerName,
        string $rideType,
        float $pickupLat,
        float $pickupLng,
        float $dropoffLat,
        float $dropoffLng,
    ): array {
        $pdo = Database::connection();
        $distanceKm = $this->estimateDistanceKm($pickupLat, $pickupLng, $dropoffLat, $dropoffLng);
        $fareEstimate = $this->estimateFare($distanceKm);
        $id = 'r_' . time() . '_' . substr(bin2hex(random_bytes(9)), 0, 9);
        $now = date('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO rides (id, passengerName, rideType, pickupLat, pickupLng, dropoffLat,
                                    dropoffLng, status, distanceKm, fareEstimate, createdAt, updatedAt, userId)
                 VALUES (:id, :passengerName, :rideType, :pickupLat, :pickupLng, :dropoffLat,
                         :dropoffLng, :status, :distanceKm, :fareEstimate, :createdAt, :updatedAt, :userId)'
            )->execute([
                'id'            => $id,
                'passengerName' => $passengerName,
                'rideType'      => $rideType,
                'pickupLat'     => $pickupLat,
                'pickupLng'     => $pickupLng,
                'dropoffLat'    => $dropoffLat,
                'dropoffLng'    => $dropoffLng,
                'status'        => 'pending',
                'distanceKm'    => $distanceKm,
                'fareEstimate'  => $fareEstimate,
                'createdAt'     => $now,
                'updatedAt'     => $now,
                'userId'        => $userId,
            ]);

            // Read ownership back from the persisted ride; never derive the recipient
            // from request data or guess an owner for legacy/null-owned rides.
            $ride = $this->findById($userId, $id);
            $passengerUserId = $ride['userId'] ?? null;
            if (!is_string($passengerUserId) || trim($passengerUserId) === '') {
                throw new \RuntimeException('Created ride has no passenger owner.');
            }

            $this->createNotification(
                $passengerUserId,
                $id,
                'ride_booked',
                'Ride request received',
                'Your ride request has been submitted.'
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Existing dispatch: trigger offer mechanism if eligible driver exists
        try {
            $driver = $this->nearestEligibleDriver($pickupLat, $pickupLng);
            if ($driver) {
                $this->offerRide($ride['id'], $pickupLat, $pickupLng);
            }
        } catch (\Exception $e) {
            // Preserve ride creation even if no eligible driver or offer fails
            error_log('[SakayTa dispatch] ride=' . $ride['id'] . ' exception=' . get_class($e) . ' message=' . $e->getMessage());
        }
        return $ride;
    }

    /**
     * Estimate distance and fare without creating a ride.
     * Used for pre-booking fare estimates (FR-05, FR-06, BR-02).
     *
     * @return array{distanceKm:float,baseFare:float,ratePerKm:float,fareEstimate:float,fareSource:string}
     */
    public function estimateRide(
        float $pickupLat,
        float $pickupLng,
        float $dropoffLat,
        float $dropoffLng
    ): array {
        // Validate coordinates before calculation
        if ($pickupLat < -90.0 || $pickupLat > 90.0 || $dropoffLat < -90.0 || $dropoffLat > 90.0) {
            throw new RideException('Latitude must be between -90 and 90.', 400);
        }
        if ($pickupLng < -180.0 || $pickupLng > 180.0 || $dropoffLng < -180.0 || $dropoffLng > 180.0) {
            throw new RideException('Longitude must be between -180 and 180.', 400);
        }

        $distanceKm = $this->estimateDistanceKm($pickupLat, $pickupLng, $dropoffLat, $dropoffLng);
        $cfg = $this->fareConfig();
        $fareEstimate = round($cfg['baseFare'] + $distanceKm * $cfg['ratePerKm']);

        return [
            'distanceKm'    => $distanceKm,
            'baseFare'      => $cfg['baseFare'],
            'ratePerKm'     => $cfg['ratePerKm'],
            'fareEstimate'  => $fareEstimate,
            'fareSource'    => $cfg['fareSource'],
        ];
    }

    // ------------------------------------------------------------- Read

    /**
     * @return array<int, array<string,mixed>>
     */
    public function getMyRides(string $userId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT id, passengerName, rideType, pickupLat, pickupLng, dropoffLat, dropoffLng,
                    driverId, status, distanceKm, fareEstimate, createdAt, updatedAt
             FROM rides WHERE userId = :uid ORDER BY createdAt DESC'
        );
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed> */
    public function getRideById(string $userId, string $rideId): array
    {
        $ride = $this->findById($userId, $rideId);
        return $ride;
    }

    // --------------------------------------------- Driver pending/active (read-only)

    /**
     * Current pending ride request for the authenticated driver.
     * READ-ONLY: resolves drivers.user_id -> drivers.id -> rides.requestedDriver.
     *
     * @return array<string,mixed>|null
     */
    public function getDriverPendingRide(string $userId): ?array
    {
        $driver = $this->getDriverProfile($userId);
        $driverId = (string) ($driver['id'] ?? '');
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT id, passengerName, rideType, pickupLat, pickupLng, dropoffLat, dropoffLng,
                    driverId, status, requestedDriver, requestStatus, requestCreatedAt,
                    requestExpiresAt, distanceKm, fareEstimate, createdAt, updatedAt,
                    startedAt, completedAt, cancelledAt, userId
             FROM rides
             WHERE requestedDriver = :did
               AND requestStatus = \'pending\'
               AND status = \'pending\'
               AND (requestExpiresAt IS NULL OR requestExpiresAt > NOW())
             ORDER BY requestCreatedAt DESC LIMIT 1'
        );
        $stmt->execute(['did' => $driverId]);
        $ride = $stmt->fetch();
        return $ride ? $ride : null;
    }

    /**
     * Driver's currently assigned/active ride.
     * READ-ONLY: resolves drivers.user_id -> drivers.id -> rides.driverId.
     *
     * @return array<string,mixed>|null
     */
    public function getDriverActiveRide(string $userId): ?array
    {
        $driver = $this->getDriverProfile($userId);
        $driverId = (string) ($driver['id'] ?? '');
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT id, passengerName, rideType, pickupLat, pickupLng, dropoffLat, dropoffLng,
                    driverId, status, requestedDriver, requestStatus, requestCreatedAt,
                    requestExpiresAt, distanceKm, fareEstimate, createdAt, updatedAt,
                    startedAt, completedAt, cancelledAt, userId
             FROM rides
             WHERE driverId = :did
               AND status IN (\'assigned\', \'en_route\')
             ORDER BY updatedAt DESC LIMIT 1'
        );
        $stmt->execute(['did' => $driverId]);
        $ride = $stmt->fetch();
        return $ride ? $ride : null;
    }

    // ----------------------------------------------------- Cancel (commuter)

    /**
     * Cancel own ride. Only allowed while pending or assigned.
     * @return array{message:string, ride:array<string,mixed>}
     */
    public function cancelRide(string $userId, string $rideId): array
    {
        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();

            $rideStmt = $pdo->prepare(
                'SELECT id, driverId, status
                 FROM rides WHERE id = :id AND userId = :uid LIMIT 1 FOR UPDATE'
            );
            $rideStmt->execute(['id' => $rideId, 'uid' => $userId]);
            $current = $rideStmt->fetch();
            if (!$current) {
                throw new RideException('Ride not found.', 404);
            }

            $status = (string) $current['status'];
            if (!in_array($status, self::CANCELLABLE, true)) {
                throw new RideException(
                    'Cannot cancel ride in ' . $status . ' state. Cancellation only allowed before the ride starts.',
                    409
                );
            }

            $driverId = null;
            $driverBusy = null;
            if ($status === 'assigned') {
                $driverId = (string) ($current['driverId'] ?? '');
                if ($driverId === '') {
                    throw new RideException('Assigned driver profile is unavailable.', 409);
                }

                $driverStmt = $pdo->prepare(
                    'SELECT id, busy FROM drivers WHERE id = :id LIMIT 1 FOR UPDATE'
                );
                $driverStmt->execute(['id' => $driverId]);
                $driver = $driverStmt->fetch();
                if (!$driver) {
                    throw new RideException('Assigned driver profile is unavailable.', 409);
                }
                $driverBusy = (int) $driver['busy'];
                if ($driverBusy !== 0 && $driverBusy !== 1) {
                    throw new RideException('Assigned driver busy state is invalid.', 409);
                }
            }

            $now = date('Y-m-d H:i:s');
            $cancelStmt = $pdo->prepare(
                "UPDATE rides
                 SET status = 'cancelled', cancelledAt = :cancelledAt, updatedAt = :updatedAt
                 WHERE id = :id AND userId = :uid AND status IN ('pending', 'assigned')"
            );
            $cancelStmt->execute([
                'cancelledAt' => $now,
                'updatedAt' => $now,
                'id' => $rideId,
                'uid' => $userId,
            ]);
            if ($cancelStmt->rowCount() !== 1) {
                throw new RideException('Ride is no longer available to cancel.', 409);
            }

            if ($driverId !== null && $driverBusy === 1) {
                $busyStmt = $pdo->prepare(
                    'UPDATE drivers SET busy = 0 WHERE id = :id AND busy = 1'
                );
                $busyStmt->execute(['id' => $driverId]);
                if ($busyStmt->rowCount() !== 1) {
                    throw new RideException('Assigned driver busy state could not be released.', 409);
                }
            }

            $updatedRide = $this->findById($userId, $rideId);
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RideException('Failed to cancel ride.', 500);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['message' => 'Ride cancelled successfully.', 'ride' => $updatedRide];
    }

    // ------------------------------------------------------- Legal transitions

    /**
     * Validate that a status transition is part of the documented lifecycle.
     * Used for future-proofing; not enforced on create (always 'pending').
     *
     * @return bool true if (from,to) is a legal foundation transition
     */
    public static function isLegalTransition(string $from, string $to): bool
    {
        $map = [
            'pending'   => ['assigned', 'en_route', 'cancelled'],
            'assigned'  => ['en_route', 'cancelled'],
            'en_route'  => ['completed'],
            'completed' => [],
            'cancelled' => [],
        ];
        return isset($map[$from]) && in_array($to, $map[$from], true);
    }

    // ---------------------------------------------------------------- Helpers

    private function estimateDistanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $R = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return round($R * 2 * atan2(sqrt($a), sqrt(1 - $a)), 4);
    }

    private function estimateFare(float $distanceKm): float
    {
        $cfg = $this->fareConfig();
        return round($cfg['baseFare'] + $distanceKm * $cfg['ratePerKm']);
    }

    /** @return array{baseFare:float,ratePerKm:float,fareSource:string} */
    private function fareConfig(): array
    {
        $pdo = Database::connection();
        $row = $pdo->query(
            'SELECT baseFare, ratePerKm FROM fare_config ORDER BY updatedAt DESC LIMIT 1'
        )->fetch();
        return $row
            ? ['baseFare' => (float) $row['baseFare'], 'ratePerKm' => (float) $row['ratePerKm'], 'fareSource' => 'global_config']
            : ['baseFare' => 15.0, 'ratePerKm' => 8.0, 'fareSource' => 'global_config'];
    }


    /** @return array<string,mixed> */
    private function findById(string $userId, string $rideId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT id, passengerName, rideType, pickupLat, pickupLng, dropoffLat, dropoffLng,
                    driverId, status, requestedDriver, requestStatus, requestCreatedAt,
                    requestExpiresAt, distanceKm, fareEstimate, createdAt, updatedAt,
                    startedAt, completedAt, cancelledAt, userId
             FROM rides WHERE id = :id AND userId = :uid LIMIT 1'
        );
        $stmt->execute(['id' => $rideId, 'uid' => $userId]);
        $ride = $stmt->fetch();
        if (!$ride) {
            throw new RideException('Ride not found.', 404);
        }
        return $ride;
    }

    /**
     * Accept the pending offer addressed to the authenticated driver.
     *
     * @return array{message:string,ride:array<string,mixed>}
     */
    public function acceptRide(string $userId, string $rideId): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $rideStmt = $pdo->prepare(
                'SELECT id, userId, status, requestedDriver, requestStatus, requestExpiresAt,
                        CASE WHEN requestExpiresAt IS NOT NULL AND requestExpiresAt > NOW() THEN 1 ELSE 0 END AS requestIsUnexpired
                 FROM rides WHERE id = :id LIMIT 1 FOR UPDATE'
            );
            $rideStmt->execute(['id' => $rideId]);
            $ride = $rideStmt->fetch();
            if (!$ride) {
                throw new RideException('Ride not found.', 404);
            }
            if ($ride['status'] !== 'pending' || $ride['requestStatus'] !== 'pending') {
                throw new RideException('Ride is no longer available.', 409);
            }

            $driverStmt = $pdo->prepare(
                'SELECT id, verified, licenseStatus, availability, busy
                 FROM drivers WHERE user_id = :uid LIMIT 1 FOR UPDATE'
            );
            $driverStmt->execute(['uid' => $userId]);
            $driver = $driverStmt->fetch();
            if (!$driver) {
                throw new RideException('Driver profile not found.', 404);
            }

            $driverId = (string) $driver['id'];
            if (($ride['requestedDriver'] ?? null) !== $driverId) {
                throw new RideException('Not offered to this driver.', 403);
            }
            if (!(bool) $ride['requestIsUnexpired']) {
                throw new RideException('This ride request has expired.', 410);
            }
            if ((int) $driver['verified'] !== 1 || $driver['licenseStatus'] !== 'verified' || $driver['availability'] !== 'available') {
                throw new RideException('Driver is no longer eligible for this ride.', 403);
            }
            if ((int) $driver['busy'] !== 0) {
                throw new RideException('Driver is already assigned to another ride.', 409);
            }

            $now = date('Y-m-d H:i:s');
            $acceptStmt = $pdo->prepare(
                "UPDATE rides
                 SET driverId = :driverId, status = 'assigned', requestStatus = 'accepted', updatedAt = :updatedAt
                 WHERE id = :id
                   AND status = 'pending'
                   AND requestStatus = 'pending'
                   AND requestedDriver = :requestedDriver
                   AND requestExpiresAt IS NOT NULL
                   AND requestExpiresAt > NOW()"
            );
            $acceptStmt->execute([
                'driverId' => $driverId,
                'updatedAt' => $now,
                'id' => $rideId,
                'requestedDriver' => $driverId,
            ]);
            if ($acceptStmt->rowCount() !== 1) {
                $stateStmt = $pdo->prepare(
                    'SELECT status, requestedDriver, requestStatus,
                            CASE WHEN requestExpiresAt IS NOT NULL AND requestExpiresAt > NOW() THEN 1 ELSE 0 END AS requestIsUnexpired
                     FROM rides WHERE id = :id LIMIT 1 FOR UPDATE'
                );
                $stateStmt->execute(['id' => $rideId]);
                $current = $stateStmt->fetch();
                if (!$current) {
                    throw new RideException('Ride not found.', 404);
                }
                if ($current['status'] === 'pending' && $current['requestStatus'] === 'pending'
                    && ($current['requestedDriver'] ?? null) === $driverId
                    && !(bool) $current['requestIsUnexpired']) {
                    throw new RideException('This ride request has expired.', 410);
                }
                if ($current['status'] === 'pending' && $current['requestStatus'] === 'pending'
                    && ($current['requestedDriver'] ?? null) !== $driverId) {
                    throw new RideException('Not offered to this driver.', 403);
                }
                throw new RideException('Ride is no longer available.', 409);
            }

            $busyStmt = $pdo->prepare('UPDATE drivers SET busy = 1 WHERE id = :id AND busy = 0');
            $busyStmt->execute(['id' => $driverId]);
            if ($busyStmt->rowCount() !== 1) {
                throw new RideException('Driver is already assigned to another ride.', 409);
            }

            $updatedRide = $this->findRideByIdInternal($rideId);
            $passengerUserId = $ride['userId'] ?? null;
            if (!is_string($passengerUserId) || trim($passengerUserId) === '') {
                throw new RideException('Passenger account is unavailable.', 409);
            }
            $this->createNotification(
                $passengerUserId,
                $rideId,
                'ride_assigned',
                'Driver assigned',
                'A driver accepted your ride request.'
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['message' => 'Ride accepted successfully.', 'ride' => $updatedRide];
    }

    /**
     * Start an assigned ride owned by the authenticated driver.
     *
     * @return array{message:string,ride:array<string,mixed>}
     */
    public function startRide(string $userId, string $rideId): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $rideStmt = $pdo->prepare(
                'SELECT id, userId, driverId, status
                 FROM rides WHERE id = :id LIMIT 1 FOR UPDATE'
            );
            $rideStmt->execute(['id' => $rideId]);
            $ride = $rideStmt->fetch();
            if (!$ride) {
                throw new RideException('Ride not found.', 404);
            }

            $driverStmt = $pdo->prepare(
                'SELECT id FROM drivers WHERE user_id = :uid LIMIT 1 FOR UPDATE'
            );
            $driverStmt->execute(['uid' => $userId]);
            $driver = $driverStmt->fetch();
            if (!$driver) {
                throw new RideException('Driver profile not found.', 404);
            }

            $driverId = (string) $driver['id'];
            if (($ride['driverId'] ?? null) !== $driverId) {
                throw new RideException('This ride is assigned to another driver.', 403);
            }
            if ($ride['status'] !== 'assigned') {
                throw new RideException('Ride is no longer available to start.', 409);
            }

            $now = date('Y-m-d H:i:s');
            $startStmt = $pdo->prepare(
                "UPDATE rides
                 SET status = 'en_route', startedAt = :startedAt, updatedAt = :updatedAt
                 WHERE id = :id AND driverId = :driverId AND status = 'assigned'"
            );
            $startStmt->execute([
                'startedAt' => $now,
                'updatedAt' => $now,
                'id' => $rideId,
                'driverId' => $driverId,
            ]);
            if ($startStmt->rowCount() !== 1) {
                throw new RideException('Ride is no longer available to start.', 409);
            }

            $updatedRide = $this->findRideByIdInternal($rideId);
            $passengerUserId = $ride['userId'] ?? null;
            if (!is_string($passengerUserId) || trim($passengerUserId) === '') {
                throw new RideException('Passenger account is unavailable.', 409);
            }
            $this->createNotification(
                $passengerUserId,
                $rideId,
                'ride_en_route',
                'Ride started',
                'Your ride is now en route.'
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['message' => 'Ride started successfully.', 'ride' => $updatedRide];
    }

    /**
     * Complete an en_route ride owned by the authenticated driver and release busy state.
     *
     * @return array{message:string,ride:array<string,mixed>}
     */
    public function completeRide(string $userId, string $rideId): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            // Keep the same ride-then-driver lock order used by acceptRide() and startRide().
            $rideStmt = $pdo->prepare(
                'SELECT id, userId, driverId, status
                 FROM rides WHERE id = :id LIMIT 1 FOR UPDATE'
            );
            $rideStmt->execute(['id' => $rideId]);
            $ride = $rideStmt->fetch();
            if (!$ride) {
                throw new RideException('Ride not found.', 404);
            }

            $driverStmt = $pdo->prepare(
                'SELECT id FROM drivers WHERE user_id = :uid LIMIT 1 FOR UPDATE'
            );
            $driverStmt->execute(['uid' => $userId]);
            $driver = $driverStmt->fetch();
            if (!$driver) {
                throw new RideException('Driver profile not found.', 404);
            }

            $driverId = (string) $driver['id'];
            if (($ride['driverId'] ?? null) !== $driverId) {
                throw new RideException('This ride is assigned to another driver.', 403);
            }
            if ($ride['status'] !== 'en_route') {
                throw new RideException('Ride is no longer available to complete.', 409);
            }

            $now = date('Y-m-d H:i:s');
            $completeStmt = $pdo->prepare(
                "UPDATE rides
                 SET status = 'completed', completedAt = :completedAt, updatedAt = :updatedAt
                 WHERE id = :id AND driverId = :driverId AND status = 'en_route'"
            );
            $completeStmt->execute([
                'completedAt' => $now,
                'updatedAt' => $now,
                'id' => $rideId,
                'driverId' => $driverId,
            ]);
            if ($completeStmt->rowCount() !== 1) {
                throw new RideException('Ride is no longer available to complete.', 409);
            }

            $busyStmt = $pdo->prepare(
                'UPDATE drivers SET busy = 0 WHERE id = :id AND busy = 1'
            );
            $busyStmt->execute(['id' => $driverId]);
            if ($busyStmt->rowCount() !== 1) {
                throw new RideException('Driver busy state could not be released.', 409);
            }

            $updatedRide = $this->findRideByIdInternal($rideId);
            $passengerUserId = $ride['userId'] ?? null;
            if (!is_string($passengerUserId) || trim($passengerUserId) === '') {
                throw new RideException('Passenger account is unavailable.', 409);
            }
            $this->createNotification(
                $passengerUserId,
                $rideId,
                'ride_completed',
                'Ride completed',
                'Your ride has been completed.'
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['message' => 'Ride completed successfully.', 'ride' => $updatedRide];
    }

    /** @return array<string,mixed> */
    public function declineRide(string $userId, string $rideId): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $driver = $this->getDriverProfile($userId);
            $driverId = (string) ($driver['id'] ?? '');
            $stmt = $pdo->prepare('SELECT id, userId, status, requestedDriver, requestStatus, requestExpiresAt, pickupLat, pickupLng FROM rides WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute(['id' => $rideId]);
            $ride = $stmt->fetch();
            if (!$ride) { throw new RideException('Ride not found.', 404); }
            if ($ride['status'] !== 'pending') { throw new RideException('Ride not available.', 409); }
            if (($ride['requestedDriver'] ?? null) !== $driverId) { throw new RideException('Not offered to this driver.', 403); }
            if (($ride['requestStatus'] ?? null) !== 'pending') { throw new RideException('Request already processed.', 409); }
            $declineStmt = $pdo->prepare("UPDATE rides SET requestedDriver = NULL, requestStatus = 'declined', updatedAt = :n WHERE id = :id AND requestedDriver = :driverId AND status = 'pending' AND requestStatus = 'pending'");
            $declineStmt->execute(['n' => date('Y-m-d H:i:s'), 'id' => $rideId, 'driverId' => $driverId]);
            if ($declineStmt->rowCount() !== 1) {
                throw new RideException('Ride offer is no longer available.', 409);
            }

            $passengerUserId = $ride['userId'] ?? null;
            if (!is_string($passengerUserId) || trim($passengerUserId) === '') {
                throw new RideException('Passenger account is unavailable.', 409);
            }
            $this->createNotification(
                $passengerUserId,
                $rideId,
                'ride_driver_declined',
                'Driver update',
                'A driver declined your ride request.'
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        try {
            $ride = $this->findRideByIdInternal($rideId);
            if (($ride['pickupLat'] ?? null) && ($ride['pickupLng'] ?? null)) {
                $this->offerRide($rideId, (float) $ride['pickupLat'], (float) $ride['pickupLng'], $driverId);
            }
        } catch (\Exception $e) {
            // Re-offer best-effort; preserve decline result
        }
        $this->createNotification($userId, $rideId, 'ride_declined', 'Ride declined', 'Your ride request was declined; searching for another driver.');
        return ['message' => 'Ride declined; request sent to next driver.', 'ride' => $this->findRideByIdInternal($rideId)];
    }

    private function getDriverProfile(string $userId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM drivers WHERE user_id = :uid LIMIT 1');
        $stmt->execute(['uid' => $userId]);
        $driver = $stmt->fetch();
        if (!$driver) {
            throw new RideException('Driver profile not found.', 404);
        }
        return $driver;
    }
}
