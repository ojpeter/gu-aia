<?php

declare(strict_types=1);

namespace GuAia\Ingestion;

/**
 * What cleaning did, and what it deliberately did not do.
 *
 * `flagged` is the interesting half. It lists instruction-shaped sentences that
 * were KEPT — because deleting text from a University page silently changes what
 * the University said — so that the owning office can look at them and decide.
 * A non-empty `flagged` on a real page is either an editorial oddity or an
 * attempt to influence this system, and both are worth a human looking at.
 */
final readonly class CleaningResult
{
    /**
     * @param list<string> $removed kinds of artefact deleted outright
     * @param list<string> $flagged instruction-shaped text kept for review
     */
    public function __construct(
        public string $text,
        public array $removed = [],
        public array $flagged = [],
    ) {
    }

    public function hasSuspiciousContent(): bool
    {
        return $this->flagged !== []
            || in_array('invisible_or_bidi_control_characters', $this->removed, true);
    }
}
