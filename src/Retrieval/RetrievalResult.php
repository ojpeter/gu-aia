<?php

declare(strict_types=1);

namespace GuAia\Retrieval;

/**
 * Either confident context, or the NoConfidentContext of Section 6's pseudocode.
 *
 * Two states, not one nullable list, so that "retrieval found nothing worth
 * using" carries a REASON. That reason becomes the refusal's logged
 * refusal_reason (Section 13) and feeds the weekly Unanswered Questions Report,
 * which Section 13 calls a primary deliverable: "a ranked list of what the
 * public comes to the University's website looking for and cannot find".
 *
 * A refusal without a reason is a refusal nobody can act on.
 */
final readonly class RetrievalResult
{
    /** @param list<ScoredChunk> $chunks */
    private function __construct(
        public bool $isConfident,
        public array $chunks,
        public ?string $reason = null,
    ) {
    }

    /** @param list<ScoredChunk> $chunks */
    public static function confident(array $chunks): self
    {
        return new self(true, $chunks);
    }

    public static function noConfidentContext(string $reason): self
    {
        return new self(false, [], $reason);
    }

    /**
     * Reference number => chunk id, as handed to the citation binder. Numbering
     * starts at 1 because that is what the prompt contract tells the model to
     * emit, and a mismatch here would fail every citation.
     *
     * @return array<int, int>
     */
    public function referenceMap(): array
    {
        $map = [];
        foreach ($this->chunks as $index => $chunk) {
            $map[$index + 1] = $chunk->chunkId;
        }

        return $map;
    }

    /** INV-11: any stale source makes the whole answer carry a caution. */
    public function hasStaleSource(?\DateTimeImmutable $now = null): bool
    {
        foreach ($this->chunks as $chunk) {
            if ($chunk->isStale($now)) {
                return true;
            }
        }

        return false;
    }
}
