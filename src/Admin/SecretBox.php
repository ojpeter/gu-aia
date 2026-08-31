<?php

declare(strict_types=1);

namespace GuAia\Admin;

use RuntimeException;
use SensitiveParameter;

/**
 * Authenticated encryption for secrets held at rest. CLAUDE.md Rule 5.
 *
 * Built for one job: `admin_users.totp_secret_enc`. That column was named for
 * encryption from the day the schema was written and held plaintext until now,
 * which was recorded as a known gap rather than left to be discovered. Anyone
 * who could read the column could mint valid second factors, which reduces 2FA
 * to a single factor against a database-read attacker — and a database-read
 * attacker is exactly who 2FA is supposed to still stop.
 *
 * AES-256-GCM, not CBC or CTR. The tag matters as much as the confidentiality:
 * a TOTP secret that an attacker can flip bits in is a TOTP secret they can
 * grind. GCM refuses to decrypt anything that has been altered.
 *
 * THE KEY IS SEPARATE FROM LOG_HASH_KEY, deliberately. That one pseudonymises
 * log identifiers and may reasonably be readable by whoever runs reports; this
 * one guards a second factor. Reusing a key across two purposes means the
 * weaker handling of either becomes the security of both.
 *
 * THE ENVELOPE IS VERSIONED. Byte 0 is a format version, so the algorithm can
 * change later without guessing at how existing rows were written. A migration
 * that has to infer its own input format is a migration that corrupts something.
 */
final class SecretBox
{
    private const VERSION = "\x01";
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;   // 96 bits, the GCM standard
    private const TAG_BYTES = 16;
    private const KEY_BYTES = 32;

    private string $key;

    /** @param string $keyHex 64 hex characters, from SECRET_ENCRYPTION_KEY */
    public function __construct(#[SensitiveParameter] string $keyHex)
    {
        $key = @hex2bin(trim($keyHex));

        if ($key === false || strlen($key) !== self::KEY_BYTES) {
            // Refuses rather than deriving something from whatever was supplied.
            // A short or malformed key that silently "worked" would produce
            // ciphertext everyone assumed was strong.
            throw new RuntimeException(
                'SECRET_ENCRYPTION_KEY must be exactly 64 hex characters (32 bytes). '
                . 'Generate one with: php -r "echo bin2hex(random_bytes(32));"'
            );
        }

        $this->key = $key;
    }

    public function encrypt(#[SensitiveParameter] string $plaintext): string
    {
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_BYTES
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return self::VERSION . $iv . $tag . $ciphertext;
    }

    /**
     * Returns null for anything that does not decrypt: a truncated envelope, a
     * wrong key, an unknown version, or a tampered tag.
     *
     * Null rather than an exception because the caller's correct response is
     * always the same — treat the secret as unusable and refuse the sign-in —
     * and an exception here would turn a bad row into a 500 on the login page.
     */
    public function decrypt(string $envelope): ?string
    {
        $minimum = 1 + self::IV_BYTES + self::TAG_BYTES;

        if (strlen($envelope) <= $minimum || $envelope[0] !== self::VERSION) {
            return null;
        }

        $iv = substr($envelope, 1, self::IV_BYTES);
        $tag = substr($envelope, 1 + self::IV_BYTES, self::TAG_BYTES);
        $ciphertext = substr($envelope, $minimum);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $plaintext === false ? null : $plaintext;
    }

    /** For provisioning. Printed once, never stored by this code. */
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(self::KEY_BYTES));
    }
}
