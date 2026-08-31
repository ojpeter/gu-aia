<?php

declare(strict_types=1);

namespace GuAia\Retrieval;

/**
 * A retrieved chunk with its score and everything an answer needs to cite it.
 *
 * `reviewedAt` travels with the chunk rather than being fetched later because
 * INV-11 requires EVERY answer to carry the last-reviewed date of its source. A
 * value that has to be looked up separately is a value that will eventually be
 * omitted.
 */
final readonly class ScoredChunk
{
    public function __construct(
        public int $chunkId,
        public int $documentId,
        public string $body,
        public float $score,
        public string $sourceRef,
        public string $title,
        public string $reviewedAt,
        public int $reviewIntervalDays,
        public bool $isAuthoritative = false,
        public ?string $categoryKey = null,
        public ?string $headingPath = null,
        public ?int $pageNumber = null,
        public float $lexicalScore = 0.0,
        public bool $exactCodeMatch = false,
    ) {
    }

    /** INV-11: past its review interval, the answer must carry a visible caution. */
    public function isStale(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        $due = (new \DateTimeImmutable($this->reviewedAt))
            ->modify('+' . $this->reviewIntervalDays . ' days');

        return $now > $due;
    }
}
