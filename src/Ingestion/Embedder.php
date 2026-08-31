<?php

declare(strict_types=1);

namespace GuAia\Ingestion;

/**
 * Produces the vector stored on each chunk row. requirements.md Section 3.
 *
 * "Embeddings are generated at ingestion, stored as a compact binary blob on the
 *  chunk row. No separate vector database."
 *
 * Behind an interface for the same reason as Generator: whether embeddings come
 * from a hosted API or a local model is Section 18 open question 1, and that
 * decision must stay reversible. `modelName()` is recorded on every chunk so a
 * model change is detectable rather than silently mixing incompatible vectors.
 */
interface Embedder
{
    /** @return list<float> unit-normalised, so cosine similarity is a dot product */
    public function embed(string $text): array;

    public function dimensions(): int;

    /** Recorded on the chunk row; a change here means a full re-index. */
    public function modelName(): string;
}
