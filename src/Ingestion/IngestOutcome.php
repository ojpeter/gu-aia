<?php

declare(strict_types=1);

namespace GuAia\Ingestion;

/**
 * What happened to one document.
 *
 * Three outcomes, not two, because "unchanged" is genuinely different from
 * "ingested": a nightly crawl over a stable corpus should report almost
 * entirely unchanged, and a run that reports everything re-ingested is a run
 * where change detection has broken and every embedding has just been churned.
 * Collapsing the two would hide that.
 */
final readonly class IngestOutcome
{
    public const INGESTED = 'ingested';
    public const UNCHANGED = 'unchanged';
    public const REJECTED = 'rejected';

    /** @param list<string> $flagged instruction-shaped text kept for review (INV-6) */
    private function __construct(
        public string $status,
        public ?int $documentId = null,
        public int $chunks = 0,
        public ?string $reason = null,
        public ?string $message = null,
        public array $flagged = [],
    ) {
    }

    /** @param list<string> $flagged */
    public static function ingested(int $documentId, int $chunks, array $flagged = []): self
    {
        return new self(self::INGESTED, $documentId, $chunks, flagged: $flagged);
    }

    public static function unchanged(int $documentId): self
    {
        return new self(self::UNCHANGED, $documentId);
    }

    public static function rejected(string $reason, string $message): self
    {
        return new self(self::REJECTED, reason: $reason, message: $message);
    }

    public function wasIndexed(): bool
    {
        return $this->status === self::INGESTED;
    }
}
