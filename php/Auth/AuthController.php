<?php declare(strict_types=1);

/**
 * SakayTa — HTTP layer for /api/auth/* (register, login, me).
 * Reads JSON request bodies, extracts the bearer token, and writes JSON
 * responses. All validation + storage happens in AuthService.
 */

namespace Sakayta\Auth;

final class AuthController
{
    private AuthService $service;

    public function __construct(?AuthService $service = null)
    {
        $this->service = $service ?? new AuthService();
    }

    /** POST /api/auth/register -> 201 on success (AuthException otherwise). */
    public function register(): void
    {
        $body = $this->jsonBody();
        $identifier = (string) ($body['identifier'] ?? ($body['email'] ?? ''));
        $result = $this->service->register(
            $identifier,
            (string) ($body['full_name'] ?? ''),
            (string) ($body['password'] ?? ''),
            isset($body['confirm_password']) ? (string) $body['confirm_password'] : null,
            (string) ($body['role'] ?? '')
        );
        $this->respond(201, $result);
    }

    /** POST /api/auth/login -> 200 on success. */
    public function login(): void
    {
        $body = $this->jsonBody();
        $identifier = (string) ($body['identifier'] ?? ($body['email'] ?? ''));
        $result = $this->service->login(
            $identifier,
            (string) ($body['password'] ?? '')
        );
        $this->respond(200, $result);
    }

    /** GET /api/auth/me -> 200 with the current user (no password fields). */
    public function me(): void
    {
        $user = $this->service->me($this->bearerToken());
        $this->respond(200, $user);
    }

    // ---------------------------------------------------------------- Helpers

    /** @return array<string,mixed> */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /** Extract the token from "Authorization: Bearer <token>". */
    private function bearerToken(): string
    {
        // Prefer $_SERVER; fall back to Apache getallheaders() because some
        // Apache/PHP-on-Windows builds do not populate HTTP_AUTHORIZATION.
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if ($header === '' && function_exists('getallheaders')) {
            $all = getallheaders();
            foreach ($all as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = (string) $value;
                    break;
                }
            }
        }

        if ($header === ''
            || preg_match('/^Bearer\s+(.+)$/i', $header, $m) !== 1
            || trim($m[1]) === '') {
            throw new AuthException('No token provided. Authorization required.', 401);
        }
        return trim($m[1]);
    }

    /** Emit a JSON response and stop. */
    private function respond(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}