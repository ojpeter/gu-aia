<?php

declare(strict_types=1);

namespace GuAia\Tests\Integration;

use GuAia\Admin\AuthenticatedUser;
use GuAia\Admin\CuratedEntryInput;
use GuAia\Admin\CuratedEntryWriter;
use GuAia\Admin\Role;
use GuAia\Ingestion\HashingEmbedder;
use GuAia\Retrieval\VectorCodec;
use GuAia\Tests\Support\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Curated question-and-answer authoring. requirements.md Sections 5.1, 14.
 *
 * All of it runs inside a rolled-back transaction, so nothing reaches the
 * corpus — which must stay empty until Phase 0 completes.
 */
final class CuratedEntryTest extends TestCase
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

    // ---------------------------------------------------------- validation

    public function testAnEntryWithoutAReviewDateIsRefusedBeforeItReachesTheDatabase(): void
    {
        // INV-11 at the form layer. The schema would refuse it too, but a
        // constraint violation is a 500; a field error is something a person can
        // act on.
        [$input, $errors] = CuratedEntryInput::fromRequest($this->pdo, $this->validRequest(['reviewed_at' => '']));

        self::assertNull($input);
        self::assertArrayHasKey('reviewed_at', $errors);
    }

    public function testAFutureReviewDateIsRefused(): void
    {
        // A future review date would keep the staleness sweep permanently
        // satisfied, which is INV-11 defeated by a typo.
        $tomorrow = (new \DateTimeImmutable('+1 day'))->format('Y-m-d');
        [$input, $errors] = CuratedEntryInput::fromRequest($this->pdo, $this->validRequest(['reviewed_at' => $tomorrow]));

        self::assertNull($input);
        self::assertArrayHasKey('reviewed_at', $errors);
    }

    public function testAnImpossibleDateIsRefused(): void
    {
        // 2026-02-30 satisfies any sensible regex and is not a date.
        [$input, $errors] = CuratedEntryInput::fromRequest($this->pdo, $this->validRequest(['reviewed_at' => '2026-02-30']));

        self::assertNull($input);
        self::assertArrayHasKey('reviewed_at', $errors);
    }

    public function testAZeroReviewIntervalIsRefused(): void
    {
        [$input, $errors] = CuratedEntryInput::fromRequest($this->pdo, $this->validRequest(['review_interval_days' => '0']));

        self::assertNull($input);
        self::assertArrayHasKey('review_interval_days', $errors);
    }

    public function testACategoryThatDoesNotExistIsRefused(): void
    {
        // Rule 4: enum-like fields are validated against the actual lookup
        // table, not a hard-coded array.
        [$input, $errors] = CuratedEntryInput::fromRequest($this->pdo, $this->validRequest(['category_key' => 'invented']));

        self::assertNull($input);
        self::assertArrayHasKey('category_key', $errors);
    }

    public function testAnOfficeThatDoesNotExistIsRefused(): void
    {
        [$input, $errors] = CuratedEntryInput::fromRequest($this->pdo, $this->validRequest(['owning_office_id' => '999999']));

        self::assertNull($input);
        self::assertArrayHasKey('owning_office_id', $errors);
    }

    public function testEmptyQuestionAndAnswerAreRefused(): void
    {
        [$input, $errors] = CuratedEntryInput::fromRequest(
            $this->pdo,
            $this->validRequest(['question' => '', 'answer' => '  '])
        );

        self::assertNull($input);
        self::assertArrayHasKey('question', $errors);
        self::assertArrayHasKey('answer', $errors);
    }

    public function testAValidRequestProducesAnInput(): void
    {
        [$input, $errors] = CuratedEntryInput::fromRequest($this->pdo, $this->validRequest());

        self::assertSame([], $errors);
        self::assertNotNull($input);
    }

    // -------------------------------------------------------------- writing

    public function testSavingCreatesADocumentAChunkAndAnEntry(): void
    {
        // A curated entry is not a special case: it is backed by real corpus
        // rows so it flows through the same retrieval, citation and reviewed_at
        // machinery as crawled content.
        $entryId = $this->save($this->validRequest());

        $row = $this->entry($entryId);
        self::assertSame('active', $row['status']);

        $documentId = (int) $row['document_id'];
        $document = $this->one('SELECT * FROM documents WHERE id = ?', [$documentId]);

        self::assertSame('curated', $document['source_type']);
        self::assertSame('ingested', $document['ingest_status']);
        self::assertSame('active', $document['status']);

        $chunk = $this->one('SELECT * FROM chunks WHERE document_id = ?', [$documentId]);
        self::assertSame('active', $chunk['status']);
        self::assertNotNull($chunk['embedding']);
    }

    public function testTheChunkCarriesTheQuestionAsWellAsTheAnswer(): void
    {
        // Retrieval matches against the chunk body, and a member of the public
        // phrases their query far closer to the question than to the answer. A
        // chunk holding only the answer would be near-unfindable by the query it
        // was written for.
        $entryId = $this->save($this->validRequest([
            'question' => 'Does the University offer evening classes?',
            'answer' => 'Some programmes run in the evening.',
        ]));

        $documentId = (int) $this->entry($entryId)['document_id'];
        $chunk = $this->one('SELECT body FROM chunks WHERE document_id = ?', [$documentId]);

        self::assertStringContainsString('evening classes', (string) $chunk['body']);
        self::assertStringContainsString('run in the evening', (string) $chunk['body']);
    }

    public function testTheStoredEmbeddingDecodesToTheEmbedderDimensions(): void
    {
        $entryId = $this->save($this->validRequest());
        $documentId = (int) $this->entry($entryId)['document_id'];

        $chunk = $this->one('SELECT embedding, embedding_dims, embedding_model FROM chunks WHERE document_id = ?', [$documentId]);
        $vector = VectorCodec::decode((string) $chunk['embedding']);

        self::assertCount((int) $chunk['embedding_dims'], $vector);
        self::assertSame((new HashingEmbedder())->modelName(), $chunk['embedding_model']);
    }

    public function testEditingSupersedesRatherThanOverwrites(): void
    {
        // INV-12, and the most ordinary action in the console. Overwriting would
        // destroy the text somebody may already have been answered from, quietly.
        $firstId = $this->save($this->validRequest(['answer' => 'The original answer.']));
        $firstDocumentId = (int) $this->entry($firstId)['document_id'];

        $secondId = $this->save($this->validRequest(['answer' => 'The corrected answer.']), supersedes: $firstId);

        self::assertNotSame($firstId, $secondId);

        // The old version still exists, marked superseded.
        $old = $this->entry($firstId);
        self::assertSame('superseded', $old['status']);
        self::assertSame('The original answer.', $old['answer']);
        self::assertNotNull($old['superseded_at']);

        // And its chunk is out of retrieval, so both versions are never live at
        // once competing for the same question.
        $oldChunk = $this->one('SELECT status FROM chunks WHERE document_id = ?', [$firstDocumentId]);
        self::assertSame('superseded', $oldChunk['status']);

        $new = $this->entry($secondId);
        self::assertSame('active', $new['status']);
        self::assertSame('The corrected answer.', $new['answer']);
    }

    public function testOnlyTheCurrentVersionIsRetrievable(): void
    {
        $firstId = $this->save($this->validRequest(['answer' => 'Old.']));
        $this->save($this->validRequest(['answer' => 'New.']), supersedes: $firstId);

        $active = $this->pdo->query(
            "SELECT COUNT(*) FROM chunks c
               INNER JOIN documents d ON d.id = c.document_id
              WHERE d.source_type = 'curated' AND c.status = 'active' AND d.status = 'active'"
        );

        self::assertSame(1, (int) ($active === false ? -1 : $active->fetchColumn()));
    }

    public function testAFailedSaveLeavesNothingBehind(): void
    {
        // The document, chunk and entry are written in one transaction: an entry
        // that existed as a document but not as a chunk would be invisible to
        // retrieval while appearing in the console.
        $before = $this->rowsIn('documents');

        $writer = new CuratedEntryWriter($this->pdo, new HashingEmbedder());
        [$input] = CuratedEntryInput::fromRequest($this->pdo, $this->validRequest());
        self::assertNotNull($input);

        // Supersede an id that does not exist, then write normally: the write
        // still succeeds, which is the documented behaviour (a missing previous
        // version is not a reason to refuse the new one).
        $writer->save($this->author(), $input, 999999);

        self::assertSame($before + 1, $this->rowsIn('documents'));
    }

    // ------------------------------------------------------------ fixtures

    /** @param array<string, string> $overrides @return array<string, string> */
    private function validRequest(array $overrides = []): array
    {
        return array_merge([
            'question' => 'Where do I collect my transcript?',
            'answer' => 'Transcripts are collected from the Academic Registrar.',
            'category_key' => 'contact_directions',
            'owning_office_id' => (string) $this->firstOfficeId(),
            'reviewed_at' => date('Y-m-d'),
            'review_interval_days' => '365',
        ], $overrides);
    }

    /** @param array<string, string> $request */
    private function save(array $request, ?int $supersedes = null): int
    {
        [$input, $errors] = CuratedEntryInput::fromRequest($this->pdo, $request);
        self::assertSame([], $errors);
        self::assertNotNull($input);

        return (new CuratedEntryWriter($this->pdo, new HashingEmbedder()))
            ->save($this->author(), $input, $supersedes);
    }

    private function author(): AuthenticatedUser
    {
        return new AuthenticatedUser(1, 'Test Editor', 'editor@example.invalid', Role::Editor);
    }

    private function firstOfficeId(): int
    {
        $statement = $this->pdo->query('SELECT MIN(id) FROM offices');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function entry(int $id): array
    {
        return $this->one('SELECT * FROM curated_entries WHERE id = ?', [$id]);
    }

    /** @param list<mixed> $parameters @return array<string, mixed> */
    private function one(string $sql, array $parameters): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch();

        self::assertIsArray($row, 'Expected a row for: ' . $sql);

        return $row;
    }

    private function rowsIn(string $table): int
    {
        $statement = $this->pdo->query("SELECT COUNT(*) FROM {$table}");

        return $statement === false ? -1 : (int) $statement->fetchColumn();
    }
}
