<?php

declare(strict_types=1);

namespace GuAia\Answering;

use GuAia\Retrieval\ScoredChunk;

/**
 * What the pipeline produced, in a shape the interface and the logger can both
 * use without re-deriving anything.
 *
 * Every field Section 13 requires on a logged interaction is here, because an
 * InteractionLogger that has to reconstruct the mode or hunt for the citations
 * is a logger that will eventually record something that did not happen.
 */
final readonly class AnswerResult
{
    /**
     * @param list<ScoredChunk> $sources        cited or quoted; never the whole candidate pool
     * @param array<int, int>   $citations      reference number => chunk id
     * @param bool              $staleSource    INV-11: the answer must carry a visible caution
     * @param bool              $handoffMissing the refusal names no contact; see RefusalRenderer
     */
    public function __construct(
        public AnswerMode $mode,
        public string $text,
        public array $sources = [],
        public array $citations = [],
        public ?string $categoryKey = null,
        public ?string $refusalReason = null,
        public bool $staleSource = false,
        public bool $degraded = false,
        public ?string $degradedReason = null,
        public ?string $model = null,
        public ?string $promptVersion = null,
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public float $cost = 0.0,
        public bool $handoffMissing = false,
    ) {
    }

    public function isRefusal(): bool
    {
        return $this->mode === AnswerMode::Refuse;
    }

    /** INV-1: a non-refusal answer must carry at least one citation. */
    public function isGrounded(): bool
    {
        return $this->isRefusal() || $this->citations !== [];
    }
}
