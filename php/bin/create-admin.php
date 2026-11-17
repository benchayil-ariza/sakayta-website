<?php
/**
 * php/bin/create-admin.php
 * One-time setup tool: creates the FIRST admin account in MariaDB.
 *
 * Why it exists: public signup blocks role=admin on purpose, so the first admin
 * must be made from the command line. This replaces the old Node.js/SQLite
 * scripts/createAdmin.js, which no longer matches the system.
 *
 * Run (PowerShell, from the project folder):
 *   $env:ADMIN_EMAIL="admin@example.com"
 *   $env:ADMIN_PASSWORD="a strong password"
 *   C:\xampp\php\php.exe php\bin\create-admin.php
 *
 * Safety:
 *   - The password comes from the environment, never from a command argument
 *     and never from the code.
 *   - Refuses to run when SAKAYTA_ENV=production.
 *   - If an admin already exists it changes nothing.
 *   - Writes exactly one row in `users`. Stores only a bcrypt hash.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// --- read php/config/.env (real environment variables win) -------------------
$cfg = [];
$envFile = __DIR__ . '/../config/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $cfg[trim($k)] = trim(trim($v), "\"'");
    }
}
$get = static function (string $key, string $default = '') use ($cfg): string {
    $v = getenv($key);
    return ($v !== false && $v !== '') ? $v : ($cfg[$key] ?? $default);
};

function fail(string $msg, int $code = 1): void { fwrite(STDERR, "[create-admin] $msg\n"); exit($code); }

if (strtolower($get('SAKAYTA_ENV')) === 'production') fail('Refusing to run when SAKAYTA_ENV=production.');

$email    = strtolower(trim($get('ADMIN_EMAIL')));
$password = (string) (getenv('ADMIN_PASSWORD') ?: '');
$name     = $get('ADMIN_NAME', 'Administrator');

if ($email === '' || $password === '') fail('Set ADMIN_EMAIL and ADMIN_PASSWORD as environment variables first.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('That email address is not valid.');
if (strlen($password) < 8)  fail('The password must have at least 8 characters.');
if (strlen($password) > 72) fail('The password must have at most 72 characters (bcrypt limit).');

// --- connect -----------------------------------------------------------------
try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $get('DB_HOST', '127.0.0.1'), $get('DB_PORT', '3306'), $get('DB_NAME', 'sakayta'), $get('DB_CHARSET', 'utf8mb4'));
    $pdo = new PDO($dsn, $get('DB_USER', 'root'), $get('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    fail('Cannot connect to the database. Check DB_* in php/config/.env and that MariaDB is running.');
}

try {
    $exists = $pdo->query("SELECT email FROM users WHERE role = 'admin' LIMIT 1")->fetch();
    if ($exists) { echo "[create-admin] An admin already exists ({$exists['email']}). No change made.\n"; exit(0); }

    $taken = $pdo->prepare('SELECT role FROM users WHERE email = :e LIMIT 1');
    $taken->execute([':e' => $email]);
    if ($row = $taken->fetch()) fail("That email is already used by a {$row['role']} account. No change made.");

    $id = 'u_' . time() . '_' . bin2hex(random_bytes(4));
    // users.mobile is NOT NULL in the schema, so give the admin a unique placeholder.
    $mobile = $get('ADMIN_MOBILE', 'admin-' . $id);

    $ins = $pdo->prepare(
        "INSERT INTO users (id, full_name, email, mobile, password_hash, role, created_at)
         VALUES (:id, :name, :email, :mobile, :hash, 'admin', NOW())"
    );
    $ins->execute([
        ':id' => $id, ':name' => $name, ':email' => $email, ':mobile' => $mobile,
        ':hash' => password_hash($password, PASSWORD_BCRYPT),
    ]);
    echo "[create-admin] Admin created ($email). Log in at http://localhost:3000/login.html\n";
} catch (Throwable $e) {
    fail('Could not create the admin: ' . $e->getMessage());
}