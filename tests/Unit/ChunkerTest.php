<?php

declare(strict_types=1);

namespace GuAia\Tests\Unit;

use GuAia\Ingestion\Block;
use GuAia\Ingestion\Chunker;
use PHPUnit\Framework\TestCase;

/**
 * requirements.md Section 5.3.
 *
 * The atomic-block tests carry a safety property, not a formatting preference.
 * Half a fees table is not a smaller answer, it is a wrong one: a chunk holding
 * the first four rows of a fee schedule looks complete, cites cleanly, and quotes
 * an amount belonging to a different programme than the one asked about. That is
 * risk R-1 arriving past INV-2, because the figure was never generated — it was
 * retrieved from a chunk that should never have existed.
 */
final class ChunkerTest extends TestCase
{
    public function testAFeesTableIsNeverSplitAndKeepsItsCaption(): void
    {
        $table = str_repeat("Programme | Tuition | Functional\n", 400);

        $chunks = (new Chunker())->chunk([
            new Block(Block::HEADING, 'Fees', 1),
            new Block(Block::PARAGRAPH, 'The following fees apply.'),
            new Block(
                type: Block::TABLE,
                text: $table,
                caption: 'Tuition and functional fees, 2026/27',
                atomic: true,
            ),
        ]);

        $tableChunks = array_values(array_filter(
            $chunks,
            static fn ($c): bool => $c->isAtomicBlock
        ));

        self::assertCount(
            1,
            $tableChunks,
            'Section 5.3: a fees table is extracted whole and never split, however large.'
        );
        self::assertStringContainsString(
            'Tuition and functional fees, 2026/27',
            $tableChunks[0]->body,
            'A fee amount with nothing naming what it is for is worse than no amount.'
        );
        self::assertSame(Block::TABLE, $tableChunks[0]->atomicBlockKind);
    }

    public function testAnEntryRequirementsListIsNeverSplit(): void
    {
        $list = str_repeat("- Two principal passes at A level\n", 300);

        $chunks = (new Chunker())->chunk([
            new Block(Block::HEADING, 'Entry requirements', 1),
            new Block(type: Block::LIST, text: $list, atomic: true),
        ]);

        $atomic = array_values(array_filter($chunks, static fn ($c): bool => $c->isAtomicBlock));
        self::assertCount(1, $atomic);
        self::assertSame(Block::LIST, $atomic[0]->atomicBlockKind);
    }

    public function testAnAtomicBlockNeverSharesAChunkWithSurroundingProse(): void
    {
        $chunks = (new Chunker())->chunk([
            new Block(Block::PARAGRAPH, 'Fees are reviewed annually by Council.'),
            new Block(type: Block::TABLE, text: "A | B\n1 | 2", caption: 'Fees', atomic: true),
            new Block(Block::PARAGRAPH, 'Payment is due on registration.'),
        ]);

        foreach ($chunks as $chunk) {
            if ($chunk->isAtomicBlock) {
                self::assertStringNotContainsString('reviewed annually', $chunk->body);
                self::assertStringNotContainsString('due on registration', $chunk->body);
            }
        }
    }

    public function testHeadingsStartNewChunksAndBuildAHeadingPath(): void
    {
        $chunks = (new Chunker())->chunk([
            new Block(Block::HEADING, 'Admissions', 1),
            new Block(Block::PARAGRAPH, 'How to apply to the University.'),
            new Block(Block::HEADING, 'Undergraduate', 2),
            new Block(Block::PARAGRAPH, 'Undergraduate applications open in May.'),
        ]);

        self::assertCount(2, $chunks);
        self::assertSame(['Admissions'], $chunks[0]->headingPath);
        self::assertSame(['Admissions', 'Undergraduate'], $chunks[1]->headingPath);
        self::assertSame('Admissions > Undergraduate', $chunks[1]->headingPathString());
    }

    public function testASiblingHeadingReplacesRatherThanNests(): void
    {
        $chunks = (new Chunker())->chunk([
            new Block(Block::HEADING, 'Admissions', 1),
            new Block(Block::HEADING, 'Undergraduate', 2),
            new Block(Block::PARAGRAPH, 'First.'),
            new Block(Block::HEADING, 'Postgraduate', 2),
            new Block(Block::PARAGRAPH, 'Second.'),
        ]);

        self::assertSame(['Admissions', 'Undergraduate'], $chunks[0]->headingPath);
        self::assertSame(['Admissions', 'Postgraduate'], $chunks[1]->headingPath);
    }

    public function testLongProseIsSplitTowardTheTargetWindow(): void
    {
        $paragraph = str_repeat('word ', 120);
        $blocks = [new Block(Block::HEADING, 'Prospectus', 1)];
        for ($i = 0; $i < 12; $i++) {
            $blocks[] = new Block(Block::PARAGRAPH, $paragraph);
        }

        $chunks = (new Chunker())->chunk($blocks);

        self::assertGreaterThan(1, count($chunks), 'Long prose must be split.');
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(
                1000,
                $chunk->tokenCount,
                'A prose chunk should stay near the 500-800 window; only atomic blocks may exceed it.'
            );
        }
    }

    public function testPageNumbersSurviveChunking(): void
    {
        $chunks = (new Chunker())->chunk([
            new Block(Block::HEADING, 'Calendar', 1),
            new Block(type: Block::PARAGRAPH, text: 'Semester one begins in August.', pageNumber: 14),
        ]);

        self::assertSame(14, $chunks[0]->pageNumber);
    }

    public function testEmptyInputProducesNoChunks(): void
    {
        self::assertSame([], (new Chunker())->chunk([]));
    }

    public function testHeadingsAloneProduceNoChunks(): void
    {
        // A heading with no content under it is a navigation artefact, not a
        // fact, and indexing it would put an empty citation into the corpus.
        $chunks = (new Chunker())->chunk([
            new Block(Block::HEADING, 'Contact', 1),
            new Block(Block::HEADING, 'Main Campus', 2),
        ]);

        self::assertSame([], $chunks);
    }
}
