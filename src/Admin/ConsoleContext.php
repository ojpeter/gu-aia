<?php

declare(strict_types=1);

namespace GuAia\Admin;

use GuAia\Logging\AuditLog;
use GuAia\Safety\Csrf;
use PDO;

/**
 * Everything a console page needs, handed over explicitly.
 *
 * The first version of the bootstrap simply left `$pdo`, `$csrf`, `$audit`,
 * `$esc` and `$require` lying in the including scope. It worked, and static
 * analysis was right to object: a page that depends on variables a `require`
 * happens to leave behind has a dependency nobody can see, and renaming one of
 * them breaks pages silently at runtime rather than loudly at analysis time.
 *
 * Returning one object costs a few characters per use and makes the dependency
 * real.
 */
final readonly class ConsoleContext
{
    public function __construct(
        public PDO $pdo,
        public Csrf $csrf,
        public AuditLog $audit,
        public ?AuthenticatedUser $user,
        public string $clientIp,
        /**
         * Null when SECRET_ENCRYPTION_KEY is unset or malformed. That is
         * fail-closed by construction: without it, an encrypted TOTP secret
         * cannot be decrypted, so an account requiring a second factor simply
         * cannot sign in. A misconfigured key locks authorisers out rather than
         * quietly letting them past.
         */
        public ?SecretBox $secrets = null,
    ) {
    }

    public function esc(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function isSignedIn(): bool
    {
        return $this->user !== null;
    }

    /**
     * Guards a page. Per action, not once at login (CLAUDE.md Rule 5).
     *
     * A denial is audited: a signed-in reader repeatedly reaching for the
     * authoritative-flag page is something an operator should be able to see,
     * and it is invisible if only successes are recorded.
     *
     * Not named `require` because that reads as the language construct at every
     * call site, and a security check should not be the thing people misread.
     */
    public function requirePermission(string $permission): AuthenticatedUser
    {
        if ($this->user === null) {
            header('Location: login.php');
            exit;
        }

        if (!$this->user->may($permission)) {
            $this->audit->record(
                action: AuditLog::PERMISSION_DENIED,
                user: $this->user,
                entityType: 'permission',
                entityId: $permission,
                ip: $this->clientIp,
            );

            http_response_code(403);
            exit('You do not have permission to do that.');
        }

        return $this->user;
    }
}
