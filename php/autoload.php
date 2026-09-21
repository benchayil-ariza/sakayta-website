<?php declare(strict_types=1);

/**
 * SakayTa — minimal PSR-4-ish autoloader for the php/ foundation.
 *
 * Registers the `Sakayta\` namespace against php/ so that
 *   Sakayta\Config\Config  -> php/config/Config.php
 *   Sakayta\Db\Database    -> php/db/Database.php
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Sakayta\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));          // Config\Config
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});