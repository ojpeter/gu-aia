<?php

declare(strict_types=1);

namespace GuAia\Ingestion;

/**
 * One structural element of an extracted document.
 *
 * Extraction produces blocks, not a wall of text, because the chunker's rules
 * are structural: split on headings, and never split a fees table
 * (requirements.md Section 5.3). Neither is expressible over flat text.
 */
final readonly class Block
{
    public const HEADING = 'heading';
    public const PARAGRAPH = 'paragraph';
    public const LIST = 'list';
    public const TABLE = 'table';

    /**
     * @param string      $type    one of the constants above
     * @param int         $level   heading depth (1-6); 0 for everything else
     * @param string|null $caption a table's caption, kept with it and never orphaned
     * @param bool        $atomic  must never be split across chunks
     */
    public function __construct(
        public string $type,
        public string $text,
        public int $level = 0,
        public ?string $caption = null,
        public bool $atomic = false,
        public ?int $pageNumber = null,
    ) {
    }

    public function isHeading(): bool
    {
        return $this->type === self::HEADING;
    }
}
