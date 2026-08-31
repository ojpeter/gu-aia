<?php

/**
 * Shared bootstrap for the admin console. requirements.md Section 14.
 *
 * Every console page includes this first. It establishes the session, the
 * database connection, CSRF, the audit log, and — for every page except the
 * login form — the signed-in user.
 *
 * SESSION SECURITY (CLAUDE.md Rule 5)
 *
 * A separate cookie name from the public widget, so a visitor's widget session
 * and a staff member's console session are different cookies with different
 * lifetimes and cannot be confused for one another. Secure, httponly, and
 * SameSite=Strict rather than Lax: the console has no legitimate cross-site
 * entry point at all, so the stricter setting costs nothing and closes the
 * top-level-navigation CSRF gap that Lax leaves open.
 *
 * Idle timeout is 30 minutes, and the session id is regenerated on sign-in and
 * on timeout. Regeneration on privilege change is what stops a session fixed
 * before login from surviving into an authenticated session.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use GuAia\Admin\AuthenticatedUser;
use GuAia\Admin\ConsoleContext;
use GuAia\Admin\Role;
use GuAia\Logging\AuditLog;
use GuAia\Logging\IdentifierHasher;
use GuAia\Safety\Csrf;

const CONSOLE_IDLE_TIMEOUT_SECONDS = 1800;

$root = dirname(__DIR__, 2);

/** @var array<string, string> $env */
$env = (static function (string $path): array {
    if (!is_readable($path)) {
        http_response_code(503);
        exit('Configuration missing.');
    }
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $out[trim($k)] = trim(trim($v), "\"'");
    }
    return $out;
})($root . '/.env');

$isHttps = (($_SERVER['HTTPS'] ?? '') !== '') || (($_SERVER['SERVER_PORT'] ?? '') === '443');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    // Strict, not Lax: the console has no legitimate cross-site entry point.
    'samesite' => 'Strict',
    'secure' => $isHttps,
]);
session_name('gu_aia_console');
session_start();

if (isset($_SESSION['console_last_seen'])
    && (time() - (int) $_SESSION['console_last_seen']) > CONSOLE_IDLE_TIMEOUT_SECONDS) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['console_last_seen'] = time();

$csrf = new Csrf($_SESSION);

try {
    $options = require $root . '/config/pdo_options.php';
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $env['DB_HOST'] ?? 'localhost',
            $env['DB_NAME'] ?? 'gu_aia',
            $env['DB_CHARSET'] ?? 'utf8mb4'
        ),
        $env['DB_APP_USER'] ?? '',
        $env['DB_APP_PASS'] ?? '',
        $options
    );
    $hasher = new IdentifierHasher($env['LOG_HASH_KEY'] ?? '');
    $audit = new AuditLog($pdo, $hasher);
} catch (Throwable $e) {
    error_log('[gu-aia console] ' . $e->getMessage());
    http_response_code(503);
    exit('The console is temporarily unavailable.');
}

/** Rebuilds the signed-in user from the session, or null. */
$currentUser = (static function (): ?AuthenticatedUser {
    $stored = $_SESSION['console_user'] ?? null;
    if (!is_array($stored)) {
        return null;
    }

    $role = Role::tryFrom((string) ($stored['role'] ?? ''));
    if ($role === null) {
        return null;
    }

    return new AuthenticatedUser(
        id: (int) $stored['id'],
        name: (string) $stored['name'],
        email: (string) $stored['email'],
        role: $role,
        officeId: $stored['office_id'] === null ? null : (int) $stored['office_id'],
        twoFactorSatisfied: (bool) ($stored['two_factor'] ?? false),
    );
})();

return new ConsoleContext(
    pdo: $pdo,
    csrf: $csrf,
    audit: $audit,
    user: $currentUser,
    clientIp: (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
);
