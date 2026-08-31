<?php

/**
 * Creates a console account. requirements.md Section 14; CLAUDE.md Rule 5.
 *
 *   php bin/create_admin.php "Name" email@gu.ac.ug reader|editor|authoriser
 *
 * Runs under the MIGRATION account, which is the only one with INSERT on
 * admin_users. The web-serving account deliberately cannot create console users
 * or change a role — it holds column-level UPDATE on last_login_at,
 * failed_logins and locked_until and nothing else — so account creation is an
 * operator action at a terminal, not something reachable from a request.
 *
 * THE PASSWORD IS NEVER PASSED AS AN ARGUMENT. Command-line arguments are
 * visible in the process list and land in shell history. It is read from stdin,
 * with terminal echo disabled where the platform allows.
 *
 * An `authoriser` account is enrolled in 2FA at creation, because an authoriser
 * with no second factor cannot sign in at all (Authenticator returns
 * twoFactorNotEnrolled). That is deliberate: letting them through "until they
 * enrol" is how a 2FA requirement becomes advisory.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use GuAia\Admin\Authenticator;
use GuAia\Admin\Role;
use GuAia\Admin\Totp;

$root = dirname(__DIR__);

$name = $argv[1] ?? null;
$email = $argv[2] ?? null;
$roleName = $argv[3] ?? null;

if ($name === null || $email === null || $roleName === null) {
    fwrite(STDERR, "Usage: php bin/create_admin.php \"Full Name\" email@gu.ac.ug reader|editor|authoriser\n");
    exit(1);
}

$role = Role::tryFrom($roleName);
if ($role === null) {
    fwrite(STDERR, "Unknown role '{$roleName}'. Use reader, editor or authoriser.\n");
    exit(1);
}

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "That is not a valid email address.\n");
    exit(1);
}

/** @var array<string, string> $env */
$env = (static function (string $path): array {
    if (!is_readable($path)) {
        fwrite(STDERR, "No .env found at {$path}.\n");
        exit(1);
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

$password = readSecret('Password (will not echo where supported): ');
$confirm = readSecret('Confirm password: ');

if ($password !== $confirm) {
    fwrite(STDERR, "Passwords did not match.\n");
    exit(1);
}

// A console account can change what the University appears to say in public.
// The floor is higher than a typical web account, and it is enforced rather
// than suggested.
if (strlen($password) < 12) {
    fwrite(STDERR, "Password must be at least 12 characters.\n");
    exit(1);
}

try {
    $options = require $root . '/config/pdo_options.php';
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $env['DB_HOST'] ?? 'localhost',
            $env['DB_NAME'] ?? 'gu_aia',
            $env['DB_CHARSET'] ?? 'utf8mb4'
        ),
        $env['DB_MIGRATION_USER'] ?? '',
        $env['DB_MIGRATION_PASS'] ?? '',
        $options
    );
} catch (PDOException) {
    fwrite(STDERR, "Cannot connect to the database. Check .env.\n");
    exit(1);
}

$secret = null;
if ($role === Role::Authoriser) {
    $secret = Totp::generateSecret();
}

try {
    $statement = $pdo->prepare(
        'INSERT INTO admin_users (name, email, password_hash, role, totp_secret_enc, totp_enabled)
         VALUES (:name, :email, :hash, :role, :secret, :enabled)'
    );
    $statement->execute([
        'name' => $name,
        'email' => strtolower(trim($email)),
        'hash' => Authenticator::hash($password),
        'role' => $role->value,
        'secret' => $secret,
        'enabled' => $secret === null ? 0 : 1,
    ]);
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'uq_admin_email')) {
        fwrite(STDERR, "An account with that email already exists.\n");
        exit(1);
    }
    fwrite(STDERR, "Could not create the account.\n");
    exit(1);
}

printf("Created %s (%s) as %s.\n", $name, $email, $role->value);

if ($secret !== null) {
    $issuer = rawurlencode('Gulu University');
    $label = rawurlencode('GU-AIA:' . $email);

    echo "\nTwo-factor authentication is REQUIRED for this role.\n";
    echo "Enrol this secret in an authenticator app now — it is shown once and\n";
    echo "cannot be recovered, and without it this account cannot sign in.\n\n";
    printf("  Secret: %s\n", $secret);
    printf("  URI:    otpauth://totp/%s?secret=%s&issuer=%s\n", $label, $secret, $issuer);
    echo "\nNOTE: the secret is stored unencrypted in totp_secret_enc. The column is\n";
    echo "named for encryption at rest, which is not yet implemented — see progress.md.\n";
}

exit(0);

/**
 * Reads a line from stdin without echoing where the platform allows.
 *
 * On Windows there is no portable stty, so the input IS visible. The script says
 * so rather than pretending otherwise: an operator who believes their password
 * was hidden when it was not may leave a terminal unlocked on that assumption.
 */
function readSecret(string $prompt): string
{
    $isWindows = str_starts_with(strtoupper(PHP_OS_FAMILY), 'WIN');

    if ($isWindows) {
        fwrite(STDOUT, "(This terminal cannot hide input; what you type will be visible.)\n");
        fwrite(STDOUT, $prompt);

        return rtrim((string) fgets(STDIN), "\r\n");
    }

    fwrite(STDOUT, $prompt);
    shell_exec('stty -echo');
    $value = rtrim((string) fgets(STDIN), "\r\n");
    shell_exec('stty echo');
    fwrite(STDOUT, "\n");

    return $value;
}
