<?php

declare(strict_types=1);

namespace GuAia\Tests\Unit;

use GuAia\Ingestion\HashingEmbedder;
use GuAia\Retrieval\QueryNormaliser;
use GuAia\Retrieval\Reranker;
use GuAia\Retrieval\ScoredChunk;
use GuAia\Retrieval\VectorCodec;
use PHPUnit\Framework\TestCase;

/**
 * The parts of retrieval that do not need a database.
 *
 * The end-to-end pipeline needs a corpus, and Phase 0 gates indexing, so
 * Retriever itself is exercised only for its fail-closed behaviour here.
 */
final class RetrievalTest extends TestCase
{
    // ------------------------------------------------------------ vector codec

    public function testVectorSurvivesAnEncodeDecodeRound(): void
    {
        $vector = (new HashingEmbedder(64))->embed('Bachelor of Science in Computer Science');

        $decoded = VectorCodec::decode(VectorCodec::encode($vector));

        self::assertCount(count($vector), $decoded);
        foreach ($vector as $i => $value) {
            // float32 storage, so exact equality is not available; 1e-6 is far
            // tighter than any similarity decision depends on.
            self::assertEqualsWithDelta($value, $decoded[$i], 1e-6);
        }
    }

    public function testEmptyBlobDecodesToAnEmptyVectorRatherThanFailing(): void
    {
        self::assertSame([], VectorCodec::decode(''));
    }

    // ---------------------------------------------------------------- embedder

    public function testEmbeddingIsDeterministic(): void
    {
        $embedder = new HashingEmbedder();

        self::assertSame(
            $embedder->embed('entry requirements for medicine'),
            $embedder->embed('entry requirements for medicine'),
            'A non-deterministic embedder would make every re-index change retrieval.'
        );
    }

    public function testEmbeddingIsUnitNormalised(): void
    {
        $vector = (new HashingEmbedder())->embed('tuition fees for the Bachelor of Laws');

        $magnitude = 0.0;
        foreach ($vector as $value) {
            $magnitude += $value * $value;
        }

        self::assertEqualsWithDelta(1.0, sqrt($magnitude), 1e-6);
    }

    public function testEmptyTextEmbedsToAZeroVectorWithoutDividingByZero(): void
    {
        $vector = (new HashingEmbedder())->embed('   ');

        self::assertCount(256, $vector);
        self::assertSame(0.0, array_sum(array_map('abs', $vector)));
    }

    public function testLexicallySimilarTextScoresHigherThanUnrelatedText(): void
    {
        // This is the honest claim for a hashed n-gram embedder: it captures
        // spelling overlap and morphology, not meaning. The test asserts exactly
        // that and nothing more.
        $embedder = new HashingEmbedder();
        $reranker = new Reranker();

        $query = $embedder->embed('admission requirements');
        $near = $embedder->embed('admissions requirement');
        $far = $embedder->embed('bursary payment schedule');

        self::assertGreaterThan(
            $reranker->cosine($query, $far),
            $reranker->cosine($query, $near)
        );
    }

    public function testModelNameIsVersionedSoASwapForcesAReindex(): void
    {
        self::assertNotSame(
            (new HashingEmbedder(128))->modelName(),
            (new HashingEmbedder(256))->modelName(),
            'Two incompatible vector spaces must not share a model name.'
        );
    }

    // ---------------------------------------------------------------- reranker

    public function testMismatchedVectorLengthsScoreZeroRatherThanGuessing(): void
    {
        $reranker = new Reranker();

        self::assertSame(0.0, $reranker->cosine([1.0, 0.0], [1.0, 0.0, 0.0]));
        self::assertSame(0.0, $reranker->cosine([], []));
    }

    public function testExactCodeMatchOutranksAMerelyRelevantChunk(): void
    {
        // Section 6: "a user typing a code knows what they want."
        $reranker = new Reranker();

        $prose = $this->chunk(1, lexical: 10.0, exactCode: false);
        $coded = $this->chunk(2, lexical: 1.0, exactCode: true);

        $ranked = $reranker->rerank([$prose, $coded], [1.0, 0.0], [1 => [1.0, 0.0], 2 => [0.0, 1.0]]);

        self::assertSame(2, $ranked[0]->chunkId);
    }

    public function testRerankingOrdersByDescendingScore(): void
    {
        $reranker = new Reranker();

        $ranked = $reranker->rerank(
            [$this->chunk(1, lexical: 1.0), $this->chunk(2, lexical: 9.0)],
            [1.0, 0.0],
            [1 => [0.0, 1.0], 2 => [1.0, 0.0]]
        );

        self::assertSame(2, $ranked[0]->chunkId);
        self::assertGreaterThanOrEqual($ranked[1]->score, $ranked[0]->score);
    }

    public function testAChunkWithNoStoredVectorStillRanksOnLexicalScore(): void
    {
        // A chunk embedded by a previous model is excluded from the vector map
        // rather than compared across incompatible spaces. It must not vanish.
        $ranked = (new Reranker())->rerank([$this->chunk(1, lexical: 5.0)], [1.0, 0.0], []);

        self::assertCount(1, $ranked);
        self::assertGreaterThan(0.0, $ranked[0]->score);
    }

    // --------------------------------------------------------------- normaliser

    public function testCodesAreExtractedAndNormalised(): void
    {
        $normaliser = new QueryNormaliser();

        self::assertSame(['CSC101'], $normaliser->codes('What is CSC 101 about?'));
        self::assertSame(['BIO2201'], $normaliser->codes('Tell me about BIO2201'));
        self::assertSame([], $normaliser->codes('Tell me about computer science'));
    }

    public function testShortTermsAreDroppedBecauseTheIndexIgnoresThem(): void
    {
        $terms = (new QueryNormaliser())->terms('is it a fee');

        self::assertNotContains('is', $terms);
        self::assertNotContains('it', $terms);
        self::assertContains('fee', $terms);
    }

    public function testAbbreviationsExpandButKeepTheOriginalTerm(): void
    {
        $normaliser = new QueryNormaliser(['ucer' => ['university council examination results']]);

        $terms = $normaliser->terms('ucer');

        self::assertContains('ucer', $terms, 'The typed form may be the one in the document.');
        self::assertContains('examination', $terms);
    }

    public function testAQueryOfOnlyOperatorsProducesNoExpression(): void
    {
        self::assertSame('', (new QueryNormaliser())->forFullText('+++ --- ***'));
    }

    private function chunk(int $id, float $lexical = 0.0, bool $exactCode = false): ScoredChunk
    {
        return new ScoredChunk(
            chunkId: $id,
            documentId: 1,
            body: 'body',
            score: 0.0,
            sourceRef: 'https://gu.ac.ug/example',
            title: 'Example',
            reviewedAt: '2026-01-01',
            reviewIntervalDays: 180,
            lexicalScore: $lexical,
            exactCodeMatch: $exactCode,
        );
    }
}
