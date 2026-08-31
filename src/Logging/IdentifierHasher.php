<?php

declare(strict_types=1);

namespace GuAia\Logging;

use RuntimeException;

/**
 * Pseudonymises IP addresses and session identifiers before they are stored.
 *
 * docs/data-protection.md DF-2: the raw IP is never stored, only a keyed hash,
 * which is all rate limiting actually needs.
 *
 * WHY KEYED, AND NOT JUST HASHED
 *
 * A plain SHA-256 of an IPv4 address is not pseudonymisation in any meaningful
 * sense. The entire address space is about 4.3 billion values, which is a few
 * minutes of brute force on ordinary hardware — anyone holding the log can
 * recover every address in it exactly. The same is true of a hashed session id
 * drawn from a small space.
 *
 * An HMAC under a secret the log reader does not have makes that attack
 * impossible without the key, while still being deterministic enough for the two
 * things this project needs: counting requests from one source in a window, and
 * correlating a session's turns.
 *
 * THE KEY IS REQUIRED, AND ITS ABSENCE IS FATAL
 *
 * If LOG_HASH_KEY is missing, this class throws rather than falling back to an
 * unkeyed hash. Falling back would produce a column that looks pseudonymised,
 * passes review, and is trivially reversible — the worst of the three possible
 * states, because nobody would know to look at it again. Failing at startup is
 * loud, and it is fixed by setting one environment variable.
 */
final class IdentifierHasher
{
    public function __construct(private readonly string $key)
    {
        if (trim($this->key) === '') {
            throw new RuntimeException(
                'LOG_HASH_KEY is not set. Refusing to store identifiers under an unkeyed hash, '
                . 'which for an IPv4 address is reversible in minutes.'
            );
        }
    }

    /** @return string 64 hex characters, matching the CHAR(64) columns */
    public function hash(string $value): string
    {
        return hash_hmac('sha256', trim($value), $this->key);
    }

    /** Null in, null out: an absent IP is recorded as absent, not as a hash of "". */
    public function hashOrNull(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $this->hash($value);
    }
}
