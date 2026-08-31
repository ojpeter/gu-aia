<?php

declare(strict_types=1);

namespace GuAia\Ingestion;

/**
 * Retrieves a document for ingestion.
 *
 * An interface so the crawler's scope rules can be tested without reaching the
 * network, and so a local fixture fetcher can drive the ingester end to end
 * before Phase 0 permits anything real to be indexed.
 */
interface Fetcher
{
    /** Scope check, separable from fetching so it can be asserted on its own. */
    public function isAllowed(string $url): bool;

    /** @throws \RuntimeException if the URL is out of scope or cannot be retrieved */
    public function fetch(string $url): FetchedResource;
}
