<?php

declare(strict_types=1);

namespace GuAia\Tests\Unit;

use GuAia\Admin\SecretBox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Encryption at rest for TOTP secrets. CLAUDE.md Rule 5.
 *
 * The tests that matter here are the negative ones. Encryption that round-trips
 * is easy; encryption that refuses a tampered value, a wrong key or a malformed
 * envelope is the part that decides whether this is a control or a decoration.
 */
final class SecretBoxTest extends TestCase
{
    private const KEY = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';
    private const OTHER_KEY = 'ffeeddccbbaa99887766554433221100ffeeddccbbaa99887766554433221100';

    public function testASecretRoundTrips(): void
    {
        $box = new SecretBox(self::KEY);
        $secret = 'JBSWY3DPEHPK3PXP';

        self::assertSame($secret, $box->decrypt($box->encrypt($secret)));
    }

    public function testTheCiphertextDoesNotContainThePlaintext(): void
    {
        $box = new SecretBox(self::KEY);
        $secret = 'JBSWY3DPEHPK3PXP';

        self::assertStringNotContainsString($secret, $box->encrypt($secret));
    }

    public function testEncryptingTheSameSecretTwiceGivesDifferentCiphertext(): void
    {
        // A fresh IV each time. Without it, identical secrets produce identical
        // ciphertext, and anyone reading the column can see which accounts share
        // a secret — or that one was reused after a reset.
        $box = new SecretBox(self::KEY);

        self::assertNotSame($box->encrypt('JBSWY3DPEHPK3PXP'), $box->encrypt('JBSWY3DPEHPK3PXP'));
    }

    public function testAWrongKeyDoesNotDecrypt(): void
    {
        $envelope = (new SecretBox(self::KEY))->encrypt('JBSWY3DPEHPK3PXP');

        self::assertNull((new SecretBox(self::OTHER_KEY))->decrypt($envelope));
    }

    public function testATamperedCiphertextIsRejected(): void
    {
        // The whole reason for GCM rather than CBC or CTR. A TOTP secret an
        // attacker can flip bits in is a TOTP secret they can grind; the
        // authentication tag makes any alteration fail closed.
        $box = new SecretBox(self::KEY);
        $envelope = $box->encrypt('JBSWY3DPEHPK3PXP');

        $tampered = $envelope;
        $tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 0x01);

        self::assertNull($box->decrypt($tampered));
    }

    public function testATamperedTagIsRejected(): void
    {
        $box = new SecretBox(self::KEY);
        $envelope = $box->encrypt('JBSWY3DPEHPK3PXP');

        $tampered = $envelope;
        $tampered[14] = chr(ord($tampered[14]) ^ 0x01);

        self::assertNull($box->decrypt($tampered));
    }

    public function testAnUnknownEnvelopeVersionIsRejected(): void
    {
        // Byte 0 is a format version so the algorithm can change later without
        // guessing at how existing rows were written.
        $box = new SecretBox(self::KEY);
        $envelope = $box->encrypt('JBSWY3DPEHPK3PXP');
        $envelope[0] = "\x02";

        self::assertNull($box->decrypt($envelope));
    }

    public function testPlaintextInTheColumnDoesNotDecrypt(): void
    {
        // The migration case. A row still holding a raw base32 secret must be
        // treated as unusable rather than silently accepted, because accepting
        // it would quietly undo the encryption for every legacy row.
        self::assertNull((new SecretBox(self::KEY))->decrypt('JBSWY3DPEHPK3PXP'));
    }

    public function testTruncatedAndEmptyEnvelopesAreRejected(): void
    {
        $box = new SecretBox(self::KEY);

        self::assertNull($box->decrypt(''));
        self::assertNull($box->decrypt("\x01"));
        self::assertNull($box->decrypt(substr($box->encrypt('JBSWY3DPEHPK3PXP'), 0, 20)));
    }

    public function testAMalformedKeyIsRefusedAtConstruction(): void
    {
        // Refusing beats deriving something from whatever was supplied: a short
        // key that silently "worked" would produce ciphertext everyone assumed
        // was strong.
        foreach (['', 'not-hex', '00112233', str_repeat('a', 63)] as $bad) {
            try {
                new SecretBox($bad);
                self::fail(sprintf('Key "%s" should have been refused.', $bad));
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAGeneratedKeyIsAcceptable(): void
    {
        $key = SecretBox::generateKey();

        self::assertSame(64, strlen($key));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
        self::assertSame('secret', (new SecretBox($key))->decrypt((new SecretBox($key))->encrypt('secret')));
    }

    public function testTheEnvelopeFitsTheColumn(): void
    {
        // admin_users.totp_secret_enc is VARBINARY(255). A 32-byte base32 secret
        // plus the envelope must fit with room to spare, or a longer secret
        // would be truncated on write and fail to decrypt afterwards.
        $envelope = (new SecretBox(self::KEY))->encrypt(str_repeat('A', 64));

        self::assertLessThan(255, strlen($envelope));
    }
}
