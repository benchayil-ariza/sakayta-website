<?php declare(strict_types=1);

namespace Sakayta\Mail;

use Sakayta\Db\Database;

/**
 * Sends the receipt of a completed ride to the passenger and to the driver (their account email).
 * Safe to call more than once: an email that was already sent is skipped.
 */
final class ReceiptMailer
{
    /** @return array<string,mixed>|null */
    public static function loadData(string $rideId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT t.id AS txId, t.rideId, t.fareAmount, t.paymentMethod, t.rideType, t.distanceKm,
                    t.passengerName, t.driverName, t.completedAt,
                    t.pickupLat, t.pickupLng, t.dropoffLat, t.dropoffLng,
                    r.pickupAddress, r.dropoffAddress,
                    pu.email AS passengerEmail, du.email AS driverEmail
             FROM `transactions` t
             JOIN rides r ON r.id = t.rideId
             LEFT JOIN users pu ON pu.id = t.passengerUserId
             LEFT JOIN drivers d ON d.id = t.driverId
             LEFT JOIN users du ON du.id = d.user_id
             WHERE t.rideId = :rid
             LIMIT 1'
        );
        $stmt->execute(['rid' => $rideId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function sendForRide(string $rideId): void
    {
        $t = self::loadData($rideId);
        if ($t === null) {
            return;
        }
        $pdo = Database::connection();
        $people = [
            ['passenger', (string) ($t['passengerEmail'] ?? ''), (string) ($t['passengerName'] ?? '')],
            ['driver',    (string) ($t['driverEmail'] ?? ''),    (string) ($t['driverName'] ?? '')],
        ];
        foreach ($people as [$role, $email, $name]) {
            $to = strtolower(trim($email));
            if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $kind = 'receipt_' . $role;
            $check = $pdo->prepare('SELECT status FROM email_log WHERE kind = :k AND refId = :r AND toEmail = :e LIMIT 1');
            $check->execute(['k' => $kind, 'r' => $rideId, 'e' => $to]);
            if ($check->fetchColumn() === 'sent') {
                continue;
            }
            $mail = MailTemplates::receipt($t, $role);
            $res = BrevoMailer::send($to, $name, $mail['subject'], $mail['html'], $mail['text'], ['receipt']);
            MailLog::record($pdo, $kind, $rideId, $to, $res);
        }
    }
}