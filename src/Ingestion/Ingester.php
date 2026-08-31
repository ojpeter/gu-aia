<?php

declare(strict_types=1);

namespace GuAia\Ingestion;

use GuAia\Retrieval\VectorCodec;
use PDO;
use Throwable;

/**
 * Ingests one document. requirements.md Section 5.
 *
 * fetch -> extract -> clean -> chunk -> embed -> write, and every step can
 * refuse. The refusals are the interesting part:
 *
 *   NO OWNER, NO REVIEW DATE, NO REVIEW INTERVAL -> NOT INDEXED (INV-11).
 *   Checked here as well as by the schema, so the report says which field is
 *   missing instead of surfacing a constraint violation.
 *
 *   CANNOT EXTRACT -> NOT INDEXED, and recorded on the document with a reason
 *   the owning office can act on (Section 5.2). Never approximated.
 *
 *   UNCHANGED CONTENT -> NOT RE-INDEXED. Re-embedding an identical page on every
 *   nightly crawl would churn the corpus, invalidate stable embeddings and make
 *   "when did this actually change" unanswerable.
 *
 * RE-INGESTION SUPERSEDES (INV-12). A changed page becomes a new document and
 * new chunks; the previous ones are marked superseded and linked. That is what
 * lets a past answer be reconstructed after the page it came from has moved on,
 * which is the whole reason the retention rules exist.
 */
final class Ingester
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Fetcher $fetcher,
        private readonly Embedder $embedder,
        private readonly Cleaner $cleaner = new Cleaner(),
        private readonly Chunker $chunker = new Chunker(),
        private readonly HtmlExtractor $html = new HtmlExtractor(),
        private readonly PdfExtractor $pdf = new PdfExtractor(),
    ) {
    }

    /**
     * @param array{owning_office_id: int, reviewed_at: string, review_interval_days: int, category_key?: ?string, title?: ?string} $metadata
     */
    public function ingest(string $url, array $metadata): IngestOutcome
    {
        // INV-11, checked before anything is fetched. There is no point spending
        // a request on a document that cannot legally be indexed.
        $missing = $this->missingMetadata($metadata);
        if ($missing !== []) {
            return IngestOutcome::rejected(
                'missing_metadata',
                'Not indexed: ' . implode(', ', $missing) . '. INV-11 requires all three.'
            );
        }

        try {
            $resource = $this->fetcher->fetch($url);
        } catch (Throwable $e) {
            return IngestOutcome::rejected('fetch_failed', $e->getMessage());
        }

        try {
            $blocks = $this->extract($resource);
        } catch (ExtractionFailed $e) {
            $this->recordRejection($url, $metadata, $e->reason, $e->getMessage());

            return IngestOutcome::rejected($e->reason, $e->getMessage());
        }

        $flagged = [];
        $blocks = $this->cleanBlocks($blocks, $flagged);

        $chunks = $this->chunker->chunk($blocks);

        if ($chunks === []) {
            $this->recordRejection($url, $metadata, 'no_content', 'Nothing survived extraction and chunking.');

            return IngestOutcome::rejected('no_content', 'Nothing survived extraction and chunking.');
        }

        $contentHash = hash('sha256', implode("\n", array_map(
            static fn (Chunk $c): string => $c->body,
            $chunks
        )));

        $existing = $this->activeDocument($url);

        if ($existing !== null && (string) $existing['content_hash'] === $contentHash) {
            $this->pdo->prepare('UPDATE documents SET last_ingested_at = NOW() WHERE id = ?')
                ->execute([(int) $existing['id']]);

            return IngestOutcome::unchanged((int) $existing['id']);
        }

        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            if ($existing !== null) {
                $this->supersede((int) $existing['id']);
            }

            $documentId = $this->insertDocument($url, $resource, $metadata, $contentHash, $existing);

            foreach ($chunks as $ordinal => $chunk) {
                $this->insertChunk($documentId, $ordinal + 1, $chunk, $metadata);
            }

            if ($existing !== null) {
                $this->pdo->prepare('UPDATE documents SET superseded_by_id = ? WHERE id = ?')
                    ->execute([$documentId, (int) $existing['id']]);
            }

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            /** @var list<string> $flagged */
            return IngestOutcome::ingested($documentId, count($chunks), $flagged);
        } catch (Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return list<Block> */
    private function extract(FetchedResource $resource): array
    {
        if ($resource->isPdf()) {
            return $this->pdf->extract($resource->body);
        }

        if ($resource->isHtml()) {
            return $this->html->extract($resource->body);
        }

        throw ExtractionFailed::unsupportedFormat($resource->contentType);
    }

    /**
     * Cleaning runs per block, and what it FLAGS travels back to the caller.
     *
     * INV-6: instruction-shaped prose is kept, not deleted, because silently
     * editing a University page changes what the University said. The flag is
     * how a human gets told, and an ingest run that flags something is an ingest
     * run somebody should look at.
     *
     * @param list<Block>  $blocks
     * @param list<string> $flagged
     *
     * @return list<Block>
     */
    private function cleanBlocks(array $blocks, array &$flagged): array
    {
        $flagged = [];
        $cleaned = [];

        foreach ($blocks as $block) {
            $result = $this->cleaner->clean($block->text);

            foreach ($result->flagged as $flag) {
                $flagged[] = $flag;
            }

            if (trim($result->text) === '') {
                continue;
            }

            $cleaned[] = new Block(
                type: $block->type,
                text: $result->text,
                level: $block->level,
                caption: $block->caption,
                atomic: $block->atomic,
                pageNumber: $block->pageNumber,
            );
        }

        return $cleaned;
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return list<string>
     */
    private function missingMetadata(array $metadata): array
    {
        $missing = [];

        if ((int) ($metadata['owning_office_id'] ?? 0) < 1) {
            $missing[] = 'no owning office';
        }
        if (trim((string) ($metadata['reviewed_at'] ?? '')) === '') {
            $missing[] = 'no review date';
        }
        if ((int) ($metadata['review_interval_days'] ?? 0) < 1) {
            $missing[] = 'no review interval';
        }

        return $missing;
    }

    /** @return array<string, mixed>|null */
    private function activeDocument(string $url): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, content_hash FROM documents
              WHERE source_ref_hash = ? AND status = 'active' LIMIT 1"
        );
        $statement->execute([hash('sha256', $url)]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    private function supersede(int $documentId): void
    {
        $this->pdo->prepare(
            "UPDATE chunks SET status = 'superseded', superseded_at = NOW() WHERE document_id = ?"
        )->execute([$documentId]);

        $this->pdo->prepare(
            "UPDATE documents SET status = 'superseded', superseded_at = NOW() WHERE id = ?"
        )->execute([$documentId]);
    }

    /**
     * @param array<string, mixed>      $metadata
     * @param array<string, mixed>|null $existing
     */
    private function insertDocument(
        string $url,
        FetchedResource $resource,
        array $metadata,
        string $contentHash,
        ?array $existing,
    ): int {
        // A superseded document keeps the old source_ref_hash, and the unique
        // key is on that hash, so a new version needs a distinct reference.
        // Versioning it keeps the URL readable in the corpus browser.
        $reference = $existing === null ? $url : $url . '#v' . time();

        $statement = $this->pdo->prepare(
            'INSERT INTO documents
                (source_type, source_ref, source_ref_hash, title, owning_office_id,
                 reviewed_at, review_interval_days, category_key, is_authoritative,
                 content_hash, ingest_status, last_ingested_at)
             VALUES
                (:type, :ref, :hash, :title, :office,
                 :reviewed_at, :interval, :category, 0,
                 :content_hash, \'ingested\', NOW())'
        );

        $statement->execute([
            'type' => $resource->isPdf() ? 'pdf' : 'web_page',
            'ref' => mb_substr($reference, 0, 1000),
            'hash' => hash('sha256', $reference),
            'title' => mb_substr((string) ($metadata['title'] ?? $url), 0, 500),
            'office' => (int) $metadata['owning_office_id'],
            'reviewed_at' => (string) $metadata['reviewed_at'],
            'interval' => (int) $metadata['review_interval_days'],
            'category' => $metadata['category_key'] ?? null,
            'content_hash' => $contentHash,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $metadata */
    private function insertChunk(int $documentId, int $ordinal, Chunk $chunk, array $metadata): void
    {
        $vector = $this->embedder->embed($chunk->body);

        $statement = $this->pdo->prepare(
            'INSERT INTO chunks
                (document_id, ordinal, heading_path, page_number, caption, body, token_count,
                 is_atomic_block, atomic_block_kind, embedding, embedding_dims, embedding_model,
                 embedded_at, category_key, reviewed_at, is_authoritative, owning_office_id)
             VALUES
                (:document_id, :ordinal, :heading_path, :page_number, :caption, :body, :tokens,
                 :atomic, :atomic_kind, :embedding, :dims, :model,
                 NOW(), :category, :reviewed_at, 0, :office)'
        );

        $statement->bindValue('document_id', $documentId, PDO::PARAM_INT);
        $statement->bindValue('ordinal', $ordinal, PDO::PARAM_INT);
        $statement->bindValue('heading_path', $chunk->headingPath === [] ? null : $chunk->headingPathString());
        // PARAM_INT|PARAM_NULL is not a valid combination; a null value with
        // PARAM_NULL is how an absent page number is bound.
        $statement->bindValue(
            'page_number',
            $chunk->pageNumber,
            $chunk->pageNumber === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
        $statement->bindValue('caption', $chunk->caption);
        $statement->bindValue('body', $chunk->body);
        $statement->bindValue('tokens', $chunk->tokenCount, PDO::PARAM_INT);
        $statement->bindValue('atomic', $chunk->isAtomicBlock ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue('atomic_kind', $chunk->atomicBlockKind);
        $statement->bindValue('embedding', VectorCodec::encode($vector), PDO::PARAM_LOB);
        $statement->bindValue('dims', $this->embedder->dimensions(), PDO::PARAM_INT);
        $statement->bindValue('model', $this->embedder->modelName());
        $statement->bindValue('category', $metadata['category_key'] ?? null);
        $statement->bindValue('reviewed_at', (string) $metadata['reviewed_at']);
        $statement->bindValue('office', (int) $metadata['owning_office_id'], PDO::PARAM_INT);
        $statement->execute();
    }

    /**
     * Records WHY a document was refused, on the document itself.
     *
     * Section 5.2 requires a report to the owning office. A rejection that only
     * appears in a log line is a rejection nobody outside DICTS ever sees, and
     * the office that could fix it is the office that never hears.
     *
     * @param array<string, mixed> $metadata
     */
    private function recordRejection(string $url, array $metadata, string $reason, string $message): void
    {
        $existing = $this->activeDocument($url);

        if ($existing !== null) {
            $this->pdo->prepare(
                "UPDATE documents SET ingest_status = 'rejected', ingest_rejection_reason = ?
                  WHERE id = ?"
            )->execute([mb_substr($reason . ': ' . $message, 0, 500), (int) $existing['id']]);

            return;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO documents
                (source_type, source_ref, source_ref_hash, title, owning_office_id,
                 reviewed_at, review_interval_days, ingest_status, ingest_rejection_reason)
             VALUES
                (:type, :ref, :hash, :title, :office, :reviewed_at, :interval,
                 \'rejected\', :reason)'
        );

        $statement->execute([
            'type' => 'web_page',
            'ref' => mb_substr($url, 0, 1000),
            'hash' => hash('sha256', $url),
            'title' => mb_substr((string) ($metadata['title'] ?? $url), 0, 500),
            'office' => (int) $metadata['owning_office_id'],
            'reviewed_at' => (string) $metadata['reviewed_at'],
            'interval' => (int) $metadata['review_interval_days'],
            'reason' => mb_substr($reason . ': ' . $message, 0, 500),
        ]);
    }
}
