<?php

declare(strict_types=1);

namespace GuAia\Ingestion;

/**
 * Structure-aware chunking. requirements.md Section 5.3.
 *
 *   "Split on headings, then to a target of roughly 500-800 tokens with a small
 *    overlap. NEVER SPLIT A FEES TABLE OR AN ENTRY-REQUIREMENTS LIST ACROSS
 *    CHUNKS. Tables are extracted whole and stored with their caption."
 *
 * The atomic-block rule is a safety rule, not a formatting preference, and it is
 * the reason this class exists rather than a naive character-window splitter.
 * Half a fees table is not a smaller answer; it is a wrong one. A chunk
 * containing the first four programmes of a fee schedule looks complete, cites
 * cleanly, and quotes an amount that belongs to a different programme than the
 * one the reader asked about. That is risk R-1 arriving through the back door,
 * past INV-2, because the figure was never generated — it was retrieved, from a
 * chunk that should never have existed.
 *
 * So an atomic block becomes its own chunk whatever its size, and it keeps its
 * caption, because a fee table without "Faculty of Medicine, 2026/27" attached
 * is an amount with nothing to bind it to.
 *
 * TOKEN COUNTING: approximated by whitespace-delimited words. There is no
 * tokenizer here, and installing one to serve a 500-800 target that Section 5.3
 * itself calls "roughly" would be precision theatre. The approximation runs
 * about 25-35% under a real BPE count for English prose, so the effective window
 * is conservative — chunks come out smaller than the target rather than larger,
 * which is the safe direction.
 */
final class Chunker
{
    public function __construct(
        private readonly int $targetTokensMin = 500,
        private readonly int $targetTokensMax = 800,
        private readonly int $overlapTokens = 60,
    ) {
    }

    /**
     * @param list<Block> $blocks
     *
     * @return list<Chunk>
     */
    public function chunk(array $blocks): array
    {
        /** @var list<Chunk> $chunks */
        $chunks = [];

        /** @var list<string> $headingPath */
        $headingPath = [];

        /** @var list<Block> $pending */
        $pending = [];
        $pendingTokens = 0;

        $flush = function () use (&$pending, &$pendingTokens, &$chunks, &$headingPath): void {
            if ($pending === []) {
                return;
            }
            $chunks[] = $this->makeChunk($pending, $headingPath);
            $pending = [];
            $pendingTokens = 0;
        };

        foreach ($blocks as $block) {
            if ($block->isHeading()) {
                // A heading starts a new section, so whatever was accumulating
                // belongs to the previous one.
                $flush();
                $headingPath = $this->updateHeadingPath($headingPath, $block);
                continue;
            }

            if ($block->atomic) {
                // Never merged, never split, never sharing a chunk with prose
                // that could push it over a boundary.
                $flush();
                $chunks[] = new Chunk(
                    body: $this->renderAtomic($block),
                    headingPath: $headingPath,
                    pageNumber: $block->pageNumber,
                    caption: $block->caption,
                    isAtomicBlock: true,
                    atomicBlockKind: $block->type,
                    tokenCount: $this->countTokens($block->text),
                );
                continue;
            }

            $blockTokens = $this->countTokens($block->text);

            if ($pendingTokens > 0 && $pendingTokens + $blockTokens > $this->targetTokensMax) {
                $carry = $this->overlapFrom($pending);
                $flush();
                if ($carry !== null) {
                    $pending[] = $carry;
                    $pendingTokens = $this->countTokens($carry->text);
                }
            }

            $pending[] = $block;
            $pendingTokens += $blockTokens;

            if ($pendingTokens >= $this->targetTokensMin) {
                $carry = $this->overlapFrom($pending);
                $flush();
                if ($carry !== null) {
                    $pending[] = $carry;
                    $pendingTokens = $this->countTokens($carry->text);
                }
            }
        }

        $flush();

        // The overlap carry can leave a final chunk that is nothing but the
        // repeated tail of the one before it. It adds no information and would
        // compete with its own source in retrieval.
        return $this->dropRedundantTail($chunks);
    }

    /**
     * @param list<Block>  $blocks
     * @param list<string> $headingPath
     */
    private function makeChunk(array $blocks, array $headingPath): Chunk
    {
        $text = implode("\n\n", array_map(static fn (Block $b): string => trim($b->text), $blocks));
        $page = null;
        foreach ($blocks as $b) {
            if ($b->pageNumber !== null) {
                $page = $b->pageNumber;
                break;
            }
        }

        return new Chunk(
            body: trim($text),
            headingPath: $headingPath,
            pageNumber: $page,
            tokenCount: $this->countTokens($text),
        );
    }

    /**
     * A table is stored with its caption, because a fee amount with nothing
     * naming what it is for is worse than no amount.
     */
    private function renderAtomic(Block $block): string
    {
        if ($block->caption === null || trim($block->caption) === '') {
            return trim($block->text);
        }

        return trim($block->caption) . "\n\n" . trim($block->text);
    }

    /**
     * @param list<string> $path
     *
     * @return list<string>
     */
    private function updateHeadingPath(array $path, Block $heading): array
    {
        $level = max(1, $heading->level);
        $path = array_slice($path, 0, $level - 1);
        $path[] = trim($heading->text);

        /** @var list<string> $path */
        return array_values($path);
    }

    /**
     * The small overlap Section 5.3 asks for: carry the last block forward if it
     * is small enough to be context rather than duplication.
     *
     * @param list<Block> $pending
     */
    private function overlapFrom(array $pending): ?Block
    {
        if ($this->overlapTokens <= 0 || $pending === []) {
            return null;
        }

        $last = $pending[count($pending) - 1];
        if ($last->atomic || $this->countTokens($last->text) > $this->overlapTokens) {
            return null;
        }

        return $last;
    }

    /**
     * @param list<Chunk> $chunks
     *
     * @return list<Chunk>
     */
    private function dropRedundantTail(array $chunks): array
    {
        $count = count($chunks);
        if ($count < 2) {
            return $chunks;
        }

        $last = $chunks[$count - 1];
        $previous = $chunks[$count - 2];

        if (!$last->isAtomicBlock && str_contains($previous->body, $last->body)) {
            array_pop($chunks);
        }

        return $chunks;
    }

    private function countTokens(string $text): int
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $trimmed) ?: []);
    }
}
