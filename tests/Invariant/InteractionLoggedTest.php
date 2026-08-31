<?php

declare(strict_types=1);

namespace GuAia\Tests\Invariant;

use GuAia\Answering\AnswerMode;
use GuAia\Logging\IdentifierHasher;
use GuAia\Logging\InteractionLogger;
use GuAia\Retrieval\ScoredChunk;
use GuAia\Tests\Support\Database;
use GuAia\Tests\Support\PipelineBuilder;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * INV-7 — Everything is logged.
 *
 * "Query, retrieved set, model used, answer, citations, refusal reason and
 *  latency, under one correlation ID. InteractionLogger writes IN THE SAME
 *  TRANSACTION as the response is served."
 *
 * The database half runs against the real schema inside a transaction that is
 * rolled back, so nothing is left behind — which matters here more than usual,
 * because the corpus must stay empty until Phase 0 completes.
 *
 * Where no database is available the write tests skip rather than pass. A test
 * that silently passes because it could not run is worse than one that fails.
 */
#[Group('invariant')]
final class InteractionLoggedTest extends TestCase
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

    // ------------------------------------------------------------------------

    public function testTheAnswerCarriesEverySectionThirteenFieldTheLogNeeds(): void
    {
        // If a field is missing here, the logger cannot record it, and INV-7
        // fails at the point it is least likely to be noticed.
        $result = PipelineBuilder::make()
            ->withChunk('Applications are submitted through the online portal.')
            ->build()
            ->answer('Will I be admitted?');

        foreach (
            [
                'mode', 'text', 'sources', 'retrieved', 'citations', 'categoryKey',
                'refusalReason', 'staleSource', 'degraded', 'degradedReason',
                'model', 'promptVersion', 'promptTokens', 'completionTokens', 'cost',
            ] as $field
        ) {
            self::assertTrue(
                property_exists($result, $field),
                sprintf('INV-7: AnswerResult is missing "%s", which Section 13 requires on every interaction.', $field)
            );
        }
    }

    public function testAnInteractionIsWrittenWithItsCorrelationIdAndFields(): void
    {
        $logger = new InteractionLogger($this->pdo, new IdentifierHasher('test-key'));

        $result = PipelineBuilder::make()
            ->withChunk('Applications are submitted through the online portal.')
            ->build()
            ->answer('Will I be admitted?');

        $correlationId = self::correlationId();
        $id = $logger->log($correlationId, 'Will I be admitted?', $result, 42, [
            'ip' => '203.0.113.9',
            'session_id' => 'abc123',
        ]);

        $row = $this->fetchInteraction($id);

        self::assertSame($correlationId, $row['correlation_id']);
        self::assertSame('Will I be admitted?', $row['query_text']);
        self::assertSame(AnswerMode::Refuse->value, $row['mode']);
        self::assertSame('individual_outcome', $row['category_key']);
        self::assertSame('individual_outcome', $row['refusal_reason']);
        self::assertSame(42, (int) $row['latency_ms']);
    }

    public function testTheRawIpIsNeverStored(): void
    {
        // docs/data-protection.md DF-2. A plain hash of an IPv4 address is
        // reversible in minutes, so the stored value must be an HMAC and must
        // not be the address itself under any encoding.
        $logger = new InteractionLogger($this->pdo, new IdentifierHasher('test-key'));

        $result = PipelineBuilder::make()->withNoConfidentContext()->build()->answer('Anything at all?');
        $id = $logger->log(self::correlationId(), 'Anything at all?', $result, 5, ['ip' => '203.0.113.9']);

        $row = $this->fetchInteraction($id);

        self::assertNotNull($row['ip_hash']);
        self::assertSame(64, strlen((string) $row['ip_hash']));
        self::assertStringNotContainsString('203.0.113.9', (string) $row['ip_hash']);
        self::assertNotSame(hash('sha256', '203.0.113.9'), $row['ip_hash'], 'The hash must be keyed.');
    }

    public function testAnAbsentIpIsRecordedAsAbsentNotAsAHashOfNothing(): void
    {
        $logger = new InteractionLogger($this->pdo, new IdentifierHasher('test-key'));

        $result = PipelineBuilder::make()->withNoConfidentContext()->build()->answer('Anything at all?');
        $id = $logger->log(self::correlationId(), 'Anything at all?', $result, 5);

        self::assertNull($this->fetchInteraction($id)['ip_hash']);
    }

    public function testAnUnkeyedHasherIsRefusedOutright(): void
    {
        $this->expectException(RuntimeException::class);
        new IdentifierHasher('');
    }

    public function testEveryRefusalBecomesAnUnansweredQuestion(): void
    {
        // Section 13: the weekly report is a primary deliverable, and it is built
        // from these rows. A refusal that is not recorded is a question the
        // University never learns it could not answer.
        $logger = new InteractionLogger($this->pdo, new IdentifierHasher('test-key'));

        $result = PipelineBuilder::make()->withNoConfidentContext('below_threshold')->build()
            ->answer('How many students dropped out last year?');

        $id = $logger->log(self::correlationId(), 'How many students dropped out last year?', $result, 12);

        $statement = $this->pdo->prepare(
            'SELECT normalised_question, refusal_reason FROM unanswered_questions WHERE interaction_id = ?'
        );
        $statement->execute([$id]);
        $row = $statement->fetch();

        self::assertIsArray($row);
        self::assertSame('below_threshold', $row['refusal_reason']);
        self::assertSame(
            'how many students dropped out last year',
            $row['normalised_question'],
            'The normalised form is what the weekly ranking groups on.'
        );
    }

    public function testAnAnsweredQuestionDoesNotBecomeAnUnansweredOne(): void
    {
        // A real chunk row is needed: interaction_retrievals has a foreign key
        // onto chunks, and the server refuses a retrieval logged against an id
        // that does not exist. That refusal is correct - a citation pointing at a
        // chunk which never existed would make INV-1's audit trail worthless -
        // and it is why this fixture writes to the corpus tables (inside the
        // transaction, rolled back, so the corpus stays empty).
        $chunkId = $this->seedChunk('Fees schedule 2026/27.');

        $logger = new InteractionLogger($this->pdo, new IdentifierHasher('test-key'));

        $result = PipelineBuilder::make()
            ->withChunk('Fees schedule 2026/27.', authoritative: true, chunkId: $chunkId)
            ->build()
            ->answer('What are the fees?');

        self::assertSame(AnswerMode::Quoted, $result->mode);
        $id = $logger->log(self::correlationId(), 'What are the fees?', $result, 8);

        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM unanswered_questions WHERE interaction_id = ?');
        $statement->execute([$id]);

        self::assertSame(0, (int) $statement->fetchColumn());
    }

    public function testTheLoggerWritesInsideTheCallersTransaction(): void
    {
        // INV-7's real requirement. If the logger opened and committed its own
        // transaction, the row would survive this rollback — and in production
        // an interaction could be logged for a response that was never served,
        // or worse, served without being logged.
        $logger = new InteractionLogger($this->pdo, new IdentifierHasher('test-key'));

        $result = PipelineBuilder::make()->withNoConfidentContext()->build()->answer('Anything at all?');
        $correlationId = self::correlationId();
        $logger->log($correlationId, 'Anything at all?', $result, 5);

        $this->pdo->rollBack();

        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM interactions WHERE correlation_id = ?');
        $statement->execute([$correlationId]);

        self::assertSame(
            0,
            (int) $statement->fetchColumn(),
            'INV-7 breach: the logger committed independently of the caller.'
        );
    }

    /**
     * Writes a document and one chunk, and returns the chunk id.
     *
     * Only ever called inside the test transaction, which tearDown rolls back.
     * The corpus must stay empty until Phase 0 completes, and a fixture that
     * leaked would put fabricated content into the very corpus this project
     * exists to keep honest.
     */
    private function seedChunk(string $body): int
    {
        $suffix = bin2hex(random_bytes(8));

        $document = $this->pdo->prepare(<<<'SQL'
            INSERT INTO documents
                (source_type, source_ref, source_ref_hash, title, owning_office_id,
                 reviewed_at, review_interval_days, ingest_status)
            VALUES ('web_page', :ref, :hash, 'Test fixture',
                    (SELECT MIN(id) FROM offices), '2026-01-01', 365, 'ingested')
            SQL);
        $document->execute([
            'ref' => 'test://fixture/' . $suffix,
            'hash' => hash('sha256', $suffix),
        ]);
        $documentId = (int) $this->pdo->lastInsertId();

        $chunk = $this->pdo->prepare(<<<'SQL'
            INSERT INTO chunks
                (document_id, ordinal, body, reviewed_at, owning_office_id)
            VALUES (:document_id, 1, :body, '2026-01-01', (SELECT MIN(id) FROM offices))
            SQL);
        $chunk->execute(['document_id' => $documentId, 'body' => $body]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed> */
    private function fetchInteraction(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM interactions WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();

        self::assertIsArray($row, 'No interaction row was written.');

        return $row;
    }

    private static function correlationId(): string
    {
        return sprintf(
            '%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff),
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }
}
