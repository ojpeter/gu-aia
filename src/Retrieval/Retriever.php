<?php

declare(strict_types=1);

namespace GuAia\Retrieval;

use GuAia\Ingestion\Embedder;

/**
 * The retrieval pipeline. requirements.md Section 6.
 *
 *     retrieve(query):
 *         normalised  := normalise(query)
 *         candidates  := fulltextSearch(normalised, limit: 200)
 *         candidates  += exactMatches(programmeCodes(normalised))
 *         scored      := rerank(candidates, embed(query))
 *         top         := scored.take(6)
 *
 *         if top.isEmpty() or top[0].score < THRESHOLD:
 *             return NoConfidentContext          # -> refusal + handoff, INV-1
 *         return top
 *
 * "The threshold is configuration, tuned against the evaluation set, and IS
 *  EXPECTED TO PRODUCE REFUSALS. A configuration that never refuses is
 *  misconfigured."
 *
 * FAILING CLOSED ON AN UNSET THRESHOLD
 *
 * config/retrieval.php ships with `score_threshold => null`, because the value
 * has to come from the evaluation set and there is no corpus to tune against
 * yet. A null threshold therefore refuses everything, with the reason
 * `retrieval_threshold_not_configured`.
 *
 * That is deliberate and it is the only defensible reading. An untuned threshold
 * means nobody knows at what score an answer stops being supported — and a system
 * that does not know when to refuse must refuse. Treating null as zero would make
 * the system answer from its single best candidate no matter how poor, which is
 * precisely the "confidently wrong" failure Section 0 is written against.
 */
final class Retriever implements ContextRetriever
{
    public function __construct(
        private readonly CandidateGenerator $candidates,
        private readonly Reranker $reranker,
        private readonly Embedder $embedder,
        private readonly QueryNormaliser $normaliser,
        private readonly ?float $scoreThreshold = null,
        private readonly int $topK = 6,
    ) {
    }

    public function retrieve(string $query, ?string $categoryKey = null): RetrievalResult
    {
        if ($this->scoreThreshold === null) {
            // See the class docblock. Not an error — a refusal, with a reason
            // that names the missing configuration rather than blaming the user.
            return RetrievalResult::noConfidentContext('retrieval_threshold_not_configured');
        }

        $expression = $this->normaliser->forFullText($query);
        $codes = $this->normaliser->codes($query);

        if ($expression === '' && $codes === []) {
            return RetrievalResult::noConfidentContext('query_had_no_searchable_terms');
        }

        $pool = $this->candidates->candidates($expression, $categoryKey);

        // Exact code matches are added, not substituted: a question can name a
        // code and still be best answered by prose about it.
        foreach ($this->candidates->byCode($codes, $categoryKey) as $byCode) {
            $pool[] = $byCode;
        }

        $pool = $this->deduplicate($pool);

        if ($pool === []) {
            return RetrievalResult::noConfidentContext('no_candidates');
        }

        $vectors = $this->candidates->embeddings(
            array_map(static fn (ScoredChunk $c): int => $c->chunkId, $pool),
            $this->embedder->modelName()
        );

        $ranked = $this->reranker->rerank($pool, $this->embedder->embed($query), $vectors);
        $top = array_slice($ranked, 0, $this->topK);

        if ($top === [] || $top[0]->score < $this->scoreThreshold) {
            return RetrievalResult::noConfidentContext('below_threshold');
        }

        return RetrievalResult::confident($top);
    }

    /**
     * A chunk can arrive from both the full-text arm and the code arm. Keep the
     * one carrying the exact-code flag, since that is the signal the reranker
     * boosts on.
     *
     * @param list<ScoredChunk> $pool
     *
     * @return list<ScoredChunk>
     */
    private function deduplicate(array $pool): array
    {
        /** @var array<int, ScoredChunk> $byId */
        $byId = [];

        foreach ($pool as $chunk) {
            $existing = $byId[$chunk->chunkId] ?? null;

            if ($existing === null) {
                $byId[$chunk->chunkId] = $chunk;
                continue;
            }

            // Merge: keep the better lexical score and the exact-code flag.
            $byId[$chunk->chunkId] = new ScoredChunk(
                chunkId: $chunk->chunkId,
                documentId: $chunk->documentId,
                body: $chunk->body,
                score: 0.0,
                sourceRef: $chunk->sourceRef,
                title: $chunk->title,
                reviewedAt: $chunk->reviewedAt,
                reviewIntervalDays: $chunk->reviewIntervalDays,
                isAuthoritative: $chunk->isAuthoritative,
                categoryKey: $chunk->categoryKey,
                headingPath: $chunk->headingPath,
                pageNumber: $chunk->pageNumber,
                lexicalScore: max($existing->lexicalScore, $chunk->lexicalScore),
                exactCodeMatch: $existing->exactCodeMatch || $chunk->exactCodeMatch,
            );
        }

        return array_values($byId);
    }
}
