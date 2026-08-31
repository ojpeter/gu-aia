<?php

declare(strict_types=1);

namespace GuAia\Safety;

/**
 * Refusal intents, matched BEFORE retrieval. requirements.md Section 7, INV-3.
 *
 * "The system never states, predicts, estimates or implies whether a person will
 *  be admitted, will qualify, or will pass."
 *
 * Running before retrieval is the point, and it is what makes INV-3 hold rather
 * than merely being requested in a prompt: if no context is ever fetched for
 * these questions, no amount of retrieved text and no model wording can turn one
 * into an answer. A model asked "will I get in with 12 points" and handed the
 * entry-requirements page will, sooner or later, say something encouraging.
 *
 * TWO KINDS OF MISTAKE, AND THEY ARE NOT EQUAL
 *
 *   Missing an individual-outcome question (under-refusing) risks telling
 *   somebody they will probably be admitted. Section 0: that costs them a term.
 *
 *   Refusing an ordinary question (over-refusing) costs them thirty seconds and
 *   a link to the Registry.
 *
 * So the patterns below lean toward refusing, deliberately — but not without
 * limit, because Section 12 measures refusal PRECISION as well as recall, and an
 * assistant that refuses everything is not an assistant. The negative cases in
 * tests/Invariant/NoIndividualOutcomeTest.php are as load-bearing as the
 * positive ones, and a change that improves recall by wrecking precision should
 * fail that test.
 *
 * JUDGEMENT CALLS, RECORDED SO THEY CAN BE ARGUED WITH
 *
 *   "how do I apply"            -> NOT refused. Process, not outcome.
 *   "can I apply with a diploma" -> REFUSED. Section 7 lists "do I qualify" as an
 *                                   individual outcome, and this is that question
 *                                   with the eligibility rule left implicit.
 *                                   Ambiguity resolves toward saying less.
 *   "what are the entry requirements for BSc Computer Science"
 *                                -> NOT refused. A published rule, not a person.
 */
final class RefusalIntents
{
    /** Section 7: "Will I get in", "do I qualify", "is my application approved". */
    public const INDIVIDUAL_OUTCOME = 'individual_outcome';

    /** Section 7: "What is my balance", "what are my results". Phase 1 refuses. */
    public const INDIVIDUAL_RECORD = 'individual_record';

    /**
     * Individual OUTCOME — a prediction or judgement about a person.
     *
     * @var list<string>
     */
    private const OUTCOME_PATTERNS = [
        // Admission, in the first person, in any tense.
        '/\b(will|would|can|could|shall|am|are|do|did|have|has)\s+(i|we|my\s+\w+|he|she|they|my\s+son|my\s+daughter|my\s+child)\b.{0,40}\b(get\s+in|getting\s+in|be\s+admitted|admitted|admission|make\s+the\s+cut|be\s+taken|be\s+accepted|accepted|be\s+selected|selected)\b/i',
        // Eligibility and qualification, first person.
        '/\b(do|does|will|would|can|could|am|is)\s+(i|we|my\s+\w+|he|she|they)\b.{0,40}\b(qualify|qualified|eligible|eligibility|stand\s+a\s+chance|have\s+a\s+chance)\b/i',
        // "am I eligible", "do I qualify" without an intervening clause.
        '/\b(am\s+i|are\s+we|is\s+he|is\s+she|are\s+they)\s+(eligible|qualified)\b/i',
        '/\bdo\s+(i|we|they)\s+qualify\b/i',
        // Chances, odds, likelihood.
        '/\b(my|our|his|her|their)\s+(chances?|odds|likelihood)\b/i',
        '/\b(what\s+are\s+)?(the\s+)?chances\s+(that\s+)?(i|we|he|she|they)\b/i',
        // Status of a specific application.
        '/\b(is|was|has)\s+my\s+(application|admission|form|name)\b/i',
        '/\bhave\s+i\s+been\s+(admitted|selected|accepted|considered)\b/i',
        '/\bwas\s+i\s+(admitted|selected|accepted|taken)\b/i',
        '/\bmy\s+(application|admission)\s+(status|approved|successful|rejected)\b/i',
        // Passing, progressing, graduating.
        '/\b(will|can|would)\s+(i|we|he|she|they)\b.{0,30}\b(pass|fail|graduate|proceed|progress|retake)\b/i',
        // "with my grades / points / results, ..." — the framing that invites a
        // prediction even when the verb is neutral.
        '/\bwith\s+(my|these|those|his|her|their)\s+(grades?|points?|results?|marks?|score|aggregate|ucer?)\b/i',
        '/\b(my|his|her|their)\s+(points?|grades?|aggregate)\s+(are|is|were|was)\b.{0,40}\b(enough|sufficient|good\s+enough)\b/i',
        // The same question with the verb fronted — "are my points enough",
        // "is my aggregate good enough". Interrogative inversion is the more
        // natural phrasing and was missed by the declarative pattern above.
        '/\b(are|is|were|was)\s+(my|our|his|her|their)\s+(points?|grades?|aggregate|marks?|results?|score)\b.{0,40}\b(enough|sufficient|good\s+enough|competitive)\b/i',
        // Eligibility phrased about a qualification the asker holds.
        '/\b(can|could|may)\s+(i|we|he|she|they)\s+(apply|join|enrol|enroll|be\s+considered)\b.{0,40}\bwith\b/i',
        // "am I good enough", "do I have what it takes"
        '/\bam\s+i\s+good\s+enough\b/i',
        '/\bdo\s+i\s+have\s+what\s+it\s+takes\b/i',
    ];

    /**
     * Individual RECORD — a lookup against a person's own data. Phase 1 has no
     * Portal integration at all (INV-10), so these are refused and routed rather
     * than attempted.
     *
     * @var list<string>
     */
    private const RECORD_PATTERNS = [
        '/\b(my|our)\s+(fees?\s+)?balance\b/i',
        '/\bhow\s+much\s+do\s+i\s+owe\b/i',
        '/\b(my|our)\s+(results?|marks?|transcript|grades?\s+for)\b/i',
        '/\b(my|our)\s+(timetable|time\s+table|exam\s+timetable|registration|registration\s+status)\b/i',
        '/\b(my|our)\s+(student\s+number|registration\s+number|reg\s+no)\b/i',
        '/\bcheck\s+my\s+(results?|balance|status|admission\s+letter)\b/i',
        '/\b(my|our)\s+(admission\s+letter|student\s+portal|account)\b/i',
        '/\bhave\s+i\s+paid\b/i',
    ];

    /**
     * Phrases that look like an individual question but are ordinary requests for
     * published information. Checked FIRST, so that a legitimate question is not
     * refused because it happens to contain the word "I".
     *
     * Kept deliberately narrow: this list is the only way a question escapes the
     * patterns above, so anything added here must be genuinely about a published
     * rule or process rather than about a person.
     *
     * @var list<string>
     */
    private const PROCESS_EXEMPTIONS = [
        '/\bhow\s+(do|can|should)\s+i\s+(apply|register|pay|submit|contact|find|get\s+the\s+form|access)\b/i',
        '/\bwhere\s+(do|can)\s+i\s+(apply|pay|find|get|submit|collect)\b/i',
        '/\bwhen\s+(do|can|should)\s+i\s+(apply|register|pay|submit)\b/i',
        '/\bwhat\s+documents\s+do\s+i\s+need\b/i',
        '/\bwhat\s+do\s+i\s+need\s+to\s+(apply|bring|submit)\b/i',
    ];

    /**
     * Returns the refusal category, or null if the question is not an individual
     * question and may proceed to retrieval.
     */
    public function match(string $query): ?string
    {
        $normalised = $this->normalise($query);

        if ($normalised === '') {
            return null;
        }

        // An explicit request for process information is not a request for a
        // prediction, even when it is phrased in the first person.
        foreach (self::PROCESS_EXEMPTIONS as $pattern) {
            if (preg_match($pattern, $normalised) === 1) {
                return null;
            }
        }

        // RECORD before OUTCOME, deliberately. Record patterns are the narrower
        // of the two — they name a specific piece of the asker's own data ("my
        // admission letter", "my balance") — whereas the outcome patterns are
        // broad by design. Checked the other way round, "can I see my admission
        // letter" matched the outcome pattern on "can I ... admission" and was
        // routed to the Registry instead of the Portal. Both refuse, so INV-3
        // held either way, but the user was sent to the wrong place, and a
        // refusal that misdirects is a refusal that failed (Section 9).
        foreach (self::RECORD_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalised) === 1) {
                return self::INDIVIDUAL_RECORD;
            }
        }

        foreach (self::OUTCOME_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalised) === 1) {
                return self::INDIVIDUAL_OUTCOME;
            }
        }

        return null;
    }

    /**
     * Casefold, strip punctuation that would break word boundaries, and collapse
     * whitespace. Deliberately conservative: it must not "helpfully" rewrite the
     * question into something that no longer matches.
     */
    private function normalise(string $query): string
    {
        $q = mb_strtolower(trim($query), 'UTF-8');
        // Keep letters, digits and spaces; turn everything else into a space so
        // that "will i get in?" and "will-i-get-in" both match.
        $q = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $q) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $q) ?? '');
    }
}
