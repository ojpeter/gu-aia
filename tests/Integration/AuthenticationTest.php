<?php

declare(strict_types=1);

namespace GuAia\Tests\Integration;

use GuAia\Admin\Authenticator;
use GuAia\Admin\Role;
use GuAia\Admin\Totp;
use GuAia\Logging\AuditLog;
use GuAia\Logging\IdentifierHasher;
use GuAia\Tests\Support\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Console authentication against the real schema.
 *
 * Every test runs inside a transaction that is rolled back, so no console
 * account survives the suite. That matters here specifically: a test account
 * with a known password left behind in a database is a real credential, not a
 * fixture.
 */
final class AuthenticationTest extends TestCase
{
    private ?PDO $pdo = null;

    private const PASSWORD = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        $this->pdo = Database::connect();
        if ($this->pdo === null) {
            self::markTestSkipped('No database available (' . Database::unavailableReason() . ').');
        }
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testCorrectCredentialsSignAReaderIn(): void
    {
        $email = $this->createUser(Role::Reader);

        $result = (new Authenticator($this->pdo))->attempt($email, self::PASSWORD);

        self::assertTrue($result->successful);
        self::assertNotNull($result->user);
        self::assertSame(Role::Reader, $result->user->role);
    }

    public function testAWrongPasswordIsRefused(): void
    {
        $email = $this->createUser(Role::Reader);

        self::assertFalse((new Authenticator($this->pdo))->attempt($email, 'wrong')->successful);
    }

    public function testAnUnknownAccountAndAWrongPasswordAreIndistinguishable(): void
    {
        // Staff emails at a university are guessable by construction, so the
        // login form must not confirm which ones exist.
        $email = $this->createUser(Role::Reader);
        $authenticator = new Authenticator($this->pdo);

        $wrongPassword = $authenticator->attempt($email, 'wrong');
        $noSuchAccount = $authenticator->attempt('nobody@gu.ac.ug', 'wrong');

        self::assertFalse($wrongPassword->successful);
        self::assertFalse($noSuchAccount->successful);
        self::assertSame($wrongPassword->message(), $noSuchAccount->message());
    }

    public function testADeactivatedAccountCannotSignIn(): void
    {
        $email = $this->createUser(Role::Reader, active: false);

        self::assertFalse((new Authenticator($this->pdo))->attempt($email, self::PASSWORD)->successful);
    }

    public function testRepeatedFailuresLockTheAccountTemporarily(): void
    {
        $email = $this->createUser(Role::Reader);
        $authenticator = new Authenticator($this->pdo);

        for ($i = 0; $i < 5; $i++) {
            $authenticator->attempt($email, 'wrong');
        }

        // Now locked: even the correct password is refused.
        self::assertFalse($authenticator->attempt($email, self::PASSWORD)->successful);

        $lockedUntil = $this->column($email, 'locked_until');
        self::assertNotNull($lockedUntil, 'Five failures must set a lockout.');

        // Temporary, not permanent — otherwise an attacker denies a named member
        // of staff access by getting their password wrong five times.
        self::assertLessThan(
            (new \DateTimeImmutable('+1 hour')),
            new \DateTimeImmutable((string) $lockedUntil),
            'The lockout must expire, not be permanent.'
        );
    }

    public function testASuccessfulSignInClearsTheFailureCount(): void
    {
        $email = $this->createUser(Role::Reader);
        $authenticator = new Authenticator($this->pdo);

        $authenticator->attempt($email, 'wrong');
        $authenticator->attempt($email, 'wrong');
        self::assertSame(2, (int) $this->column($email, 'failed_logins'));

        self::assertTrue($authenticator->attempt($email, self::PASSWORD)->successful);
        self::assertSame(0, (int) $this->column($email, 'failed_logins'));
        self::assertNotNull($this->column($email, 'last_login_at'));
    }

    public function testAnAuthoriserMustSupplyASecondFactor(): void
    {
        $secret = Totp::generateSecret();
        $email = $this->createUser(Role::Authoriser, totpSecret: $secret);

        $authenticator = new Authenticator($this->pdo);

        self::assertFalse(
            $authenticator->attempt($email, self::PASSWORD)->successful,
            'CLAUDE.md Rule 5: the role that can mark a document authoritative requires 2FA.'
        );

        $code = (new Totp())->codeAt($secret);
        $result = $authenticator->attempt($email, self::PASSWORD, $code);

        self::assertTrue($result->successful);
        self::assertNotNull($result->user);
        self::assertTrue($result->user->twoFactorSatisfied);
        self::assertTrue($result->user->may(Role::MARK_AUTHORITATIVE));
    }

    public function testAnAuthoriserWithNoEnrolledSecretCannotSignInAtAll(): void
    {
        // Letting them through "until they enrol" is how a 2FA requirement
        // becomes advisory. The operator reason is recorded; the user still sees
        // the same generic failure.
        $email = $this->createUser(Role::Authoriser, totpSecret: null);

        $result = (new Authenticator($this->pdo))->attempt($email, self::PASSWORD, '123456');

        self::assertFalse($result->successful);
        self::assertSame('two_factor_not_enrolled', $result->operatorReason);
        self::assertSame('Those details did not match an active account.', $result->message());
    }

    public function testAWrongSecondFactorIsRefusedEvenWithTheRightPassword(): void
    {
        $email = $this->createUser(Role::Authoriser, totpSecret: Totp::generateSecret());

        self::assertFalse((new Authenticator($this->pdo))->attempt($email, self::PASSWORD, '000000')->successful);
    }

    public function testAFailedSignInIsAuditedWithoutNamingAnAccount(): void
    {
        // The attempt is recorded, because a spike in these is what an operator
        // needs to see. The attribution is withheld, because "somebody tried to
        // sign in as this named person" is a claim anyone can manufacture with a
        // guessed email.
        $audit = new AuditLog($this->pdo, new IdentifierHasher('test-key'));
        $audit->recordFailedLogin('203.0.113.4');

        $statement = $this->pdo->query(
            "SELECT admin_user_id, action FROM admin_audit_log WHERE action = 'login_failed' ORDER BY id DESC LIMIT 1"
        );
        $row = $statement === false ? null : $statement->fetch();

        self::assertIsArray($row);
        self::assertNull($row['admin_user_id']);
    }

    public function testTheAuditLogRecordsWhoDidWhat(): void
    {
        $email = $this->createUser(Role::Authoriser, totpSecret: $secret = Totp::generateSecret());
        $result = (new Authenticator($this->pdo))->attempt($email, self::PASSWORD, (new Totp())->codeAt($secret));

        self::assertNotNull($result->user);

        $audit = new AuditLog($this->pdo, new IdentifierHasher('test-key'));
        $audit->record(
            action: AuditLog::MARK_AUTHORITATIVE,
            user: $result->user,
            entityType: 'document',
            entityId: '42',
            detail: 'Marked authoritative for fees',
        );

        $recent = $audit->recent(1);

        self::assertCount(1, $recent);
        self::assertSame(AuditLog::MARK_AUTHORITATIVE, $recent[0]['action']);
        self::assertSame('document', $recent[0]['entity_type']);
        self::assertSame('42', $recent[0]['entity_id']);
        self::assertNotNull($recent[0]['actor']);
    }

    // ------------------------------------------------------------- fixtures

    private function createUser(Role $role, bool $active = true, ?string $totpSecret = null): string
    {
        $email = 'test-' . bin2hex(random_bytes(6)) . '@gu.ac.ug';

        $statement = $this->pdo->prepare(
            'INSERT INTO admin_users (name, email, password_hash, role, is_active, totp_secret_enc, totp_enabled)
             VALUES (:name, :email, :hash, :role, :active, :secret, :enabled)'
        );
        $statement->execute([
            'name' => 'Test Person',
            'email' => $email,
            'hash' => Authenticator::hash(self::PASSWORD),
            'role' => $role->value,
            'active' => $active ? 1 : 0,
            'secret' => $totpSecret,
            'enabled' => $totpSecret === null ? 0 : 1,
        ]);

        return $email;
    }

    private function column(string $email, string $column): mixed
    {
        $statement = $this->pdo->prepare("SELECT {$column} FROM admin_users WHERE email = ?");
        $statement->execute([$email]);

        return $statement->fetchColumn();
    }
}
