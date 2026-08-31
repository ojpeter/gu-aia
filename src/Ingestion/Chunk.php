<?php

declare(strict_types=1);

namespace GuAia\Ingestion;

/**
 * One chunk, ready to be written to the `chunks` table.
 *
 * Carries everything Section 5.3 says a chunk must retain: heading path, page
 * number, caption, and the document metadata that INV-11 requires on every
 * answer. The denormalisation is deliberate and is explained in
 * db/migrations/0001_corpus.sql.
 */
final readonly class Chunk
{
    /** @param list<string> $headingPath outermost heading first */
    public function __construct(
        public string $body,
        public array $headingPath = [],
        public ?int $pageNumber = null,
        public ?string $caption = null,
        public bool $isAtomicBlock = false,
        public ?string $atomicBlockKind = null,
        public int $tokenCount = 0,
    ) {
    }

    public function headingPathString(): string
    {
        return implode(' > ', $this->headingPath);
    }
}
