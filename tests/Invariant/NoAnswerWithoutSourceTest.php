<?php

declare(strict_types=1);

namespace GuAia\Tests\Invariant;

use GuAia\Answering\CitationBinder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * INV-1 — No answer without a source.
 *
 * "Every factual sentence in a response traces to a retrieved chunk... a
 *  response with zero citations is discarded and replaced by the refusal
 *  template."
 *
 * Section 8: "A response failing either check is DISCARDED, NOT REPAIRED."
 *
 * The most important assertion in this file is the fluent-but-uncited one. That
 * is the failure that gets shipped, because it reads perfectly.
 */
#[Group('invariant')]
final class NoAnswerWithoutSourceTest extends TestCase
{
    private CitationBinder $binder;

    /** Reference number => chunk id, as passed to the generator. */
    private const RETRIEVED = [1 => 101, 2 => 102, 3 => 103];

    protected function setUp(): void
    {
        $this->binder = new CitationBinder();
    }

    public function testAnswerWithNoCitationIsDiscarded(): void
    {
        $fluentAndUncited = 'Tuition for the Bachelor of Science in Computer Science '
            . 'is 1,200,000 UGX per semester for Ugandan students.';

        self::assertNull(
            $this->binder->bind($fluentAndUncited, self::RETRIEVED),
            'INV-1 breach: a confident, uncited answer was accepted. This is the '
            . 'exact failure mode requirements.md Section 0 is written against.'
        );
    }

    public function testEmptyAnswerIsDiscarded(): void
    {
        self::assertNull($this->binder->bind('', self::RETRIEVED));
        self::assertNull($this->binder->bind('   ', self::RETRIEVED));
    }

    public function testCitationToSomethingNeverRetrievedIsDiscarded(): void
    {
        // A reference to a chunk that was not in the retrieved set is a
        // fabricated source, which is worse than no source: it looks verified.
        self::assertNull(
            $this->binder->bind('Applications close in June. [7]', self::RETRIEVED),
            'INV-1 breach: a citation pointing outside the retrieved set was accepted.'
        );
    }

    public function testPartiallyValidCitationsAreDiscardedNotRepaired(): void
    {
        $answer = 'Applications close in June [1] and the fee is payable on registration [9].';

        self::assertNull(
            $this->binder->bind($answer, self::RETRIEVED),
            'INV-1 breach: an answer with one valid and one invalid citation must be '
            . 'discarded whole. Stripping the bad marker and serving the rest converts '
            . '"the model produced something ungrounded" into "the user received '
            . 'something ungrounded, slightly tidied".'
        );
    }

    public function testValidlyCitedAnswerIsBound(): void
    {
        $bound = $this->binder->bind('Applications close in June. [1]', self::RETRIEVED);

        self::assertNotNull($bound);
        self::assertSame([1 => 101], $bound->citations);
        self::assertSame([101], $bound->chunkIds());
        self::assertSame(1, $bound->citationCount());
    }

    public function testMultipleAndGroupedCitationsResolve(): void
    {
        $bound = $this->binder->bind('The programme runs for four years [1,3] on the main campus [2].', self::RETRIEVED);

        self::assertNotNull($bound);
        // References resolve in ascending reference order, not order of
        // appearance in the prose: [1,3] then [2] yields chunks 101, 102, 103.
        // The binder's job is to prove each reference was retrieved, not to
        // preserve the model's sentence order.
        self::assertSame([101, 102, 103], $bound->chunkIds());
        self::assertSame([1 => 101, 2 => 102, 3 => 103], $bound->citations);
    }

    public function testZeroReferenceIsNotAValidCitation(): void
    {
        self::assertNull(
            $this->binder->bind('The fee is set annually. [0]', self::RETRIEVED),
            'INV-1 breach: [0] is not a reference to anything and must not satisfy '
            . 'the at-least-one-citation requirement.'
        );
    }

    public function testEmptyRetrievedSetCannotProduceAnAnswer(): void
    {
        // Section 6: if retrieval returns nothing above threshold the system
        // refuses. Nothing the generator says can rescue that.
        self::assertNull($this->binder->bind('Here is an answer. [1]', []));
    }
}
