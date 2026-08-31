<?php

declare(strict_types=1);

namespace GuAia\Tests\Unit;

use GuAia\Answering\AnswerMode;
use GuAia\Answering\BudgetGuard;
use GuAia\Answering\FakeGenerator;
use GuAia\Tests\Support\PipelineBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The answering pipeline end to end, against fixed context.
 *
 * These exercise the ORDER of the pipeline's steps, which is where its safety
 * properties live. Each test would still pass if the steps were reordered into
 * something unsafe — except the ones that assert the generator was not called,
 * or that a bad answer was discarded, which is why those are the ones that
 * matter most.
 */
final class AnsweringPipelineTest extends TestCase
{
    private function allowedBudget(): BudgetGuard
    {
        return new BudgetGuard(monthlyCeiling: 100.0, spendThisPeriod: 0.0);
    }

    public function testAnIndividualOutcomeQuestionIsRefusedWithoutRetrieving(): void
    {
        // INV-3. No context is fetched at all, so nothing exists for a model to
        // turn into an encouraging answer.
        $generator = new FakeGenerator();
        $pipeline = PipelineBuilder::make()
            ->withGenerator($generator)
            ->withChunk('Entry requirements for Medicine are published annually.')
            ->build();

        $result = $pipeline->answer('Will I get in with 12 points?');

        self::assertSame(AnswerMode::Refuse, $result->mode);
        self::assertSame('individual_outcome', $result->categoryKey);
        self::assertFalse($generator->wasCalled());
        self::assertSame([], $result->sources, 'INV-3: nothing should have been retrieved.');
    }

    public function testAFeesQuestionIsQuotedVerbatimWithoutGenerating(): void
    {
        // INV-2. The figure is returned as retrieved, not paraphrased.
        $authoritative = 'Tuition for the Bachelor of Laws, 2026/27: 1,500,000 UGX per semester.';

        $generator = new FakeGenerator();
        $pipeline = PipelineBuilder::make()
            ->withGenerator($generator)
            ->withChunk('The University reviews fees annually.')
            ->withChunk($authoritative, authoritative: true)
            ->build();

        $result = $pipeline->answer('How much is tuition for the Bachelor of Laws?');

        self::assertSame(AnswerMode::Quoted, $result->mode);
        self::assertSame($authoritative, $result->text, 'INV-2: quoted text must be verbatim.');
        self::assertFalse(
            $generator->wasCalled(),
            'INV-2 breach: a fees answer that passed through a model is a fabricated fees answer.'
        );
        self::assertNull($result->promptVersion, 'No prompt was used, because no model was called.');
        self::assertNotSame([], $result->citations);
    }

    public function testTheAuthoritativeSourceWinsInQuotedMode(): void
    {
        // Section 5.2: where two sources conflict, the authoritative one wins.
        $pipeline = PipelineBuilder::make()
            ->withChunk('An old news item mentions fees of 900,000 UGX.')
            ->withChunk('Authoritative fees schedule 2026/27.', authoritative: true)
            ->build();

        $result = $pipeline->answer('What are the fees?');

        self::assertSame('Authoritative fees schedule 2026/27.', $result->text);
    }

    public function testNoConfidentContextProducesARefusalCarryingTheReason(): void
    {
        // INV-1. Nothing downstream can rescue an empty context.
        $generator = new FakeGenerator();
        $pipeline = PipelineBuilder::make()
            ->withGenerator($generator)
            ->withNoConfidentContext('below_threshold')
            ->build();

        $result = $pipeline->answer('How many students failed last semester?');

        self::assertSame(AnswerMode::Refuse, $result->mode);
        self::assertSame('below_threshold', $result->refusalReason);
        self::assertFalse($generator->wasCalled());
    }

    public function testAGroundedAnswerIsReturnedWhenItCitesProperly(): void
    {
        $generator = (new FakeGenerator())->willReturn('Applications are submitted online. [1]');

        $result = PipelineBuilder::make()
            ->withGenerator($generator)
            ->withBudget($this->allowedBudget())
            ->withChunk('Applications are submitted through the online portal.')
            ->build()
            ->answer('How do I apply?');

        self::assertSame(AnswerMode::Grounded, $result->mode);
        self::assertSame('Applications are submitted online. [1]', $result->text);
        self::assertTrue($result->isGrounded());
        self::assertSame('system-v1', $result->promptVersion);
        self::assertCount(1, $result->sources);
    }

    public function testAnUncitedGeneratedAnswerIsDiscardedNotServed(): void
    {
        // INV-1 and Section 8: "discarded, not repaired". This is the fluent,
        // plausible, entirely unsourced paragraph — the one that would ship.
        $generator = (new FakeGenerator())
            ->willReturn('Tuition is 1,200,000 UGX per semester for Ugandan students.');

        $result = PipelineBuilder::make()
            ->withGenerator($generator)
            ->withBudget($this->allowedBudget())
            ->withChunk('Applications are submitted through the online portal.')
            ->build()
            ->answer('How do I apply?');

        self::assertSame(AnswerMode::Refuse, $result->mode);
        self::assertSame('citation_binding_failed', $result->refusalReason);
        self::assertStringNotContainsString(
            '1,200,000',
            $result->text,
            'INV-1 breach: the discarded answer leaked into the refusal.'
        );
    }

    public function testAnAnswerCitingSomethingNeverRetrievedIsDiscarded(): void
    {
        $generator = (new FakeGenerator())->willReturn('Applications close in June. [7]');

        $result = PipelineBuilder::make()
            ->withGenerator($generator)
            ->withBudget($this->allowedBudget())
            ->withChunk('Applications are submitted through the online portal.')
            ->build()
            ->answer('How do I apply?');

        self::assertSame(AnswerMode::Refuse, $result->mode);
        self::assertSame('citation_binding_failed', $result->refusalReason);
    }

    public function testTheGeneratorReceivesOnlyRetrievedContextInsideTheFence(): void
    {
        // INV-5 / INV-6 at the pipeline level.
        $generator = (new FakeGenerator())->willReturn('Answer. [1]');

        PipelineBuilder::make()
            ->withGenerator($generator)
            ->withBudget($this->allowedBudget())
            ->withChunk('Applications are submitted through the online portal.')
            ->build()
            ->answer('How do I apply?');

        $call = $generator->calls()[0];

        self::assertStringContainsString('<<<GU-AIA-CONTEXT>>>', $call['user']);
        self::assertStringContainsString('online portal', $call['user']);
        self::assertStringContainsString('ONLY from the CONTEXT', $call['system']);
    }

    public function testAStaleSourceIsFlaggedOnTheAnswer(): void
    {
        // INV-11: content past its review interval is still served, but the
        // answer must carry a visible caution.
        $result = PipelineBuilder::make()
            ->withChunk('Fees schedule.', authoritative: true, reviewedAt: '2020-01-01', reviewIntervalDays: 365)
            ->build()
            ->answer('What are the fees?');

        self::assertTrue($result->staleSource, 'INV-11: a stale source must be visible on the answer.');
    }

    public function testAFreshSourceIsNotFlaggedStale(): void
    {
        $result = PipelineBuilder::make()
            ->withChunk('Fees schedule.', authoritative: true, reviewedAt: date('Y-m-d'), reviewIntervalDays: 365)
            ->build()
            ->answer('What are the fees?');

        self::assertFalse($result->staleSource);
    }

    public function testARefusalWithoutAConfiguredContactIsStillServedAndFlagged(): void
    {
        // config/refusals.php ships with null contacts on purpose. Throwing here
        // would turn every refusal into an error page until the Registry supplies
        // an email address, and the user would get nothing at all — worse than a
        // refusal naming an office without a phone number. So it is served, and
        // the gap is flagged for the people who can fix it.
        $result = PipelineBuilder::make()
            ->withChunk('Anything.')
            ->build()
            ->answer('Will I be admitted?');

        self::assertSame(AnswerMode::Refuse, $result->mode);
        self::assertNotSame('', $result->text);
        self::assertTrue(
            $result->handoffMissing,
            'The missing contact must be visible in the log and the weekly report.'
        );
    }
}
