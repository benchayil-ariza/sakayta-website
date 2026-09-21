<?php declare(strict_types=1);

/**
 * SakayTa — shared JSON response helper for controllers.
 * Sets the status code, content type, encodes the payload, and ends the
 * request. Internal values (DSN, passwords, secrets) are never included here.
 */

namespace Sakayta\Http;

final class Response
{
    public static function json(int $status, array $payload): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}