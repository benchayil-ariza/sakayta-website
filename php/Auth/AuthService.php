<?php declare(strict_types=1);

/**
 * SakayTa — authentication service (FINAL SRS / PHP foundation).
 *
 * Pure logic layer: performs no HTTP output. Behaviour mirrors the existing
 * Node routes/auth.js (field names, messages, status codes) but is backed by
 * PDO prepared statements on MariaDB and PHP-native password hashing.
 *
 * Tokens are stateless HMAC-SHA256 signed bearer tokens (header.payload.sig)
 * carrying { uid, role, iat, exp }; exp defaults to 7 days like Node's JWT.
 */

namespace Sakayta\Auth;

use PDO;
use PDOException;
use RuntimeException;
use Sakayta\Config\Config;
use Sakayta\Db\Database;

final class AuthService
{
    private const ALLOWED_REGISTER_ROLES = ['commuter', 'driver'];
    private const TOKEN_TTL_SECONDS = 7 * 86400;   // matches Node JWT expiresIn "7d"
    private const TOKEN_ALGO = 'sha256';

    // ------------------------------------------------------------------ Public

    /**
     * Register a commuter or driver account.
     * Public registration can never create an admin (admin stays bootstrap-only).
     *
     * @return array{message:string, user:array{id:string,email:string,full_name:string,role:string}, token:string}
     */
    /** Normalize Philippine mobile formats to E.164 (+639...). */
    private function normalizeMobile(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        // Strip common separators
        $digits = preg_replace('/[\s\-\(\)\.]/', '', $raw);
        // If starts with +, keep as-is after stripping non-digits after +
        if (preg_match('/^\+\d+$/', $digits)) {
            return $digits;
        }
        // 09... (11 chars total: 0 + 10 digits) → +639...
        if (preg_match('/^0[9]\d{9}$/', $digits)) {
            return '+63' . substr($digits, 1);
        }
        // 639... → +639...
        if (preg_match('/^63\d{10}$/', $digits)) {
            return '+' . $digits;
        }
        // 9... (10 digits, starts with 9) → +639... (assume local without 0)
        if (preg_match('/^9\d{9}$/', $digits)) {
            return '+63' . $digits;
        }
        // Otherwise if it starts with + already handled above; return null for unrecognised
        return null;
    }

    /** Determine if identifier looks like a mobile number. */
    private function isMobileIdentifier(string $id): bool
    {
        $norm = $this->normalizeMobile($id);
        return $norm !== null;
    }

    /** @return array{message:string, user:array{id:string,email:string,full_name:string,role:string,mobile:?string}, token:string} */
    public function register(string $identifier, string $fullName, string $password, ?string $confirmPassword, string $role): array
    {
        $identifier = strtolower(trim($identifier));
        $fullName = trim($fullName);
        $role = strtolower(trim($role));
        $confirmPassword = $confirmPassword ?? '';

        $isMobile = $this->isMobileIdentifier($identifier);
        $email = $isMobile ? null : $identifier;
        $mobile = $isMobile ? $this->normalizeMobile($identifier) : null;

        if ($role === 'admin') {
            throw new AuthException('Admin accounts cannot be registered publicly.', 403);
        }
        if (!in_array($role, self::ALLOWED_REGISTER_ROLES, true)) {
            $role = 'commuter';   // mirrors Node: unknown role defaults to commuter
        }

        // Handle frontend "passenger" → database "commuter" mapping
        if ($role === 'passenger') {
            $role = 'commuter';
        }

        if ($fullName === '' || $password === '' || $confirmPassword === '') {
            throw new AuthException('All fields are required.', 400);
        }
        if ($email !== null && ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            throw new AuthException('Invalid email format.', 400);
        }
        if ($isMobile && $mobile === null) {
            throw new AuthException('Invalid mobile number format.', 400);
        }
        if ($password !== $confirmPassword) {
            throw new AuthException('Passwords do not match.', 400);
        }
        if (strlen($password) < 6) {
            throw new AuthException('Password must be at least 6 characters long.', 400);
        }
        if ($fullName === '' || strlen($fullName) > 255) {
            throw new AuthException('Full name must be 1-255 characters.', 400);
        }

        $pdo = Database::connection();

        // Duplicate-account protection
        $emailConflict = false;
        $mobileConflict = false;
        if ($email !== null) {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $email]);
            if ($stmt->fetchColumn() !== false) {
                $emailConflict = true;
            }
        }
        if ($mobile !== null) {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE mobile = :mobile LIMIT 1');
            $stmt->execute(['mobile' => $mobile]);
            if ($stmt->fetchColumn() !== false) {
                $mobileConflict = true;
            }
        }
        if ($emailConflict) {
            throw new AuthException('Email already registered.', 409);
        }
        if ($mobileConflict) {
            throw new AuthException('Mobile number already registered.', 409);
        }

        $userId = 'u_' . time() . '_' . substr(bin2hex(random_bytes(9)), 0, 9);
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $now = date('Y-m-d H:i:s');

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO users (id, email, full_name, password_hash, role, mobile, created_at)
                 VALUES (:id, :email, :full_name, :password_hash, :role, :mobile, :created_at)'
            );
            $stmt->execute([
                'id'            => $userId,
                'email'         => $email,
                'full_name'     => $fullName,
                'password_hash' => $hash,
                'role'          => $role,
                'mobile'        => $mobile,
                'created_at'    => $now,
            ]);
        } catch (PDOException $e) {
            // Detect duplicate-key violations (MariaDB/1062) by precise error code
            // and constraint name, NOT by substring matching on message text
            // (which wrongly catches "Column 'email' cannot be null" because it
            // contains the word "email"). Any other integrity error is re-thrown
            // as a generic internal error so it is not mislabeled.
            $isDuplicate = ($e->getCode() === '23000' || $e->getCode() === 23000);
            if ($isDuplicate) {
                $msg = strtolower($e->getMessage());
                $errno = $e->errorInfo[1] ?? null;
                $isDupKey = ($errno === 1062) || (strpos($msg, 'duplicate entry') !== false);

                if ($isDupKey) {
                    if (strpos($msg, 'uq_users_mobile') !== false || strpos($msg, 'uq_users_mobile') !== false) {
                        throw new AuthException('Mobile number already registered.', 409);
                    }
                    if (strpos($msg, 'uq_users_email') !== false || strpos($msg, 'email') !== false) {
                        throw new AuthException('Email already registered.', 409);
                    }
                    throw new AuthException('Account already exists.', 409);
                }
                // Non-duplicate integrity violation (e.g. NOT NULL, foreign key)
                if (strpos($msg, 'uq_users_mobile') !== false) {
                    throw new AuthException('Mobile number already registered.', 409);
                }
                if (strpos($msg, 'uq_users_email') !== false) {
                    throw new AuthException('Email already registered.', 409);
                }
            }
            // Any unexpected DB error is surfaced as a generic 500-style message
            throw new AuthException('Registration failed due to a database error.', 500);
        }

        $userResponse = ['id' => $userId, 'email' => $email, 'full_name' => $fullName, 'role' => $role];
        if ($mobile !== null) {
            $userResponse['mobile'] = $mobile;
        }

        return [
            'message' => 'Account created successfully.',
            'user'    => $userResponse,
            'token'   => $this->issueToken($userId, $role),
        ];
    }

    /**
     * Verify credentials and issue a signed token.
     * One error message for missing account and wrong password (no enumeration).
     *
     * @return array{message:string, user:array{id:string,email:string,full_name:string,role:string,mobile:?string}, token:string}
     */
    public function login(string $identifier, string $password): array
    {
        $identifier = strtolower(trim($identifier));

        if ($identifier === '' || $password === '') {
            throw new AuthException('Email or mobile and password are required.', 400);
        }

        $isMobile = $this->isMobileIdentifier($identifier);
        $email = $isMobile ? null : $identifier;
        $mobile = $isMobile ? $this->normalizeMobile($identifier) : null;

        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AuthException('Invalid email format.', 400);
        }
        if ($isMobile && $mobile === null) {
            throw new AuthException('Invalid mobile number format.', 400);
        }

        $pdo = Database::connection();
        if ($mobile !== null) {
            $stmt = $pdo->prepare(
                'SELECT id, email, full_name, password_hash, role, mobile, created_at FROM users WHERE mobile = :mobile LIMIT 1'
            );
            $stmt->execute(['mobile' => $mobile]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, email, full_name, password_hash, role, mobile, created_at FROM users WHERE email = :email LIMIT 1'
            );
            $stmt->execute(['email' => $email]);
        }
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            throw new AuthException('Invalid email/mobile or password.', 401);
        }

        $userResponse = [
            'id'        => $user['id'],
            'email'     => $user['email'],
            'full_name' => $user['full_name'],
            'role'      => $user['role'],
        ];
        if ($user['mobile'] !== null) {
            $userResponse['mobile'] = $user['mobile'];
        }

        return [
            'message' => 'Login successful.',
            'user'    => $userResponse,
            'token'   => $this->issueToken($user['id'], $user['role']),
        ];
    }

    /**
     * Validate a bearer token and return the logged-in user (no password fields).
     *
     * @return array{id:string,email:string,full_name:string,role:string,created_at:string}
     */
    public function me(string $token): array
    {
        $claims = $this->verifyToken($token);

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT id, email, full_name, role, created_at FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $claims['uid']]);
        $user = $stmt->fetch();

        if (!$user) {
            throw new AuthException('User not found. Token may be invalid.', 401);
        }
        return $user;
    }

    // ------------------------------------------------------------------ Token

    /** @return string header.payload.signature (all base64url, HMAC-SHA256). */
    public function issueToken(string $userId, string $role): string
    {
        $now = time();
        $header  = $this->b64urlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $payload = $this->b64urlEncode(json_encode([
            'uid'  => $userId,
            'role' => $role,
            'iat'  => $now,
            'exp'  => $now + self::TOKEN_TTL_SECONDS,
        ], JSON_UNESCAPED_SLASHES));

        $signingInput = $header . '.' . $payload;
        $signature    = $this->b64urlEncode(
            hash_hmac(self::TOKEN_ALGO, $signingInput, $this->secret(), true)
        );

        return $signingInput . '.' . $signature;
    }

    /**
     * Verify signature (constant-time compare), structure and expiry.
     * Throws AuthException 401 for anything invalid; caller maps to JSON.
     *
     * @return array{uid:string,role:string,iat:int,exp:int}
     */
    public function verifyToken(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] === '' || $parts[1] === '' || $parts[2] === '') {
            throw new AuthException('Invalid token.', 401);
        }
        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $expected = $this->b64urlEncode(
            hash_hmac(self::TOKEN_ALGO, $headerB64 . '.' . $payloadB64, $this->secret(), true)
        );
        if ($expected === '' || !hash_equals($expected, $signatureB64)) {
            throw new AuthException('Invalid token.', 401);
        }

        $payload = json_decode($this->b64urlDecode($payloadB64), true);
        if (!is_array($payload)
            || !isset($payload['uid'], $payload['role'], $payload['iat'], $payload['exp'])) {
            throw new AuthException('Invalid token.', 401);
        }

        if ($payload['exp'] <= time()) {
            throw new AuthException('Token expired. Please log in again.', 401);
        }

        return [
            'uid'  => (string) $payload['uid'],
            'role' => (string) $payload['role'],
            'iat'  => (int) $payload['iat'],
            'exp'  => (int) $payload['exp'],
        ];
    }

    // ---------------------------------------------------------------- Helpers

    private function secret(): string
    {
        $secret = Config::env('AUTH_TOKEN_SECRET', '');
        if (strlen($secret) < 16) {
            throw new RuntimeException(
                'AUTH_TOKEN_SECRET is missing or too short. Set it in php/config/.env.'
            );
        }
        return $secret;
    }

    private function b64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $data): string
    {
        $b64 = strtr($data, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($b64, true);
        if ($decoded === false) {
            throw new AuthException('Invalid token.', 401);
        }
        return $decoded;
    }
}