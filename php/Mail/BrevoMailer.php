<?php declare(strict_types=1);

namespace Sakayta\Mail;

/**
 * Sends one email through the Brevo transactional API.
 * The key stays on the server (php/config/.env). If Brevo is slow or down we give up quickly
 * and return ok=false, so the caller can carry on. The key is never logged.
 */
final class BrevoMailer
{
    private const API_URL = 'https://api.brevo.com/v3/smtp/email';

    /**
     * @param string[] $tags
     * @return array{ok:bool,id:?string,error:?string}
     */
    public static function send(string $toEmail, string $toName, string $subject, string $html, string $text, array $tags = []): array
    {
        $key  = Env::get('BREVO_API_KEY');
        $from = Env::get('MAIL_FROM_EMAIL');
        $name = Env::get('MAIL_FROM_NAME', 'SakayTa');
        if ($key === '' || $from === '') {
            error_log('[SakayTa mail] BREVO_API_KEY or MAIL_FROM_EMAIL is missing in php/config/.env');
            return ['ok' => false, 'id' => null, 'error' => 'not_configured'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'id' => null, 'error' => 'curl_missing'];
        }

        $to = ['email' => $toEmail];
        if ($toName !== '') {
            $to['name'] = $toName;
        }
        $payload = [
            'sender'      => ['name' => $name, 'email' => $from],
            'to'          => [$to],
            'subject'     => $subject,
            'htmlContent' => $html,
            'textContent' => $text,
        ];
        if ($tags !== []) {
            $payload['tags'] = array_values($tags);
        }

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => [
                'accept: application/json',
                'content-type: application/json',
                'api-key: ' . $key,
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = $body === false ? curl_error($ch) : '';
        curl_close($ch);

        if ($body === false) {
            error_log('[SakayTa mail] request failed: ' . $curlError);
            return ['ok' => false, 'id' => null, 'error' => 'network'];
        }
        $data = json_decode((string) $body, true);
        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'id' => is_array($data) ? (string) ($data['messageId'] ?? '') : null, 'error' => null];
        }
        $reason = is_array($data) ? (string) ($data['message'] ?? '') : '';
        error_log('[SakayTa mail] Brevo answered HTTP ' . $status . ($reason !== '' ? ': ' . mb_substr($reason, 0, 200) : ''));
        return ['ok' => false, 'id' => null, 'error' => 'http_' . $status];
    }
}