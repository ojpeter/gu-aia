<?php

declare(strict_types=1);

namespace GuAia\Tests\Integration;

use GuAia\Admin\AuthenticatedUser;
use GuAia\Admin\AuthoritativeMarker;
use GuAia\Admin\Role;
use GuAia\Tests\Support\Database;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Marking a source authoritative. requirements.md Section 5.2.
 *
 * The highest-consequence action in the console: it decides which document the
 * assistant quotes when two disagree, and for fees that is the figure a member
 * of the public is shown.
 *
 * The two tests that carry the most weight are the one asserting exactly one
 * authoritative source per category, and the one asserting the flag reaches the
 * chunks. The second is the quiet one — chunks denormalise the flag so retrieval
 * stays a single-table scan, and a document flagged without its chunks updated
 * would be authoritative in the console and not in the answering pipeline, while
 * the screen showed the change had worked.
 */
final class AuthoritativeMarkerTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $this->pdo = Database::connect();
        if ($this->pdo === null) {
            self::markTestSkipped('No database available (' . Database::unavailableReason() . ').');
        }
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testMarkingSetsTheFlagOnTheDocumentAndItsChunks(): void
    {
        [$documentId, $chunkId] = $this->seedDocument('fees', 'Fees schedule 2026/27');

        (new AuthoritativeMarker($this->pdo))->mark($this->authoriser(), $documentId, 'fees');

        self::assertSame(1, $this->flagOnDocument($documentId));
        self::assertSame(
            1,
            $this->flagOnChunk($chunkId),
            'The flag must reach the chunks, or the console and the answering pipeline disagree.'
        );
    }

    public function testMarkingANewSourceDisplacesThePreviousOne(): void
    {
        // "The one marked authoritative wins" is only meaningful if there is
        // exactly one. Two would make the outcome depend on retrieval order.
        [$oldId, $oldChunkId] = $this->seedDocument('fees', 'Fees schedule 2025/26');
        [$newId, $newChunkId] = $this->seedDocument('fees', 'Fees schedule 2026/27');

        $marker = new AuthoritativeMarker($this->pdo);
        $marker->mark($this->authoriser(), $oldId, 'fees');
        $displaced = $marker->mark($this->authoriser(), $newId, 'fees');

        self::assertSame($oldId, $displaced['previousDocumentId']);
        self::assertSame('Fees schedule 2025/26', $displaced['previousTitle']);

        self::assertSame(0, $this->flagOnDocument($oldId));
        self::assertSame(0, $this->flagOnChunk($oldChunkId), 'The displaced document\'s chunks must be cleared too.');
        self::assertSame(1, $this->flagOnDocument($newId));
        self::assertSame(1, $this->flagOnChunk($newChunkId));
    }

    public function testExactlyOneDocumentIsAuthoritativePerCategory(): void
    {
        [$a] = $this->seedDocument('fees', 'A');
        [$b] = $this->seedDocument('fees', 'B');
        [$c] = $this->seedDocument('fees', 'C');

        $marker = new AuthoritativeMarker($this->pdo);
        $marker->mark($this->authoriser(), $a, 'fees');
        $marker->mark($this->authoriser(), $b, 'fees');
        $marker->mark($this->authoriser(), $c, 'fees');

        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM documents WHERE category_key = ? AND is_authoritative = 1 AND status = 'active'"
        );
        $statement->execute(['fees']);

        self::assertSame(1, (int) $statement->fetchColumn());
    }

    public function testMarkingDoesNotAffectOtherCategories(): void
    {
        [$feesId] = $this->seedDocument('fees', 'Fees');
        [$entryId] = $this->seedDocument('entry_requirements', 'Entry requirements');

        $marker = new AuthoritativeMarker($this->pdo);
        $marker->mark($this->authoriser(), $feesId, 'fees');
        $marker->mark($this->authoriser(), $entryId, 'entry_requirements');

        self::assertSame(1, $this->flagOnDocument($feesId), 'Categories are independent.');
        self::assertSame(1, $this->flagOnDocument($entryId));
    }

    public function testAnAuthoriserWithoutASecondFactorIsRefused(): void
    {
        // Checked here as well as at the page guard: a privileged action that
        // trusts its caller is one refactor away from being unguarded.
        [$documentId] = $this->seedDocument('fees', 'Fees');

        $withoutTwoFactor = new AuthenticatedUser(
            1,
            'No Second Factor',
            'nofa@example.invalid',
            Role::Authoriser,
            twoFactorSatisfied: false
        );

        $this->expectException(RuntimeException::class);
        (new AuthoritativeMarker($this->pdo))->mark($withoutTwoFactor, $documentId, 'fees');
    }

    public function testAnEditorIsRefused(): void
    {
        [$documentId] = $this->seedDocument('fees', 'Fees');

        $editor = new AuthenticatedUser(1, 'Editor', 'editor@example.invalid', Role::Editor);

        $this->expectException(RuntimeException::class);
        (new AuthoritativeMarker($this->pdo))->mark($editor, $documentId, 'fees');
    }

    public function testASupersededDocumentCannotBeMadeAuthoritative(): void
    {
        // Otherwise the flag resurrects content that was deliberately retired.
        [$documentId] = $this->seedDocument('fees', 'Retired schedule');
        $this->pdo->prepare("UPDATE documents SET status = 'superseded' WHERE id = ?")->execute([$documentId]);

        $this->expectException(RuntimeException::class);
        (new AuthoritativeMarker($this->pdo))->mark($this->authoriser(), $documentId, 'fees');
    }

    public function testADocumentCannotBeMarkedForACategoryItIsNotFiledUnder(): void
    {
        // The category filter and the authoritative flag must not disagree.
        [$documentId] = $this->seedDocument('fees', 'Fees');

        $this->expectException(RuntimeException::class);
        (new AuthoritativeMarker($this->pdo))->mark($this->authoriser(), $documentId, 'entry_requirements');
    }

    public function testAMissingDocumentIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        (new AuthoritativeMarker($this->pdo))->mark($this->authoriser(), 999999, 'fees');
    }

    public function testCurrentAuthoritativeReportsWhatWouldBeDisplaced(): void
    {
        // The screen uses this to show the consequence before the click.
        $marker = new AuthoritativeMarker($this->pdo);

        self::assertNull($marker->currentAuthoritative('fees'));

        [$documentId] = $this->seedDocument('fees', 'Fees schedule');
        $marker->mark($this->authoriser(), $documentId, 'fees');

        $current = $marker->currentAuthoritative('fees');
        self::assertIsArray($current);
        self::assertSame('Fees schedule', $current['title']);
    }

    // ------------------------------------------------------------- fixtures

    private function authoriser(): AuthenticatedUser
    {
        return new AuthenticatedUser(
            1,
            'Test Authoriser',
            'authoriser@example.invalid',
            Role::Authoriser,
            twoFactorSatisfied: true
        );
    }

    /** @return array{0: int, 1: int} document id, chunk id */
    private function seedDocument(string $categoryKey, string $title): array
    {
        $suffix = bin2hex(random_bytes(8));

        $document = $this->pdo->prepare(<<<'SQL'
            INSERT INTO documents
                (source_type, source_ref, source_ref_hash, title, owning_office_id,
                 reviewed_at, review_interval_days, category_key, ingest_status)
            VALUES ('web_page', :ref, :hash, :title, (SELECT MIN(id) FROM offices),
                    '2026-01-01', 365, :category, 'ingested')
            SQL);
        $document->execute([
            'ref' => 'test://auth/' . $suffix,
            'hash' => hash('sha256', $suffix),
            'title' => $title,
            'category' => $categoryKey,
        ]);
        $documentId = (int) $this->pdo->lastInsertId();

        $chunk = $this->pdo->prepare(<<<'SQL'
            INSERT INTO chunks (document_id, ordinal, body, reviewed_at, owning_office_id, category_key)
            VALUES (:document_id, 1, :body, '2026-01-01', (SELECT MIN(id) FROM offices), :category)
            SQL);
        $chunk->execute([
            'document_id' => $documentId,
            'body' => $title . ' body text.',
            'category' => $categoryKey,
        ]);

        return [$documentId, (int) $this->pdo->lastInsertId()];
    }

    private function flagOnDocument(int $documentId): int
    {
        $statement = $this->pdo->prepare('SELECT is_authoritative FROM documents WHERE id = ?');
        $statement->execute([$documentId]);

        return (int) $statement->fetchColumn();
    }

    private function flagOnChunk(int $chunkId): int
    {
        $statement = $this->pdo->prepare('SELECT is_authoritative FROM chunks WHERE id = ?');
        $statement->execute([$chunkId]);

        return (int) $statement->fetchColumn();
    }
}
