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
    private function nearestEligibleDriver(?string $rideId, float $pickupLat, float $pickupLng): ?array
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
        $params = ['lat1' => $pickupLat, 'lng1' => $pickupLng, 'lat2' => $pickupLat];
        if ($rideId !== null) {
            $sql .= " AND NOT EXISTS (
                          SELECT 1 FROM ride_offer_attempts a
                          WHERE a.rideId = :rideId AND a.driverId = d.id
                      )";
            $params['rideId'] = $rideId;
        }
        $sql .= " ORDER BY (6371 * ACOS(COS(RADIANS(:lat1)) * COS(RADIANS(d.latitude)) * COS(RADIANS(d.longitude) - RADIANS(:lng1)) + SIN(RADIANS(:lat2)) * SIN(RADIANS(d.latitude)))) ASC,
                          d.id ASC
                  LIMIT 1 FOR UPDATE";
        $stmt = $pdo->prepare($sql);
        try { $stmt->execute($params); } catch (\PDOException $e) { error_log('[SakayTa dispatch PDO] nearestEligibleDriver SELECT failed code='. $e->getCode() .' message='. $e->getMessage()); throw $e; }
        $row = $stmt->fetch();
        return $row ? $row : null;
    }

    /** Lock or initialize the durable dispatch row. */
    private function lockDispatchState(PDO $pdo, string $rideId, string $now): array
    {
        $pdo->prepare(
            "INSERT IGNORE INTO ride_dispatch_state (rideId, dispatchStatus, attemptCount, updatedAt)
             VALUES (:rideId, 'queued', 0, :updatedAt)"
        )->execute(['rideId' => $rideId, 'updatedAt' => $now]);

        $stmt = $pdo->prepare('SELECT rideId, dispatchStatus, nextAttemptAt, attemptCount, lastAttemptAt, lastErrorCode, updatedAt FROM ride_dispatch_state WHERE rideId = :rideId LIMIT 1 FOR UPDATE');
        $stmt->execute(['rideId' => $rideId]);
        $state = $stmt->fetch();
        if (!$state) {
            throw new \RuntimeException('Ride dispatch state could not be locked.');
        }
        return $state;
    }

    private function lockActiveOffer(PDO $pdo, string $rideId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT offerId, rideId, driverId, offeredAt, expiresAt, outcome, respondedAt, activeSlot
             FROM ride_offer_attempts
             WHERE rideId = :rideId AND activeSlot = 1
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['rideId' => $rideId]);
        $attempt = $stmt->fetch();
        return $attempt === false ? null : $attempt;
    }

    private function dispatchHistoryAvailable(PDO $pdo): bool
    {
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ('ride_dispatch_state', 'ride_offer_attempts')"
        );
        return (int) $stmt->fetchColumn() === 2;
    }

    private function isValidDatabaseDateTime(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/D', $value, $parts)) {
            return false;
        }
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            && (int) $parts[4] <= 23
            && (int) $parts[5] <= 59
            && (int) $parts[6] <= 59;
    }

    /** @return array{outcome:string,message:string,ride:array<string,mixed>,dispatchStatus:string} */
    private function stopDispatchWithoutDriver(PDO $pdo, array $ride, string $now, string $reason): array
    {
        $rideId = (string) $ride['id'];
        $pdo->prepare(
            "UPDATE rides
             SET requestedDriver = NULL, requestStatus = NULL, requestCreatedAt = NULL,
                 requestExpiresAt = NULL, updatedAt = :updatedAt
             WHERE id = :id AND status = 'pending' AND driverId IS NULL"
        )->execute(['updatedAt' => $now, 'id' => $rideId]);
        $pdo->prepare(
            "UPDATE ride_dispatch_state
             SET dispatchStatus = 'stopped', nextAttemptAt = NULL, lastAttemptAt = :lastAttemptAt,
                 lastErrorCode = :reason, updatedAt = :updatedAt
             WHERE rideId = :rideId AND dispatchStatus NOT IN ('stopped', 'manual_review')"
        )->execute(['lastAttemptAt' => $now, 'reason' => $reason, 'updatedAt' => $now, 'rideId' => $rideId]);

        $passengerUserId = $ride['userId'] ?? null;
        if (is_string($passengerUserId) && trim($passengerUserId) !== '') {
            $this->createNotification(
                $passengerUserId,
                $rideId,
                'ride_dispatch_stopped',
                'No driver available',
                'We could not assign a driver automatically. Please cancel the ride or arrange manual follow-up.'
            );
        }
        return [
            'outcome' => 'exhausted',
            'message' => 'No untried eligible driver remains. The ride needs cancellation or manual follow-up.',
            'ride' => $this->findRideByIdInternal($rideId),
            'dispatchStatus' => 'stopped',
        ];
    }

    /** @return array{outcome:string,message:string,ride:array<string,mixed>,dispatchStatus:string} */
    private function markDispatchManualReview(PDO $pdo, array $ride, ?array $attempt, string $now, string $reason): array
    {
        $rideId = (string) $ride['id'];
        if ($attempt !== null && ($attempt['outcome'] ?? null) === 'pending' && (int) ($attempt['activeSlot'] ?? 0) === 1) {
            $closeAttempt = $pdo->prepare(
                "UPDATE ride_offer_attempts
                 SET outcome = 'superseded', respondedAt = :respondedAt, activeSlot = NULL
                 WHERE offerId = :offerId AND outcome = 'pending' AND activeSlot = 1"
            );
            $closeAttempt->execute(['respondedAt' => $now, 'offerId' => $attempt['offerId']]);
            if ($closeAttempt->rowCount() !== 1) {
                throw new RideException('Invalid active offer could not be closed.', 409);
            }
        }
        $pdo->prepare(
            "UPDATE rides
             SET requestedDriver = NULL, requestStatus = NULL, requestCreatedAt = NULL,
                 requestExpiresAt = NULL, updatedAt = :updatedAt
             WHERE id = :id AND status = 'pending' AND driverId IS NULL"
        )->execute(['updatedAt' => $now, 'id' => $rideId]);
        $pdo->prepare(
            "UPDATE ride_dispatch_state
             SET dispatchStatus = 'manual_review', nextAttemptAt = NULL, lastAttemptAt = :lastAttemptAt,
                 lastErrorCode = :reason, updatedAt = :updatedAt
             WHERE rideId = :rideId"
        )->execute(['lastAttemptAt' => $now, 'reason' => $reason, 'updatedAt' => $now, 'rideId' => $rideId]);

        $passengerUserId = $ride['userId'] ?? null;
        if (is_string($passengerUserId) && trim($passengerUserId) !== '') {
            $this->createNotification(
                $passengerUserId,
                $rideId,
                'ride_dispatch_stopped',
                'Ride needs follow-up',
                'We could not safely continue automatic driver assignment. Please cancel the ride or arrange manual follow-up.'
            );
        }
        return [
            'outcome' => 'manual_review',
            'message' => 'Automatic dispatch needs manual follow-up.',
            'ride' => $this->findRideByIdInternal($rideId),
            'dispatchStatus' => 'manual_review',
        ];
    }

    /** @return array{outcome:string,message:string,ride:array<string,mixed>,dispatchStatus:string} */
    private function createNextOfferWithinTransaction(PDO $pdo, array $ride, array $state, string $now): array
    {
        $rideId = (string) $ride['id'];
        if (!is_numeric($ride['pickupLat'] ?? null) || !is_numeric($ride['pickupLng'] ?? null)) {
            return $this->stopDispatchWithoutDriver($pdo, $ride, $now, 'pickup_unavailable');
        }

        $driver = $this->nearestEligibleDriver($rideId, (float) $ride['pickupLat'], (float) $ride['pickupLng']);
        if ($driver === null) {
            return $this->stopDispatchWithoutDriver($pdo, $ride, $now, 'no_untried_driver');
        }

        $offerId = 'o_' . time() . '_' . substr(bin2hex(random_bytes(9)), 0, 9);
        $expiresAt = date('Y-m-d H:i:s', strtotime($now . ' +30 seconds'));
        $pdo->prepare(
            "INSERT INTO ride_offer_attempts
                (offerId, rideId, driverId, offeredAt, expiresAt, outcome, respondedAt, activeSlot)
             VALUES (:offerId, :rideId, :driverId, :offeredAt, :expiresAt, 'pending', NULL, 1)"
        )->execute([
            'offerId' => $offerId,
            'rideId' => $rideId,
            'driverId' => $driver['driver_id'],
            'offeredAt' => $now,
            'expiresAt' => $expiresAt,
        ]);

        $rideUpdate = $pdo->prepare(
            "UPDATE rides
             SET requestedDriver = :driverId, requestStatus = 'pending', requestCreatedAt = :createdAt,
                 requestExpiresAt = :expiresAt, updatedAt = :updatedAt
             WHERE id = :rideId AND status = 'pending' AND driverId IS NULL AND requestedDriver IS NULL"
        );
        $rideUpdate->execute([
            'driverId' => $driver['driver_id'],
            'createdAt' => $now,
            'expiresAt' => $expiresAt,
            'updatedAt' => $now,
            'rideId' => $rideId,
        ]);
        if ($rideUpdate->rowCount() !== 1) {
            throw new RideException('Ride is no longer available for dispatch.', 409);
        }

        $stateUpdate = $pdo->prepare(
            "UPDATE ride_dispatch_state
             SET dispatchStatus = 'offered', nextAttemptAt = NULL, attemptCount = attemptCount + 1,
                 lastAttemptAt = :lastAttemptAt, lastErrorCode = NULL, updatedAt = :updatedAt
             WHERE rideId = :rideId AND dispatchStatus NOT IN ('stopped', 'manual_review')"
        );
        $stateUpdate->execute(['lastAttemptAt' => $now, 'updatedAt' => $now, 'rideId' => $rideId]);
        if ($stateUpdate->rowCount() !== 1) {
            throw new RideException('Ride dispatch state is no longer eligible.', 409);
        }

        $this->createNotification(
            (string) $driver['user_id'],
            $rideId,
            'ride_request',
            'New ride request',
            'You have a new ride request near you.',
            $offerId
        );
        return [
            'outcome' => 'reassigned',
            'message' => 'Ride request sent to the next eligible driver.',
            'ride' => $this->findRideByIdInternal($rideId),
            'dispatchStatus' => 'offered',
        ];
    }

    /** Persist an initial offer transactionally; repeated calls are idempotent. */
    public function offerRide(string $rideId): array
    {
        $pdo = Database::connection();
        if (!$this->dispatchHistoryAvailable($pdo)) {
            return $this->offerInitialRideWithoutHistory($pdo, $rideId);
        }
        $pdo->beginTransaction();
        try {
            $rideStmt = $pdo->prepare(
                'SELECT id, userId, status, driverId, requestedDriver, requestStatus, requestExpiresAt,
                        pickupLat, pickupLng
                 FROM rides WHERE id = :id LIMIT 1 FOR UPDATE'
            );
            $rideStmt->execute(['id' => $rideId]);
            $ride = $rideStmt->fetch();
            if (!$ride) {
                throw new RideException('Ride not found.', 404);
            }
            if ($ride['status'] !== 'pending' || $ride['driverId'] !== null) {
                throw new RideException('Ride is no longer eligible for dispatch.', 409);
            }

            $now = date('Y-m-d H:i:s');
            $state = $this->lockDispatchState($pdo, $rideId, $now);
            if (in_array($state['dispatchStatus'], ['stopped', 'manual_review'], true)) {
                $pdo->commit();
                return $this->findRideByIdInternal($rideId);
            }
            $attempt = $this->lockActiveOffer($pdo, $rideId);
            if ($attempt !== null) {
                if (($attempt['outcome'] ?? null) === 'pending'
                    && ($ride['requestedDriver'] ?? null) === $attempt['driverId']
                    && $ride['requestStatus'] === 'pending') {
                    $pdo->commit();
                    return $this->findRideByIdInternal($rideId);
                }
                $result = $this->markDispatchManualReview($pdo, $ride, $attempt, $now, 'active_offer_mismatch');
            } elseif (($ride['requestedDriver'] ?? null) !== null || $ride['requestStatus'] === 'pending') {
                $result = $this->markDispatchManualReview($pdo, $ride, null, $now, 'offer_history_missing');
            } else {
                $result = $this->createNextOfferWithinTransaction($pdo, $ride, $state, $now);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $result['ride'];
    }

    /** Initial offer fallback for installations without dispatch-history tables. */
    private function offerInitialRideWithoutHistory(PDO $pdo, string $rideId): array
    {
        $pdo->beginTransaction();
        try {
            $rideStmt = $pdo->prepare(
                'SELECT id, status, driverId, requestedDriver, requestStatus, requestExpiresAt, pickupLat, pickupLng
                 FROM rides WHERE id = :id LIMIT 1 FOR UPDATE'
            );
            $rideStmt->execute(['id' => $rideId]);
            $ride = $rideStmt->fetch();
            if (!$ride) {
                throw new RideException('Ride not found.', 404);
            }
            if ($ride['status'] !== 'pending' || $ride['driverId'] !== null) {
                throw new RideException('Ride is no longer eligible for dispatch.', 409);
            }
            if (($ride['requestedDriver'] ?? null) !== null || ($ride['requestStatus'] ?? null) !== null) {
                throw new RideException('Ride already has an offer or requires manual review.', 409);
            }
            if (!is_numeric($ride['pickupLat'] ?? null) || !is_numeric($ride['pickupLng'] ?? null)) {
                throw new RideException('Ride pickup coordinates are unavailable.', 409);
            }

            $driver = $this->nearestEligibleDriver(null, (float) $ride['pickupLat'], (float) $ride['pickupLng']);
            if ($driver === null) {
                throw new RideException('No eligible verified available driver found.', 404);
            }

            $now = date('Y-m-d H:i:s');
            $expiresAt = date('Y-m-d H:i:s', strtotime($now . ' +30 seconds'));
            $offerStmt = $pdo->prepare(
                "UPDATE rides
                 SET requestedDriver = :driverId, requestStatus = 'pending', requestCreatedAt = :createdAt,
                     requestExpiresAt = :expiresAt, updatedAt = :updatedAt
                 WHERE id = :id AND status = 'pending' AND driverId IS NULL
                   AND requestedDriver IS NULL AND requestStatus IS NULL"
            );
            $offerStmt->execute([
                'driverId' => $driver['driver_id'],
                'createdAt' => $now,
                'expiresAt' => $expiresAt,
                'updatedAt' => $now,
                'id' => $rideId,
            ]);
            if ($offerStmt->rowCount() !== 1) {
                throw new RideException('Ride is no longer available for dispatch.', 409);
            }

            $this->createNotification(
                (string) $driver['user_id'],
                $rideId,
                'ride_request',
                'New ride request',
                'You have a new ride request near you.'
            );
            $updatedRide = $this->findRideByIdInternal($rideId);
            $pdo->commit();
            return $updatedRide;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Process a bounded batch of expired and invalid pending offers. */
    public function processExpiredOffers(int $batchSize = 100): array
    {
        if ($batchSize < 1 || $batchSize > 500) {
            throw new \InvalidArgumentException('Batch size must be between 1 and 500.');
        }
        $pdo = Database::connection();
        if ($this->dispatchHistoryAvailable($pdo)) {
            $stmt = $pdo->query(
                "SELECT r.id
                 FROM rides r
                 LEFT JOIN ride_dispatch_state s ON s.rideId = r.id
                 LEFT JOIN ride_offer_attempts a ON a.rideId = r.id AND a.activeSlot = 1
                 WHERE r.status = 'pending' AND r.requestStatus = 'pending'
                   AND (r.requestExpiresAt IS NULL OR r.requestExpiresAt <= NOW()
                        OR a.expiresAt IS NULL OR a.expiresAt <= NOW())
                   AND (s.rideId IS NULL OR s.dispatchStatus NOT IN ('stopped', 'manual_review'))
                 ORDER BY COALESCE(r.requestExpiresAt, a.expiresAt, r.requestCreatedAt, r.updatedAt, r.createdAt), r.id
                 LIMIT " . $batchSize
            );
        } else {
            $stmt = $pdo->query(
                "SELECT id FROM rides
                 WHERE status = 'pending' AND requestStatus = 'pending'
                   AND requestExpiresAt IS NOT NULL AND requestExpiresAt <= NOW()
                 ORDER BY requestExpiresAt, id LIMIT " . $batchSize
            );
        }
        $rideIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $summary = ['examined' => count($rideIds), 'reassigned' => 0, 'exhausted' => 0, 'manual_review' => 0, 'ignored' => 0, 'failed' => 0];
        foreach ($rideIds as $rideId) {
            try {
                $outcome = $this->processOneExpiredOffer((string) $rideId);
                if (isset($summary[$outcome])) {
                    $summary[$outcome]++;
                } else {
                    $summary['ignored']++;
                }
            } catch (\Throwable $e) {
                $summary['failed']++;
                error_log('[SakayTa expired-offer processor] ride=' . (string) $rideId . ' error=' . get_class($e) . ' code=' . (string) $e->getCode());
            }
        }
        return $summary;
    }

    /** Expire the authenticated driver's current offer, if MariaDB says it is due. */
    public function expireRideOffer(string $userId, string $rideId): array
    {
        $driver = $this->getDriverProfile($userId);
        $driverId = (string) ($driver['id'] ?? '');
        if ($driverId === '') {
            throw new RideException('Driver profile not found.', 404);
        }
        $outcome = $this->processOneExpiredOffer($rideId, $driverId);
        $details = match ($outcome) {
            'reassigned' => ['message' => 'The expired offer was sent to the next eligible driver.', 'dispatchStatus' => 'offered'],
            'exhausted' => ['message' => 'The offer expired. No other eligible driver was found; manual follow-up is required.', 'dispatchStatus' => 'stopped'],
            'manual_review' => ['message' => 'The offer expired, but dispatch needs manual follow-up.', 'dispatchStatus' => 'manual_review'],
            default => throw new RideException('Ride offer is no longer available.', 409),
        };
        return $details + ['outcome' => $outcome, 'ride' => $this->findRideByIdInternal($rideId)];
    }

    /** @return array{outcome:string,message:string,ride:array<string,mixed>,dispatchStatus:string} */
    private function expireCurrentOfferWithinTransaction(PDO $pdo, array $ride, array $state, array $attempt, string $now): array
    {
        $rideId = (string) $ride['id'];
        $expireAttempt = $pdo->prepare(
            "UPDATE ride_offer_attempts
             SET outcome = 'expired', respondedAt = :respondedAt, activeSlot = NULL
             WHERE offerId = :offerId AND outcome = 'pending' AND activeSlot = 1"
        );
        $expireAttempt->execute(['respondedAt' => $now, 'offerId' => $attempt['offerId']]);
        if ($expireAttempt->rowCount() !== 1) {
            throw new RideException('Expired offer was already processed.', 409);
        }

        $clearRide = $pdo->prepare(
            "UPDATE rides
             SET requestedDriver = NULL, requestStatus = NULL, requestCreatedAt = NULL,
                 requestExpiresAt = NULL, updatedAt = :updatedAt
             WHERE id = :id AND status = 'pending' AND requestStatus = 'pending'
               AND requestedDriver = :driverId AND requestExpiresAt <= NOW()"
        );
        $clearRide->execute(['updatedAt' => $now, 'id' => $rideId, 'driverId' => $attempt['driverId']]);
        if ($clearRide->rowCount() !== 1) {
            throw new RideException('Expired ride offer changed during processing.', 409);
        }

        $ride['requestedDriver'] = null;
        $ride['requestStatus'] = null;
        $ride['requestCreatedAt'] = null;
        $ride['requestExpiresAt'] = null;
        return $this->createNextOfferWithinTransaction($pdo, $ride, $state, $now);
    }

    private function processOneExpiredOffer(string $rideId, ?string $expectedDriverId = null): string
    {
        $pdo = Database::connection();
        if (!$this->dispatchHistoryAvailable($pdo)) {
            return $this->expireOneOfferWithoutDispatchHistory($pdo, $rideId, $expectedDriverId);
        }
        $pdo->beginTransaction();
        try {
            $rideStmt = $pdo->prepare(
                'SELECT id, userId, status, driverId, requestedDriver, requestStatus, requestCreatedAt,
                        requestExpiresAt, pickupLat, pickupLng
                 FROM rides WHERE id = :id LIMIT 1 FOR UPDATE'
            );
            $rideStmt->execute(['id' => $rideId]);
            $ride = $rideStmt->fetch();
            if (!$ride) {
                if ($expectedDriverId !== null) {
                    throw new RideException('Ride not found.', 404);
                }
                $pdo->commit();
                return 'ignored';
            }
            if ($expectedDriverId !== null && ($ride['requestedDriver'] ?? null) !== $expectedDriverId) {
                throw new RideException('Ride offer is no longer assigned to this driver.', 409);
            }
            if ($ride['status'] !== 'pending' || $ride['requestStatus'] !== 'pending' || $ride['driverId'] !== null) {
                if ($expectedDriverId !== null) {
                    throw new RideException('Ride is no longer available for expiration.', 409);
                }
                $pdo->commit();
                return 'ignored';
            }
            $now = date('Y-m-d H:i:s');
            $state = $this->lockDispatchState($pdo, $rideId, $now);
            if (in_array($state['dispatchStatus'], ['stopped', 'manual_review'], true)) {
                $pdo->commit();
                return 'ignored';
            }
            $attempt = $this->lockActiveOffer($pdo, $rideId);
            if ($attempt === null
                || ($attempt['outcome'] ?? null) !== 'pending'
                || (int) ($attempt['activeSlot'] ?? 0) !== 1
                || ($ride['requestedDriver'] ?? null) !== ($attempt['driverId'] ?? null)) {
                if ($expectedDriverId !== null) {
                    throw new RideException('Active ride offer could not be verified.', 409);
                }
                $result = $this->markDispatchManualReview($pdo, $ride, $attempt, $now, 'offer_history_mismatch');
                $pdo->commit();
                return $result['outcome'];
            }
            if ($expectedDriverId !== null && ($attempt['driverId'] ?? null) !== $expectedDriverId) {
                throw new RideException('Ride offer is no longer assigned to this driver.', 409);
            }

            $dbNow = (string) $pdo->query('SELECT NOW()')->fetchColumn();
            $rideExpiry = $ride['requestExpiresAt'] ?? null;
            $attemptExpiry = $attempt['expiresAt'] ?? null;
            if (!$this->isValidDatabaseDateTime($rideExpiry) || !$this->isValidDatabaseDateTime($attemptExpiry)) {
                if ($expectedDriverId !== null) {
                    throw new RideException('Ride offer expiry is invalid.', 409);
                }
                $result = $this->markDispatchManualReview($pdo, $ride, $attempt, $now, 'invalid_expiry');
                $pdo->commit();
                return $result['outcome'];
            }
            $rideExpired = $rideExpiry <= $dbNow;
            $attemptExpired = $attemptExpiry <= $dbNow;
            if ($rideExpired !== $attemptExpired) {
                if ($expectedDriverId !== null) {
                    throw new RideException('Ride offer expiry metadata does not match.', 409);
                }
                $result = $this->markDispatchManualReview($pdo, $ride, $attempt, $now, 'expiry_mismatch');
                $pdo->commit();
                return $result['outcome'];
            }
            if (!$rideExpired) {
                if ($expectedDriverId !== null) {
                    throw new RideException('Ride offer has not expired yet.', 409);
                }
                $pdo->commit();
                return 'ignored';
            }
            $result = $this->expireCurrentOfferWithinTransaction($pdo, $ride, $state, $attempt, $now);
            $pdo->commit();
            return $result['outcome'];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Expiry fallback for installations without the optional dispatch-history tables. */
    private function expireOneOfferWithoutDispatchHistory(PDO $pdo, string $rideId, ?string $expectedDriverId): string
    {
        $pdo->beginTransaction();
        try {
            $rideStmt = $pdo->prepare(
                'SELECT id, userId, status, driverId, requestedDriver, requestStatus, requestExpiresAt, pickupLat, pickupLng
                 FROM rides WHERE id = :id LIMIT 1 FOR UPDATE'
            );
            $rideStmt->execute(['id' => $rideId]);
            $ride = $rideStmt->fetch();
            if (!$ride) {
                throw new RideException('Ride not found.', 404);
            }
            if ($expectedDriverId !== null && ($ride['requestedDriver'] ?? null) !== $expectedDriverId) {
                throw new RideException('Ride offer is no longer assigned to this driver.', 409);
            }
            if ($ride['status'] !== 'pending' || $ride['requestStatus'] !== 'pending' || $ride['driverId'] !== null) {
                throw new RideException('Ride is no longer available for expiration.', 409);
            }
            if (!$this->isValidDatabaseDateTime($ride['requestExpiresAt'] ?? null)) {
                throw new RideException('Ride offer expiry is invalid.', 409);
            }
            $dbNow = (string) $pdo->query('SELECT NOW()')->fetchColumn();
            if ((string) $ride['requestExpiresAt'] > $dbNow) {
                throw new RideException('Ride offer has not expired yet.', 409);
            }
            $expiredDriverId = (string) ($ride['requestedDriver'] ?? '');
            if ($expiredDriverId === '') {
                throw new RideException('Ride offer has no assigned driver.', 409);
            }
            $driverStmt = $pdo->prepare('SELECT user_id FROM drivers WHERE id = :id LIMIT 1');
            $driverStmt->execute(['id' => $expiredDriverId]);
            $expiredDriverUserId = $driverStmt->fetchColumn();
            if (!is_string($expiredDriverUserId) || trim($expiredDriverUserId) === '') {
                throw new RideException('Expired driver account is unavailable.', 409);
            }

            $now = $dbNow;
            $nextDriver = null;
            if (is_numeric($ride['pickupLat'] ?? null) && is_numeric($ride['pickupLng'] ?? null)) {
                $nextStmt = $pdo->prepare(
                    "SELECT d.id AS driver_id, d.user_id
                     FROM drivers d
                     WHERE d.verified = 1 AND d.licenseStatus = 'verified'
                       AND d.availability = 'available' AND d.busy = 0
                       AND d.user_id IS NOT NULL AND d.latitude IS NOT NULL AND d.longitude IS NOT NULL
                       AND d.id <> :expiredDriver
                       AND NOT EXISTS (
                           SELECT 1 FROM notifications n
                           WHERE n.rideId = :rideId AND n.type = 'ride_request' AND n.userId = d.user_id
                       )
                     ORDER BY (6371 * ACOS(COS(RADIANS(:lat1)) * COS(RADIANS(d.latitude))
                              * COS(RADIANS(d.longitude) - RADIANS(:lng1))
                              + SIN(RADIANS(:lat2)) * SIN(RADIANS(d.latitude)))) ASC,
                              d.id ASC
                     LIMIT 1 FOR UPDATE"
                );
                $nextStmt->execute([
                    'expiredDriver' => $expiredDriverId,
                    'rideId' => $rideId,
                    'lat1' => (float) $ride['pickupLat'],
                    'lng1' => (float) $ride['pickupLng'],
                    'lat2' => (float) $ride['pickupLat'],
                ]);
                $nextDriver = $nextStmt->fetch() ?: null;
            }

            $expiresAt = $nextDriver === null ? null : date('Y-m-d H:i:s', strtotime($now . ' +30 seconds'));
            $transition = $pdo->prepare(
                "UPDATE rides
                 SET requestedDriver = :nextDriver, requestStatus = :nextStatus,
                     requestCreatedAt = :createdAt, requestExpiresAt = :expiresAt, updatedAt = :updatedAt
                 WHERE id = :id AND status = 'pending' AND driverId IS NULL AND requestStatus = 'pending'
                   AND requestedDriver = :expiredDriver AND requestExpiresAt <= NOW()"
            );
            $transition->execute([
                'nextDriver' => $nextDriver['driver_id'] ?? null,
                'nextStatus' => $nextDriver === null ? 'declined' : 'pending',
                'createdAt' => $nextDriver === null ? null : $now,
                'expiresAt' => $expiresAt,
                'updatedAt' => $now,
                'id' => $rideId,
                'expiredDriver' => $expiredDriverId,
            ]);
            if ($transition->rowCount() !== 1) {
                throw new RideException('Expired ride offer changed during processing.', 409);
            }

            $this->createNotification($expiredDriverUserId, $rideId, 'ride_timeout', 'Ride request expired', 'The response window for this ride request has ended.');
            if ($nextDriver !== null) {
                $this->createNotification(
                    (string) $nextDriver['user_id'],
                    $rideId,
                    'ride_request',
                    'New ride request',
                    'You have a new ride request near you.'
                );
                $outcome = 'reassigned';
            } else {
                $passengerUserId = $ride['userId'] ?? null;
                if (is_string($passengerUserId) && trim($passengerUserId) !== '') {
                    $this->createNotification(
                        $passengerUserId,
                        $rideId,
                        'ride_dispatch_stopped',
                        'No driver available',
                        'We could not find another eligible driver automatically. Please cancel the ride or arrange manual follow-up.'
                    );
                }
                $outcome = 'exhausted';
            }
            $pdo->commit();
            return $outcome;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Internal ride lookup by ID only (service-level, no passenger ownership check). */
    private function findRideByIdInternal(string $rideId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT id, passengerName, rideType, pickupLat, pickupLng, dropoffLat, dropoffLng, pickupAddress, dropoffAddress,
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

    private function createNotification(string $userId, ?string $rideId, string $type, string $title, string $message, ?string $offerId = null): void
    {
        $pdo = Database::connection();
        $id = 'n_' . time() . '_' . substr(bin2hex(random_bytes(9)), 0, 9);
        try {
            if ($offerId === null) {
                $pdo->prepare('INSERT INTO notifications (id, userId, rideId, type, title, message, `read`, createdAt) VALUES (:id, :uid, :rid, :type, :title, :msg, 0, :now)')
                    ->execute(['id' => $id, 'uid' => $userId, 'rid' => $rideId, 'type' => $type, 'title' => $title, 'msg' => $message, 'now' => date('Y-m-d H:i:s')]);
            } else {
                $pdo->prepare('INSERT INTO notifications (id, userId, rideId, offerId, type, title, message, `read`, createdAt) VALUES (:id, :uid, :rid, :offerId, :type, :title, :msg, 0, :now)')
                    ->execute(['id' => $id, 'uid' => $userId, 'rid' => $rideId, 'offerId' => $offerId, 'type' => $type, 'title' => $title, 'msg' => $message, 'now' => date('Y-m-d H:i:s')]);
            }
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
        ?string $pickupAddress = null,
        ?string $dropoffAddress = null,
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
                                    dropoffLng, pickupAddress, dropoffAddress, status, distanceKm,
                                    fareEstimate, createdAt, updatedAt, userId)
                 VALUES (:id, :passengerName, :rideType, :pickupLat, :pickupLng, :dropoffLat,
                         :dropoffLng, :pickupAddress, :dropoffAddress, :status, :distanceKm,
                         :fareEstimate, :createdAt, :updatedAt, :userId)'
            )->execute([
                'id'             => $id,
                'passengerName'  => $passengerName,
                'rideType'       => $rideType,
                'pickupLat'      => $pickupLat,
                'pickupLng'      => $pickupLng,
                'dropoffLat'     => $dropoffLat,
                'dropoffLng'     => $dropoffLng,
                'pickupAddress'  => $pickupAddress,
                'dropoffAddress' => $dropoffAddress,
                'status'         => 'pending',
                'distanceKm'     => $distanceKm,
                'fareEstimate'   => $fareEstimate,
                'createdAt'      => $now,
                'updatedAt'      => $now,
                'userId'         => $userId,
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

        // Dispatch happens after booking and its passenger notification commit.
        try {
            $ride = $this->offerRide($ride['id']);
        } catch (\Exception $e) {
            // Preserve the committed booking if dispatch infrastructure fails.
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
            'SELECT id, passengerName, rideType, pickupLat, pickupLng, dropoffLat, dropoffLng, pickupAddress, dropoffAddress,
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
            'SELECT id, passengerName, rideType, pickupLat, pickupLng, dropoffLat, dropoffLng, pickupAddress, dropoffAddress,
                    driverId, status, requestedDriver, requestStatus, requestCreatedAt,
                    requestExpiresAt, distanceKm, fareEstimate, createdAt, updatedAt,
                    startedAt, completedAt, cancelledAt, userId
             FROM rides
             WHERE requestedDriver = :did
               AND requestStatus = \'pending\'
               AND status = \'pending\'
               AND requestExpiresAt IS NOT NULL AND requestExpiresAt > NOW()
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
            'SELECT id, passengerName, rideType, pickupLat, pickupLng, dropoffLat, dropoffLng, pickupAddress, dropoffAddress,
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

        /**
     * This driver's own rides, newest first (READ-ONLY).
     * @return array<int,array<string,mixed>>
     */
    public function getDriverRides(string $userId, int $limit = 10): array
    {
        $driver = $this->getDriverProfile($userId);
        $limit = max(1, min(50, $limit));
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT id, passengerName, rideType, pickupAddress, dropoffAddress, driverId, status,
                    distanceKm, fareEstimate, createdAt, updatedAt, startedAt, completedAt, cancelledAt
             FROM rides
             WHERE driverId = :did
             ORDER BY COALESCE(completedAt, cancelledAt, startedAt, updatedAt, createdAt) DESC
             LIMIT ' . $limit
        );
        $stmt->execute(['did' => (string) $driver['id']]);
        return $stmt->fetchAll();
    }

    /**
     * Today's rides and earnings (completed rides), plus all-time totals (READ-ONLY).
     * "Today" uses the same PHP clock that wrote completedAt.
     * @return array<string,int|float>
     */
    public function getDriverStats(string $userId): array
    {
        $driver = $this->getDriverProfile($userId);
        $start = date('Y-m-d 00:00:00');
        $end = date('Y-m-d 00:00:00', strtotime('tomorrow'));
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS completedRides,
                    COALESCE(SUM(fareEstimate), 0) AS totalEarnings,
                    COALESCE(SUM(CASE WHEN completedAt >= :s1 AND completedAt < :e1 THEN 1 ELSE 0 END), 0) AS todayRides,
                    COALESCE(SUM(CASE WHEN completedAt >= :s2 AND completedAt < :e2 THEN fareEstimate ELSE 0 END), 0) AS todayEarnings
             FROM rides
             WHERE driverId = :did AND status = 'completed'"
        );
        $stmt->execute(['s1' => $start, 'e1' => $end, 's2' => $start, 'e2' => $end, 'did' => (string) $driver['id']]);
        $row = $stmt->fetch() ?: [];
        return [
            'todayRides'     => (int) ($row['todayRides'] ?? 0),
            'todayEarnings'  => round((float) ($row['todayEarnings'] ?? 0), 2),
            'completedRides' => (int) ($row['completedRides'] ?? 0),
            'totalEarnings'  => round((float) ($row['totalEarnings'] ?? 0), 2),
        ];
    }

    // ----------------------------------------------------- Cancel (commuter)

    /**
     * Cancel own ride. Only allowed while pending or assigned.
     * @return array{message:string, ride:array<string,mixed>}
     */
    public function cancelRide(string $userId, string $rideId): array
    {
        $pdo = Database::connection();
        $hasDispatchHistory = $this->dispatchHistoryAvailable($pdo);
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

            $now = date('Y-m-d H:i:s');
            $activeAttempt = null;
            if ($hasDispatchHistory) {
                $this->lockDispatchState($pdo, $rideId, $now);
                $activeAttempt = $this->lockActiveOffer($pdo, $rideId);
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

            if ($hasDispatchHistory && $activeAttempt !== null && ($activeAttempt['outcome'] ?? null) === 'pending') {
                $attemptStmt = $pdo->prepare(
                    "UPDATE ride_offer_attempts
                     SET outcome = 'superseded', respondedAt = :respondedAt, activeSlot = NULL
                     WHERE offerId = :offerId AND outcome = 'pending' AND activeSlot = 1"
                );
                $attemptStmt->execute(['respondedAt' => $now, 'offerId' => $activeAttempt['offerId']]);
                if ($attemptStmt->rowCount() !== 1) {
                    throw new RideException('Pending offer could not be closed during cancellation.', 409);
                }
            }
            if ($hasDispatchHistory) {
                $pdo->prepare(
                    "UPDATE ride_dispatch_state
                     SET dispatchStatus = 'stopped', nextAttemptAt = NULL, updatedAt = :updatedAt
                     WHERE rideId = :rideId"
                )->execute(['updatedAt' => $now, 'rideId' => $rideId]);
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
            'SELECT id, passengerName, rideType, pickupLat, pickupLng, dropoffLat, dropoffLng, pickupAddress, dropoffAddress,
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
        $hasDispatchHistory = $this->dispatchHistoryAvailable($pdo);
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

            $now = date('Y-m-d H:i:s');
            $activeAttempt = null;
            if ($hasDispatchHistory) {
                $dispatchState = $this->lockDispatchState($pdo, $rideId, $now);
                $activeAttempt = $this->lockActiveOffer($pdo, $rideId);
                if ($dispatchState['dispatchStatus'] !== 'offered'
                    || $activeAttempt === null
                    || ($activeAttempt['outcome'] ?? null) !== 'pending'
                    || (int) ($activeAttempt['activeSlot'] ?? 0) !== 1
                    || ($activeAttempt['driverId'] ?? null) !== ($ride['requestedDriver'] ?? null)) {
                    throw new RideException('Offer history is unavailable; dispatch requires manual review.', 409);
                }
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

            if ($hasDispatchHistory) {
                $attemptUpdate = $pdo->prepare(
                    "UPDATE ride_offer_attempts
                     SET outcome = 'accepted', respondedAt = :respondedAt, activeSlot = NULL
                     WHERE offerId = :offerId AND driverId = :driverId AND outcome = 'pending'
                       AND activeSlot = 1 AND expiresAt > NOW()"
                );
                $attemptUpdate->execute(['respondedAt' => $now, 'offerId' => $activeAttempt['offerId'], 'driverId' => $driverId]);
                if ($attemptUpdate->rowCount() !== 1) {
                    throw new RideException('Ride offer is no longer available.', 409);
                }
                $stopDispatch = $pdo->prepare(
                    "UPDATE ride_dispatch_state
                     SET dispatchStatus = 'stopped', nextAttemptAt = NULL, updatedAt = :updatedAt
                     WHERE rideId = :rideId AND dispatchStatus = 'offered'"
                );
                $stopDispatch->execute(['updatedAt' => $now, 'rideId' => $rideId]);
                if ($stopDispatch->rowCount() !== 1) {
                    throw new RideException('Ride dispatch state could not be finalized.', 409);
                }
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
                'SELECT id, userId, passengerName, rideType, pickupLat, pickupLng,
                        dropoffLat, dropoffLng, driverId, status, distanceKm, fareEstimate
                 FROM rides WHERE id = :id LIMIT 1 FOR UPDATE'
            );
            $rideStmt->execute(['id' => $rideId]);
            $ride = $rideStmt->fetch();
            if (!$ride) {
                throw new RideException('Ride not found.', 404);
            }

            $driverStmt = $pdo->prepare(
                'SELECT id, user_id, name FROM drivers WHERE user_id = :uid LIMIT 1 FOR UPDATE'
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

            $passengerUserId = $ride['userId'] ?? null;
            if (!is_string($passengerUserId) || trim($passengerUserId) === '') {
                throw new RideException('Passenger account is unavailable.', 409);
            }
            $fare = $ride['fareEstimate'] ?? null;
            if (!is_numeric($fare) || !is_finite((float) $fare) || (float) $fare < 0) {
                throw new RideException('Ride fare is unavailable.', 409);
            }
            if (!is_string($ride['passengerName'] ?? null) || trim((string) $ride['passengerName']) === ''
                || !is_string($driver['name'] ?? null) || trim((string) $driver['name']) === '') {
                throw new RideException('Receipt names are unavailable.', 409);
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

            $transactionId = 'tx_' . time() . '_' . substr(bin2hex(random_bytes(9)), 0, 9);
            $pdo->prepare(
                'INSERT INTO `transactions`
                    (id, rideId, passengerUserId, driverId, fareAmount, currency,
                     paymentMethod, rideType, distanceKm, passengerName, driverName,
                     pickupLat, pickupLng, dropoffLat, dropoffLng, completedAt, createdAt)
                 VALUES
                    (:id, :rideId, :passengerUserId, :driverId, :fareAmount, \'PHP\',
                     \'cash\', :rideType, :distanceKm, :passengerName, :driverName,
                     :pickupLat, :pickupLng, :dropoffLat, :dropoffLng, :completedAt, :createdAt)'
            )->execute([
                'id' => $transactionId,
                'rideId' => $rideId,
                'passengerUserId' => $passengerUserId,
                'driverId' => $driverId,
                'fareAmount' => number_format((float) $fare, 2, '.', ''),
                'rideType' => $ride['rideType'],
                'distanceKm' => $ride['distanceKm'],
                'passengerName' => $ride['passengerName'],
                'driverName' => $driver['name'],
                'pickupLat' => $ride['pickupLat'],
                'pickupLng' => $ride['pickupLng'],
                'dropoffLat' => $ride['dropoffLat'],
                'dropoffLng' => $ride['dropoffLng'],
                'completedAt' => $now,
                'createdAt' => $now,
            ]);

            $updatedRide = $this->findRideByIdInternal($rideId);
            $this->createNotification(
                $passengerUserId,
                $rideId,
                'ride_completed',
                'Ride completed',
                'Your ride has been completed.'
            );
            $this->createNotification(
                $passengerUserId,
                $rideId,
                'receipt_ready',
                'Cash receipt ready',
                'Your completed ride cash receipt is ready to view.'
            );
            $this->createNotification(
                $userId,
                $rideId,
                'receipt_ready',
                'Cash receipt ready',
                'Your completed ride cash receipt is ready to view.'
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

    /** Return a completed ride receipt only to its passenger or assigned driver. */
    public function getReceiptForUser(string $userId, string $role, string $rideId): array
    {
        $pdo = Database::connection();
        if ($role === 'commuter') {
            $stmt = $pdo->prepare(
                "SELECT t.id, t.rideId, t.fareAmount, t.currency, t.paymentMethod,
                        t.rideType, t.distanceKm, t.passengerName, t.driverName,
                        t.pickupLat, t.pickupLng, t.dropoffLat, t.dropoffLng, r.pickupAddress, r.dropoffAddress,
                        t.completedAt, t.createdAt
                 FROM `transactions` t
                 JOIN rides r ON r.id = t.rideId
                 WHERE t.rideId = :rideId AND t.passengerUserId = :userId
                   AND r.status = 'completed'
                 LIMIT 1"
            );
            $stmt->execute(['rideId' => $rideId, 'userId' => $userId]);
        } elseif ($role === 'driver') {
            $stmt = $pdo->prepare(
                "SELECT t.id, t.rideId, t.fareAmount, t.currency, t.paymentMethod,
                        t.rideType, t.distanceKm, t.passengerName, t.driverName,
                        t.pickupLat, t.pickupLng, t.dropoffLat, t.dropoffLng, r.pickupAddress, r.dropoffAddress,
                        t.completedAt, t.createdAt
                 FROM `transactions` t
                 JOIN rides r ON r.id = t.rideId
                 JOIN drivers d ON d.id = t.driverId
                 WHERE t.rideId = :rideId AND d.user_id = :userId
                   AND r.status = 'completed'
                 LIMIT 1"
            );
            $stmt->execute(['rideId' => $rideId, 'userId' => $userId]);
        } else {
            throw new RideException('Only passengers and assigned drivers can view receipts.', 403);
        }

        $transaction = $stmt->fetch();
        if (!$transaction) {
            throw new RideException('Receipt not found.', 404);
        }
        return $transaction;
    }

    /** @return array<string,mixed> */
    public function declineRide(string $userId, string $rideId): array
    {
        $pdo = Database::connection();
        if (!$this->dispatchHistoryAvailable($pdo)) {
            return $this->declineRideWithoutDispatchHistory($pdo, $userId, $rideId);
        }
        $pdo->beginTransaction();
        try {
            $driver = $this->getDriverProfile($userId);
            $driverId = (string) ($driver['id'] ?? '');
            $stmt = $pdo->prepare(
                'SELECT id, userId, status, driverId, requestedDriver, requestStatus, requestExpiresAt,
                        requestCreatedAt, pickupLat, pickupLng
                 FROM rides WHERE id = :id LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['id' => $rideId]);
            $ride = $stmt->fetch();
            if (!$ride) {
                throw new RideException('Ride not found.', 404);
            }
            if ($ride['status'] !== 'pending') {
                throw new RideException('Ride not available.', 409);
            }
            if (($ride['requestedDriver'] ?? null) !== $driverId) {
                throw new RideException('Not offered to this driver.', 403);
            }
            if (($ride['requestStatus'] ?? null) !== 'pending') {
                throw new RideException('Request already processed.', 409);
            }

            $now = date('Y-m-d H:i:s');
            $state = $this->lockDispatchState($pdo, $rideId, $now);
            $attempt = $this->lockActiveOffer($pdo, $rideId);
            if ($state['dispatchStatus'] !== 'offered'
                || $attempt === null
                || ($attempt['outcome'] ?? null) !== 'pending'
                || (int) ($attempt['activeSlot'] ?? 0) !== 1
                || ($attempt['driverId'] ?? null) !== $driverId) {
                throw new RideException('Offer history is unavailable; dispatch requires manual review.', 409);
            }

            $dbNow = (string) $pdo->query('SELECT NOW()')->fetchColumn();
            $rideExpiry = $ride['requestExpiresAt'] ?? null;
            $attemptExpiry = $attempt['expiresAt'] ?? null;
            if (!$this->isValidDatabaseDateTime($rideExpiry) || !$this->isValidDatabaseDateTime($attemptExpiry)) {
                $result = $this->markDispatchManualReview($pdo, $ride, $attempt, $now, 'invalid_expiry');
            } else {
                $rideExpired = $rideExpiry <= $dbNow;
                $attemptExpired = $attemptExpiry <= $dbNow;
                if ($rideExpired !== $attemptExpired) {
                    $result = $this->markDispatchManualReview($pdo, $ride, $attempt, $now, 'expiry_mismatch');
                } elseif ($rideExpired) {
                    $result = $this->expireCurrentOfferWithinTransaction($pdo, $ride, $state, $attempt, $now);
                } else {
                    $declineAttempt = $pdo->prepare(
                        "UPDATE ride_offer_attempts
                         SET outcome = 'declined', respondedAt = :respondedAt, activeSlot = NULL
                         WHERE offerId = :offerId AND driverId = :driverId AND outcome = 'pending'
                           AND activeSlot = 1 AND expiresAt > NOW()"
                    );
                    $declineAttempt->execute(['respondedAt' => $now, 'offerId' => $attempt['offerId'], 'driverId' => $driverId]);
                    if ($declineAttempt->rowCount() !== 1) {
                        throw new RideException('Ride offer is no longer available.', 409);
                    }
                    $clearRide = $pdo->prepare(
                        "UPDATE rides
                         SET requestedDriver = NULL, requestStatus = NULL, requestCreatedAt = NULL,
                             requestExpiresAt = NULL, updatedAt = :updatedAt
                         WHERE id = :id AND status = 'pending' AND requestStatus = 'pending'
                           AND requestedDriver = :driverId AND requestExpiresAt > NOW()"
                    );
                    $clearRide->execute(['updatedAt' => $now, 'id' => $rideId, 'driverId' => $driverId]);
                    if ($clearRide->rowCount() !== 1) {
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
                    $this->createNotification(
                        $userId,
                        $rideId,
                        'ride_declined',
                        'Ride declined',
                        'You declined this ride request.'
                    );
                    $ride['requestedDriver'] = null;
                    $ride['requestStatus'] = null;
                    $ride['requestCreatedAt'] = null;
                    $ride['requestExpiresAt'] = null;
                    $result = $this->createNextOfferWithinTransaction($pdo, $ride, $state, $now);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return [
            'message' => $result['message'],
            'dispatchStatus' => $result['dispatchStatus'],
            'ride' => $result['ride'],
        ];
    }

    /** Decline and re-offer using surviving ride_request notifications as best-effort history. */
    private function declineRideWithoutDispatchHistory(PDO $pdo, string $userId, string $rideId): array
    {
        $pdo->beginTransaction();
        try {
            $driver = $this->getDriverProfile($userId);
            $driverId = (string) ($driver['id'] ?? '');
            $stmt = $pdo->prepare(
                'SELECT id, userId, status, driverId, requestedDriver, requestStatus, requestExpiresAt, pickupLat, pickupLng
                 FROM rides WHERE id = :id LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['id' => $rideId]);
            $ride = $stmt->fetch();
            if (!$ride) {
                throw new RideException('Ride not found.', 404);
            }
            if ($ride['status'] !== 'pending') {
                throw new RideException('Ride not available.', 409);
            }
            if (($ride['requestedDriver'] ?? null) !== $driverId) {
                throw new RideException('Not offered to this driver.', 403);
            }
            if (($ride['requestStatus'] ?? null) !== 'pending') {
                throw new RideException('Request already processed.', 409);
            }

            $now = date('Y-m-d H:i:s');
            if (!$this->isValidDatabaseDateTime($ride['requestExpiresAt'] ?? null)
                || (string) $ride['requestExpiresAt'] <= (string) $pdo->query('SELECT NOW()')->fetchColumn()) {
                throw new RideException('This ride request has expired.', 410);
            }

            $passengerUserId = $ride['userId'] ?? null;
            if (!is_string($passengerUserId) || trim($passengerUserId) === '') {
                throw new RideException('Passenger account is unavailable.', 409);
            }

            $nextDriver = null;
            if (is_numeric($ride['pickupLat'] ?? null) && is_numeric($ride['pickupLng'] ?? null)) {
                $nextStmt = $pdo->prepare(
                    "SELECT d.id AS driver_id, d.user_id, d.name, d.latitude, d.longitude
                     FROM drivers d
                     WHERE d.verified = 1
                       AND d.licenseStatus = 'verified'
                       AND d.availability = 'available'
                       AND d.busy = 0
                       AND d.user_id IS NOT NULL
                       AND d.latitude IS NOT NULL AND d.longitude IS NOT NULL
                       AND d.id <> :declinedDriver
                       AND NOT EXISTS (
                           SELECT 1 FROM notifications n
                           WHERE n.rideId = :rideId AND n.type = 'ride_request'
                             AND n.userId = d.user_id
                       )
                     ORDER BY (6371 * ACOS(COS(RADIANS(:lat1)) * COS(RADIANS(d.latitude))
                              * COS(RADIANS(d.longitude) - RADIANS(:lng1))
                              + SIN(RADIANS(:lat2)) * SIN(RADIANS(d.latitude)))) ASC,
                              d.id ASC
                     LIMIT 1 FOR UPDATE"
                );
                $nextStmt->execute([
                    'declinedDriver' => $driverId,
                    'rideId' => $rideId,
                    'lat1' => (float) $ride['pickupLat'],
                    'lng1' => (float) $ride['pickupLng'],
                    'lat2' => (float) $ride['pickupLat'],
                ]);
                $nextDriver = $nextStmt->fetch() ?: null;
            }

            if ($nextDriver !== null) {
                $expiresAt = date('Y-m-d H:i:s', strtotime($now . ' +30 seconds'));
                $transition = $pdo->prepare(
                    "UPDATE rides
                     SET requestedDriver = :nextDriver, requestStatus = 'pending', requestCreatedAt = :createdAt,
                         requestExpiresAt = :expiresAt, updatedAt = :updatedAt
                     WHERE id = :id AND status = 'pending' AND driverId IS NULL
                       AND requestStatus = 'pending' AND requestedDriver = :declinedDriver
                       AND requestExpiresAt > NOW()"
                );
                $transition->execute([
                    'nextDriver' => $nextDriver['driver_id'],
                    'createdAt' => $now,
                    'expiresAt' => $expiresAt,
                    'updatedAt' => $now,
                    'id' => $rideId,
                    'declinedDriver' => $driverId,
                ]);
                if ($transition->rowCount() !== 1) {
                    throw new RideException('Ride offer is no longer available.', 409);
                }

                $this->createNotification($passengerUserId, $rideId, 'ride_driver_declined', 'Driver update', 'A driver declined your ride request.');
                $this->createNotification($userId, $rideId, 'ride_declined', 'Ride declined', 'You declined this ride request.');
                $this->createNotification(
                    (string) $nextDriver['user_id'],
                    $rideId,
                    'ride_request',
                    'New ride request',
                    'You have a new ride request near you.'
                );
                $message = 'Ride declined. Request sent to the next eligible driver.';
                $dispatchStatus = 'offered';
            } else {
                $transition = $pdo->prepare(
                    "UPDATE rides
                     SET requestedDriver = NULL, requestStatus = 'declined', requestCreatedAt = NULL,
                         requestExpiresAt = NULL, updatedAt = :updatedAt
                     WHERE id = :id AND status = 'pending' AND driverId IS NULL
                       AND requestStatus = 'pending' AND requestedDriver = :declinedDriver
                       AND requestExpiresAt > NOW()"
                );
                $transition->execute(['updatedAt' => $now, 'id' => $rideId, 'declinedDriver' => $driverId]);
                if ($transition->rowCount() !== 1) {
                    throw new RideException('Ride offer is no longer available.', 409);
                }

                $this->createNotification($passengerUserId, $rideId, 'ride_driver_declined', 'Driver update', 'A driver declined your ride request.');
                $this->createNotification($userId, $rideId, 'ride_declined', 'Ride declined', 'You declined this ride request.');
                $this->createNotification(
                    $passengerUserId,
                    $rideId,
                    'ride_dispatch_stopped',
                    'No driver available',
                    'We could not find another eligible driver automatically. Please cancel the ride or arrange manual follow-up.'
                );
                $message = 'Ride declined. No other eligible driver was found; manual follow-up is required.';
                $dispatchStatus = 'stopped';
            }

            $updatedRide = $this->findRideByIdInternal($rideId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'message' => $message,
            'dispatchStatus' => $dispatchStatus,
            'ride' => $updatedRide,
        ];
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
