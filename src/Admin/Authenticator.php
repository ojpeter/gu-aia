<?php

declare(strict_types=1);

namespace GuAia\Admin;

use PDO;

/**
 * Console authentication. CLAUDE.md Rule 5, requirements.md Section 14.
 *
 * "password_hash(), regenerate_session() on login and privilege change, and 2FA
 *  for any high-privilege role."
 *
 * WHY THE FAILURE PATH IS SO CAREFUL HERE
 *
 * A compromised console account can change what the University appears to say in
 * public. That is a different class of consequence from most admin panels: not
 * data loss, but a false statement published in the University's name, to people
 * deciding whether to apply. So:
 *
 *   THE SAME ANSWER FOR EVERY FAILURE. Unknown email, wrong password, deactivated
 *   account and locked account all return the same result. Distinguishing them
 *   turns the login form into an account enumerator, and staff emails at a
 *   university are guessable by construction.
 *
 *   THE HASH IS COMPUTED EVEN WHEN THE ACCOUNT DOES NOT EXIST. Otherwise the
 *   response time distinguishes "no such user" from "wrong password" just as
 *   loudly as a different message would.
 *
 *   LOCKOUT IS TEMPORARY, NOT PERMANENT. A permanent lock hands an attacker a
 *   denial-of-service against a named member of staff by getting their password
 *   wrong five times.
 *
 * This class does not touch $_SESSION. Session regeneration is the caller's job
 * because only the caller knows the request boundary, and a class that quietly
 * regenerated sessions would be untestable.
 */
final class Authenticator
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;

    /** Cost 12: noticeably slower than the default 10, still imperceptible to a human logging in. */
    private const HASH_OPTIONS = ['cost' => 12];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Totp $totp = new Totp(),
    ) {
    }

    public function attempt(string $email, string $password, ?string $totpCode = null): AuthenticationResult
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, email, password_hash, role, office_id, is_active,
                    failed_logins, locked_until, totp_enabled, totp_secret_enc
               FROM admin_users
              WHERE email = :email'
        );
        $statement->execute(['email' => strtolower(trim($email))]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            // Compute a hash anyway, so a missing account costs the same time as
            // a wrong password. Without this the timing is the enumeration
            // oracle the identical message was meant to close.
            password_verify($password, '$2y$12$' . str_repeat('.', 53));

            return AuthenticationResult::failed();
        }

        $lockedUntil = $row['locked_until'] === null ? null : new \DateTimeImmutable((string) $row['locked_until']);
        if ($lockedUntil !== null && $lockedUntil > new \DateTimeImmutable()) {
            return AuthenticationResult::failed();
        }

        if ((int) $row['is_active'] !== 1 || !password_verify($password, (string) $row['password_hash'])) {
            $this->recordFailure((int) $row['id'], (int) $row['failed_logins']);

            return AuthenticationResult::failed();
        }

        $role = Role::from((string) $row['role']);

        // 2FA is required for any role that can mark a document authoritative.
        // Checked here, at login, AND again at the point of the action.
        $needsTwoFactor = $role->may(Role::MARK_AUTHORITATIVE) || (int) $row['totp_enabled'] === 1;

        if ($needsTwoFactor) {
            $secret = $row['totp_secret_enc'] === null ? '' : (string) $row['totp_secret_enc'];

            if ($secret === '') {
                // An authoriser without an enrolled second factor cannot sign in
                // at all. Letting them through "until they enrol" is how a 2FA
                // requirement becomes advisory.
                return AuthenticationResult::twoFactorNotEnrolled();
            }

            if ($totpCode === null || !$this->totp->verify($secret, $totpCode)) {
                $this->recordFailure((int) $row['id'], (int) $row['failed_logins']);

                return AuthenticationResult::failed();
            }
        }

        $this->recordSuccess((int) $row['id']);

        return AuthenticationResult::succeeded(new AuthenticatedUser(
            id: (int) $row['id'],
            name: (string) $row['name'],
            email: (string) $row['email'],
            role: $role,
            officeId: $row['office_id'] === null ? null : (int) $row['office_id'],
            twoFactorSatisfied: $needsTwoFactor,
        ));
    }

    /** For bin/create_admin.php and for password changes. */
    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, self::HASH_OPTIONS);
    }

    private function recordFailure(int $userId, int $currentFailures): void
    {
        $failures = $currentFailures + 1;

        // Temporary, not permanent: a permanent lock hands an attacker a
        // denial-of-service against a named member of staff.
        $lockUntil = $failures >= self::MAX_FAILED_ATTEMPTS
            ? (new \DateTimeImmutable())->modify('+' . self::LOCKOUT_MINUTES . ' minutes')->format('Y-m-d H:i:s')
            : null;

        $statement = $this->pdo->prepare(
            'UPDATE admin_users SET failed_logins = :failures, locked_until = :locked_until WHERE id = :id'
        );
        $statement->execute(['failures' => $failures, 'locked_until' => $lockUntil, 'id' => $userId]);
    }

    private function recordSuccess(int $userId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE admin_users SET last_login_at = NOW(), failed_logins = 0, locked_until = NULL WHERE id = :id'
        );
        $statement->execute(['id' => $userId]);
    }
}
