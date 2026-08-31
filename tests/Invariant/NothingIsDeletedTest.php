<?php

declare(strict_types=1);

namespace GuAia\Tests\Invariant;

use GuAia\Answering\AnswerMode;
use GuAia\Answering\AnswerResult;
use GuAia\Logging\IdentifierHasher;
use GuAia\Logging\InteractionLogger;
use GuAia\Logging\RetentionSweeper;
use GuAia\Tests\Support\Database;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * INV-12, retention half — "redaction only".
 *
 * `NoHardDeleteTest` covers the static half: no DELETE in application code, no
 * DELETE granted to any account. This covers the behavioural half, which is the
 * one that actually gets exercised in production every night: when a record's
 * retention expires, the ROW SURVIVES and its identifying content is blanked.
 *
 * Why the distinction matters. Deleting the row would satisfy a naive reading of
 * data protection and destroy the thing this system needs most when something
 * goes wrong: the ability to say what the assistant told somebody on a given
 * day. Section 13 keeps the record and removes the person from it. That is a
 * deliberate trade, and docs/data-protection.md Section 4 requires it to be
 * disclosed in the privacy notice rather than assumed.
 */
#[Group('invariant')]
final class NothingIsDeletedTest extends TestCase
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

    public function testAnUnsetRetentionPeriodRefusesToSweep(): void
    {
        // Guessing would be worse than failing: too short destroys the record a
        // complaint needs, too long is a retention nobody authorised.
        $sweeper = new RetentionSweeper($this->pdo, retentionDays: null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not set/');
        $sweeper->sweep();
    }

    public function testAZeroRetentionPeriodIsAlsoRefused(): void
    {
        $this->expectException(RuntimeException::class);
        (new RetentionSweeper($this->pdo, retentionDays: 0))->sweep();
    }

    public function testExpiredInteractionsAreRedactedNotRemoved(): void
    {
        $id = $this->logAnOldInteraction('What are the fees for Medicine?');

        $before = $this->countInteractions();
        (new RetentionSweeper($this->pdo, retentionDays: 30))->sweep();

        self::assertSame(
            $before,
            $this->countInteractions(),
            'INV-12 breach: the retention sweep removed a row instead of redacting it.'
        );

        $row = $this->fetchInteraction($id);
        self::assertSame('[redacted]', $row['query_text']);
        self::assertNull($row['answer']);
        self::assertNotNull($row['redacted_at']);
    }

    public function testWhatSurvivesRedactionIsWhatAComplaintNeeds(): void
    {
        // The point of keeping the row: "on this date the assistant refused this
        // category of question, in this mode, in this many milliseconds" is
        // answerable a year later without keeping the words somebody typed.
        $id = $this->logAnOldInteraction('Will I be admitted?');

        (new RetentionSweeper($this->pdo, retentionDays: 30))->sweep();

        $row = $this->fetchInteraction($id);
        self::assertNotEmpty($row['correlation_id']);
        self::assertSame('refuse', $row['mode']);
        self::assertSame('individual_outcome', $row['refusal_reason']);
        self::assertNotNull($row['created_at']);
        self::assertNotNull($row['latency_ms']);
    }

    public function testRecentInteractionsAreUntouched(): void
    {
        $id = $this->logAnOldInteraction('A recent question?', ageDays: 0);

        (new RetentionSweeper($this->pdo, retentionDays: 30))->sweep();

        self::assertSame('A recent question?', $this->fetchInteraction($id)['query_text']);
        self::assertNull($this->fetchInteraction($id)['redacted_at']);
    }

    public function testASecondSweepDoesNotReRedact(): void
    {
        $this->logAnOldInteraction('What are the fees?');

        $sweeper = new RetentionSweeper($this->pdo, retentionDays: 30);
        $first = $sweeper->sweep();
        $second = $sweeper->sweep();

        self::assertGreaterThan(0, $first['interactions']);
        self::assertSame(0, $second['interactions'], 'redacted_at must make the sweep idempotent.');
    }

    public function testTechnicalIdentifiersAreClearedOnTheirOwnShorterClock(): void
    {
        // DF-2: the hashed IP is kept only as long as abuse investigation needs
        // it, which is far less than the interaction record.
        $id = $this->logAnOldInteraction('What are the fees?', ageDays: 10);

        self::assertNotNull($this->fetchInteraction($id)['ip_hash']);

        (new RetentionSweeper($this->pdo, retentionDays: 3650, technicalRetentionDays: 7))->sweep();

        $row = $this->fetchInteraction($id);
        self::assertNull($row['ip_hash'], 'DF-2: technical identifiers expire before the interaction does.');
        self::assertSame(
            'What are the fees?',
            $row['query_text'],
            'The interaction itself is not yet due for redaction.'
        );
    }

    // ------------------------------------------------------------- fixtures

    private function logAnOldInteraction(string $question, int $ageDays = 60): int
    {
        $logger = new InteractionLogger($this->pdo, new IdentifierHasher('test-key'));

        $result = new AnswerResult(
            mode: AnswerMode::Refuse,
            text: 'I could not find this in the published information.',
            categoryKey: 'individual_outcome',
            refusalReason: 'individual_outcome',
        );

        $id = $logger->log(
            correlationId: bin2hex(random_bytes(18)),
            question: $question,
            result: $result,
            latencyMs: 11,
            context: ['ip' => '203.0.113.7'],
        );

        // Backdate, so the sweep has something expired to find.
        $statement = $this->pdo->prepare(
            'UPDATE interactions SET created_at = DATE_SUB(NOW(), INTERVAL :days DAY) WHERE id = :id'
        );
        $statement->execute(['days' => $ageDays, 'id' => $id]);

        $unanswered = $this->pdo->prepare(
            'UPDATE unanswered_questions SET created_at = DATE_SUB(NOW(), INTERVAL :days DAY)
              WHERE interaction_id = :id'
        );
        $unanswered->execute(['days' => $ageDays, 'id' => $id]);

        return $id;
    }

    private function countInteractions(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM interactions');

        return $statement === false ? -1 : (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function fetchInteraction(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM interactions WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();

        self::assertIsArray($row);

        return $row;
    }
}
