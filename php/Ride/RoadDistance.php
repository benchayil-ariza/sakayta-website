<?php declare(strict_types=1);

/**
 * SakayTa: road distance between two points, from a routing service (OSRM).
 *
 * - Answers are saved in the route_cache table, so the fare estimate and the booking
 *   always use the same distance.
 * - If the service does not answer, we use the straight-line distance times a detour
 *   factor and say so ("estimated"). Booking is never blocked.
 * - The browser never sends a distance. The server works it out from the coordinates.
 */

namespace Sakayta\Ride;

use PDO;
use Sakayta\Db\Database;

final class RoadDistance
{
    private const ROUTE_URL = 'https://router.project-osrm.org/route/v1/driving';  // free demo server: light use only
    private const DETOUR = 1.3;                 // road length / straight-line length, for the fallback
    private const MAX_ROUTED_STRAIGHT_KM = 150.0;   // farther than this we do not ask the service

    /** @return array{km:float,source:string}  source is "road" or "estimated" */
    public static function between(float $lat1, float $lng1, float $lat2, float $lng2): array
    {
        $straight = self::straightKm($lat1, $lng1, $lat2, $lng2);
        $key = sha1(sprintf(
            '%s,%s|%s,%s',
            number_format($lat1, 4, '.', ''), number_format($lng1, 4, '.', ''),
            number_format($lat2, 4, '.', ''), number_format($lng2, 4, '.', '')
        ));

        $pdo = Database::connection();
        $cached = self::readCache($pdo, $key);
        if ($cached !== null) {
            return ['km' => $cached, 'source' => 'road'];
        }

        if ($straight <= self::MAX_ROUTED_STRAIGHT_KM) {
            $km = self::fetchRoadKm($lat1, $lng1, $lat2, $lng2, $straight);
            if ($km !== null) {
                self::writeCache($pdo, $key, $km);
                return ['km' => $km, 'source' => 'road'];
            }
        }

        return ['km' => round($straight * self::DETOUR, 4), 'source' => 'estimated'];
    }

    public static function straightKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)), 4);
    }

    private static function fetchRoadKm(float $lat1, float $lng1, float $lat2, float $lng2, float $straight): ?float
    {
        if (!function_exists('curl_init')) {
            error_log('[SakayTa route] the PHP curl extension is not enabled');
            return null;
        }
        // number_format keeps the dot as the decimal mark, whatever the server language is
        $url = self::ROUTE_URL . '/'
            . number_format($lng1, 6, '.', '') . ',' . number_format($lat1, 6, '.', '') . ';'
            . number_format($lng2, 6, '.', '') . ',' . number_format($lat2, 6, '.', '')
            . '?overview=false&alternatives=false&steps=false';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'SakayTa/1.0 (school project)',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) {
            error_log('[SakayTa route] request failed: ' . curl_error($ch));
        }
        curl_close($ch);
        if ($body === false || $status !== 200) {
            return null;
        }

        $data = json_decode((string) $body, true);
        $meters = $data['routes'][0]['distance'] ?? null;
        if (($data['code'] ?? '') !== 'Ok' || !is_numeric($meters)) {
            return null;
        }
        $km = round(((float) $meters) / 1000, 4);
        // a road cannot be shorter than the straight line, and a huge detour means the points were
        // snapped to the wrong road: do not trust it, use the fallback
        if ($km < $straight * 0.9 || $km > $straight * 6 + 2) {
            return null;
        }
        return $km;
    }

    private static function readCache(PDO $pdo, string $key): ?float
    {
        try {
            $stmt = $pdo->prepare('SELECT distanceKm FROM route_cache WHERE routeKey = :k LIMIT 1');
            $stmt->execute(['k' => $key]);
            $v = $stmt->fetchColumn();
            return $v === false ? null : (float) $v;
        } catch (\Throwable $e) {
            error_log('[SakayTa route cache] read failed: ' . $e->getMessage());
            return null;
        }
    }

    private static function writeCache(PDO $pdo, string $key, float $km): void
    {
        try {
            $pdo->prepare('INSERT IGNORE INTO route_cache (routeKey, distanceKm, createdAt) VALUES (:k, :km, :now)')
                ->execute(['k' => $key, 'km' => $km, 'now' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            error_log('[SakayTa route cache] write failed: ' . $e->getMessage());
        }
    }
}