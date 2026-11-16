<?php declare(strict_types=1);

namespace Sakayta\Mail;

use PDO;

/** A short record of each email we tried to send. No message text and no codes are kept. */
final class MailLog
{
    /** @param array{ok:bool,id:?string,error:?string} $res */
    public static function record(PDO $pdo, string $kind, ?string $refId, string $to, array $res): void
    {
        try {
            $pdo->prepare(
                'INSERT INTO email_log (kind, refId, toEmail, status, providerId, error, createdAt)
                 VALUES (:k, :r, :e, :s, :p, :err, :n)
                 ON DUPLICATE KEY UPDATE status = VALUES(status), providerId = VALUES(providerId),
                                         error = VALUES(error), createdAt = VALUES(createdAt)'
            )->execute([
                'k'   => $kind,
                'r'   => $refId,
                'e'   => $to,
                's'   => $res['ok'] ? 'sent' : 'failed',
                'p'   => ($res['id'] ?? '') !== '' ? mb_substr((string) $res['id'], 0, 120) : null,
                'err' => $res['error'],
                'n'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[SakayTa mail log] ' . $e->getMessage());
        }
    }
}