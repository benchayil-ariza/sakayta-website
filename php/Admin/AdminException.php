<?php declare(strict_types=1);

/**
 * SakayTa — admin-flow failure carrying the HTTP status the router should emit.
 * Kept as the ONLY exception type the admin layer throws so the API entry point
 * can map errors to JSON without leaking internals.
 */

namespace Sakayta\Admin;

use RuntimeException;

final class AdminException extends RuntimeException
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