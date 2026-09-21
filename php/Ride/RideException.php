<?php declare(strict_types=1);

/**
 * SakayTa — ride-domain failure carrying the HTTP status for the router.
 * Mirrors Sakayta\Auth\AuthException and Sakayta\Driver\DriverException so the
 * API entry point can map ride errors to JSON without leaking internals.
 */

namespace Sakayta\Ride;

use RuntimeException;

final class RideException extends RuntimeException
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