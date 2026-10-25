<?php declare(strict_types=1);

namespace Sakayta\Notification;

use Sakayta\Auth\CurrentUser;
use Sakayta\Http\Response;

final class NotificationController
{
    private NotificationService $service;

    public function __construct(?NotificationService $service = null)
    {
        $this->service = $service ?? new NotificationService();
    }

    public function list(): never
    {
        $user = CurrentUser::required();
        Response::json(200, $this->service->listForUser($user['uid']));
    }

    public function markRead(string $id): never
    {
        $user = CurrentUser::required();
        Response::json(200, $this->service->markRead($user['uid'], $id));
    }

    public function delete(string $id): never
    {
        $user = CurrentUser::required();
        $this->service->delete($user['uid'], $id);
        http_response_code(204);
        exit;
    }
}
