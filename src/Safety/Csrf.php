<?php

declare(strict_types=1);

namespace GuAia\Safety;

/**
 * CSRF tokens. CLAUDE.md Rule 5.
 *
 * "Every state-changing form (public and admin) includes a CSRF token and every
 *  handler verifies it before touching the database."
 *
 * The question form counts. It is not obviously state-changing to look at, but
 * it writes an interaction row, consumes rate-limit budget and, once a real
 * generator is wired, spends money. A page on another site that can silently
 * submit questions on a visitor's behalf can drain the budget (INV-8) and fill
 * the Unanswered Questions Report with noise that Communications would then act
 * on. Both matter.
 *
 * Verification is hash_equals, not ==, because a timing-variable comparison on a
 * secret is a real if unglamorous leak.
 */
final class Csrf
{
    private const SESSION_KEY = 'gu_aia_csrf';

    /** @param array<string, mixed> $session passed in, so this stays testable */
    public function __construct(private array &$session)
    {
    }

    public function token(): string
    {
        if (!isset($this->session[self::SESSION_KEY]) || !is_string($this->session[self::SESSION_KEY])) {
            $this->session[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $this->session[self::SESSION_KEY];
    }

    public function verify(?string $candidate): bool
    {
        $expected = $this->session[self::SESSION_KEY] ?? null;

        if (!is_string($expected) || $candidate === null || $candidate === '') {
            return false;
        }

        return hash_equals($expected, $candidate);
    }

    /** Ready-to-render hidden field. */
    public function field(): string
    {
        return sprintf(
            '<input type="hidden" name="csrf_token" value="%s">',
            htmlspecialchars($this->token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }
}
