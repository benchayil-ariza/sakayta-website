<?php declare(strict_types=1);

namespace Sakayta\Notification;

use Sakayta\Db\Database;

final class NotificationService
{
    /** @return array{count:int,notifications:array<int,array<string,mixed>>} */
    public function listForUser(string $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, userId, rideId, type, title, message, `read`, createdAt,
                    createdAt AS created_at
             FROM notifications
             WHERE userId = :userId
             ORDER BY createdAt DESC'
        );
        $stmt->execute(['userId' => $userId]);
        $notifications = array_map(self::normalizeNotification(...), $stmt->fetchAll());

        return ['count' => count($notifications), 'notifications' => $notifications];
    }

    /** @return array<string,mixed> */
    public function markRead(string $userId, string $notificationId): array
    {
        $this->requireOwnership($userId, $notificationId);

        $stmt = Database::connection()->prepare(
            'UPDATE notifications SET `read` = 1 WHERE id = :id AND userId = :userId'
        );
        $stmt->execute(['id' => $notificationId, 'userId' => $userId]);

        $notification = $this->findOwned($userId, $notificationId);
        if ($notification === null) {
            throw new NotificationException('Notification not found', 404);
        }

        return self::normalizeNotification($notification);
    }

    public function delete(string $userId, string $notificationId): void
    {
        $this->requireOwnership($userId, $notificationId);

        $stmt = Database::connection()->prepare(
            'DELETE FROM notifications WHERE id = :id AND userId = :userId'
        );
        $stmt->execute(['id' => $notificationId, 'userId' => $userId]);
        if ($stmt->rowCount() !== 1) {
            throw new NotificationException('Notification not found', 404);
        }
    }

    private function requireOwnership(string $userId, string $notificationId): void
    {
        if ($this->findOwned($userId, $notificationId) !== null) {
            return;
        }

        $stmt = Database::connection()->prepare(
            'SELECT userId FROM notifications WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $notificationId]);
        $ownerId = $stmt->fetchColumn();
        if ($ownerId === false) {
            throw new NotificationException('Notification not found', 404);
        }

        throw new NotificationException('You do not have access to this notification', 403);
    }

    /** @return array<string,mixed>|null */
    private function findOwned(string $userId, string $notificationId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, userId, rideId, type, title, message, `read`, createdAt,
                    createdAt AS created_at
             FROM notifications
             WHERE id = :id AND userId = :userId
             LIMIT 1'
        );
        $stmt->execute(['id' => $notificationId, 'userId' => $userId]);
        $notification = $stmt->fetch();

        return $notification === false ? null : $notification;
    }

    /** @param array<string,mixed> $notification @return array<string,mixed> */
    private static function normalizeNotification(array $notification): array
    {
        $notification['read'] = (int) $notification['read'];
        return $notification;
    }
}
