<?php

declare(strict_types=1);

namespace GuAia\Ingestion;

/**
 * What a fetcher returned. requirements.md Section 5.1.
 *
 * `contentHash` is over the extracted body, not the raw response, so that a
 * page whose only change is a rotating banner or a build timestamp does not
 * look modified. Re-indexing everything on every crawl would churn the corpus,
 * invalidate every embedding and make "when did this actually change"
 * unanswerable.
 */
final readonly class FetchedResource
{
    public function __construct(
        public string $url,
        public string $body,
        public string $contentType,
        public int $statusCode,
    ) {
    }

    public function isHtml(): bool
    {
        return str_contains(strtolower($this->contentType), 'text/html');
    }

    public function isPdf(): bool
    {
        return str_contains(strtolower($this->contentType), 'application/pdf')
            || str_starts_with($this->body, '%PDF-');
    }
}
