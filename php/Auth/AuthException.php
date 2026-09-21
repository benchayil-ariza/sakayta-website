<?php declare(strict_types=1);

/**
 * SakayTa — auth-flow failure carrying the HTTP status the router should emit.
 * Kept as the ONLY exception type the auth layer throws so the API entry point
 * can map errors to JSON without leaking internals.
 */

namespace Sakayta\Auth;

use RuntimeException;

final class AuthException extends RuntimeException
{
    private int $httpStatus;

    public function __construct(string $message, int $httpStatus = 400)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}