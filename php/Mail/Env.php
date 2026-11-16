<?php declare(strict_types=1);

namespace Sakayta\Mail;

/** Reads a setting from the environment or from php/config/.env (that file is never committed to Git). */
final class Env
{
    /** @var array<string,string>|null */
    private static ?array $file = null;

    public static function get(string $key, string $default = ''): string
    {
        $v = getenv($key);
        if (is_string($v) && $v !== '') {
            return $v;
        }
        if (isset($_ENV[$key]) && is_string($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }
        if (self::$file === null) {
            self::$file = self::load();
        }
        return self::$file[$key] ?? $default;
    }

    /** @return array<string,string> */
    private static function load(): array
    {
        $out = [];
        $path = __DIR__ . '/../config/.env';
        if (!is_readable($path)) {
            return $out;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if (strlen($v) >= 2 && (($v[0] === '"' && substr($v, -1) === '"') || ($v[0] === "'" && substr($v, -1) === "'"))) {
                $v = substr($v, 1, -1);
            }
            $out[$k] = $v;
        }
        return $out;
    }
}