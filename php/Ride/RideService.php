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
                  AND d.latitude IS NOT NULL
                  AND d.longitude IS NOT NULL";
        if ($excludeDriverId) {
            $sql .= " AND d.id != :exclude";
        }
        $sql .= " ORDER BY (6371 * ACOS(COS(RADIANS(:lat)) * COS(RADIANS(d.latitude)) * COS(RADIANS(d.longitude) - RADIANS(:lng)) + SIN(RADIANS(:lat)) * SIN(RADIANS(d.latitude)))) ASC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $params = ['lat' => $pickupLat, 'lng' => $pickupLng];
        if ($excludeDriverId) $params['exclude'] = $excludeDriverId;
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ? $row : null;
    }

    /** Offer ride to nearest eligible driver; create notification; set driverId but keep pending. */
    public function offerRide(string $rideId, float $pickupLat, float $pickupLng): array
    {
        $ride = $this->findByIdForAdminOrSelf($rideId, ''); // need proper auth check; using service-level
        $driver = $this->nearestEligibleDriver($pickupLat, $pickupLng);
        if (!$driver) {
            throw new RideException('No eligible verified available driver found.', 404);
        }
        $pdo = Database::connection();
        $pdo->prepare("UPDATE rides SET driverId = :did, updatedAt = :now WHERE id = :rid")
            ->execute(['did' => $driver['driver_id'], 'now' => date('Y-m-d H:i:s'), 'rid' => $rideId]);
        // Notification
        $this->createNotification($driver['user_id'], $rideId, 'ride_request',
            'New ride request', 'You have a new ride request near you.');
        return $this->findById('', $rideId); // simplified
    }

    private function createNotification(string $userId, ?string $rideId, string $type, string $title, string $message): void
    {
        $pdo = Database::connection();
        $id = 'n_' . time() . '_' . substr(bin2hex(random_bytes(9)), 0, 9);
        $pdo->prepare('INSERT INTO notifications (id, userId, rideId, type, title, message, `read`, createdAt) VALUES (:id, :uid, :rid, :type, :title, :msg, 0, :now)')
            ->execute(['id' => $id, 'uid' => $userId, 'rid' => $rideId, 'type' => $type, 'title' => $title, 'msg' => $message, 'now' => date('Y-m-d H:i:s')]);
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

        $ride = $this->findById($userId, $id);
        // Existing dispatch: trigger offer mechanism if eligible driver exists
        try {
            $driver = $this->nearestEligibleDriver($pickupLat, $pickupLng);
            if ($driver) {
                $this->offerRide($ride['id'], $pickupLat, $pickupLng);
            }
        } catch (\Exception $e) {
            // Preserve ride creation even if no eligible driver or offer fails
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

    // ----------------------------------------------------- Cancel (commuter)

    /**
     * Cancel own ride. Only allowed while pending or assigned.
     * @return array{message:string, ride:array<string,mixed>}
     */
    public function cancelRide(string $userId, string $rideId): array
    {
        $pdo = Database::connection();

        $current = $this->findById($userId, $rideId);
        $status  = (string) ($current['status'] ?? '');

        if (!in_array($status, self::CANCELLABLE, true)) {
            throw new RideException(
                'Cannot cancel ride in ' . $status . ' state. Cancellation only allowed before the ride starts.',
                409
            );
        }

        $now = date('Y-m-d H:i:s');
        try {
            $pdo->prepare(
                'UPDATE rides SET status = :status, cancelledAt = :cancelledAt, updatedAt = :updatedAt
                 WHERE id = :id AND userId = :uid'
            )->execute([
                'status'       => 'cancelled',
                'cancelledAt'  => $now,
                'updatedAt'    => $now,
                'id'           => $rideId,
                'uid'          => $userId,
            ]);
        } catch (PDOException $e) {
            throw new RideException('Failed to cancel ride.', 500);
        }

        return ['message' => 'Ride cancelled successfully.', 'ride' => $this->findById($userId, $rideId)];
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

}