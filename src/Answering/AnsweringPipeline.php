<?php

declare(strict_types=1);

namespace GuAia\Answering;

use GuAia\Retrieval\RetrievalResult;
use GuAia\Retrieval\ContextRetriever;
use GuAia\Safety\RefusalRenderer;

/**
 * The answering pipeline. requirements.md Sections 6, 7, 8, 9.
 *
 * This is the class where the invariants either hold or do not, because it is
 * the only place that decides what a visitor actually receives. The ORDER of the
 * steps is the safety property; each one is placed where it is for a reason that
 * would be lost if it moved.
 *
 *   1. ROUTE FIRST (INV-3, INV-10). Individual-outcome and individual-record
 *      questions are refused before retrieval runs, so no context is ever
 *      fetched that a model could turn into an encouraging answer.
 *
 *   2. RETRIEVE (INV-1). If retrieval returns nothing above threshold, the
 *      answer is a refusal with the reason retrieval gave. Nothing downstream
 *      can rescue an empty context, and nothing should try.
 *
 *   3. QUOTED MODE DOES NOT CALL GENERATION (INV-2). Fees, entry requirements
 *      and deadlines are returned as the retrieved text itself. The generator is
 *      not consulted, not even to tidy the wording.
 *
 *   4. BUDGET AND TIMEOUT BEFORE GENERATION (INV-8). Over the ceiling, or on a
 *      timeout, the answer degrades to retrieval-only. Degraded mode is a real
 *      path with a real output, not an error.
 *
 *   5. BIND CITATIONS, DISCARD ON FAILURE (INV-1, Section 8). A generated answer
 *      that cites nothing, or cites something that was not retrieved, is thrown
 *      away and replaced by the refusal template. It is never repaired.
 *
 * Logging (INV-7) is intentionally NOT done here. The logger writes in the same
 * transaction as the response is served, which is the caller's transaction; this
 * class returns everything the log needs on the AnswerResult and lets the
 * request handler own the boundary.
 */
final class AnsweringPipeline
{
    public function __construct(
        private readonly CategoryRouter $router,
        private readonly ContextRetriever $retriever,
        private readonly PromptBuilder $prompts,
        private readonly Generator $generator,
        private readonly CitationBinder $binder,
        private readonly RefusalRenderer $refusals,
        /** @var array<string, array{mode: string, handoff: ?string}> */
        private readonly array $categories = [],
        private readonly ?BudgetGuard $budget = null,
    ) {
    }

    public function answer(string $question): AnswerResult
    {
        // 1. Route before retrieval.
        $routing = $this->router->route($question);

        if ($routing->refusedBeforeRetrieval) {
            return $this->refuse(
                templateKey: $routing->categoryKey ?? 'off_topic',
                categoryKey: $routing->categoryKey,
                reason: $routing->categoryKey ?? 'refused_before_retrieval',
            );
        }

        // 2. Retrieve.
        $retrieval = $this->retriever->retrieve($question, $routing->categoryKey);

        if (!$retrieval->isConfident) {
            return $this->refuse(
                templateKey: 'no_confident_context',
                categoryKey: $routing->categoryKey,
                reason: $retrieval->reason ?? 'no_confident_context',
            );
        }

        // 3. Quoted mode never reaches the generator.
        if ($routing->mode === AnswerMode::Quoted) {
            return $this->quote($retrieval, $routing->categoryKey);
        }

        // 4. Budget and timeout guard the generation call.
        if ($this->budget !== null && !$this->budget->mayGenerate()) {
            return $this->degrade($retrieval, $routing->categoryKey, $this->budget->reason());
        }

        try {
            $generation = $this->generator->generate(
                $this->prompts->systemPrompt(),
                $this->prompts->userContent($question, $retrieval)
            );
        } catch (GenerationTimedOut) {
            // Section 11: fall back to retrieval-only results rather than an
            // error page.
            return $this->degrade($retrieval, $routing->categoryKey, 'generation_timeout');
        }

        // 5. Bind citations. Discard, never repair.
        $bound = $this->binder->bind($generation->text, $retrieval->referenceMap());

        if ($bound === null) {
            return $this->refuse(
                templateKey: 'no_confident_context',
                categoryKey: $routing->categoryKey,
                reason: 'citation_binding_failed',
                model: $this->generator->modelName(),
                promptTokens: $generation->promptTokens,
                completionTokens: $generation->completionTokens,
                cost: $generation->cost,
            );
        }

        $cited = $this->citedChunks($retrieval, $bound->chunkIds());

        return new AnswerResult(
            mode: AnswerMode::Grounded,
            text: $bound->text,
            sources: $cited,
            retrieved: $retrieval->chunks,
            citations: $bound->citations,
            categoryKey: $routing->categoryKey,
            staleSource: $retrieval->hasStaleSource(),
            model: $this->generator->modelName(),
            promptVersion: $this->prompts->version(),
            promptTokens: $generation->promptTokens,
            completionTokens: $generation->completionTokens,
            cost: $generation->cost,
        );
    }

    /**
     * INV-2. The authoritative passage is returned as it stands.
     *
     * Where the retrieved set contains a chunk from a document marked
     * authoritative for this category, that one wins — Section 5.2: "where two
     * sources conflict, the one marked authoritative for that category wins".
     */
    private function quote(RetrievalResult $retrieval, ?string $categoryKey): AnswerResult
    {
        $chosen = $retrieval->chunks[0];
        $reference = 1;

        foreach ($retrieval->chunks as $index => $chunk) {
            if ($chunk->isAuthoritative) {
                $chosen = $chunk;
                $reference = $index + 1;
                break;
            }
        }

        return new AnswerResult(
            mode: AnswerMode::Quoted,
            // Verbatim. No summarising, no rounding, no "approximately".
            text: $chosen->body,
            sources: [$chosen],
            retrieved: $retrieval->chunks,
            citations: [$reference => $chosen->chunkId],
            categoryKey: $categoryKey,
            staleSource: $chosen->isStale(),
            promptVersion: null, // No prompt was used, because no model was called.
        );
    }

    /** INV-8 / Section 11: links and extracts, no generation. */
    private function degrade(RetrievalResult $retrieval, ?string $categoryKey, string $reason): AnswerResult
    {
        $citations = [];
        foreach ($retrieval->chunks as $index => $chunk) {
            $citations[$index + 1] = $chunk->chunkId;
        }

        return new AnswerResult(
            mode: AnswerMode::Degraded,
            text: $this->refusals->render('degraded_mode', null)->text,
            sources: $retrieval->chunks,
            retrieved: $retrieval->chunks,
            citations: $citations,
            categoryKey: $categoryKey,
            staleSource: $retrieval->hasStaleSource(),
            degraded: true,
            degradedReason: $reason,
        );
    }

    private function refuse(
        string $templateKey,
        ?string $categoryKey,
        string $reason,
        ?string $model = null,
        int $promptTokens = 0,
        int $completionTokens = 0,
        float $cost = 0.0,
    ): AnswerResult {
        $handoff = $this->categories[$categoryKey]['handoff'] ?? null;
        $rendered = $this->refusals->render($templateKey, $handoff);

        return new AnswerResult(
            mode: AnswerMode::Refuse,
            text: $rendered->text,
            categoryKey: $categoryKey,
            refusalReason: $reason,
            model: $model,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            cost: $cost,
            handoffMissing: $rendered->handoffMissing,
        );
    }

    /**
     * Only the chunks the answer actually cited travel onward. The rest of the
     * candidate pool is logged (Section 13) but never shown: a source list longer
     * than the answer's real evidence implies support that is not there.
     *
     * @param list<int> $chunkIds
     *
     * @return list<\GuAia\Retrieval\ScoredChunk>
     */
    private function citedChunks(RetrievalResult $retrieval, array $chunkIds): array
    {
        $wanted = array_flip($chunkIds);

        return array_values(array_filter(
            $retrieval->chunks,
            static fn ($chunk): bool => isset($wanted[$chunk->chunkId])
        ));
    }
}
