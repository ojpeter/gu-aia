<?php

declare(strict_types=1);

namespace GuAia\Tests\Unit;

use GuAia\Admin\AuthenticatedUser;
use GuAia\Admin\Role;
use GuAia\Admin\Totp;
use PHPUnit\Framework\TestCase;

/**
 * Console authorisation and second-factor logic.
 *
 * The database-backed half of authentication lives in
 * tests/Integration/AuthenticationTest.php; this covers the parts that are pure
 * logic, which are also the parts most likely to be quietly loosened later.
 */
final class AdminSecurityTest extends TestCase
{
    // -------------------------------------------------------------- roles

    public function testAReaderChangesNothing(): void
    {
        $reader = $this->user(Role::Reader);

        self::assertTrue($reader->may(Role::VIEW_REPORTS));
        self::assertTrue($reader->may(Role::VIEW_CORPUS));
        self::assertFalse($reader->may(Role::EDIT_CURATED));
        self::assertFalse($reader->may(Role::TRIGGER_REINDEX));
        self::assertFalse($reader->may(Role::MARK_AUTHORITATIVE));
    }

    public function testAnEditorCannotMarkADocumentAuthoritative(): void
    {
        // The steep step in the ladder. Marking a source authoritative decides
        // which fees figure the public is shown when two sources disagree.
        $editor = $this->user(Role::Editor);

        self::assertTrue($editor->may(Role::EDIT_CURATED));
        self::assertFalse($editor->may(Role::MARK_AUTHORITATIVE));
    }

    public function testAnAuthoriserWithoutASecondFactorMayNotMarkAuthoritative(): void
    {
        // Having the role is not the same fact as having passed 2FA this
        // session, and conflating them is how a 2FA requirement becomes a label.
        $withoutTwoFactor = $this->user(Role::Authoriser, twoFactorSatisfied: false);

        self::assertFalse(
            $withoutTwoFactor->may(Role::MARK_AUTHORITATIVE),
            'CLAUDE.md Rule 5: 2FA is required for the role that can mark a document authoritative.'
        );

        // The rest of the role still works, so a missing second factor degrades
        // rather than locking the person out of everything.
        self::assertTrue($withoutTwoFactor->may(Role::VIEW_REPORTS));
    }

    public function testAnAuthoriserWithASecondFactorMayMarkAuthoritative(): void
    {
        self::assertTrue($this->user(Role::Authoriser, twoFactorSatisfied: true)->may(Role::MARK_AUTHORITATIVE));
    }

    public function testNoRoleCanEditCorpusContent(): void
    {
        // Section 14: "No content editing capability beyond curated entries. The
        // website remains the source of truth; the console must never become a
        // second place where facts live." There is no such permission to grant.
        foreach (Role::cases() as $role) {
            self::assertNotContains(
                'edit_corpus',
                $role->permissions(),
                'Section 14 forbids the console becoming a second place where facts live.'
            );
        }
    }

    public function testOnlyMarkAuthoritativeRequiresASecondFactor(): void
    {
        self::assertSame([Role::MARK_AUTHORITATIVE], Role::permissionsRequiringTwoFactor());
        self::assertFalse(Role::requiresTwoFactor(Role::EDIT_CURATED));
    }

    // --------------------------------------------------------------- totp

    public function testAValidCodeVerifies(): void
    {
        $totp = new Totp();
        $secret = Totp::generateSecret();
        $at = 1_800_000_000;

        self::assertTrue($totp->verify($secret, $totp->codeAt($secret, $at), $at));
    }

    public function testOneStepOfClockDriftIsToleratedInBothDirections(): void
    {
        // Phone clocks drift, and a staff member forty seconds fast should not
        // be locked out of the console.
        $totp = new Totp();
        $secret = Totp::generateSecret();
        $at = 1_800_000_000;

        self::assertTrue($totp->verify($secret, $totp->codeAt($secret, $at - 30), $at));
        self::assertTrue($totp->verify($secret, $totp->codeAt($secret, $at + 30), $at));
    }

    public function testDriftBeyondOneStepIsRejected(): void
    {
        // Every extra window multiplies the codes an attacker can guess at once.
        $totp = new Totp();
        $secret = Totp::generateSecret();
        $at = 1_800_000_000;

        self::assertFalse($totp->verify($secret, $totp->codeAt($secret, $at - 90), $at));
        self::assertFalse($totp->verify($secret, $totp->codeAt($secret, $at + 90), $at));
    }

    public function testAWrongCodeIsRejected(): void
    {
        $totp = new Totp();
        $secret = Totp::generateSecret();

        self::assertFalse($totp->verify($secret, '000000', 1_800_000_000));
        self::assertFalse($totp->verify($secret, '', 1_800_000_000));
        self::assertFalse($totp->verify($secret, '12345', 1_800_000_000));
    }

    public function testAnEmptyOrInvalidSecretNeverVerifies(): void
    {
        // A missing secret must fail closed, not accept everything.
        $totp = new Totp();

        self::assertFalse($totp->verify('', '123456'));
        self::assertFalse($totp->verify('not-base32!!', '123456'));
    }

    public function testCodesAreSixDigitsAndZeroPadded(): void
    {
        $totp = new Totp();
        $secret = Totp::generateSecret();

        for ($i = 0; $i < 20; $i++) {
            $code = $totp->codeAt($secret, 1_800_000_000 + ($i * 30));
            self::assertSame(6, strlen($code));
            self::assertMatchesRegularExpression('/^\d{6}$/', $code);
        }
    }

    public function testKnownAnswerFromRfc4226(): void
    {
        // RFC 4226 Appendix D publishes HOTP values for the ASCII secret
        // "12345678901234567890" at counters 0..9. TOTP is HOTP over a time
        // counter, so feeding the matching timestamps must reproduce them.
        // A self-consistent implementation can be self-consistently wrong;
        // this is the check that it interoperates with a real authenticator.
        $totp = new Totp();
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // base32 of the RFC secret

        $expected = ['755224', '287082', '359152', '969429', '338314'];

        foreach ($expected as $counter => $code) {
            self::assertSame(
                $code,
                $totp->codeAt($secret, $counter * 30),
                sprintf('RFC 4226 counter %d should produce %s.', $counter, $code)
            );
        }
    }

    private function user(Role $role, bool $twoFactorSatisfied = false): AuthenticatedUser
    {
        return new AuthenticatedUser(
            id: 1,
            name: 'Test User',
            email: 'test@example.invalid',
            role: $role,
            twoFactorSatisfied: $twoFactorSatisfied,
        );
    }
}
