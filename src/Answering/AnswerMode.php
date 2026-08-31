<?php

declare(strict_types=1);

namespace GuAia\Answering;

/**
 * How a question is answered. requirements.md Section 7.
 *
 * The distinction between Quoted and Grounded is the whole of INV-2 and is not a
 * presentation detail: Quoted means the authoritative text is returned verbatim
 * and generation is never called for the figure itself. A fees amount that has
 * passed through a language model is a fabricated fees amount, however faithful
 * it looks.
 */
enum AnswerMode: string
{
    /** Authoritative text verbatim, plus a link and the academic year (INV-2). */
    case Quoted = 'quoted';

    /** Generated from retrieved context only, cited (INV-1, INV-5). */
    case Grounded = 'grounded';

    /** Refusal template plus a named human contact (Section 9). */
    case Refuse = 'refuse';

    /**
     * Retrieval-only: links and extracts, no generation. Entered when the budget
     * ceiling is reached or generation times out (INV-8). A real code path, not
     * an error state.
     */
    case Degraded = 'degraded';

    /** Whether this mode is permitted to call the generator at all. */
    public function callsGenerator(): bool
    {
        return $this === self::Grounded;
    }
}
