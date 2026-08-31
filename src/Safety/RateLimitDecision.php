<?php

declare(strict_types=1);

namespace GuAia\Safety;

/**
 * The outcome of a rate-limit check.
 *
 * Section 11 requires "a stated limit and a clear message on breach", so the
 * limit and the retry window travel with the decision rather than being looked
 * up again by whatever renders the message. A breach message that cannot say
 * when to try again is not clear.
 */
final readonly class RateLimitDecision
{
    public function __construct(
        public bool $allowed,
        public int $hitCount,
        public int $limit,
        public int $retryAfterSeconds = 0,
    ) {
    }

    public function message(): string
    {
        if ($this->allowed) {
            return '';
        }

        $minutes = (int) ceil($this->retryAfterSeconds / 60);

        return sprintf(
            'You have asked %d questions in the last hour, which is the limit of %d. '
            . 'Please try again in about %d minute%s.',
            $this->hitCount,
            $this->limit,
            $minutes,
            $minutes === 1 ? '' : 's'
        );
    }
}
