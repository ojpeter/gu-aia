<?php

declare(strict_types=1);

namespace GuAia\Answering;

/**
 * The citation binder. requirements.md Section 8, INV-1.
 *
 * "After generation, the citation binder verifies that every cited reference
 *  exists in the retrieved set and that the answer contains at least one
 *  citation. A response failing either check is DISCARDED, NOT REPAIRED, and the
 *  refusal template is served in its place."
 *
 * This class is the mechanical half of INV-1, and it exists because the prompt
 * contract alone is not a control. A prompt asks the model to cite; this refuses
 * to serve the answer when it did not. The distinction matters most in exactly
 * the case that is hardest to notice — a fluent, plausible, entirely uncited
 * paragraph about fees.
 *
 * DISCARD, NEVER REPAIR. It is tempting to strip an invalid citation marker and
 * serve the rest, or to re-ask the model. Both convert "the model produced
 * something ungrounded" into "the user received something ungrounded, slightly
 * tidied". The only safe output for a failed check is the refusal template.
 */
final class CitationBinder
{
    /**
     * Reference markers the generator is instructed to emit: [1], [2], [1,2].
     * Deliberately strict — a loose pattern would accept the model's improvised
     * formats and thereby accept unverifiable claims.
     */
    private const REFERENCE_PATTERN = '/\[(\d+(?:\s*,\s*\d+)*)\]/';

    /**
     * @param array<int, int> $referenceMap reference number => chunk id, built
     *                                      from the retrieved set that was
     *                                      actually passed to the generator
     *
     * @return BoundAnswer|null null means DISCARD and serve the refusal template
     */
    public function bind(string $answer, array $referenceMap): ?BoundAnswer
    {
        $trimmed = trim($answer);

        if ($trimmed === '') {
            return null;
        }

        $cited = $this->extractReferences($trimmed);

        // INV-1: an answer with no citation has no source, so it is not an
        // answer this system is permitted to serve.
        if ($cited === []) {
            return null;
        }

        $chunkIds = [];
        foreach ($cited as $reference) {
            // A reference to something that was never retrieved is a fabricated
            // source. That is worse than no citation, because it looks verified.
            if (!array_key_exists($reference, $referenceMap)) {
                return null;
            }
            $chunkIds[$reference] = $referenceMap[$reference];
        }

        return new BoundAnswer($trimmed, $chunkIds);
    }

    /**
     * @return list<int> distinct reference numbers, in ascending order
     */
    private function extractReferences(string $answer): array
    {
        if (preg_match_all(self::REFERENCE_PATTERN, $answer, $matches) === 0) {
            return [];
        }

        $references = [];
        foreach ($matches[1] as $group) {
            foreach (explode(',', $group) as $number) {
                $number = (int) trim($number);
                // [0] is not a valid reference; treat it as absent rather than
                // silently accepting it.
                if ($number > 0) {
                    $references[$number] = true;
                }
            }
        }

        $list = array_keys($references);
        sort($list);

        /** @var list<int> $list */
        return $list;
    }
}
