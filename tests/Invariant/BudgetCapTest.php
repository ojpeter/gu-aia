<?php

declare(strict_types=1);

namespace GuAia\Tests\Invariant;

use GuAia\Answering\AnswerMode;
use GuAia\Answering\BudgetGuard;
use GuAia\Answering\FakeGenerator;
use GuAia\Tests\Support\PipelineBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * INV-8 — Spend is capped, and degraded mode is real.
 *
 * "On reaching the configured monthly ceiling the system degrades to
 *  retrieval-only — links and extracts, no generation — and alerts. It never
 *  overspends and never silently fails. Budget check before every generation
 *  call; DEGRADED MODE IS A TESTED CODE PATH, NOT A HYPOTHETICAL."
 *
 * The last clause is the reason this file exists. A budget check that has never
 * been exercised is a branch that will be taken for the first time in production,
 * during an admissions cycle, at the exact moment nobody wants a surprise.
 */
#[Group('invariant')]
final class BudgetCapTest extends TestCase
{
    public function testAnUnsetCeilingFailsClosed(): void
    {
        // config/budget.php ships with monthly_ceiling => null, because the value
        // is set by the Chief, ICT Services before launch. Null means nobody has
        // authorised any spend, and the only reading of that which cannot end in
        // an unauthorised bill is: do not spend.
        $guard = new BudgetGuard(monthlyCeiling: null);

        self::assertFalse($guard->mayGenerate());
        self::assertSame('budget_ceiling_not_configured', $guard->reason());
    }

    public function testAnExhaustedBudgetRefusesGeneration(): void
    {
        $guard = new BudgetGuard(monthlyCeiling: 100.0, spendThisPeriod: 100.0);

        self::assertFalse($guard->mayGenerate());
        self::assertSame('budget_exhausted', $guard->reason());
    }

    public function testSpendWithinTheCeilingIsAllowed(): void
    {
        $guard = new BudgetGuard(monthlyCeiling: 100.0, spendThisPeriod: 10.0);

        self::assertTrue($guard->mayGenerate());
    }

    public function testAlertFiresAtEightyPercent(): void
    {
        self::assertFalse((new BudgetGuard(100.0, 79.0))->shouldAlert());
        self::assertTrue((new BudgetGuard(100.0, 80.0))->shouldAlert());
    }

    public function testExhaustedBudgetProducesRetrievalOnlyAnswerNotAnError(): void
    {
        $generator = new FakeGenerator();
        $pipeline = PipelineBuilder::make()
            ->withGenerator($generator)
            ->withBudget(new BudgetGuard(monthlyCeiling: 10.0, spendThisPeriod: 10.0))
            ->withChunk('Applications are submitted through the online portal.')
            ->build();

        $result = $pipeline->answer('How do I apply?');

        self::assertSame(AnswerMode::Degraded, $result->mode);
        self::assertTrue($result->degraded);
        self::assertSame('budget_exhausted', $result->degradedReason);
        self::assertFalse(
            $generator->wasCalled(),
            'INV-8 breach: the generator was called after the ceiling was reached.'
        );
        self::assertNotSame([], $result->sources, 'Degraded mode still returns links and extracts.');
    }

    public function testGenerationTimeoutDegradesRatherThanErroring(): void
    {
        // Section 11: "Generation timeout falls back to retrieval-only results
        // rather than an error page."
        $generator = (new FakeGenerator())->willTimeOut();
        $pipeline = PipelineBuilder::make()
            ->withGenerator($generator)
            ->withBudget(new BudgetGuard(monthlyCeiling: 100.0, spendThisPeriod: 0.0))
            ->withChunk('Applications are submitted through the online portal.')
            ->build();

        $result = $pipeline->answer('How do I apply?');

        self::assertSame(AnswerMode::Degraded, $result->mode);
        self::assertSame('generation_timeout', $result->degradedReason);
        self::assertNotSame('', $result->text);
    }

    public function testDegradedModeIsNeverPermittedToGenerate(): void
    {
        self::assertFalse(AnswerMode::Degraded->callsGenerator());
    }
}
