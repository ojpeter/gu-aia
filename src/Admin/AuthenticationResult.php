<?php

declare(strict_types=1);

namespace GuAia\Admin;

/**
 * The outcome of a sign-in attempt.
 *
 * Only two states are ever shown to the person signing in: success, and a single
 * indistinguishable failure. `twoFactorNotEnrolled` exists as a separate state
 * because it is an ADMINISTRATIVE fact rather than a credential fact - an
 * authoriser account with no enrolled second factor is a configuration error
 * somebody has to fix, and it should reach the audit log and the operator. It
 * must still render to the user as the same generic failure.
 */
final readonly class AuthenticationResult
{
    private function __construct(
        public bool $successful,
        public ?AuthenticatedUser $user = null,
        public ?string $operatorReason = null,
    ) {
    }

    public static function succeeded(AuthenticatedUser $user): self
    {
        return new self(true, $user);
    }

    public static function failed(): self
    {
        return new self(false);
    }

    public static function twoFactorNotEnrolled(): self
    {
        return new self(false, null, 'two_factor_not_enrolled');
    }

    /**
     * The one message a failed sign-in may show. Unknown email, wrong password,
     * deactivated account, locked account and missing second factor all produce
     * this, because anything more specific enumerates accounts.
     */
    public function message(): string
    {
        return 'Those details did not match an active account.';
    }
}
