<?php

declare(strict_types=1);

namespace GuAia\Answering;

/**
 * An answer that has passed the citation binder: every reference it makes
 * resolves to a chunk that was actually retrieved, and it makes at least one.
 *
 * The existence of this type is the point. Nothing downstream should accept a
 * bare string as an answer, because a bare string carries no evidence that
 * INV-1 held. If a value of this class exists, the check passed; if the check
 * failed, there is no value, only the refusal template.
 */
final readonly class BoundAnswer
{
    /**
     * @param array<int, int> $citations reference number => chunk id, exactly the
     *                                   references the answer actually used
     */
    public function __construct(
        public string $text,
        public array $citations,
    ) {
    }

    /** @return list<int> */
    public function chunkIds(): array
    {
        return array_values(array_unique($this->citations));
    }

    public function citationCount(): int
    {
        return count($this->citations);
    }
}
