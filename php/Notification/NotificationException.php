<?php declare(strict_types=1);

namespace Sakayta\Notification;

use RuntimeException;

final class NotificationException extends RuntimeException
{
    public function __construct(string $message, private int $httpStatus = 400)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
