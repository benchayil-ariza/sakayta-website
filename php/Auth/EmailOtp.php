<?php declare(strict_types=1);

namespace Sakayta\Auth;

use PDO;
use Sakayta\Db\Database;
use Sakayta\Http\Response;
use Sakayta\Mail\BrevoMailer;
use Sakayta\Mail\Env;
use Sakayta\Mail\MailLog;
use Sakayta\Mail\MailTemplates;

/**
 * Email codes, sent with Brevo. Two uses:
 *
 *  SIGN UP
 *   POST /api/auth/otp/send    {email}         sends a 6-digit code
 *   POST /api/auth/otp/verify  {email, code}   returns a one-time emailToken
 *   register must then include the email and the emailToken (checked by requireVerified())
 *
 *  FORGOT PASSWORD
 *   POST /api/auth/password/forgot  {email}                                              sends a code (same answer for every email)
 *   POST /api/auth/password/reset   {email, code, newPassword, confirmPassword}          changes the password
 *
 * Only a keyed hash of each code is saved. A code works for 10 minutes, for 5 tries, and once.
 */
final class EmailOtp
{
    private const CODE_MINUTES = 10;
    private const MAX_WRONG = 5;
    private const RESEND_SECONDS = 60;
    private const MAX_PER_HOUR_EMAIL = 5;
    private const MAX_PER_HOUR_IP = 15;
    private const TOKEN_MINUTES = 20;
    private const MIN_PASSWORD = 8;
    private const MAX_PASSWORD = 72;     // bcrypt only uses the first 72 bytes
    /** Leave empty to accept any email. Use ['gmail.com'] if your instructor wants Gmail addresses only. */
    private const ALLOWED_DOMAINS = [];

    // ------------------------------------------------------------------ sign up

    public static function send(): void
    {
        $email = self::cleanEmail(self::body()['email'] ?? null);
        if ($email === null) {
            self::fail(400, 'Enter a valid email address.');
        }
        $domain = substr($email, (int) strrpos($email, '@') + 1);
        if (self::ALLOWED_DOMAINS !== [] && !in_array($domain, self::ALLOWED_DOMAINS, true)) {
            self::fail(400, 'Please use a ' . implode(' or ', self::ALLOWED_DOMAINS) . ' email address.');
        }

        $pdo = Database::connection();
        self::cleanup($pdo);
        if (self::userId($pdo, $email) !== null) {
            self::fail(409, 'An account with this email already exists. Try logging in instead.');
        }
        self::throttle($pdo, $email, 'register');

        $code = self::newCode();
        $id = self::store($pdo, $email, 'register', $code);
        $mail = MailTemplates::otp($code, self::CODE_MINUTES, 'register');
        $res = BrevoMailer::send($email, '', $mail['subject'], $mail['html'], $mail['text'], ['otp']);
        MailLog::record($pdo, 'otp', null, $email, $res);
        if (!$res['ok']) {
            $pdo->prepare('DELETE FROM email_otps WHERE id = :id')->execute(['id' => $id]);
            self::fail(502, 'We could not send the email right now. Please try again in a moment.');
        }

        Response::json(200, [
            'ok' => true,
            'expiresInSeconds' => self::CODE_MINUTES * 60,
            'resendAfterSeconds' => self::RESEND_SECONDS,
        ]);
    }

    public static function verify(): void
    {
        $body = self::body();
        $email = self::cleanEmail($body['email'] ?? null);
        $code = preg_replace('/\D+/', '', (string) ($body['code'] ?? '')) ?? '';
        if ($email === null || strlen($code) !== 6) {
            self::fail(400, 'Enter the 6-digit code.');
        }

        $pdo = Database::connection();
        $row = self::latest($pdo, $email, 'register');
        $now = date('Y-m-d H:i:s');

        if (!$row || $row['verifiedAt'] !== null || (string) $row['expiresAt'] <= $now) {
            self::fail(400, 'That code has expired. Please request a new one.');
        }
        self::checkCode($pdo, $row, 'register', $email, $code);

        $token = bin2hex(random_bytes(24));
        $upd = $pdo->prepare(
            'UPDATE email_otps SET verifiedAt = :v, tokenHash = :th
             WHERE id = :id AND verifiedAt IS NULL AND attempts < :max AND expiresAt > :now'
        );
        $upd->execute(['v' => $now, 'th' => hash('sha256', $token), 'id' => $row['id'], 'max' => self::MAX_WRONG, 'now' => $now]);
        if ($upd->rowCount() !== 1) {
            self::fail(409, 'Please request a new code.');
        }
        Response::json(200, [
            'verified' => true,
            'emailToken' => $token,
            'validForSeconds' => self::TOKEN_MINUTES * 60,
        ]);
    }

    /** Stops registration unless this email was verified with a code, by the same browser, in the last 20 minutes. */
    public static function requireVerified(): void
    {
        $body = self::body();
        // the signup page sends the email as "identifier". It must be an email address
        $email = self::cleanEmail($body['identifier'] ?? ($body['email'] ?? null));
        $token = (string) ($body['emailToken'] ?? '');
        $ok = false;
        if ($email !== null && preg_match('/^[a-f0-9]{48}$/', $token) === 1) {
            $stmt = Database::connection()->prepare(
                "SELECT 1 FROM email_otps
                 WHERE email = :e AND purpose = 'register' AND tokenHash = :th
                   AND verifiedAt IS NOT NULL AND verifiedAt > :cut LIMIT 1"
            );
            $stmt->execute([
                'e'   => $email,
                'th'  => hash('sha256', $token),
                'cut' => date('Y-m-d H:i:s', time() - self::TOKEN_MINUTES * 60),
            ]);
            $ok = $stmt->fetchColumn() !== false;
        }
        if (!$ok) {
            self::fail(403, 'Please verify your email with the code we sent.');
        }
    }

    // ---------------------------------------------------------- forgot password

    public static function sendReset(): void
    {
        $email = self::cleanEmail(self::body()['email'] ?? null);
        if ($email === null) {
            self::fail(400, 'Enter a valid email address.');
        }
        $pdo = Database::connection();
        self::cleanup($pdo);
        self::throttle($pdo, $email, 'reset');

        if (self::userId($pdo, $email) !== null) {
            $code = self::newCode();
            $id = self::store($pdo, $email, 'reset', $code);
            $mail = MailTemplates::otp($code, self::CODE_MINUTES, 'reset');
            $res = BrevoMailer::send($email, '', $mail['subject'], $mail['html'], $mail['text'], ['password-reset']);
            MailLog::record($pdo, 'otp_reset', null, $email, $res);
            if (!$res['ok']) {
                $pdo->prepare('DELETE FROM email_otps WHERE id = :id')->execute(['id' => $id]);
            }
        } else {
            // no such account: save an already expired row, so the limits still count this request
            self::store($pdo, $email, 'reset', self::newCode(), true);
        }

        // the same answer in every case, so nobody can use this form to find out who has an account
        Response::json(200, [
            'ok' => true,
            'message' => 'If this email has a SakayTa account, we sent a code.',
            'expiresInSeconds' => self::CODE_MINUTES * 60,
            'resendAfterSeconds' => self::RESEND_SECONDS,
        ]);
    }

    public static function resetPassword(): void
    {
        $body = self::body();
        $email = self::cleanEmail($body['email'] ?? null);
        $code = preg_replace('/\D+/', '', (string) ($body['code'] ?? '')) ?? '';
        $new = (string) ($body['newPassword'] ?? '');
        $confirm = (string) ($body['confirmPassword'] ?? '');
        if ($email === null || strlen($code) !== 6) {
            self::fail(400, 'Enter the 6-digit code.');
        }
        $problem = self::passwordProblem($new);
        if ($problem !== null) {
            self::fail(400, $problem);
        }
        if ($new !== $confirm) {
            self::fail(400, 'The two passwords do not match.');
        }

        $pdo = Database::connection();
        $row = self::latest($pdo, $email, 'reset');
        $now = date('Y-m-d H:i:s');
        if (!$row || $row['verifiedAt'] !== null || (string) $row['expiresAt'] <= $now) {
            self::fail(400, 'That code has expired. Please request a new one.');
        }
        self::checkCode($pdo, $row, 'reset', $email, $code);

        $stmt = $pdo->prepare('SELECT id, full_name FROM users WHERE LOWER(email) = :e LIMIT 1');
        $stmt->execute(['e' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            self::fail(400, 'That code has expired. Please request a new one.');
        }

        $pdo->beginTransaction();
        try {
            // use the code up first: only one request can win
            $used = $pdo->prepare(
                'UPDATE email_otps SET verifiedAt = :v
                 WHERE id = :id AND verifiedAt IS NULL AND attempts < :max AND expiresAt > :now'
            );
            $used->execute(['v' => $now, 'id' => $row['id'], 'max' => self::MAX_WRONG, 'now' => $now]);
            if ($used->rowCount() !== 1) {
                $pdo->rollBack();
                self::fail(409, 'Please request a new code.');
            }
            $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
                ->execute(['h' => password_hash($new, PASSWORD_BCRYPT), 'id' => $user['id']]);
            // any other open reset code for this email stops working
            $pdo->prepare("UPDATE email_otps SET expiresAt = :n WHERE email = :e AND purpose = 'reset' AND verifiedAt IS NULL")
                ->execute(['n' => $now, 'e' => $email]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[SakayTa password reset] ' . get_class($e) . ': ' . $e->getMessage());
            self::fail(500, 'Could not change the password. Please try again.');
        }

        // a security notice, in case it was not the owner. A mail problem does not change the result
        $mail = MailTemplates::passwordChanged((string) ($user['full_name'] ?? ''), date('M j, Y, g:i A'));
        $res = BrevoMailer::send($email, (string) ($user['full_name'] ?? ''), $mail['subject'], $mail['html'], $mail['text'], ['security']);
        MailLog::record($pdo, 'password_changed', null, $email, $res);

        Response::json(200, ['ok' => true, 'message' => 'Your password was changed. You can log in now.']);
    }

    // ------------------------------------------------------------------ helpers

       private static function passwordProblem(string $p): ?string
    {
        // keep this rule the same as the signup rule in AuthService (8 or more characters)
        if (strlen($p) < self::MIN_PASSWORD) {
            return 'Use at least ' . self::MIN_PASSWORD . ' characters.';
        }
        if (strlen($p) > self::MAX_PASSWORD) {
            return 'The password is too long (' . self::MAX_PASSWORD . ' characters at most).';
        }
        return null;
    }

    /** Wrong tries and the check of the code itself. Ends the request when the code is wrong. */
    private static function checkCode(PDO $pdo, array $row, string $purpose, string $email, string $code): void
    {
        if ((int) $row['attempts'] >= self::MAX_WRONG) {
            self::fail(429, 'Too many wrong tries. Please request a new code.');
        }
        if (!hash_equals((string) $row['codeHash'], self::hash($purpose, $email, $code))) {
            $pdo->prepare('UPDATE email_otps SET attempts = attempts + 1 WHERE id = :id')->execute(['id' => $row['id']]);
            $left = max(0, self::MAX_WRONG - 1 - (int) $row['attempts']);
            Response::json(400, [
                'error' => $left > 0 ? 'That code is not correct.' : 'Too many wrong tries. Please request a new code.',
                'attemptsLeft' => $left,
            ]);
            exit;
        }
    }

    /** @return array<string,mixed>|false */
    private static function latest(PDO $pdo, string $email, string $purpose): array|false
    {
        $stmt = $pdo->prepare(
            'SELECT id, codeHash, attempts, expiresAt, verifiedAt FROM email_otps
             WHERE email = :e AND purpose = :p ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['e' => $email, 'p' => $purpose]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private static function store(PDO $pdo, string $email, string $purpose, string $code, bool $expired = false): int
    {
        $now = time();
        $pdo->prepare(
            'INSERT INTO email_otps (email, purpose, codeHash, expiresAt, ip, createdAt)
             VALUES (:e, :p, :h, :x, :ip, :n)'
        )->execute([
            'e'  => $email,
            'p'  => $purpose,
            'h'  => self::hash($purpose, $email, $code),
            'x'  => date('Y-m-d H:i:s', $expired ? $now - 1 : $now + self::CODE_MINUTES * 60),
            'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'n'  => date('Y-m-d H:i:s', $now),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** Slows down repeats and abuse: 60 seconds between codes, and limits per email and per IP. */
    private static function throttle(PDO $pdo, string $email, string $purpose): void
    {
        $last = $pdo->prepare('SELECT createdAt FROM email_otps WHERE email = :e AND purpose = :p ORDER BY id DESC LIMIT 1');
        $last->execute(['e' => $email, 'p' => $purpose]);
        $lastAt = $last->fetchColumn();
        if (is_string($lastAt)) {
            $wait = self::RESEND_SECONDS - (time() - (int) strtotime($lastAt));
            if ($wait > 0) {
                Response::json(429, ['error' => 'Please wait ' . $wait . ' seconds before asking for a new code.', 'retryAfter' => $wait]);
                exit;
            }
        }
        $since = date('Y-m-d H:i:s', time() - 3600);
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $byEmail = $pdo->prepare('SELECT COUNT(*) FROM email_otps WHERE email = :e AND purpose = :p AND createdAt > :s');
        $byEmail->execute(['e' => $email, 'p' => $purpose, 's' => $since]);
        $byIp = $pdo->prepare('SELECT COUNT(*) FROM email_otps WHERE ip = :ip AND createdAt > :s');
        $byIp->execute(['ip' => $ip, 's' => $since]);
        if ((int) $byEmail->fetchColumn() >= self::MAX_PER_HOUR_EMAIL || (int) $byIp->fetchColumn() >= self::MAX_PER_HOUR_IP) {
            self::fail(429, 'Too many codes were requested. Please try again later.');
        }
    }

    private static function cleanup(PDO $pdo): void
    {
        $pdo->prepare('DELETE FROM email_otps WHERE createdAt < :old')->execute(['old' => date('Y-m-d H:i:s', time() - 86400)]);
    }

    private static function userId(PDO $pdo, string $email): ?string
    {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = :e LIMIT 1');
        $stmt->execute(['e' => $email]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (string) $id;
    }

    private static function newCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private static function hash(string $purpose, string $email, string $code): string
    {
        $secret = Env::get('AUTH_TOKEN_SECRET');
        if ($secret === '') {
            error_log('[SakayTa otp] AUTH_TOKEN_SECRET is missing');
            self::fail(500, 'Server is not configured.');
        }
        return hash_hmac('sha256', $purpose . '|' . $email . '|' . $code, $secret);
    }

    private static function cleanEmail(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = strtolower(trim($v));
        if ($v === '' || strlen($v) > 190 || filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        return $v;
    }

    /** @return array<string,mixed> */
    private static function body(): array
    {
        $raw = file_get_contents('php://input');
        $data = ($raw === false || trim($raw) === '') ? [] : json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private static function fail(int $status, string $message): never
    {
        Response::json($status, ['error' => $message]);
        exit;
    }
}