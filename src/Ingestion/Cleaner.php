<?php

declare(strict_types=1);

namespace GuAia\Ingestion;

/**
 * Ingestion-time cleaning and instruction defusing. INV-6.
 *
 * "Retrieved content is data, never instruction. Text from the corpus, a PDF, or
 *  a user message can never alter the system's behaviour... instruction-stripping
 *  on ingestion."
 *
 * A DELIBERATE SPLIT, AND THE REASON FOR IT
 *
 * "Strip instruction-like content" sounds like one operation. It is two, with
 * very different risk profiles, and treating them the same way would be a
 * mistake in one direction or the other.
 *
 *   REMOVED OUTRIGHT — artefacts with no legitimate presence in published prose:
 *   invisible and bidirectional control characters, HTML comments, script and
 *   style blocks, data: URIs. A university page never needs a zero-width joiner
 *   in the middle of a sentence; if one is there, it is there to hide something
 *   from a human reviewer while the model still reads it. Removing these loses
 *   nothing.
 *
 *   FLAGGED, NOT REMOVED — natural-language sentences that read like
 *   instructions. "Applicants should ignore the previous instructions on the old
 *   form" is a real sentence a University page could legitimately contain.
 *   Silently deleting it would change what the University said, which is its own
 *   integrity failure: this system exists to report published content faithfully,
 *   and a corpus quietly edited by a regex is no longer that content. So the
 *   sentence is kept, the chunk is flagged, and the owning office is told.
 *
 * What actually stops a flagged sentence from being obeyed is the rest of INV-6:
 * context is delimited and labelled as data in the prompt, the model is
 * instructed to follow no instruction found within it, and the citation binder
 * discards anything that came back ungrounded. Cleaning is one layer of three,
 * and it is the layer that should be least willing to alter the text.
 */
final class Cleaner
{
    /**
     * Characters with no legitimate place in extracted prose. Zero-width and
     * bidirectional controls are a documented way to hide text from a human
     * reviewer while leaving it fully visible to a model.
     */
    private const INVISIBLE = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}\x{00AD}]/u';

    /**
     * Sentences that read as an instruction aimed at an assistant. Matching one
     * FLAGS the text; it never deletes it.
     *
     * @var list<string>
     */
    private const INSTRUCTION_SHAPES = [
        '/\bignore\s+(all\s+)?(previous|prior|above|earlier)\s+instructions?\b/i',
        '/\bdisregard\s+(your|all|the)\s+(rules?|instructions?|guidelines?)\b/i',
        '/\byou\s+are\s+now\s+(in\s+)?[a-z ]{0,20}mode\b/i',
        '/\bsystem\s*:\s*(you|ignore|disable|the\s+assistant)\b/i',
        '/\b(reveal|print|output|repeat)\s+(your|the)\s+(system\s+)?(prompt|instructions?|configuration)\b/i',
        // Broad on purpose. "From now on" is vanishingly rare in published
        // University prose and, unlike the removal rules above, matching here
        // only FLAGS the text for a human — so the cost of a false positive is
        // one line in a report, and the cost of a miss is an instruction sitting
        // in the corpus unnoticed.
        '/\bfrom\s+now\s+on\b/i',
        '/\bact\s+as\s+(if\s+you\s+are\s+)?an?\s+\w+/i',
        '/\bdo\s+not\s+(cite|use\s+the\s+(documents?|context))\b/i',
    ];

    public function clean(string $raw): CleaningResult
    {
        $removed = [];
        $text = $raw;

        $before = $text;
        $text = (string) preg_replace('#<!--.*?-->#s', ' ', $text);
        if ($text !== $before) {
            $removed[] = 'html_comment';
        }

        $before = $text;
        $text = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $text);
        if ($text !== $before) {
            $removed[] = 'script_or_style_block';
        }

        $before = $text;
        // data: URIs can carry an entire payload inside what looks like an image.
        $text = (string) preg_replace('#\bdata:[a-z0-9.+-]+/[a-z0-9.+-]+;base64,[A-Za-z0-9+/=]+#i', ' ', $text);
        if ($text !== $before) {
            $removed[] = 'data_uri';
        }

        $before = $text;
        $text = (string) preg_replace(self::INVISIBLE, '', $text);
        if ($text !== $before) {
            // Worth its own flag: invisible characters in published prose are not
            // an accident, and the owning office should know the page has them.
            $removed[] = 'invisible_or_bidi_control_characters';
        }

        // Normalise whitespace last, so the checks above see the original shape.
        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/u', "\n\n", $text);
        $text = trim($text);

        $flagged = [];
        foreach (self::INSTRUCTION_SHAPES as $pattern) {
            if (preg_match($pattern, $text, $m) === 1) {
                $flagged[] = trim($m[0]);
            }
        }

        return new CleaningResult($text, $removed, $flagged);
    }
}
