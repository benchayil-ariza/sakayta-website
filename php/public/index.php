<?php declare(strict_types=1);

/**
 * SakayTa — PHP foundation landing page.
 *
 * Served by Apache at the SakayTa vhost root. It is intentionally minimal:
 * the full REST API and authentication are NOT implemented yet (foundation
 * only per the implementation order).
 */

namespace Sakayta;

require __DIR__ . '/../autoload.php';

use Sakayta\Config\Config;

Config::bootstrap();

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'app'      => 'sakayta-php-foundation',
    'stage'    => 'foundation-only (no REST API / auth migration yet)',
    'environment' => Config::isProduction() ? 'production' : 'development',
    'endpoints' => [
        '/health.php' => 'Apache + PHP + MariaDB health check (JSON)',
    ],
    'timestamp' => gmdate('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);