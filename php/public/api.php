<?php declare(strict_types=1);

/**
 * SakayTa PHP API — single entry point for /api/* on Apache (vhost :8088).
 *
 * .htaccess rewrites:  /api/auth/register  ->  api.php?route=auth/register
 *
 * Scope for this phase: authentication + driver self-service + ride foundation.
 * Admin, notification, SOAP, mapping/fare, and HTTPS are intentionally NOT
 * implemented yet — those routes 404.
 */

namespace Sakayta;

require __DIR__ . '/../autoload.php';

use Sakayta\Auth\AuthController;
use Sakayta\Auth\AuthException;
use Sakayta\Admin\AdminException;
use Sakayta\Admin\AdminController;
use Sakayta\Config\Config;
use Sakayta\Driver\DriverController;
use Sakayta\Driver\DriverException;
use Sakayta\Ride\RideController;
use Sakayta\Ride\RideException;

header('Content-Type: application/json; charset=utf-8');

// CORS: allow the Node.js frontend (served on localhost:3000) to call this
// PHP API cross-origin. Without these headers the browser blocks the fetch
// (preflight fails on OPTIONS) and the UI shows a "Network error".
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');

Config::bootstrap();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Satisfy the CORS preflight: the browser sends OPTIONS before a cross-origin
// POST with a JSON body; return 200 with the Allow headers above.
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$route  = trim((string) ($_GET['route'] ?? ''));
$parts  = $route === ''
    ? []
    : array_values(array_filter(explode('/', $route), static fn(string $p): bool => $p !== ''));

$notFound = static function (): void {
    http_response_code(404);
    echo json_encode(['error' => 'Not found.']);
    exit;
};

$methodNotAllowed = static function (string $allow): void {
    http_response_code(405);
    header('Allow: ' . $allow);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
};

try {
    $resource = strtolower($parts[0] ?? '');
    $sub      = strtolower($parts[1] ?? '');
    $depth    = count($parts);

    if ($resource === 'auth') {
        if (!in_array($sub, ['register', 'login', 'me'], true)) {
            $notFound();
        }
        $allowedMethods = ['register' => 'POST', 'login' => 'POST', 'me' => 'GET'];
        if ($method !== $allowedMethods[$sub]) {
            $methodNotAllowed($allowedMethods[$sub]);
        }

        $controller = new AuthController();
        match ($sub) {
            'register' => $controller->register(),
            'login'    => $controller->login(),
            'me'       => $controller->me(),
        };
    } elseif ($resource === 'drivers') {
        if ($sub !== 'me') {
            $notFound();
        }
        $controller = new DriverController();

        if ($depth === 2) {
            match ($method) {
                'GET'  => $controller->me(),
                'POST' => $controller->create(),
                'PUT'  => $controller->update(),
                default => $methodNotAllowed('GET, POST, PUT'),
            };
        } elseif ($depth === 3 && strtolower($parts[2] ?? '') === 'submit-license') {
            if ($method !== 'POST') {
                $methodNotAllowed('POST');
            }
            $controller->submitLicense();
        } elseif ($depth === 3 && strtolower($parts[2] ?? '') === 'availability') {
            if ($method !== 'PUT') {
                $methodNotAllowed('PUT');
            }
            $controller->setAvailability();
        } else {
            $notFound();
        }
    } elseif ($resource === 'rides') {
        // Ride routes: all scoped to the authenticated commuter
        $controller = new RideController();

        if ($depth === 1) {
            // /rides
            if ($method !== 'POST' && $method !== 'GET') {
                $methodNotAllowed('GET, POST');
            }
            match ($method) {
                'GET' => $controller->list(),
                'POST' => $controller->create(),
            };
        } elseif ($depth === 2) {
            if (strtolower($parts[1] ?? '') === 'estimate') {
                if ($method !== 'GET') {
                    $methodNotAllowed('GET');
                }
                $controller->estimate();
            } else {
                // /rides/:id
                if ($method !== 'GET') {
                    $methodNotAllowed('GET');
                }
                $controller->getById($parts[1]);
            }
        } elseif ($depth === 3 && strtolower($parts[2] ?? '') === 'cancel') {
            // /rides/:id/cancel
            if ($method !== 'POST') {
                $methodNotAllowed('POST');
            }
            $controller->cancel($parts[1]);
        } else {
            $notFound();
        }
    } elseif ($resource === 'admin') {
        // Admin routes: require admin role
        $controller = new AdminController();

        if ($depth === 2) {
            if ($sub === 'fare') {
                // Handle GET and PUT for /admin/fare
                if ($method === 'GET') {
                    $controller->fare();
                } elseif ($method === 'PUT') {
                    $controller->updateFare();
                } else {
                    $methodNotAllowed('GET, PUT');
                }
            } else {
                // Handle GET for stats, drivers, logs
                if ($method !== 'GET') {
                    $methodNotAllowed('GET');
                }
                match ($sub) {
                    'stats' => $controller->stats(),
                    'drivers' => $controller->drivers(),
                    'logs' => $controller->logs(),
                    default => $notFound(),
                };
            }
        } elseif ($depth === 3) {
            // Handle PUT for verify and reject
            if ($method !== 'PUT') {
                $methodNotAllowed('PUT');
            }
            $action = strtolower($parts[2] ?? '');
            if ($action === 'verify') {
                $controller->verifyDriver($parts[1]);
            } elseif ($action === 'reject') {
                $controller->rejectDriver($parts[1]);
            } else {
                $notFound();
            }
        } else {
            $notFound();
        }
    } else {
        $notFound();
    }
} catch (AuthException $e) {
    http_response_code($e->httpStatus());
    echo json_encode(['error' => $e->getMessage()]);
} catch (AdminException $e) {
    http_response_code($e->httpStatus());
    echo json_encode(['error' => $e->getMessage()]);
} catch (DriverException $e) {
    http_response_code($e->httpStatus());
    echo json_encode(['error' => $e->getMessage()]);
} catch (RideException $e) {
    http_response_code($e->httpStatus());
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('[sakayta-api] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error.']);
}