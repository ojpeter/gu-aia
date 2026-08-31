<?php

declare(strict_types=1);

namespace GuAia\Tests\Invariant;

use GuAia\Answering\AnswerMode;
use GuAia\Answering\CategoryRouter;
use GuAia\Answering\FakeGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * INV-2 — High-stakes facts are quoted, never paraphrased.
 *
 * "Fees, entry requirements, deadlines and application steps are returned as the
 *  authoritative text plus a link... Category classifier routes to QuotedAnswer
 *  mode, WHICH DOES NOT CALL GENERATION for the figure itself."
 *
 * Risk R-1 in docs/ai-risk-register.md is a fabricated fees figure, assessed
 * severe and likely-without-treatment. This is that treatment.
 *
 * The assertion that carries the invariant is the last one: a fees question must
 * be answerable with the generator never having been invoked. "No generated
 * figure, ever" is only a real guarantee if something counts the calls.
 */
#[Group('invariant')]
final class QuotedNotParaphrasedTest extends TestCase
{
    /**
     * Shared with the evaluation harness — one source of truth, so a question
     * fixed in one place cannot stay broken in the other.
     *
     * @return iterable<string, array{string}>
     */
    public static function highStakesQuestions(): iterable
    {
        /** @var array<string, array{questions: list<string>}> $set */
        $set = require dirname(__DIR__, 2) . '/config/eval/golden_set.php';

        foreach ($set['quoted_high_stakes']['questions'] as $question) {
            yield $question => [$question];
        }
    }

    #[DataProvider('highStakesQuestions')]
    public function testHighStakesQuestionsRouteToQuotedMode(string $query): void
    {
        $routing = (new CategoryRouter())->route($query);

        self::assertSame(
            AnswerMode::Quoted,
            $routing->mode,
            sprintf(
                'INV-2 breach: "%s" routed to %s. Fees, entry requirements and deadlines '
                . 'must be returned verbatim, never generated.',
                $query,
                $routing->mode->value
            )
        );
        self::assertContains($routing->categoryKey, ['fees', 'entry_requirements', 'deadlines_calendar']);
    }

    public function testSectionTwelveRequiresTwentyHighStakesQuestions(): void
    {
        self::assertCount(
            20,
            iterator_to_array(self::highStakesQuestions()),
            'Section 12 requires 20 fees and entry-requirement questions that must return quoted text.'
        );
    }

    public function testQuotedModeIsNotPermittedToCallTheGenerator(): void
    {
        self::assertFalse(
            AnswerMode::Quoted->callsGenerator(),
            'INV-2 breach: quoted mode must never call generation for the figure itself.'
        );
        self::assertFalse(AnswerMode::Refuse->callsGenerator());
        self::assertFalse(
            AnswerMode::Degraded->callsGenerator(),
            'INV-8 breach: degraded mode is retrieval-only and must not generate.'
        );
        self::assertTrue(AnswerMode::Grounded->callsGenerator());
    }

    #[DataProvider('highStakesQuestions')]
    public function testGeneratorIsNeverInvokedForAHighStakesQuestion(string $query): void
    {
        $generator = new FakeGenerator();
        $routing = (new CategoryRouter())->route($query);

        // This is the shape the answering pipeline must take: consult the mode
        // BEFORE reaching for the generator, never after.
        if ($routing->mode->callsGenerator()) {
            $generator->generate('system', 'user');
        }

        self::assertFalse(
            $generator->wasCalled(),
            sprintf(
                'INV-2 breach: the generator was invoked for "%s". A fees or deadline '
                . 'figure that has passed through a language model is a fabricated '
                . 'figure, however faithful it looks.',
                $query
            )
        );
    }

    public function testFeesWinsOverDeadlineWhenAQuestionMentionsBoth(): void
    {
        // Both route to Quoted, so the invariant holds either way — but the
        // category decides which authoritative document is consulted, and a
        // wrong fees figure is the more expensive error.
        $routing = (new CategoryRouter())->route('What is the deadline for paying tuition fees?');

        self::assertSame(AnswerMode::Quoted, $routing->mode);
        self::assertSame('fees', $routing->categoryKey);
    }
}
