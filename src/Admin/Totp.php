<?php

declare(strict_types=1);

namespace GuAia\Admin;

/**
 * Time-based one-time passwords, RFC 6238. CLAUDE.md Rule 5.
 *
 * Implemented here rather than pulled in, for the same reason the rest of this
 * project has no runtime dependencies: it is about sixty lines of HMAC, the
 * algorithm has not changed since 2011, and a dependency in the authentication
 * path is a supply-chain surface on the one route that protects everything else.
 *
 * Two details that are easy to get wrong and matter:
 *
 *   COMPARISON IS CONSTANT-TIME. A `==` on a six-digit code leaks, slowly, which
 *   prefix was right. Six digits is a small enough space that this is not
 *   theoretical.
 *
 *   ONE STEP OF DRIFT IS ALLOWED, IN BOTH DIRECTIONS, AND NO MORE. Phone clocks
 *   drift, and a staff member whose phone is forty seconds fast should not be
 *   locked out. But every extra window multiplies the number of codes an
 *   attacker can guess at once, so the tolerance stops at one.
 */
final class Totp
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const ALGORITHM = 'sha1'; // RFC 6238 default; what authenticator apps expect.

    /** How many periods either side of "now" are accepted. */
    private const DRIFT_WINDOWS = 1;

    /** @param string $secret base32, as issued to the authenticator app */
    public function verify(string $secret, string $code, ?int $at = null): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $key = self::base32Decode($secret);
        if ($key === '') {
            return false;
        }

        $counter = (int) floor(($at ?? time()) / self::PERIOD);

        for ($drift = -self::DRIFT_WINDOWS; $drift <= self::DRIFT_WINDOWS; $drift++) {
            // hash_equals, never ==. Six digits is a small enough space that a
            // timing side channel is worth closing.
            if (hash_equals($this->codeFor($key, $counter + $drift), $code)) {
                return true;
            }
        }

        return false;
    }

    /** The current code, for tests and for confirming enrolment. */
    public function codeAt(string $secret, ?int $at = null): string
    {
        $key = self::base32Decode($secret);

        return $this->codeFor($key, (int) floor(($at ?? time()) / self::PERIOD));
    }

    /**
     * A fresh base32 secret to issue to an authenticator app.
     *
     * 16 bytes is RFC 4226's minimum recommendation and 20 its recommendation
     * proper, which is the default here. A caller cannot ask for less than the
     * minimum: a shortened TOTP secret weakens the second factor silently, and
     * the weakening would be invisible at every call site.
     */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes(max(16, $bytes)));
    }

    private function codeFor(string $key, int $counter): string
    {
        // Counter as a 64-bit big-endian integer.
        $binary = pack('N*', 0, $counter);
        $hash = hash_hmac(self::ALGORITHM, $binary, $key, true);

        // Dynamic truncation, RFC 4226 section 5.4.
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $part = substr($hash, $offset, 4);
        $value = unpack('N', $part);
        $number = ($value === false ? 0 : $value[1]) & 0x7FFFFFFF;

        return str_pad((string) ($number % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(str_replace([' ', '=', '-'], '', trim($secret)));
        if ($secret === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($secret) as $character) {
            $index = strpos(self::ALPHABET, $character);
            if ($index === false) {
                return '';
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $binary = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $binary .= chr((int) bindec($byte));
            }
        }

        return $binary;
    }

    private static function base32Encode(string $binary): string
    {
        $bits = '';
        foreach (str_split($binary) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[(int) bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }
}
