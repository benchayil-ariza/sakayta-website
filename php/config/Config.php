<?php declare(strict_types=1);

/**
 * SakayTa — central configuration.
 *
 * Reads one source of truth: environment variables, which may come from
 * php/config/.env (via Env::load) or the real process environment. Database
 * credentials are returned to callers but are NEVER echoed to the client by
 * any code in this project; the health endpoint specifically omits them.
 *
 * When SAKAYTA_ENV !== 'production' this module is safe to use in dev.
 */

namespace Sakayta\Config;

use RuntimeException;

final class Config
{
    /** Loads env early so every consumer sees the same values. */
    public static function bootstrap(): void
    {
        Env::load();
    }

    public static function env(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }
        return $value;
    }

    public static function isProduction(): bool
    {
        return self::env('SAKAYTA_ENV', 'development') === 'production';
    }

    /**
     * @return array{host:string, port:int, name:string, user:string, password:string, charset:string}
     */
    public static function database(): array
    {
        $host    = self::env('DB_HOST', '127.0.0.1');
        $port    = (int) self::env('DB_PORT', '3306');
        $name    = self::env('DB_NAME', 'sakayta');
        $user    = self::env('DB_USER', '');
        $password = self::env('DB_PASSWORD', '');

        if ($host === '' || $name === '' || $user === '') {
            throw new RuntimeException(
                'Database configuration is incomplete. Set DB_HOST, DB_NAME, DB_USER (and DB_PASSWORD) '
                . 'in php/config/.env or the environment.'
            );
        }

        return [
            'host'     => $host,
            'port'     => $port,
            'name'     => $name,
            'user'     => $user,
            'password' => $password,
            'charset'  => self::env('DB_CHARSET', 'utf8mb4'),
        ];
    }

    /** Credentials for the schema/database bootstrap script (admin privileges). */
    public static function databaseAdmin(): array
    {
        $user = self::env('DB_ADMIN_USER', 'root');
        $pass = self::env('DB_ADMIN_PASSWORD', '');

        return [
            'host'     => self::env('DB_HOST', '127.0.0.1'),
            'port'     => (int) self::env('DB_PORT', '3306'),
            'user'     => $user,
            'password' => $pass,
        ];
    }
}