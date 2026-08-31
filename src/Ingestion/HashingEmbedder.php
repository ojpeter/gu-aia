<?php

declare(strict_types=1);

namespace GuAia\Ingestion;

/**
 * A hashed character-n-gram embedder. Local, deterministic, no API, no spend.
 *
 * WHAT THIS IS HONEST ABOUT
 *
 * This is a LEXICAL embedder, not a semantic one. It hashes overlapping
 * character n-grams into a fixed-width vector, which captures spelling overlap,
 * morphology and typo tolerance — "programme"/"program", "admission"/"admissions"
 * — and captures nothing at all about meaning. It will not connect "fees" to
 * "how much do I pay", and it should not be described as if it might.
 *
 * It is here because Section 18 open question 1 is open, and something real has
 * to exist in the meantime. The alternatives were both worse: a stub returning
 * zeros would make the rerank silently inert while looking implemented, and
 * wiring a hosted embedding API would take a spend-and-data-residency decision
 * that belongs to the Chief, ICT Services, not to a commit.
 *
 * WHY IT IS NOT USELESS
 *
 * Section 3's design already leans on lexical matching: `FULLTEXT` does candidate
 * generation and the vector only reranks ~200 candidates. On the query shape that
 * Section 3 says dominates here — someone typing a programme name or a course
 * code — lexical similarity is much of what a reranker needs. This is a weak
 * reranker, not a broken one.
 *
 * WHAT MUST HAPPEN BEFORE LAUNCH
 *
 * Run the evaluation harness with this embedder and with whichever semantic
 * embedder is chosen, and compare. If the difference is small the decision is
 * cheap; if it is large, that is the argument for the spend. Either way the
 * number comes from the harness, not from an assumption — including the
 * assumption in this comment. `modelName()` is versioned so a swap forces a
 * re-index rather than mixing incompatible vectors.
 */
final class HashingEmbedder implements Embedder
{
    private const NGRAM = 4;

    public function __construct(private readonly int $dimensions = 256)
    {
    }

    /** @return list<float> */
    public function embed(string $text): array
    {
        /** @var list<float> $vector */
        $vector = array_fill(0, $this->dimensions, 0.0);

        $normalised = mb_strtolower(trim($text), 'UTF-8');
        $normalised = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalised);
        $normalised = trim((string) preg_replace('/\s+/u', ' ', $normalised));

        if ($normalised === '') {
            return $vector;
        }

        // Word-boundary markers so a short word is not swallowed by its
        // neighbours: " fees " and " feesible " should not look identical.
        $padded = ' ' . $normalised . ' ';
        $length = mb_strlen($padded, 'UTF-8');

        for ($i = 0; $i + self::NGRAM <= $length; $i++) {
            $gram = mb_substr($padded, $i, self::NGRAM, 'UTF-8');

            // crc32 is a hash, not a checksum, for this purpose: cheap, stable
            // across runs and platforms, and the distribution is good enough for
            // a 256-slot bucket.
            $bucket = crc32($gram) % $this->dimensions;
            // Sign from a second hash, so unrelated n-grams landing in the same
            // bucket tend to cancel rather than always reinforce.
            $sign = (crc32('s' . $gram) % 2) === 0 ? 1.0 : -1.0;

            $vector[$bucket] += $sign;
        }

        return $this->normalise($vector);
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function modelName(): string
    {
        // Versioned deliberately: changing NGRAM or the dimension count changes
        // every vector, and a re-index must be forced rather than hoped for.
        return sprintf('hashing-ngram%d-d%d-v1', self::NGRAM, $this->dimensions);
    }

    /**
     * Unit-normalise so cosine similarity is a plain dot product, which is what
     * the reranker relies on for its inner loop.
     *
     * Takes array<int, float> rather than list<float> because writing to a
     * bucket by index loses list-ness as far as static analysis is concerned,
     * even though every key is present. array_values on the way out restores the
     * guarantee the caller's return type promises.
     *
     * @param array<int, float> $vector
     *
     * @return list<float>
     */
    private function normalise(array $vector): array
    {
        $magnitude = 0.0;
        foreach ($vector as $value) {
            $magnitude += $value * $value;
        }

        if ($magnitude <= 0.0) {
            return array_values($vector);
        }

        $magnitude = sqrt($magnitude);

        return array_values(array_map(static fn (float $v): float => $v / $magnitude, $vector));
    }
}
