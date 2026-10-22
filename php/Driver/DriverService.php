<?php declare(strict_types=1);
/**
 * SakayTa — driver service (PHP foundation, no SOAP).
 *
 * Pure DB/logic layer, no HTTP output. Every operation resolves the driver
 * profile exclusively through the authenticated users.id (drivers.user_id) —
 * a driverId is NEVER taken from the request body/path, so a driver cannot
 * read or mutate another driver's record.
 *
 * Availability (`availability`) is a real persisted state, kept distinct from
 * `busy` (ride-busy). License submission only persists information and resets
 * to 'pending'; SOAP verification is a later checkpoint (not here).
 */

namespace Sakayta\Driver;

use PDO;
use Sakayta\Db\Database;

final class DriverService
{
    /** Maximum lengths from the MariaDB schema (VARCHAR limits). */
    private const MAX_NAME = 255;
    private const MAX_PLATE = 20;
    private const MAX_LICENSE = 50;

    // ------------------------------------------------------------ Read

    /**
     * Authenticated driver's own profile, resolved by user id.
     * @return array<string,mixed>
     */
    public function getOwnProfile(string $userId): array
    {
        return $this->findByUserIdOrFail($userId);
    }

    // ------------------------------------------------------------ Create

    /**
     * Create the authenticated user's single driver profile.
     * @return array<string,mixed>
     */
    public function createProfile(string $userId, ?string $name, ?string $plateNumber): array
    {
        $name = trim((string) $name);
        $plateNumber = $plateNumber === null ? null : trim($plateNumber);

        if ($name === '') {
            throw new DriverException('Name is required.', 400);
        }
        if (strlen($name) > self::MAX_NAME) {
            throw new DriverException('Name must be at most ' . self::MAX_NAME . ' characters.', 400);
        }
        if ($plateNumber !== null && strlen($plateNumber) > self::MAX_PLATE) {
            throw new DriverException('Plate number must be at most ' . self::MAX_PLATE . ' characters.', 400);
        }

        $pdo = Database::connection();

        $existing = $pdo->prepare('SELECT id FROM drivers WHERE user_id = :user_id LIMIT 1');
        $existing->execute(['user_id' => $userId]);
        if ($existing->fetchColumn() !== false) {
            throw new DriverException('You already have a driver record. Only one driver record per user.', 409);
        }

        $id = 'd_' . time() . '_' . substr(bin2hex(random_bytes(9)), 0, 9);

        $stmt = $pdo->prepare(
            'INSERT INTO drivers (id, user_id, name, plateNumber, licenseStatus, licenseVerifiedAt, verified, busy, availability)
             VALUES (:id, :user_id, :name, :plateNumber, :licenseStatus, NULL, 0, 0, :availability)'
        );
        $stmt->execute([
            'id'            => $id,
            'user_id'       => $userId,
            'name'          => $name,
            'plateNumber'   => $plateNumber,
            'licenseStatus' => 'pending',
            'availability'  => 'unavailable',
        ]);

        return $this->findByUserIdOrFail($userId);
    }

    // ------------------------------------------------------------ Update

    /**
     * Update the authenticated driver's own profile (name / plateNumber /
     * licenseNumber). Verification fields, ownership, and location are NOT
     * driver-writable (mirrors the Node contract and FR-10).
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    public function updateProfile(string $userId, array $fields): array
    {
        $driver = $this->findByUserIdOrFail($userId);

        foreach (['licenseStatus', 'verified'] as $protected) {
            if (array_key_exists($protected, $fields)) {
                throw new DriverException('License status is controlled by verification process only.', 403);
            }
        }
        if (array_key_exists('user_id', $fields) && (string) $fields['user_id'] !== $userId) {
            throw new DriverException('You cannot reassign a driver record to another user.', 403);
        }
        // Location fields are updated only through the authenticated location endpoint.
        foreach (['latitude', 'longitude', 'locationUpdatedAt'] as $locked) {
            if (array_key_exists($locked, $fields)) {
                unset($fields[$locked]);
            }
        }

        // Whitelisted writable columns only — identifiers come from a fixed
        // list (no user input reaches SQL structure), values are bound.
        $set = [];
        $params = ['user_id' => $userId];
        foreach (['name', 'plateNumber', 'licenseNumber'] as $col) {
            if (!array_key_exists($col, $fields) || $fields[$col] === null) {
                continue;
            }
            $value = trim((string) $fields[$col]);

            $guard = self::MAX_NAME;
            if ($col === 'plateNumber') {
                $guard = self::MAX_PLATE;
            } elseif ($col === 'licenseNumber') {
                $guard = self::MAX_LICENSE;
            }
            if ($col === 'name' && $value === '') {
                throw new DriverException('Name must not be empty.', 400);
            }
            if (strlen($value) > $guard) {
                throw new DriverException(ucfirst($col) . ' must be at most ' . $guard . ' characters.', 400);
            }

            $set[] = "`$col` = :$col";
            $params[$col] = $value;
        }

        if ($set === []) {
            throw new DriverException('Nothing to update.', 400);
        }

        $sql = 'UPDATE drivers SET ' . implode(', ', $set) . ' WHERE user_id = :user_id';
        Database::connection()->prepare($sql)->execute($params);

        return $this->findByUserIdOrFail($userId);
    }

    // ------------------------------------------------------------ Availability

    /**
     * Set the real persisted availability state for the authenticated driver.
     * This is independent of `busy` (current-ride state).
     * @return array{availability:string, driver:array<string,mixed>}
     */
    public function setAvailability(string $userId, bool $available): array
    {
        $this->findByUserIdOrFail($userId);

        $newState = $available ? 'available' : 'unavailable';

        $stmt = Database::connection()->prepare(
            "UPDATE drivers SET availability = :availability WHERE user_id = :user_id"
        );
        $stmt->execute(['availability' => $newState, 'user_id' => $userId]);

        return [
            'availability' => $newState,
            'driver'       => $this->findByUserIdOrFail($userId),
        ];
    }

    /** Persist coordinates for the authenticated driver's own profile. */
    public function updateLocation(string $userId, float $latitude, float $longitude): array
    {
        $this->findByUserIdOrFail($userId);
        $updatedAt = date('Y-m-d H:i:s');

        Database::connection()->prepare(
            'UPDATE drivers
                SET latitude = :latitude,
                    longitude = :longitude,
                    locationUpdatedAt = :locationUpdatedAt
              WHERE user_id = :user_id'
        )->execute([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'locationUpdatedAt' => $updatedAt,
            'user_id' => $userId,
        ]);

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'locationUpdatedAt' => $updatedAt,
            'driver' => $this->findByUserIdOrFail($userId),
        ];
    }

    // ------------------------------------------------------------ License

    /**
     * Submit or replace the authenticated driver's license for manual review.
     * @return array{message:string, licenseStatus:string, verified:int, driver:array<string,mixed>}
     * @throws DriverException when validation fails or the driver is already verified
     */
    public function submitLicense(string $userId, string $licenseNumber): array
    {
        $licenseNumber = trim($licenseNumber);
        if ($licenseNumber === '') {
            throw new DriverException('License number is required.', 400);
        }
        if (strlen($licenseNumber) > self::MAX_LICENSE) {
            throw new DriverException('License number must be at most ' . self::MAX_LICENSE . ' characters.', 400);
        }

        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();

            $profileStmt = $pdo->prepare(
                'SELECT id, licenseStatus, verified
                   FROM drivers
                  WHERE user_id = :user_id
                  LIMIT 1 FOR UPDATE'
            );
            $profileStmt->execute(['user_id' => $userId]);
            $profile = $profileStmt->fetch();
            if (!$profile) {
                throw new DriverException('Driver profile not found.', 404);
            }
            if ($profile['licenseStatus'] === 'verified' || (int) $profile['verified'] === 1) {
                throw new DriverException('A verified driver cannot replace a license through this workflow.', 409);
            }
            if (!in_array($profile['licenseStatus'], ['pending', 'rejected'], true)) {
                throw new DriverException('Driver license is not available for submission.', 409);
            }

            // Submission is a manual-review request; the prototype SOAP registry
            // is not authoritative and must not decide the driver's status.
            $stmt = $pdo->prepare(
                "UPDATE drivers
                    SET licenseNumber = :licenseNumber,
                        licenseStatus = 'pending',
                        licenseVerifiedAt = NULL,
                        verified = 0
                  WHERE user_id = :user_id
                    AND licenseStatus IN ('pending', 'rejected')
                    AND verified = 0"
            );
            $stmt->execute([
                'licenseNumber' => $licenseNumber,
                'user_id'       => $userId,
            ]);

            $driver = $this->findByUserIdOrFail($userId);
            if ($driver['licenseStatus'] !== 'pending'
                || (int) $driver['verified'] !== 0
                || $driver['licenseNumber'] !== $licenseNumber
                || $driver['licenseVerifiedAt'] !== null) {
                throw new DriverException('License submission could not be saved for review.', 409);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'message'       => 'License submitted and is awaiting administrator review.',
            'licenseStatus' => 'pending',
            'verified'      => 0,
            'driver'        => $driver,
        ];
    }

    /**
     * Verify a license number via the external SOAP service.
     * @param string $userId The user ID of the driver
     * @param string $licenseNumber The license number to verify
     * @return array{verified:bool, licenseNumber:string}
     * @throws RuntimeException on SOAP failure
     */
    private function verifyLicenseViaSoap(string $userId, string $licenseNumber): array
    {
        $driver = $this->findByUserIdOrFail($userId);
        $driverId = (string) $driver['id'];

        $soap = new \Sakayta\Soap\LicenseVerificationService();
        return $soap->verifyDriver($licenseNumber);
    }

    // ------------------------------------------------------------ Helpers

    /** @return array<string,mixed> */
    private function findByUserIdOrFail(string $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM drivers WHERE user_id = :user_id LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $driver = $stmt->fetch();

        if (!$driver) {
            throw new DriverException('Driver profile not found.', 404);
        }

        // Defensive: never surface auth-sensitive fields even if one is ever
        // added to this table.
        unset($driver['password_hash'], $driver['token'], $driver['token_secret']);

        return $driver;
    }
}
