<?php

declare(strict_types=1);

namespace GuAia\Retrieval;

use PDO;

/**
 * Candidate generation. requirements.md Section 6.
 *
 *   candidates := fulltextSearch(normalised, limit: 200)   # MySQL BOOLEAN MODE
 *   candidates += exactMatches(programmeCodes(normalised)) # course/programme codes
 *
 * Every value reaching SQL here is a bound parameter, including the search
 * expression — and the expression has already had its BOOLEAN MODE operators
 * removed by QueryNormaliser, because binding stops injection but does not stop
 * a stranger steering the search (see that class for the full argument).
 *
 * The one value that is interpolated is the LIMIT, which MySQL will not accept
 * as a bound parameter under native prepares. It is cast to int and clamped, so
 * nothing from a request can reach the string.
 *
 * CATEGORY FILTERING IS A SAFETY CONTROL, NOT AN OPTIMISATION. It is what stops
 * a fees question being answered from a news article (Section 6). Treat a
 * category leak as a safety defect, not a relevance bug.
 */
final class CandidateGenerator
{
    private const SELECT = <<<'SQL'
        SELECT c.id            AS chunk_id,
               c.document_id   AS document_id,
               c.body          AS body,
               c.heading_path  AS heading_path,
               c.page_number   AS page_number,
               c.reviewed_at   AS reviewed_at,
               c.is_authoritative AS is_authoritative,
               c.category_key  AS category_key,
               d.source_ref    AS source_ref,
               d.title         AS title,
               d.review_interval_days AS review_interval_days
        SQL;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $candidateLimit = 200,
    ) {
    }

    /**
     * @return list<ScoredChunk> unranked; lexicalScore carries FULLTEXT relevance
     */
    public function candidates(string $fullTextExpression, ?string $categoryKey = null): array
    {
        if (trim($fullTextExpression) === '') {
            return [];
        }

        // Clamped and cast: LIMIT cannot be bound under native prepares, so it
        // must be impossible for anything request-shaped to reach the string.
        $limit = max(1, min(1000, $this->candidateLimit));

        $categoryClause = $categoryKey === null ? '' : ' AND c.category_key = :category';

        $sql = self::SELECT . <<<SQL
            ,
                   MATCH(c.body) AGAINST (:expression IN BOOLEAN MODE) AS relevance
              FROM chunks c
              INNER JOIN documents d ON d.id = c.document_id
             WHERE MATCH(c.body) AGAINST (:expression2 IN BOOLEAN MODE)
               AND c.status = 'active'
               AND d.status = 'active'
               {$categoryClause}
             ORDER BY relevance DESC
             LIMIT {$limit}
            SQL;

        $statement = $this->pdo->prepare($sql);
        $statement->bindValue(':expression', $fullTextExpression, PDO::PARAM_STR);
        $statement->bindValue(':expression2', $fullTextExpression, PDO::PARAM_STR);
        if ($categoryKey !== null) {
            $statement->bindValue(':category', $categoryKey, PDO::PARAM_STR);
        }
        $statement->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        return $this->hydrate($rows, false);
    }

    /**
     * Exact programme and course code matches, boosted by the reranker.
     *
     * @param list<string> $codes
     *
     * @return list<ScoredChunk>
     */
    public function byCode(array $codes, ?string $categoryKey = null): array
    {
        if ($codes === []) {
            return [];
        }

        // One placeholder per code, all bound. Never a joined string.
        $placeholders = implode(', ', array_fill(0, count($codes), '?'));
        $categoryClause = $categoryKey === null ? '' : ' AND c.category_key = ?';

        $sql = self::SELECT . <<<SQL
            ,
                   0 AS relevance
              FROM chunks c
              INNER JOIN documents d ON d.id = c.document_id
              INNER JOIN chunk_codes k ON k.chunk_id = c.id
             WHERE k.code IN ({$placeholders})
               AND c.status = 'active'
               AND d.status = 'active'
               {$categoryClause}
            SQL;

        $statement = $this->pdo->prepare($sql);
        $parameters = $codes;
        if ($categoryKey !== null) {
            $parameters[] = $categoryKey;
        }
        $statement->execute($parameters);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        return $this->hydrate($rows, true);
    }

    /**
     * Embeddings for a candidate set, keyed by chunk id.
     *
     * Fetched separately so the candidate query does not drag a kilobyte of
     * BLOB per row through the sort, and skipped entirely for chunks embedded by
     * a different model — comparing vectors from two models is worse than not
     * reranking at all, because the numbers look fine.
     *
     * @param list<int> $chunkIds
     *
     * @return array<int, list<float>>
     */
    public function embeddings(array $chunkIds, string $embeddingModel): array
    {
        if ($chunkIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($chunkIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT id, embedding FROM chunks
              WHERE id IN ({$placeholders})
                AND embedding IS NOT NULL
                AND embedding_model = ?"
        );
        $statement->execute([...$chunkIds, $embeddingModel]);

        $vectors = [];
        foreach ($statement->fetchAll() as $row) {
            $vectors[(int) $row['id']] = VectorCodec::decode((string) $row['embedding']);
        }

        return $vectors;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<ScoredChunk>
     */
    private function hydrate(array $rows, bool $exactCodeMatch): array
    {
        $chunks = [];
        foreach ($rows as $row) {
            $chunks[] = new ScoredChunk(
                chunkId: (int) $row['chunk_id'],
                documentId: (int) $row['document_id'],
                body: (string) $row['body'],
                score: 0.0,
                sourceRef: (string) $row['source_ref'],
                title: (string) $row['title'],
                reviewedAt: (string) $row['reviewed_at'],
                reviewIntervalDays: (int) $row['review_interval_days'],
                isAuthoritative: (bool) $row['is_authoritative'],
                categoryKey: $row['category_key'] === null ? null : (string) $row['category_key'],
                headingPath: $row['heading_path'] === null ? null : (string) $row['heading_path'],
                pageNumber: $row['page_number'] === null ? null : (int) $row['page_number'],
                lexicalScore: (float) $row['relevance'],
                exactCodeMatch: $exactCodeMatch,
            );
        }

        return $chunks;
    }
}
