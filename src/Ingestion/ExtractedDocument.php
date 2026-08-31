<?php

declare(strict_types=1);

namespace GuAia\Ingestion;

/**
 * A document after extraction and before chunking.
 *
 * The metadata INV-11 requires is carried here as non-nullable, so a document
 * that lacks it cannot even be represented, let alone indexed. That mirrors the
 * NOT NULL + CHECK constraints in the schema; belt and braces, because the
 * schema check only fires at the very end of a long pipeline.
 */
final readonly class ExtractedDocument
{
    /**
     * @param list<Block> $blocks
     * @param string      $reviewedAt          ISO date; INV-11
     * @param int         $reviewIntervalDays  INV-11
     */
    public function __construct(
        public string $sourceRef,
        public string $title,
        public array $blocks,
        public string $owningOffice,
        public string $reviewedAt,
        public int $reviewIntervalDays,
        public ?string $categoryKey = null,
        public bool $isAuthoritative = false,
    ) {
    }

    /** Section 5.2: past its review interval, but still served with a caution. */
    public function isStale(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        $reviewed = new \DateTimeImmutable($this->reviewedAt);
        $due = $reviewed->modify('+' . $this->reviewIntervalDays . ' days');

        return $now > $due;
    }
}
