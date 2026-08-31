<?php

declare(strict_types=1);

namespace GuAia\Retrieval;

/**
 * The retrieval boundary the answering pipeline depends on.
 *
 * Extracted so the pipeline can be tested against fixed context without a
 * database. That is not only a testing convenience: the invariants the pipeline
 * carries — quoted mode never generating, citation binding discarding, degraded
 * mode returning links — are all about what happens AFTER retrieval, and tying
 * them to a live corpus would mean they could only be tested once Phase 0
 * completes. They need testing now.
 */
interface ContextRetriever
{
    public function retrieve(string $query, ?string $categoryKey = null): RetrievalResult;
}
