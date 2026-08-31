<?php

declare(strict_types=1);

namespace GuAia\Retrieval;

/**
 * In-process vector rerank. requirements.md Sections 3 and 6.
 *
 *   scored := rerank(candidates, embed(query))   # cosine, in process
 *
 * Section 3's argument for doing this in PHP rather than in a vector database:
 * "reranking 200 vectors in PHP is a few million multiply-adds and completes well
 * inside the latency budget." At 256 dimensions that is ~51,000 multiply-adds for
 * the whole candidate set, which is genuinely nothing.
 *
 * Vectors arrive unit-normalised from the embedder, so cosine similarity is a
 * plain dot product and no per-comparison magnitude is computed.
 *
 * HYBRID, NOT PURE VECTOR
 *
 * The final score blends the vector similarity with the FULLTEXT relevance that
 * produced the candidate, and adds a boost for an exact programme or course code.
 * Section 6 is explicit that a typed code should win: "a user typing a code knows
 * what they want." Pure vector ordering would let a chunk that is merely
 * *about* a programme outrank the chunk that *is* that programme's entry.
 *
 * The weights are configuration, not constants, because Section 6 says the
 * threshold and its neighbours are tuned against the evaluation set. They are
 * starting points, and they are not evidence of anything until the harness has
 * measured them.
 */
final class Reranker
{
    /**
     * The weights sum to 1.0, which bounds the base score at 1.0 and is what
     * makes the code boost below a strict tier rather than a nudge.
     *
     * @param float $exactCodeBoost ADDITIVE, and >= 1.0 on purpose. It began as a
     *   multiplier and that was wrong: multiplying a near-zero base leaves a
     *   near-zero score, so a chunk that exactly matched a typed course code
     *   could still be outranked by prose that merely mentioned the programme.
     *   Section 6 says "a user typing a code knows what they want", and a boost
     *   that cannot overcome a weak base does not deliver that. Adding at least
     *   the maximum possible base score puts every exact-code match above every
     *   non-code match, ordered among themselves by their own relevance.
     */
    public function __construct(
        private readonly float $vectorWeight = 0.6,
        private readonly float $lexicalWeight = 0.4,
        private readonly float $exactCodeBoost = 1.0,
    ) {
    }

    /**
     * @param list<ScoredChunk> $candidates carrying lexicalScore and a vector
     * @param list<float>       $queryVector
     * @param array<int, list<float>> $vectors chunkId => embedding
     *
     * @return list<ScoredChunk> ordered by descending score
     */
    public function rerank(array $candidates, array $queryVector, array $vectors): array
    {
        if ($candidates === []) {
            return [];
        }

        // Lexical relevance from FULLTEXT is unbounded and corpus-dependent, so
        // it is scaled against the best candidate in this result set rather than
        // against an absolute that does not exist.
        $maxLexical = 0.0;
        foreach ($candidates as $candidate) {
            $maxLexical = max($maxLexical, $candidate->lexicalScore);
        }

        $reranked = [];
        foreach ($candidates as $candidate) {
            $vector = $vectors[$candidate->chunkId] ?? [];
            $similarity = $this->cosine($queryVector, $vector);

            $lexical = $maxLexical > 0.0 ? $candidate->lexicalScore / $maxLexical : 0.0;

            $score = ($this->vectorWeight * $similarity) + ($this->lexicalWeight * $lexical);

            if ($candidate->exactCodeMatch) {
                $score += $this->exactCodeBoost;
            }

            $reranked[] = new ScoredChunk(
                chunkId: $candidate->chunkId,
                documentId: $candidate->documentId,
                body: $candidate->body,
                score: $score,
                sourceRef: $candidate->sourceRef,
                title: $candidate->title,
                reviewedAt: $candidate->reviewedAt,
                reviewIntervalDays: $candidate->reviewIntervalDays,
                isAuthoritative: $candidate->isAuthoritative,
                categoryKey: $candidate->categoryKey,
                headingPath: $candidate->headingPath,
                pageNumber: $candidate->pageNumber,
                lexicalScore: $candidate->lexicalScore,
                exactCodeMatch: $candidate->exactCodeMatch,
            );
        }

        usort(
            $reranked,
            static fn (ScoredChunk $a, ScoredChunk $b): int => $b->score <=> $a->score
        );

        return $reranked;
    }

    /**
     * Dot product of two unit vectors. Returns 0.0 for a missing or
     * mismatched-length vector rather than guessing — a chunk embedded by a
     * different model must not be silently compared against this one.
     *
     * @param list<float> $a
     * @param list<float> $b
     */
    public function cosine(array $a, array $b): float
    {
        $length = count($a);
        if ($length === 0 || $length !== count($b)) {
            return 0.0;
        }

        $dot = 0.0;
        for ($i = 0; $i < $length; $i++) {
            $dot += $a[$i] * $b[$i];
        }

        return $dot;
    }
}
