<?php declare(strict_types=1);

/**
 * SakayTa — HTTP layer for /api/admin/* (stats, drivers, logs, fare, verify, reject).
 * Reads JSON request bodies, extracts the bearer token via AuthService, and writes JSON
 * responses. All validation + storage happens in AdminService.
 */

namespace Sakayta\Admin;

use Sakayta\Auth\AuthException;
use Sakayta\Auth\AuthService;
use Sakayta\Config\Config;

final class AdminController
{
    private AdminService $service;
    private AuthService $authService;

    public function __construct(?AdminService $service = null, ?AuthService $authService = null)
    {
        $this->service = $service ?? new AdminService();
        $this->authService = $authService ?? new AuthService();
    }

    /** GET /api/admin/stats -> 200 on success. */
    public function stats(): void
    {
        $token = $this->bearerToken();
        $this->service->requireAdmin($token); // throws AuthException if not admin
        $result = $this->service->getStats();
        $this->respond(200, $result);
    }

    /** GET /api/admin/drivers -> 200 with optional query params. */
    public function drivers(): void
    {
        $token = $this->bearerToken();
        $this->service->requireAdmin($token);

        $query = $_GET;
        $search = isset($query['search']) ? (string) $query['search'] : null;
        $licenseStatus = isset($query['licenseStatus']) ? (string) $query['licenseStatus'] : null;
        $busy = isset($query['busy']) ? (string) $query['busy'] : null;

        $result = $this->service->getDrivers($search, $licenseStatus, $busy);
        $this->respond(200, $result);
    }

    /** GET /api/admin/logs -> 200 with optional query params. */
    public function logs(): void
    {
        $token = $this->bearerToken();
        $this->service->requireAdmin($token);

        $query = $_GET;
        $search = isset($query['search']) ? (string) $query['search'] : null;
        $actionType = isset($query['actionType']) ? (string) $query['actionType'] : null;

        $result = $this->service->getLogs($search, $actionType);
        $this->respond(200, $result);
    }

    /** GET /api/admin/fare -> 200 with current fare config. */
    public function fare(): void
    {
        $token = $this->bearerToken();
        $this->service->requireAdmin($token);
        $result = $this->service->getFare();
        $this->respond(200, $result);
    }

    /** PUT /api/admin/fare -> 200 on success. */
    public function updateFare(): void
    {
        $token = $this->bearerToken();
        $adminId = $this->service->requireAdmin($token)['id'];

        $body = $this->jsonBody();
        $baseFare = isset($body['baseFare']) ? (float) $body['baseFare'] : 0.0;
        $ratePerKm = isset($body['ratePerKm']) ? (float) $body['ratePerKm'] : 0.0;

        $result = $this->service->updateFare($baseFare, $ratePerKm, $adminId);
        $this->respond(200, $result);
    }

    /** PUT /api/admin/drivers/:id/verify -> 200 on success. */
    public function verifyDriver(string $driverId): void
    {
        $token = $this->bearerToken();
        $adminId = $this->service->requireAdmin($token)['id'];

        $result = $this->service->verifyDriver($driverId, $adminId);
        $this->respond(200, $result);
    }

    /** PUT /api/admin/drivers/:id/reject -> 200 on success. */
    public function rejectDriver(string $driverId): void
    {
        $token = $this->bearerToken();
        $adminId = $this->service->requireAdmin($token)['id'];

        $result = $this->service->rejectDriver($driverId, $adminId);
        $this->respond(200, $result);
    }

    // ---------------------------------------------------------------- Helpers

    /** @return array<string,mixed> */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /** Extract the token from "Authorization: Bearer <token>". */
    private function bearerToken(): string
    {
        // Prefer $_SERVER; fall back to Apache getallheaders() because some
        // Apache/PHP-on-Windows builds do not populate HTTP_AUTHORIZATION.
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if ($header === '' && function_exists('getallheaders')) {
            $all = getallheaders();
            foreach ($all as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = (string) $value;
                    break;
                }
            }
        }

        if ($header === ''
            || preg_match('/^Bearer\s+(.+)$/i', $header, $m) !== 1
            || trim($m[1]) === '') {
            throw new AuthException('No token provided. Authorization required.', 401);
        }
        return trim($m[1]);
    }

    /** Emit a JSON response and stop. */
    private function respond(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}