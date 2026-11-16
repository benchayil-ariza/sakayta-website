<?php declare(strict_types=1);

/**
 * SakayTa: place-name lookup (reverse geocoding) through our own server.
 *
 * The Leaflet Control Geocoder plugin in the page asks THIS route instead of asking
 * OpenStreetMap directly. Why:
 *  - OpenStreetMap wants every app to identify itself (a User-Agent). A browser cannot set it, PHP can.
 *  - We keep to its rule of at most 1 request per second, for the whole server.
 *  - Answers are saved in geocode_cache, so the same spot is never asked twice.
 *  - No cross-origin trouble: the page only talks to our own API.
 */

namespace Sakayta\Geo;

use PDO;
use Sakayta\Db\Database;
use Sakayta\Http\Response;

final class ReverseGeocoder
{
    // OpenStreetMap asks every app to name itself. Put a REAL contact here (your group's email or a website).
    private const USER_AGENT = 'SakayTa/1.0 (school project; contact: benchayilariza69@gmail.com)';
    private const SERVICE_URL = 'https://nominatim.openstreetmap.org/reverse';
    private const MIN_GAP_SECONDS = 1.1;
    private const CACHE_DAYS = 30;

    /** GET /api/geocode/reverse?lat=..&lon=..&zoom=.. */
    public static function reverse(): void
    {
        $lat = $_GET['lat'] ?? null;
        $lon = $_GET['lon'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lon)) {
            Response::json(400, ['error' => 'lat and lon must be numbers.']);
            return;
        }
        $lat = (float) $lat;
        $lon = (float) $lon;
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            Response::json(400, ['error' => 'Coordinates are out of range.']);
            return;
        }
        $zoom = max(3, min(18, (int) ($_GET['zoom'] ?? 18)));

        $key = sha1(number_format($lat, 4, '.', '') . ',' . number_format($lon, 4, '.', '') . '|' . $zoom);
        $pdo = Database::connection();

        $cached = self::readCache($pdo, $key);
        if ($cached !== null) {
            Response::json(200, $cached);
            return;
        }

        $data = self::askOpenStreetMap($lat, $lon, $zoom);
        if ($data === null) {
            Response::json(502, ['error' => 'Place lookup is unavailable right now.']);
            return;
        }
        if (isset($data['address']) || isset($data['display_name'])) {   // do not save "unable to geocode" answers
            self::writeCache($pdo, $key, $data);
        }
        Response::json(200, $data);
    }

    /** One request to OpenStreetMap. A lock file keeps all requests of the server 1.1 seconds apart. */
    private static function askOpenStreetMap(float $lat, float $lon, int $zoom): ?array
    {
        if (!function_exists('curl_init')) {
            error_log('[SakayTa geocode] the PHP curl extension is not enabled');
            return null;
        }
        $url = self::SERVICE_URL . '?' . http_build_query([
            'format'          => 'json',
            'lat'             => number_format($lat, 6, '.', ''),
            'lon'             => number_format($lon, 6, '.', ''),
            'zoom'            => $zoom,
            'addressdetails'  => 1,
            'accept-language' => 'en',
        ]);

        $lock = @fopen(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sakayta_geocode.lock', 'c+');
        if ($lock !== false) {
            flock($lock, LOCK_EX);
            $last = (float) trim((string) stream_get_contents($lock));
            $wait = self::MIN_GAP_SECONDS - (microtime(true) - $last);
            if ($wait > 0 && $wait <= self::MIN_GAP_SECONDS) {
                usleep((int) ($wait * 1000000));
            }
        }

        $data = self::fetchJson($url);

        if ($lock !== false) {
            ftruncate($lock, 0);
            rewind($lock);
            fwrite($lock, (string) microtime(true));
            fflush($lock);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $data;
    }

    private static function fetchJson(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => self::USER_AGENT,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) {
            error_log('[SakayTa geocode] request failed: ' . curl_error($ch));
        } elseif ($status !== 200) {
            error_log('[SakayTa geocode] OpenStreetMap answered HTTP ' . $status);
        }
        curl_close($ch);
        if ($body === false || $status !== 200) {
            return null;
        }
        $data = json_decode((string) $body, true);
        return is_array($data) ? $data : null;
    }

    private static function readCache(PDO $pdo, string $key): ?array
    {
        try {
            $stmt = $pdo->prepare('SELECT response FROM geocode_cache WHERE cacheKey = :k AND createdAt > :cut LIMIT 1');
            $stmt->execute(['k' => $key, 'cut' => date('Y-m-d H:i:s', strtotime('-' . self::CACHE_DAYS . ' days'))]);
            $v = $stmt->fetchColumn();
            if ($v === false) {
                return null;
            }
            $data = json_decode((string) $v, true);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            error_log('[SakayTa geocode cache] read failed: ' . $e->getMessage());
            return null;
        }
    }

    private static function writeCache(PDO $pdo, string $key, array $data): void
    {
        try {
            $pdo->prepare(
                'INSERT INTO geocode_cache (cacheKey, response, createdAt) VALUES (:k, :r, :n)
                 ON DUPLICATE KEY UPDATE response = VALUES(response), createdAt = VALUES(createdAt)'
            )->execute(['k' => $key, 'r' => json_encode($data), 'n' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            error_log('[SakayTa geocode cache] write failed: ' . $e->getMessage());
        }
    }
}