<?php

declare(strict_types=1);

namespace GuAia\Answering;

/**
 * The router's decision: which category, which mode, and whether the question
 * was refused before retrieval ever ran.
 *
 * `refusedBeforeRetrieval` is recorded rather than inferred because it is the
 * evidence for INV-3. "It was refused" and "it was refused without ever
 * fetching context" are different claims, and only the second is the invariant.
 */
final readonly class Routing
{
    public function __construct(
        public ?string $categoryKey,
        public AnswerMode $mode,
        public bool $refusedBeforeRetrieval = false,
    ) {
    }

    public function isRefusal(): bool
    {
        return $this->mode === AnswerMode::Refuse;
    }

    /** Whether retrieval should run at all for this question. */
    public function shouldRetrieve(): bool
    {
        return !$this->refusedBeforeRetrieval;
    }
}
