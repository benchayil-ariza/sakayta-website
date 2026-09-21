<?php declare(strict_types=1);

/**
 * SakayTa — driver-domain failure carrying the HTTP status for the router.
 * Mirrors Sakayta\Auth\AuthException so the API entry point can map driver
 * errors to JSON without leaking internals.
 */

namespace Sakayta\Driver;

use RuntimeException;

final class DriverException extends RuntimeException
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