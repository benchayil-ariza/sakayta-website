<?php declare(strict_types=1);

/**
 * SakayTa — admin service (PHP foundation).
 *
 * Pure logic layer: performs no HTTP output. Implements admin operations
 * and logging to admin_action_logs.
 */

namespace Sakayta\Admin;

use PDO;
use PDOException;
use Sakayta\Auth\AuthService;
use Sakayta\Db\Database;
use Sakayta\Auth\AuthException;
use Sakayta\Config\Config;

final class AdminService
{
    private AuthService $authService;

    public function __construct()
    {
        $this->authService = new AuthService();
    }

    /**
     * Verify the bearer token and ensure the user is an admin.
     *
     * @param string $token The bearer token
     * @return array{id:string, role:string} The user's id and role
     * @throws AuthException on invalid token or non-admin role
     */
    public function requireAdmin(string $token): array
    {
        $user = $this->authService->me($token);
        if ($user['role'] !== 'admin') {
            throw new AuthException('Access denied. Admin privileges required.', 403);
        }
        return ['id' => $user['id'], 'role' => $user['role']];
    }

    /**
     * Get overall platform statistics.
     *
     * @return array{
     *     totalUsers:int,
     *     totalDrivers:int,
     *     verifiedDrivers:int,
     *     pendingDrivers:int,
     *     rejectedDrivers:int,
     *     totalRides:int,
     *     pendingRides:int,
     *     assignedRides:int,
     *     enRouteRides:int,
     *     completedRides:int,
     *     cancelledRides:int
     * }
     */
    public function getStats(): array
    {
        $pdo = Database::connection();

        // Users
        $totalUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $totalDrivers = (int) $pdo->query('SELECT COUNT(*) FROM drivers')->fetchColumn();
        $verifiedDrivers = (int) $pdo->query('SELECT COUNT(*) FROM drivers WHERE verified = 1')->fetchColumn();
        $pendingDrivers = (int) $pdo->query('SELECT COUNT(*) FROM drivers WHERE licenseStatus = \'pending\'')->fetchColumn();
        $rejectedDrivers = (int) $pdo->query('SELECT COUNT(*) FROM drivers WHERE licenseStatus = \'rejected\'')->fetchColumn();

        // Rides
        $totalRides = (int) $pdo->query('SELECT COUNT(*) FROM rides')->fetchColumn();
        $pendingRides = (int) $pdo->query('SELECT COUNT(*) FROM rides WHERE status = \'pending\'')->fetchColumn();
        $assignedRides = (int) $pdo->query('SELECT COUNT(*) FROM rides WHERE status = \'assigned\'')->fetchColumn();
        $enRouteRides = (int) $pdo->query('SELECT COUNT(*) FROM rides WHERE status = \'en_route\'')->fetchColumn();
        $completedRides = (int) $pdo->query('SELECT COUNT(*) FROM rides WHERE status = \'completed\'')->fetchColumn();
        $cancelledRides = (int) $pdo->query('SELECT COUNT(*) FROM rides WHERE status = \'cancelled\'')->fetchColumn();

        return [
            'totalUsers' => $totalUsers,
            'totalDrivers' => $totalDrivers,
            'verifiedDrivers' => $verifiedDrivers,
            'pendingDrivers' => $pendingDrivers,
            'rejectedDrivers' => $rejectedDrivers,
            'totalRides' => $totalRides,
            'pendingRides' => $pendingRides,
            'assignedRides' => $assignedRides,
            'enRouteRides' => $enRouteRides,
            'completedRides' => $completedRides,
            'cancelledRides' => $cancelledRides,
        ];
    }

    /**
     * Get drivers with optional filtering.
     *
     * @param string|null $search Search term for name, plate, or license
     * @param string|null $licenseStatus Filter by license status
     * @param string|null $busy Filter by busy status ('true' or 'false' as string, or null for all)
     * @return array<int, array<string,mixed>> List of drivers
     */
    public function getDrivers(?string $search, ?string $licenseStatus, ?string $busy): array
    {
        $pdo = Database::connection();

        $sql = 'SELECT d.id, d.name, d.plateNumber, d.licenseNumber, d.licenseStatus, d.licenseVerifiedAt, d.busy
                FROM drivers d';
        $params = [];

        $conditions = [];
        if ($search !== null && $search !== '') {
            $conditions[] = '(d.name LIKE :search_name OR d.plateNumber LIKE :search_plate OR d.licenseNumber LIKE :search_license)';
            $params[':search_name'] = '%' . $search . '%';
            $params[':search_plate'] = '%' . $search . '%';
            $params[':search_license'] = '%' . $search . '%';
        }
        if ($licenseStatus !== null && $licenseStatus !== '') {
            $conditions[] = 'd.licenseStatus = :licenseStatus';
            $params[':licenseStatus'] = $licenseStatus;
        }
        if ($busy !== null && $busy !== '') {
            $conditions[] = 'd.busy = :busy';
            $params[':busy'] = ($busy === 'true' ? 1 : 0);
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY d.name';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get action logs with optional filtering.
     *
     * @param string|null $search Search term in description or performedBy
     * @param string|null $actionType Filter by action type
     * @return array<int, array<string,mixed>> List of logs
     */
    public function getLogs(?string $search, ?string $actionType): array
    {
        $pdo = Database::connection();

        $sql = 'SELECT id, actionType, description, performedBy, performedAt, metadata
                FROM admin_action_logs';
        $params = [];

        $conditions = [];
        if ($search !== null && $search !== '') {
            $conditions[] = '(description LIKE :search_desc OR performedBy LIKE :search_user)';
            $params[':search_desc'] = '%' . $search . '%';
            $params[':search_user'] = '%' . $search . '%';
        }
        if ($actionType !== null && $actionType !== '') {
            $conditions[] = 'actionType = :actionType';
            $params[':actionType'] = $actionType;
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY performedAt DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get the current fare configuration.
     *
     * @return array{baseFare:float, ratePerKm:float, updatedAt:string, updatedBy:string|null}
     */
    public function getFare(): array
    {
        $pdo = Database::connection();
        $row = $pdo->query('SELECT baseFare, ratePerKm, updatedAt, updatedBy FROM fare_config ORDER BY updatedAt DESC LIMIT 1')->fetch();

        if ($row === false) {
            // Default values if none set (should not happen if bootstrap ran)
            return ['baseFare' => 15.0, 'ratePerKm' => 8.0, 'updatedAt' => null, 'updatedBy' => null];
        }

        return [
            'baseFare' => (float) $row['baseFare'],
            'ratePerKm' => (float) $row['ratePerKm'],
            'updatedAt' => $row['updatedAt'],
            'updatedBy' => $row['updatedBy'],
        ];
    }

    /**
     * Update the fare configuration.
     *
     * @param float $baseFare
     * @param float $ratePerKm
     * @param string $adminUserId The ID of the admin performing the update
     * @return array{message:string}
     */
    public function updateFare(float $baseFare, float $ratePerKm, string $adminUserId): array
    {
        $pdo = Database::connection();
        $now = date('Y-m-d H:i:s');

        // Update the fare_config table
        $stmt = $pdo->prepare(
            'UPDATE fare_config SET baseFare = :baseFare, ratePerKm = :ratePerKm, updatedAt = :updatedAt, updatedBy = :updatedBy'
        );
        $stmt->execute([
            'baseFare' => $baseFare,
            'ratePerKm' => $ratePerKm,
            'updatedAt' => $now,
            'updatedBy' => $adminUserId,
        ]);

        // Log the action
        $this->logAction(
            'update_fare_settings',
            "Updated fare settings to base fare ₱{$baseFare} and rate ₱{$ratePerKm}/km",
            $adminUserId,
            json_encode(['baseFare' => $baseFare, 'ratePerKm' => $ratePerKm])
        );

        return ['message' => 'Fare settings updated successfully.'];
    }

    /**
     * Verify a driver (admin action).
     *
     * @param string $driverId The driver ID to verify
     * @param string $adminUserId The ID of the admin performing the action
     * @return array{message:string}
     * @throws AdminException on failure
     */
    public function verifyDriver(string $driverId, string $adminUserId): array
    {
        $pdo = Database::connection();

        // Check if driver exists
        $stmt = $pdo->prepare('SELECT id, licenseStatus, verified FROM drivers WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $driverId]);
        $driver = $stmt->fetch();

        if (!$driver) {
            throw new AdminException('Driver not found.', 404);
        }

        // Only pending drivers can be verified
        if ($driver['licenseStatus'] !== 'pending') {
            throw new AdminException('Only drivers with pending license status can be verified.', 409);
        }

        // Update driver to verified
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'UPDATE drivers SET licenseStatus = :licenseStatus, verified = :verified, licenseVerifiedAt = :licenseVerifiedAt WHERE id = :id'
        );
        $stmt->execute([
            'licenseStatus' => 'verified',
            'verified' => 1,
            'licenseVerifiedAt' => $now,
            'id' => $driverId,
        ]);

        // Log the action
        $this->logAction(
            'verify_driver',
            "Verified driver {$driverId}",
            $adminUserId,
            json_encode(['driverId' => $driverId])
        );

        return ['message' => 'Driver verified successfully.'];
    }

    /**
     * Reject a driver (admin action).
     *
     * @param string $driverId The driver ID to reject
     * @param string $adminUserId The ID of the admin performing the action
     * @return array{message:string}
     * @throws AdminException on failure
     */
    public function rejectDriver(string $driverId, string $adminUserId): array
    {
        $pdo = Database::connection();

        // Check if driver exists
        $stmt = $pdo->prepare('SELECT id, licenseStatus FROM drivers WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $driverId]);
        $driver = $stmt->fetch();

        if (!$driver) {
            throw new AdminException('Driver not found.', 404);
        }

        // Only pending drivers can be rejected
        if ($driver['licenseStatus'] !== 'pending') {
            throw new AdminException('Only drivers with pending license status can be rejected.', 409);
        }

        // Update driver to rejected
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'UPDATE drivers SET licenseStatus = :licenseStatus, verified = :verified WHERE id = :id'
        );
        $stmt->execute([
            'licenseStatus' => 'rejected',
            'verified' => 0,
            'id' => $driverId,
        ]);

        // Log the action
        $this->logAction(
            'reject_driver',
            "Rejected driver {$driverId}",
            $adminUserId,
            json_encode(['driverId' => $driverId])
        );

        return ['message' => 'Driver rejected successfully.'];
    }

    /**
     * Log an admin action to the admin_action_logs table.
     *
     * @param string $actionType
     * @param string $description
     * @param string $performedBy Admin user ID
     * @param string|null $metadata Optional JSON metadata
     * @return void
     */
    private function logAction(string $actionType, string $description, string $performedBy, ?string $metadata): void
    {
        $pdo = Database::connection();
        $id = 'a_' . time() . '_' . substr(bin2hex(random_bytes(9)), 0, 9);
        $performedAt = date('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            'INSERT INTO admin_action_logs (id, actionType, description, performedBy, performedAt, metadata)
             VALUES (:id, :actionType, :description, :performedBy, :performedAt, :metadata)'
        );
        $stmt->execute([
            'id' => $id,
            'actionType' => $actionType,
            'description' => $description,
            'performedBy' => $performedBy,
            'performedAt' => $performedAt,
            'metadata' => $metadata,
        ]);
    }
}