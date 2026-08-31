<?php

declare(strict_types=1);

namespace GuAia\Admin;

/**
 * A signed-in console user, and what they are allowed to do right now.
 *
 * `twoFactorSatisfied` travels with the user rather than being re-derived,
 * because the question "did this session actually pass a second factor" must be
 * answerable at the point of a privileged action, not inferred from the role.
 * A role that requires 2FA and a session that supplied it are two different
 * facts, and conflating them is how a 2FA requirement quietly becomes a label.
 */
final readonly class AuthenticatedUser
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public Role $role,
        public ?int $officeId = null,
        public bool $twoFactorSatisfied = false,
    ) {
    }

    /**
     * Per-action authorisation. CLAUDE.md Rule 5: checked per action, not once
     * at login.
     */
    public function may(string $permission): bool
    {
        if (!$this->role->may($permission)) {
            return false;
        }

        if (Role::requiresTwoFactor($permission) && !$this->twoFactorSatisfied) {
            return false;
        }

        return true;
    }
}
