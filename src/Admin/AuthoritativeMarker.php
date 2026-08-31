<?php

declare(strict_types=1);

namespace GuAia\Admin;

use PDO;
use RuntimeException;

/**
 * Marks a document authoritative for a category. requirements.md Section 5.2.
 *
 * "Where two sources conflict, the one marked authoritative for that category
 *  wins, and the conflict is reported to the admin console."
 *
 * This is the highest-consequence action in the system. It does not change what
 * a document says; it changes which document the assistant quotes when two
 * disagree — which, for the `fees` category, decides which figure a member of
 * the public is shown. That is why it is the only permission requiring a second
 * factor, and why every call is audited with both the new and the displaced
 * document named.
 *
 * ONE AUTHORITATIVE DOCUMENT PER CATEGORY, ENFORCED HERE.
 *
 * "The one marked authoritative wins" is only meaningful if there is exactly
 * one. Two would make the outcome depend on retrieval order, which is precisely
 * the ambiguity the flag exists to remove — and it would do so invisibly, since
 * both rows would look correct in isolation. So marking a new document
 * authoritative UNMARKS the previous one for that category, in the same
 * transaction.
 *
 * THE FLAG PROPAGATES TO CHUNKS.
 *
 * `chunks.is_authoritative` is denormalised from its document so retrieval stays
 * a single-table scan (see 0001_corpus.sql). A document flagged without its
 * chunks updated would be authoritative in the console and not in the answering
 * pipeline — the worst kind of divergence, because the screen would show the
 * change had worked.
 */
final class AuthoritativeMarker
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{previousDocumentId: int|null, previousTitle: string|null}
     *         what was displaced, for the audit entry and the confirmation
     */
    public function mark(AuthenticatedUser $user, int $documentId, string $categoryKey): array
    {
        // Checked again here, not only at the page guard. A privileged action
        // that trusts its caller to have checked is a privileged action one
        // refactor away from being unguarded.
        if (!$user->may(Role::MARK_AUTHORITATIVE)) {
            throw new RuntimeException('Not permitted to mark a document authoritative.');
        }

        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $document = $this->fetchDocument($documentId);

            if ($document === null) {
                throw new RuntimeException('That document does not exist.');
            }

            if ($document['status'] !== 'active') {
                // Making a superseded document authoritative would resurrect
                // content that was deliberately retired.
                throw new RuntimeException('A superseded document cannot be made authoritative.');
            }

            if ((string) $document['category_key'] !== $categoryKey) {
                // A document is authoritative FOR a category; a document filed
                // under a different one being marked for this one would make the
                // category filter and the authoritative flag disagree.
                throw new RuntimeException('That document is not filed under this category.');
            }

            $previous = $this->currentAuthoritative($categoryKey);

            if ($previous !== null && (int) $previous['id'] !== $documentId) {
                $this->setFlag((int) $previous['id'], false);
            }

            $this->setFlag($documentId, true);

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            return [
                'previousDocumentId' => $previous === null ? null : (int) $previous['id'],
                'previousTitle' => $previous === null ? null : (string) $previous['title'],
            ];
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    public function currentAuthoritative(string $categoryKey): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, title, source_ref, reviewed_at
               FROM documents
              WHERE category_key = :category
                AND is_authoritative = 1
                AND status = 'active'
              LIMIT 1"
        );
        $statement->execute(['category' => $categoryKey]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /** Document and chunks together, always. */
    private function setFlag(int $documentId, bool $authoritative): void
    {
        $value = $authoritative ? 1 : 0;

        $this->pdo->prepare('UPDATE documents SET is_authoritative = ? WHERE id = ?')
            ->execute([$value, $documentId]);

        $this->pdo->prepare('UPDATE chunks SET is_authoritative = ? WHERE document_id = ?')
            ->execute([$value, $documentId]);
    }

    /** @return array<string, mixed>|null */
    private function fetchDocument(int $documentId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, title, category_key, status FROM documents WHERE id = ?'
        );
        $statement->execute([$documentId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }
}
