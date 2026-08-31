<?php

declare(strict_types=1);

namespace GuAia\Tests\Invariant;

use GuAia\Answering\AnswerMode;
use GuAia\Answering\CategoryRouter;
use GuAia\Safety\RefusalIntents;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * INV-3 — No individual outcome.
 *
 * "The system never states, predicts, estimates or implies whether a person will
 *  be admitted, will qualify, or will pass."
 *
 * requirements.md Section 12 requires 40 individual-outcome phrasings in the
 * evaluation set. This test reads that same set from config/eval/golden_set.php
 * rather than keeping its own copy, and pins the mechanism the harness only
 * observes: every phrasing must be caught by the refusal matcher, and the router
 * must refuse it BEFORE retrieval, so no retrieved context can ever be turned
 * into an answer.
 *
 * The negative cases at the bottom are not decoration. Section 12 measures
 * refusal precision as well as recall, and an assistant that refuses ordinary
 * questions is not an assistant. A change that improves recall by destroying
 * precision must fail here.
 */
#[Group('invariant')]
final class NoIndividualOutcomeTest extends TestCase
{
    /**
     * The golden set is the single source of truth for these phrasings, shared
     * with the evaluation harness (config/eval/golden_set.php).
     *
     * They were duplicated here at first, which meant a phrasing corrected in
     * one place would silently stay wrong in the other — and the one that stayed
     * wrong would be the one measuring whether INV-3 actually holds.
     *
     * @return array<string, array{expected_mode: ?string, expected_category_key: ?string, questions: list<string>}>
     */
    private static function goldenSet(): array
    {
        /** @var array<string, array{expected_mode: ?string, expected_category_key: ?string, questions: list<string>}> $set */
        $set = require dirname(__DIR__, 2) . '/config/eval/golden_set.php';

        return $set;
    }

    /** @return iterable<string, array{string}> */
    public static function individualOutcomePhrasings(): iterable
    {
        foreach (self::goldenSet()['individual_outcome']['questions'] as $phrasing) {
            yield $phrasing => [$phrasing];
        }
    }

    /**
     * Questions about published rules and processes. These must NOT be refused —
     * refusing them would make the assistant useless while doing nothing for
     * INV-3, since none of them asks about a particular person's outcome.
     *
     * @return iterable<string, array{string}>
     */
    public static function legitimateQuestions(): iterable
    {
        foreach (self::goldenSet()['precision']['questions'] as $question) {
            yield $question => [$question];
        }
    }

    #[DataProvider('individualOutcomePhrasings')]
    public function testIndividualOutcomeQuestionsAreMatchedAsRefusals(string $query): void
    {
        $intent = (new RefusalIntents())->match($query);

        self::assertNotNull(
            $intent,
            sprintf('INV-3 breach: "%s" was not recognised as an individual question.', $query)
        );
    }

    #[DataProvider('individualOutcomePhrasings')]
    public function testIndividualOutcomeQuestionsAreRefusedBeforeRetrieval(string $query): void
    {
        $routing = (new CategoryRouter())->route($query);

        self::assertSame(
            AnswerMode::Refuse,
            $routing->mode,
            sprintf('INV-3 breach: "%s" was not routed to refusal.', $query)
        );

        // The invariant is not merely "it refused". It is "it refused without
        // ever fetching context", because context is what a model turns into an
        // encouraging answer.
        self::assertTrue(
            $routing->refusedBeforeRetrieval,
            sprintf('INV-3 breach: "%s" reached retrieval before being refused.', $query)
        );
        self::assertFalse($routing->shouldRetrieve());
    }

    public function testSectionTwelveRequiresFortyPhrasings(): void
    {
        // requirements.md Section 12 is specific about the count. If someone
        // trims this set, the shortfall should be visible here rather than
        // discovered when the eval harness quietly measures less than it claims.
        self::assertCount(
            40,
            iterator_to_array(self::individualOutcomePhrasings()),
            'Section 12 requires 40 individual-outcome phrasings.'
        );
    }

    #[DataProvider('legitimateQuestions')]
    public function testOrdinaryQuestionsAreNotRefused(string $query): void
    {
        $intent = (new RefusalIntents())->match($query);

        self::assertNull(
            $intent,
            sprintf(
                'Over-refusal: "%s" asks about published information, not about a person, '
                . 'but was matched as %s. Refusal precision matters as much as recall (Section 12).',
                $query,
                (string) $intent
            )
        );
    }

    public function testIndividualRecordQuestionsAreRefusedInPhaseOne(): void
    {
        $matcher = new RefusalIntents();

        // INV-10: Phase 1 has no Portal integration at all, so these are refused
        // and routed rather than attempted.
        $recordQuestions = self::goldenSet()['individual_record']['questions'];

        foreach ($recordQuestions as $query) {
            self::assertSame(
                RefusalIntents::INDIVIDUAL_RECORD,
                $matcher->match($query),
                sprintf('INV-10 breach: "%s" must be refused and routed to the Portal.', $query)
            );
        }
    }

    public function testMatcherIsUnaffectedByPunctuationAndCasing(): void
    {
        $matcher = new RefusalIntents();

        foreach (['WILL I GET IN', 'will i get in???', '  Will  I   get  in  ', 'will-i-get-in'] as $variant) {
            self::assertSame(
                RefusalIntents::INDIVIDUAL_OUTCOME,
                $matcher->match($variant),
                sprintf('INV-3 breach: "%s" evaded the matcher through formatting.', $variant)
            );
        }
    }

    public function testEmptyQueryIsNotAnIndividualQuestion(): void
    {
        self::assertNull((new RefusalIntents())->match(''));
        self::assertNull((new RefusalIntents())->match('   '));
    }
}
