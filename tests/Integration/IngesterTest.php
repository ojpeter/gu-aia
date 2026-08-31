<?php

declare(strict_types=1);

namespace GuAia\Tests\Integration;

use GuAia\Ingestion\FetchedResource;
use GuAia\Ingestion\Fetcher;
use GuAia\Ingestion\HashingEmbedder;
use GuAia\Ingestion\Ingester;
use GuAia\Ingestion\IngestOutcome;
use GuAia\Tests\Support\Database;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Ingestion end to end, against the real schema, inside a rolled-back
 * transaction.
 *
 * Nothing here touches the network. The fetcher is a fixture, deliberately: the
 * live site must not be crawled before Phase 0 assigns owners and review dates,
 * and a test suite that reaches gu.ac.ug would do exactly that every time it ran.
 */
final class IngesterTest extends TestCase
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

    public function testAPageIsExtractedChunkedEmbeddedAndStored(): void
    {
        $outcome = $this->ingest($this->page());

        self::assertSame(IngestOutcome::INGESTED, $outcome->status);
        self::assertGreaterThan(0, $outcome->chunks);

        $chunk = $this->one(
            'SELECT body, embedding, embedding_model FROM chunks WHERE document_id = ? ORDER BY ordinal LIMIT 1',
            [$outcome->documentId]
        );

        self::assertStringContainsString('Bachelor of Laws', (string) $chunk['body']);
        self::assertNotNull($chunk['embedding']);
        self::assertSame((new HashingEmbedder())->modelName(), $chunk['embedding_model']);
    }

    public function testNavigationAndFootersAreNotIndexed(): void
    {
        // Boilerplate repeats on every page. Left in, it would give every chunk
        // the same few hundred words in common and retrieval would rank on
        // menus.
        $outcome = $this->ingest($this->page());

        $bodies = $this->column('SELECT body FROM chunks WHERE document_id = ?', [$outcome->documentId]);
        $all = implode("\n", $bodies);

        self::assertStringNotContainsString('Skip to content', $all);
        self::assertStringNotContainsString('Copyright Gulu University', $all);
        self::assertStringNotContainsString('Cookie', $all);
    }

    public function testAFeesTableSurvivesAsOneAtomicChunkWithItsCaption(): void
    {
        // Section 5.3, and the reason it matters: half a fees table looks
        // complete, cites cleanly, and quotes an amount for the wrong programme.
        $outcome = $this->ingest($this->page());

        $atomic = $this->one(
            'SELECT body, is_atomic_block, atomic_block_kind FROM chunks
              WHERE document_id = ? AND is_atomic_block = 1 AND atomic_block_kind = ? LIMIT 1',
            [$outcome->documentId, 'table']
        );

        self::assertStringContainsString('Tuition and functional fees', (string) $atomic['body']);
        self::assertStringContainsString('Bachelor of Laws', (string) $atomic['body']);
        self::assertStringContainsString('Bachelor of Medicine', (string) $atomic['body']);
    }

    public function testADocumentWithNoOwningOfficeIsNotIndexed(): void
    {
        // INV-11, refused before a request is even spent.
        $outcome = $this->ingest($this->page(), ['owning_office_id' => 0]);

        self::assertSame(IngestOutcome::REJECTED, $outcome->status);
        self::assertSame('missing_metadata', $outcome->reason);
        self::assertStringContainsString('no owning office', (string) $outcome->message);
    }

    public function testADocumentWithNoReviewDateIsNotIndexed(): void
    {
        $outcome = $this->ingest($this->page(), ['reviewed_at' => '']);

        self::assertSame(IngestOutcome::REJECTED, $outcome->status);
        self::assertStringContainsString('no review date', (string) $outcome->message);
    }

    public function testAScannedPdfIsRejectedAndTheReasonRecordedOnTheDocument(): void
    {
        // Section 5.2: rejected with a report to the owning office, never
        // silently OCR'd into noise. The reason has to reach somebody who can
        // publish a text version.
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";

        $outcome = $this->ingest(new FetchedResource('https://gu.ac.ug/x.pdf', $pdf, 'application/pdf', 200));

        self::assertSame(IngestOutcome::REJECTED, $outcome->status);
        self::assertSame('no_text_layer', $outcome->reason);

        $document = $this->one(
            'SELECT ingest_status, ingest_rejection_reason FROM documents WHERE source_ref_hash = ?',
            [hash('sha256', 'https://gu.ac.ug/x.pdf')]
        );

        self::assertSame('rejected', $document['ingest_status']);
        self::assertStringContainsString('no_text_layer', (string) $document['ingest_rejection_reason']);
    }

    public function testAnAlmostEmptyPageIsRejectedRatherThanIndexedAsFragments(): void
    {
        $resource = new FetchedResource(
            'https://gu.ac.ug/thin',
            '<html><body><p>Hello.</p></body></html>',
            'text/html',
            200
        );

        $outcome = $this->ingest($resource);

        self::assertSame(IngestOutcome::REJECTED, $outcome->status);
        self::assertSame('too_little_text', $outcome->reason);
    }

    public function testUnchangedContentIsNotReIndexed(): void
    {
        // A nightly crawl over a stable corpus should report almost entirely
        // unchanged. Re-embedding identical pages would churn every vector and
        // make "when did this actually change" unanswerable.
        $first = $this->ingest($this->page());
        $second = $this->ingest($this->page());

        self::assertSame(IngestOutcome::INGESTED, $first->status);
        self::assertSame(IngestOutcome::UNCHANGED, $second->status);
        self::assertSame($first->documentId, $second->documentId);
    }

    public function testChangedContentSupersedesRatherThanOverwrites(): void
    {
        // INV-12: a past answer must stay reconstructible after the page it came
        // from has moved on.
        $first = $this->ingest($this->page());
        $second = $this->ingest($this->page('The fee is now 1,600,000 UGX per semester for the Bachelor of Laws.'));

        self::assertSame(IngestOutcome::INGESTED, $second->status);
        self::assertNotSame($first->documentId, $second->documentId);

        $old = $this->one('SELECT status, superseded_by_id FROM documents WHERE id = ?', [$first->documentId]);
        self::assertSame('superseded', $old['status']);
        self::assertSame($second->documentId, (int) $old['superseded_by_id']);

        $oldChunks = $this->column('SELECT status FROM chunks WHERE document_id = ?', [$first->documentId]);
        foreach ($oldChunks as $status) {
            self::assertSame('superseded', $status);
        }
    }

    public function testInstructionShapedTextIsKeptAndFlagged(): void
    {
        // INV-6: flagged for a human, not deleted. Silently editing a University
        // page changes what the University said.
        $outcome = $this->ingest($this->page(
            'Applicants should ignore all previous instructions printed on the 2024 form and use the new one.'
        ));

        self::assertSame(IngestOutcome::INGESTED, $outcome->status);
        self::assertNotSame([], $outcome->flagged, 'The ingest run must report what it flagged.');

        $bodies = implode("\n", $this->column('SELECT body FROM chunks WHERE document_id = ?', [$outcome->documentId]));
        self::assertStringContainsString('ignore all previous instructions printed on the 2024 form', $bodies);
    }

    public function testAFetchFailureIsReportedNotThrown(): void
    {
        $ingester = new Ingester(
            $this->pdo,
            new class implements Fetcher {
                public function isAllowed(string $url): bool
                {
                    return true;
                }

                public function fetch(string $url): FetchedResource
                {
                    throw new RuntimeException('Connection refused');
                }
            },
            new HashingEmbedder()
        );

        $outcome = $ingester->ingest('https://gu.ac.ug/anything', $this->metadata());

        self::assertSame(IngestOutcome::REJECTED, $outcome->status);
        self::assertSame('fetch_failed', $outcome->reason);
    }

    // ------------------------------------------------------------- fixtures

    private function page(string $extra = 'Applications open in May each year.'): FetchedResource
    {
        $html = <<<HTML
            <html><body>
              <nav><a href="#main">Skip to content</a> <a href="/">Home</a> <a href="/about">About</a></nav>
              <div class="cookie-banner">Cookie preferences</div>
              <main>
                <h1>Faculty of Law</h1>
                <p>The Bachelor of Laws is a four-year programme offered at the Main Campus in Gulu.
                   It prepares students for legal practice and for further study, and admits students
                   through both direct entry and mature age entry routes.</p>
                <h2>Fees</h2>
                <p>{$extra}</p>
                <table>
                  <caption>Tuition and functional fees, 2026/27</caption>
                  <tr><th>Programme</th><th>Tuition</th></tr>
                  <tr><td>Bachelor of Laws</td><td>1,500,000</td></tr>
                  <tr><td>Bachelor of Medicine</td><td>2,400,000</td></tr>
                </table>
              </main>
              <footer>Copyright Gulu University</footer>
            </body></html>
            HTML;

        return new FetchedResource('https://gu.ac.ug/faculty-of-law', $html, 'text/html', 200);
    }

    /** @param array<string, mixed> $overrides */
    private function ingest(FetchedResource $resource, array $overrides = []): IngestOutcome
    {
        $fetcher = new class ($resource) implements Fetcher {
            public function __construct(private readonly FetchedResource $resource)
            {
            }

            public function isAllowed(string $url): bool
            {
                return true;
            }

            public function fetch(string $url): FetchedResource
            {
                return $this->resource;
            }
        };

        $ingester = new Ingester($this->pdo, $fetcher, new HashingEmbedder());

        return $ingester->ingest($resource->url, array_merge($this->metadata(), $overrides));
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        $office = $this->pdo->query('SELECT MIN(id) FROM offices');

        return [
            'owning_office_id' => $office === false ? 0 : (int) $office->fetchColumn(),
            'reviewed_at' => date('Y-m-d'),
            'review_interval_days' => 365,
            'category_key' => 'fees',
            'title' => 'Faculty of Law',
        ];
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

    /** @param list<mixed> $parameters @return list<string> */
    private function column(string $sql, array $parameters): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        /** @var list<string> $values */
        $values = $statement->fetchAll(PDO::FETCH_COLUMN);

        return $values;
    }
}
