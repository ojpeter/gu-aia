<?php

declare(strict_types=1);

namespace GuAia\Logging;

use GuAia\Answering\AnswerResult;
use GuAia\Retrieval\ScoredChunk;
use PDO;

/**
 * Writes the interaction log. requirements.md Section 13, INV-7.
 *
 * "Every interaction logs: correlation ID, timestamp, query, category, retrieved
 *  chunk IDs and scores, mode, model and prompt version, answer, citations,
 *  refusal reason, latency, tokens and cost, and any feedback."
 *
 * "InteractionLogger writes IN THE SAME TRANSACTION as the response is served."
 *
 * WHAT THAT CLAUSE ACTUALLY BUYS
 *
 * It means there is no state in which a visitor received an answer that the
 * University has no record of. Log-after-respond loses exactly the interactions
 * most worth having — the ones where something went wrong mid-request — and
 * those are the ones a complaint will be about. So this class does not open its
 * own transaction: it writes into whatever transaction the request handler
 * opened, and the handler commits once, after the response is composed.
 *
 * The logger does not swallow its own errors either. A failure to log is a
 * failure to serve, because INV-7 says the record is part of the deliverable.
 *
 * PERSONAL DATA (docs/data-protection.md DF-1)
 *
 * A question can identify the person who asked it even with no login. Raw IPs
 * and session ids never reach these tables; IdentifierHasher pseudonymises them
 * under a key the log reader does not hold. Retention expiry REDACTS rather than
 * deletes (INV-12), which is why every table here has a redacted_at column and
 * no account on this schema holds DELETE.
 */
final class InteractionLogger
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentifierHasher $hasher,
    ) {
    }

    /**
     * @param array<string, string|null> $context ip, session_id, language
     *
     * @return int the interaction id, for attaching feedback later
     */
    public function log(
        string $correlationId,
        string $question,
        AnswerResult $result,
        int $latencyMs,
        array $context = [],
    ): int {
        // Explicit column list, never a loop over request input (CLAUDE.md
        // Rule 5: no mass assignment).
        $statement = $this->pdo->prepare(
            'INSERT INTO interactions (
                correlation_id, query_text, normalised_query, query_language,
                category_key, mode, model, prompt_version, answer, refusal_reason,
                degraded, degraded_reason, latency_ms, tokens_prompt,
                tokens_completion, cost, ip_hash, session_id
             ) VALUES (
                :correlation_id, :query_text, :normalised_query, :query_language,
                :category_key, :mode, :model, :prompt_version, :answer, :refusal_reason,
                :degraded, :degraded_reason, :latency_ms, :tokens_prompt,
                :tokens_completion, :cost, :ip_hash, :session_id
             )'
        );

        $statement->execute([
            'correlation_id' => $correlationId,
            'query_text' => mb_substr($question, 0, 2000),
            'normalised_query' => mb_substr($this->normalise($question), 0, 2000),
            'query_language' => $context['language'] ?? null,
            'category_key' => $result->categoryKey,
            'mode' => $result->mode->value,
            'model' => $result->model,
            'prompt_version' => $result->promptVersion,
            'answer' => $result->text,
            'refusal_reason' => $result->refusalReason,
            'degraded' => $result->degraded ? 1 : 0,
            'degraded_reason' => $result->degradedReason,
            'latency_ms' => $latencyMs,
            'tokens_prompt' => $result->promptTokens,
            'tokens_completion' => $result->completionTokens,
            'cost' => $result->cost,
            'ip_hash' => $this->hasher->hashOrNull($context['ip'] ?? null),
            'session_id' => $this->hasher->hashOrNull($context['session_id'] ?? null),
        ]);

        $interactionId = (int) $this->pdo->lastInsertId();

        $this->logRetrievals($interactionId, $result);
        $this->logCitations($interactionId, $result);

        if ($result->isRefusal()) {
            $this->logUnansweredQuestion($interactionId, $question, $result);
        }

        return $interactionId;
    }

    /**
     * Every chunk retrieval returned, with its score and whether it reached the
     * answer.
     *
     * The chunks that did NOT win are the informative half: Section 6 says the
     * threshold is tuned against the evaluation set, and this is the evidence
     * that makes tuning possible rather than a matter of taste.
     */
    private function logRetrievals(int $interactionId, AnswerResult $result): void
    {
        if ($result->retrieved === []) {
            return;
        }

        $cited = [];
        foreach ($result->sources as $source) {
            $cited[$source->chunkId] = true;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO interaction_retrievals
                (interaction_id, chunk_id, rank_position, score, passed_to_answer)
             VALUES (:interaction_id, :chunk_id, :rank_position, :score, :passed)'
        );

        foreach ($result->retrieved as $position => $chunk) {
            $statement->execute([
                'interaction_id' => $interactionId,
                'chunk_id' => $chunk->chunkId,
                'rank_position' => $position + 1,
                'score' => $this->clampScore($chunk),
                'passed' => isset($cited[$chunk->chunkId]) ? 1 : 0,
            ]);
        }
    }

    /**
     * INV-1 becomes auditable here: a grounded interaction with zero citation
     * rows is, by definition, a violation, and it can be found with one query
     * months later.
     */
    private function logCitations(int $interactionId, AnswerResult $result): void
    {
        if ($result->citations === []) {
            return;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO interaction_citations (interaction_id, chunk_id, reference_number)
             VALUES (:interaction_id, :chunk_id, :reference_number)'
        );

        foreach ($result->citations as $reference => $chunkId) {
            $statement->execute([
                'interaction_id' => $interactionId,
                'chunk_id' => $chunkId,
                'reference_number' => $reference,
            ]);
        }
    }

    /**
     * Section 13: every refusal is logged as an unanswered question, and those
     * feed the weekly report — "a ranked list of what the public comes to the
     * University's website looking for and cannot find", which Section 13 says
     * is likely worth more to the institution than the assistant itself.
     *
     * The normalised form is computed once here and indexed, so the weekly
     * ranking is a cheap GROUP BY rather than a scan.
     */
    private function logUnansweredQuestion(int $interactionId, string $question, AnswerResult $result): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO unanswered_questions
                (interaction_id, normalised_question, category_key, refusal_reason, handoff_office_id)
             VALUES (:interaction_id, :normalised_question, :category_key, :refusal_reason, :handoff_office_id)'
        );

        $statement->execute([
            'interaction_id' => $interactionId,
            'normalised_question' => mb_substr($this->normalise($question), 0, 500),
            'category_key' => $result->categoryKey,
            'refusal_reason' => $result->refusalReason ?? 'unspecified',
            // Resolved by the caller when contacts are configured; null until
            // Phase 0 supplies them, which handoffMissing already records.
            'handoff_office_id' => null,
        ]);
    }

    /**
     * The score column is DECIMAL(8,6), which tops out below 100. An exact code
     * match adds a boost that can exceed that range, so it is clamped rather
     * than allowed to raise a range error mid-request — the ordering is what the
     * column is for, and the ordering survives clamping.
     */
    private function clampScore(ScoredChunk $chunk): float
    {
        return max(-99.0, min(99.0, $chunk->score));
    }

    /**
     * Casefold and collapse, so "What are the FEES?" and "what are the fees"
     * group together in the weekly report. Deliberately not stemming: this is
     * for ranking what people asked, not for retrieval.
     */
    private function normalise(string $question): string
    {
        $text = mb_strtolower(trim($question), 'UTF-8');
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
