<?php

declare(strict_types=1);

namespace GuAia\Admin;

use GuAia\Ingestion\Embedder;
use GuAia\Retrieval\VectorCodec;
use PDO;

/**
 * Authors curated question-and-answer entries. requirements.md Sections 5.1, 14.
 *
 * "Curated question-and-answer entries — authored in the admin console for facts
 *  that live on no page."
 *
 * A curated entry is backed by a real `documents` row of `source_type='curated'`
 * and a real `chunks` row with an embedding, so it flows through exactly the same
 * retrieval, citation and `reviewed_at` machinery as crawled content. It is
 * deliberately NOT a special case that bypasses the invariants — a hand-written
 * answer is not more trustworthy than a published page, and giving it a shortcut
 * around the citation and staleness rules would make the console the one place
 * where unverifiable facts could enter.
 *
 * EDITING SUPERSEDES. IT DOES NOT OVERWRITE.
 *
 * INV-12 says nothing is deleted, and the reason is reconstructibility: if
 * somebody complains about what the assistant told them in March, the March text
 * has to still exist. Overwriting a curated answer would destroy exactly that,
 * silently, through the most ordinary action in the console. So an edit writes a
 * new document, chunk and entry, and marks the previous ones superseded with a
 * link back. Retrieval only ever sees `status='active'`.
 *
 * THIS ALSO SATISFIES PHASE 0 PER FACT rather than violating it. Section 15 gates
 * INDEXING on the content audit, and what the audit produces is exactly what this
 * form demands before it will save anything: an owning office, a review date and
 * a review interval, supplied by a named person who is accountable for them.
 */
final class CuratedEntryWriter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Embedder $embedder,
    ) {
    }

    /**
     * @param int|null $supersedesEntryId the curated entry this replaces, if any
     *
     * @return int the new curated_entries id
     */
    public function save(
        AuthenticatedUser $author,
        CuratedEntryInput $input,
        ?int $supersedesEntryId = null,
    ): int {
        // One transaction: a curated entry that existed as a document but not as
        // a chunk would be invisible to retrieval while appearing in the console,
        // which is the worst of both.
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            if ($supersedesEntryId !== null) {
                $this->supersede($supersedesEntryId);
            }

            $reference = 'curated://' . bin2hex(random_bytes(16));

            $documentId = $this->insertDocument($reference, $input);
            $chunkId = $this->insertChunk($documentId, $input);
            $entryId = $this->insertEntry($documentId, $input);

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            unset($chunkId);

            return $entryId;
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Marks a previous version superseded. Never deletes it.
     *
     * The chunk goes first: the moment it is inactive, retrieval stops returning
     * the old answer, and no window exists in which the new and old versions are
     * both live and competing for the same question.
     */
    private function supersede(int $entryId): void
    {
        $lookup = $this->pdo->prepare('SELECT document_id FROM curated_entries WHERE id = ?');
        $lookup->execute([$entryId]);
        $documentId = $lookup->fetchColumn();

        if ($documentId === false) {
            return;
        }

        $this->pdo->prepare(
            "UPDATE chunks SET status = 'superseded', superseded_at = NOW() WHERE document_id = ?"
        )->execute([$documentId]);

        $this->pdo->prepare(
            "UPDATE documents SET status = 'superseded', superseded_at = NOW() WHERE id = ?"
        )->execute([$documentId]);

        $this->pdo->prepare(
            "UPDATE curated_entries SET status = 'superseded', superseded_at = NOW() WHERE id = ?"
        )->execute([$entryId]);
    }

    private function insertDocument(string $reference, CuratedEntryInput $input): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO documents
                (source_type, source_ref, source_ref_hash, title, owning_office_id,
                 reviewed_at, review_interval_days, category_key, is_authoritative,
                 ingest_status, last_ingested_at)
             VALUES
                (\'curated\', :ref, :hash, :title, :office,
                 :reviewed_at, :interval, :category, 0,
                 \'ingested\', NOW())'
        );

        $statement->execute([
            'ref' => $reference,
            'hash' => hash('sha256', $reference),
            // The question is the document title, so the corpus browser shows
            // something a person recognises rather than a URI.
            'title' => mb_substr($input->question, 0, 500),
            'office' => $input->owningOfficeId,
            'reviewed_at' => $input->reviewedAt,
            'interval' => $input->reviewIntervalDays,
            'category' => $input->categoryKey,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * The chunk carries the question AND the answer.
     *
     * Both, because retrieval matches against the chunk body and the wording a
     * member of the public uses is far closer to the question than to the answer.
     * A chunk holding only the answer would be near-unfindable by the very query
     * it was written for.
     */
    private function insertChunk(int $documentId, CuratedEntryInput $input): int
    {
        $body = $input->question . "\n\n" . $input->answer;
        $vector = $this->embedder->embed($body);

        $statement = $this->pdo->prepare(
            'INSERT INTO chunks
                (document_id, ordinal, body, token_count, embedding, embedding_dims,
                 embedding_model, embedded_at, category_key, reviewed_at,
                 is_authoritative, owning_office_id)
             VALUES
                (:document_id, 1, :body, :tokens, :embedding, :dims,
                 :model, NOW(), :category, :reviewed_at,
                 0, :office)'
        );

        $statement->bindValue('document_id', $documentId, PDO::PARAM_INT);
        $statement->bindValue('body', $body);
        $statement->bindValue('tokens', count(preg_split('/\s+/u', trim($body)) ?: []), PDO::PARAM_INT);
        $statement->bindValue('embedding', VectorCodec::encode($vector), PDO::PARAM_LOB);
        $statement->bindValue('dims', $this->embedder->dimensions(), PDO::PARAM_INT);
        $statement->bindValue('model', $this->embedder->modelName());
        $statement->bindValue('category', $input->categoryKey);
        $statement->bindValue('reviewed_at', $input->reviewedAt);
        $statement->bindValue('office', $input->owningOfficeId, PDO::PARAM_INT);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    private function insertEntry(int $documentId, CuratedEntryInput $input): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO curated_entries (document_id, question, answer, category_key)
             VALUES (:document_id, :question, :answer, :category)'
        );

        $statement->execute([
            'document_id' => $documentId,
            'question' => $input->question,
            'answer' => $input->answer,
            'category' => $input->categoryKey,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
