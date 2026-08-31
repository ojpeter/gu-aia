<?php

declare(strict_types=1);

namespace GuAia\Tests\Support;

use GuAia\Answering\AnsweringPipeline;
use GuAia\Answering\BudgetGuard;
use GuAia\Answering\CategoryRouter;
use GuAia\Answering\CitationBinder;
use GuAia\Answering\FakeGenerator;
use GuAia\Answering\Generator;
use GuAia\Answering\PromptBuilder;
use GuAia\Retrieval\ContextRetriever;
use GuAia\Retrieval\RetrievalResult;
use GuAia\Retrieval\ScoredChunk;
use GuAia\Safety\RefusalRenderer;

/**
 * Assembles a real AnsweringPipeline against fixed context.
 *
 * Everything here is the production class except the retriever and the
 * generator. That is the point: the invariants under test — quoted mode never
 * generating, citation binding discarding, degraded mode returning links — live
 * in the pipeline itself, and testing them against a mock pipeline would test
 * nothing.
 *
 * The refusal config is loaded from config/refusals.php, contacts still null,
 * exactly as it ships. If somebody fills those in, these tests keep passing;
 * if somebody breaks the template keys, they fail.
 */
final class PipelineBuilder
{
    /** @var list<ScoredChunk> */
    private array $chunks = [];

    private ?string $noContextReason = null;
    private Generator $generator;
    private ?BudgetGuard $budget = null;

    private function __construct()
    {
        $this->generator = new FakeGenerator();
    }

    public static function make(): self
    {
        return new self();
    }

    public function withGenerator(Generator $generator): self
    {
        $this->generator = $generator;

        return $this;
    }

    public function withBudget(?BudgetGuard $budget): self
    {
        $this->budget = $budget;

        return $this;
    }

    public function withChunk(
        string $body,
        bool $authoritative = false,
        string $reviewedAt = '2026-06-01',
        int $reviewIntervalDays = 365,
    ): self {
        $this->chunks[] = new ScoredChunk(
            chunkId: 100 + count($this->chunks),
            documentId: 1,
            body: $body,
            score: 0.9,
            sourceRef: 'https://gu.ac.ug/example',
            title: 'Example page',
            reviewedAt: $reviewedAt,
            reviewIntervalDays: $reviewIntervalDays,
            isAuthoritative: $authoritative,
        );

        return $this;
    }

    /** Simulate retrieval finding nothing above threshold (INV-1). */
    public function withNoConfidentContext(string $reason = 'below_threshold'): self
    {
        $this->noContextReason = $reason;

        return $this;
    }

    public function build(): AnsweringPipeline
    {
        $root = dirname(__DIR__, 2);

        /** @var array{contacts: array<string, array<string, ?string>>, templates: array<string, string>} $refusalConfig */
        $refusalConfig = require $root . '/config/refusals.php';

        /** @var array{categories: array<string, array{mode: string, handoff: ?string}>} $categoryConfig */
        $categoryConfig = require $root . '/config/categories.php';

        return new AnsweringPipeline(
            router: new CategoryRouter(),
            retriever: $this->retriever(),
            prompts: new PromptBuilder($root . '/config/prompts'),
            generator: $this->generator,
            binder: new CitationBinder(),
            refusals: new RefusalRenderer($refusalConfig),
            categories: $categoryConfig['categories'],
            budget: $this->budget,
        );
    }

    private function retriever(): ContextRetriever
    {
        $chunks = $this->chunks;
        $reason = $this->noContextReason;

        return new class ($chunks, $reason) implements ContextRetriever {
            /** @param list<ScoredChunk> $chunks */
            public function __construct(
                private readonly array $chunks,
                private readonly ?string $reason,
            ) {
            }

            public function retrieve(string $query, ?string $categoryKey = null): RetrievalResult
            {
                if ($this->reason !== null || $this->chunks === []) {
                    return RetrievalResult::noConfidentContext($this->reason ?? 'no_candidates');
                }

                return RetrievalResult::confident($this->chunks);
            }
        };
    }
}
