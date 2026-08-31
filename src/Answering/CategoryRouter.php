<?php

declare(strict_types=1);

namespace GuAia\Answering;

use GuAia\Safety\RefusalIntents;

/**
 * The category router. requirements.md Section 7.
 *
 * "The category router runs BEFORE retrieval and determines the answering mode."
 *
 * Order is the safety property here, not an implementation detail:
 *
 *   1. Refusal intents (INV-3, INV-10). Matched first, so that an
 *      individual-outcome question never reaches retrieval and therefore can
 *      never be answered from context.
 *   2. High-stakes categories -> Quoted (INV-2). Fees, entry requirements and
 *      deadlines never pass through generation.
 *   3. Everything else -> Grounded, cited.
 *
 * "Uncategorised questions default to Grounded, and default to refusal when
 *  retrieval is weak. Ambiguity resolves toward saying less."
 *
 * The keyword lists are the deliberate Phase 1 implementation, not a placeholder
 * for a model. A classifier that is 97% accurate is 3% wrong about whether a
 * question is about fees, and being wrong about that means answering a fees
 * question from a news article. Rules are auditable, testable, and correctable
 * by the office that spots the mistake.
 */
final class CategoryRouter
{
    /**
     * Category keys that must never be generated (INV-2), with the terms that
     * route to them. Checked in this order: a question mentioning both a
     * deadline and a fee is a fees question, because the fees figure is the part
     * that costs someone a term if it is wrong.
     *
     * @var array<string, list<string>>
     */
    private const QUOTED_TERMS = [
        'fees' => [
            'fees', 'fee', 'tuition', 'functional fees', 'how much does it cost',
            'cost of the programme', 'cost of the course', 'pay per semester',
            'fees structure', 'tuition structure', 'private sponsorship',
            // "How much does the course cost" reached Grounded on the list
            // above, which would have sent a fees question through generation.
            // These cover the money question however it is phrased; the risk of
            // over-matching into Quoted is a link instead of a paragraph, while
            // the risk of under-matching is an invented figure (R-1).
            'how much does', 'how much is', 'how much are', 'how much will',
            'course cost', 'programme cost', 'program cost', 'cost of study',
        ],
        'entry_requirements' => [
            'entry requirement', 'entry requirements', 'admission requirement',
            'admission requirements', 'cut off', 'cut-off', 'cutoff',
            'minimum points', 'weighting', 'subject combination',
            'what do i need to study', 'requirements for admission',
        ],
        'deadlines_calendar' => [
            'deadline', 'closing date', 'last date', 'when does the semester',
            'academic calendar', 'when do applications close',
            'when do applications open', 'reporting date', 'orientation date',
        ],
    ];

    /**
     * Grounded categories. Generated, but cited and from retrieved context only.
     *
     * @var array<string, list<string>>
     */
    private const GROUNDED_TERMS = [
        'application_process' => [
            'how do i apply', 'how to apply', 'application process', 'apply online',
            'application form', 'where do i apply', 'submit my application',
        ],
        'contact_directions' => [
            'contact', 'phone number', 'email address', 'where is the university',
            'directions', 'how do i get to', 'located', 'campus address',
        ],
        'programme_information' => [
            'programme', 'program', 'course', 'degree', 'diploma', 'bachelor',
            'master', 'phd', 'faculty', 'department', 'duration of',
        ],
    ];

    public function __construct(
        private readonly RefusalIntents $refusalIntents = new RefusalIntents(),
    ) {
    }

    public function route(string $query): Routing
    {
        // 1. Individual questions are refused before retrieval. INV-3.
        $intent = $this->refusalIntents->match($query);
        if ($intent !== null) {
            return new Routing($intent, AnswerMode::Refuse, refusedBeforeRetrieval: true);
        }

        $normalised = $this->normalise($query);

        // 2. High-stakes categories are quoted, never generated. INV-2.
        foreach (self::QUOTED_TERMS as $category => $terms) {
            if ($this->containsAny($normalised, $terms)) {
                return new Routing($category, AnswerMode::Quoted);
            }
        }

        // 3. Grounded categories.
        foreach (self::GROUNDED_TERMS as $category => $terms) {
            if ($this->containsAny($normalised, $terms)) {
                return new Routing($category, AnswerMode::Grounded);
            }
        }

        // Uncategorised defaults to Grounded — and to refusal later, if
        // retrieval comes back weak (Section 6). Saying less is the default
        // everywhere ambiguity survives.
        return new Routing(null, AnswerMode::Grounded);
    }

    /** @param list<string> $terms */
    private function containsAny(string $haystack, array $terms): bool
    {
        foreach ($terms as $term) {
            if (str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    private function normalise(string $query): string
    {
        $q = mb_strtolower(trim($query), 'UTF-8');
        $q = preg_replace('/[^\p{L}\p{N}\-]+/u', ' ', $q) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $q) ?? '');
    }
}
