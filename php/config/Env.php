<?php declare(strict_types=1);

/**
 * SakayTa — minimal private .env loader (no third-party dependency).
 *
 * Reads KEY=VALUE lines from php/config/.env (or $SAKAYTA_ENV_FILE) and
 * populates getenv() unless the variable is already set. Values are not
 * auto-cast; callers interpret them. This loader owns NO secrets — it only
 * reads them from a file that is git-ignored and outside any web-root.
 *
 * Supported line syntax:
 *   KEY=value                (assigned)
 *   # comment                (ignored)
 *   empty lines              (ignored)
 *   optional export KEY=val  ('export ' prefix is stripped on assignment)
 */

namespace Sakayta\Config;

final class Env
{
    private const RESERVED = ['HTTP_PROXY'];

    public static function load(?string $path = null): bool
    {
        if ($path === null) {
            // php/config/.env is the default; __DIR__ = php/config when this
            // file lives in php/config/Env.php.
            $path = __DIR__ . '/.env';
        }

        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        $loaded = false;
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key   = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // Strip surrounding quotes if present.
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last  = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            if ($key === '' || in_array($key, self::RESERVED, true)) {
                continue;
            }

            if (getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
            $loaded = true;
        }

        fclose($handle);
        return $loaded;
    }
}