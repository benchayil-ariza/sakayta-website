<?php declare(strict_types=1);

/**
 * SakayTa — resolved current user for protected endpoints.
 *
 * Reads the bearer token from the Authorization header, verifies it via
 * AuthService::verifyToken, and returns the token claims {uid, role, iat, exp}.
 * requiredDriver() additionally enforces role === 'driver'.
 *
 * Used by the driver controllers; the auth controller keeps its own private
 * extraction so the already-verified auth path is untouched.
 *
 * NOTE: Apache on Windows often drops HTTP_AUTHORIZATION for mod_php. The
 * .htaccess SetEnvIf passthrough is the primary fix; getallheaders() is the
 * fallback. Keep both.
 */

namespace Sakayta\Auth;

final class CurrentUser
{
    /** @return array{uid:string,role:string,iat:int,exp:int} */
    public static function requiredDriver(): array
    {
        $claims = self::required();
        if (($claims['role'] ?? '') !== 'driver') {
            throw new AuthException('Access denied. Required role(s): driver.', 403);
        }
        return $claims;
    }

    /** @return array{uid:string,role:string,iat:int,exp:int} */
    public static function required(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
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

        $service = new AuthService();
        return $service->verifyToken(trim($m[1]));
    }
}