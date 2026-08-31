<?php

declare(strict_types=1);

namespace GuAia\Tests\Invariant;

use GuAia\Answering\AnswerMode;
use GuAia\Answering\AnswerResult;
use GuAia\Http\WidgetRenderer;
use GuAia\Retrieval\RetrievalResult;
use GuAia\Retrieval\ScoredChunk;
use GuAia\Tests\Support\Database;
use GuAia\Tests\Support\PipelineBuilder;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * INV-11 — Stale content is visible.
 *
 * "Every answer carries the last-reviewed date of its source. Content past its
 *  review interval is flagged in the answer. `reviewed_at` is mandatory on every
 *  corpus document; MISSING MEANS THE DOCUMENT IS NOT INDEXED."
 *
 * Two halves, and both are tested here because either alone is worthless:
 *
 *   THE SCHEMA HALF — a document without a real review date cannot exist.
 *   THE RENDER HALF — the date reaches the reader, and staleness is announced.
 *
 * A system that stores review dates faithfully and never shows them tells nobody
 * anything. A system that renders a date it never enforced renders a zero.
 *
 * The schema half is why this test matters more than its size suggests. The
 * NOT NULL constraint alone did NOT hold: on a MySQL-family server without
 * STRICT_TRANS_TABLES, omitting a NOT NULL DATE inserts '0000-00-00' silently,
 * and a document with a zero review date is served as though freshly reviewed
 * and never trips the caution. That was found by attempting the insert rather
 * than reading the DDL, and closed with CHECK constraints in
 * 0007_enforce_review_dates.sql. These tests are what stop it coming back.
 */
#[Group('invariant')]
final class ReviewedAtMandatoryTest extends TestCase
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

    // ------------------------------------------------------- the schema half

    public function testADocumentWithNoReviewDateCannotBeInserted(): void
    {
        // The original bug, pinned. NOT NULL was not enough; the CHECK is.
        $this->expectException(PDOException::class);
        $this->insertDocument(reviewedAt: null, reviewIntervalDays: 365);
    }

    public function testAZeroReviewDateIsRefused(): void
    {
        $this->expectException(PDOException::class);
        $this->insertDocument(reviewedAt: '0000-00-00', reviewIntervalDays: 365);
    }

    public function testAZeroReviewIntervalIsRefused(): void
    {
        // A review interval of zero means "review it never" dressed up as a
        // number, and it would keep the staleness sweep permanently satisfied.
        $this->expectException(PDOException::class);
        $this->insertDocument(reviewedAt: '2026-01-01', reviewIntervalDays: 0);
    }

    public function testADocumentWithNoOwningOfficeCannotBeInserted(): void
    {
        // INV-11 names three mandatory things, not one. Without an owning
        // office there is nobody to notify when the document goes stale, which
        // makes the review date decorative.
        $this->expectException(PDOException::class);
        $this->insertDocument(reviewedAt: '2026-01-01', reviewIntervalDays: 365, officeId: 999999);
    }

    public function testAProperlyFormedDocumentIsAccepted(): void
    {
        // The constraints must not be so tight that real content cannot be
        // indexed; an invariant that blocks everything proves nothing.
        $id = $this->insertDocument(reviewedAt: '2026-01-01', reviewIntervalDays: 365);

        self::assertGreaterThan(0, $id);
    }

    public function testChunksCarryTheSameConstraint(): void
    {
        // chunks denormalise reviewed_at from their document, and every answer
        // renders it from the chunk. A zero there is the same failure one table
        // along, so 0007 constrains both.
        $documentId = $this->insertDocument(reviewedAt: '2026-01-01', reviewIntervalDays: 365);

        $this->expectException(PDOException::class);
        // A zero date on the chunk, even though its document is valid.
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO chunks (document_id, ordinal, body, reviewed_at, owning_office_id)
            VALUES (:document_id, 1, 'body', '0000-00-00', (SELECT MIN(id) FROM offices))
            SQL);
        $statement->execute(['document_id' => $documentId]);
    }

    // ------------------------------------------------------- the render half

    public function testAStaleSourceIsAnnouncedAboveTheAnswer(): void
    {
        // Above, not below. A caution a reader meets after the figure has
        // already been read is not a caution.
        $html = (new WidgetRenderer())->answer(new AnswerResult(
            mode: AnswerMode::Quoted,
            text: 'Tuition is stated in the 2024/25 schedule.',
            sources: [$this->chunk('2020-01-01', 365)],
            staleSource: true,
        ));

        $cautionAt = strpos($html, 'overdue for review');
        $textAt = strpos($html, 'Tuition is stated');

        self::assertIsInt($cautionAt, 'INV-11: a stale source must produce a visible caution.');
        self::assertIsInt($textAt);
        self::assertLessThan($textAt, $cautionAt, 'INV-11: the caution must precede the answer text.');
    }

    public function testEveryRenderedSourceCarriesItsLastReviewedDate(): void
    {
        $html = (new WidgetRenderer())->answer(new AnswerResult(
            mode: AnswerMode::Grounded,
            text: 'An answer. [1]',
            sources: [$this->chunk('2026-03-04', 365)],
            citations: [1 => 1],
        ));

        self::assertStringContainsString(
            'Last reviewed 2026-03-04',
            $html,
            'INV-11: "every answer carries the last-reviewed date of its source".'
        );
    }

    public function testAFreshSourceProducesNoCaution(): void
    {
        // Over-warning is its own failure: a caution on every answer is a
        // caution nobody reads by the third one.
        $html = (new WidgetRenderer())->answer(new AnswerResult(
            mode: AnswerMode::Grounded,
            text: 'An answer. [1]',
            sources: [$this->chunk(date('Y-m-d'), 365)],
            citations: [1 => 1],
        ));

        self::assertStringNotContainsString('overdue for review', $html);
    }

    public function testStalenessIsMeasuredFromTheReviewInterval(): void
    {
        $reviewed = '2026-01-01';

        // One day before the interval elapses, and one day after.
        $justInside = new \DateTimeImmutable('2026-06-29');
        $justOutside = new \DateTimeImmutable('2026-07-02');

        $chunk = $this->chunk($reviewed, 180);

        self::assertFalse($chunk->isStale($justInside));
        self::assertTrue($chunk->isStale($justOutside));
    }

    public function testOneStaleSourceMakesTheWholeAnswerCautioned(): void
    {
        // An answer citing three fresh passages and one overdue one is an answer
        // that may be wrong. The caution is per answer, not per source.
        $retrieval = RetrievalResult::confident([
            $this->chunk(date('Y-m-d'), 365),
            $this->chunk('2019-01-01', 365),
        ]);

        self::assertTrue($retrieval->hasStaleSource());
    }

    public function testThePipelineFlagsStalenessOntoTheAnswer(): void
    {
        $result = PipelineBuilder::make()
            ->withChunk('Fees schedule.', authoritative: true, reviewedAt: '2019-01-01', reviewIntervalDays: 365)
            ->build()
            ->answer('What are the fees?');

        self::assertTrue($result->staleSource);
    }

    // ------------------------------------------------------------- fixtures

    private function insertDocument(?string $reviewedAt, int $reviewIntervalDays, ?int $officeId = null): int
    {
        $suffix = bin2hex(random_bytes(8));

        // When the test means "no review date", the column is OMITTED entirely
        // rather than bound as NULL. That is the real-world shape of the bug:
        // ingestion code that simply forgets the field. It is also why a
        // COALESCE(..., DEFAULT(reviewed_at)) fixture does not work here -
        // reviewed_at deliberately has no default, because a defaulted review
        // date is a fabricated review date.
        $columns = ['source_type', 'source_ref', 'source_ref_hash', 'title', 'owning_office_id', 'review_interval_days'];
        $values = ["'web_page'", ':ref', ':hash', "'INV-11 fixture'", ':office_id', ':interval'];

        $parameters = [
            'ref' => 'test://inv11/' . $suffix,
            'hash' => hash('sha256', $suffix),
            'office_id' => $officeId ?? $this->firstOfficeId(),
            'interval' => $reviewIntervalDays,
        ];

        if ($reviewedAt !== null) {
            $columns[] = 'reviewed_at';
            $values[] = ':reviewed_at';
            $parameters['reviewed_at'] = $reviewedAt;
        }

        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO documents (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', $values)
        ));

        $statement->execute($parameters);

        return (int) $this->pdo->lastInsertId();
    }

    private function firstOfficeId(): int
    {
        $statement = $this->pdo->query('SELECT MIN(id) FROM offices');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    private function chunk(string $reviewedAt, int $reviewIntervalDays): ScoredChunk
    {
        return new ScoredChunk(
            chunkId: 1,
            documentId: 1,
            body: 'Body.',
            score: 0.9,
            sourceRef: 'https://gu.ac.ug/example',
            title: 'Example page',
            reviewedAt: $reviewedAt,
            reviewIntervalDays: $reviewIntervalDays,
        );
    }
}
