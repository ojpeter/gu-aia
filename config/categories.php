<?php

declare(strict_types=1);

/**
 * Answer categories and their modes (requirements.md Section 7).
 *
 * The category router runs BEFORE retrieval and determines the answering mode.
 * Refusal intents in particular are matched before retrieval, so that no amount
 * of retrieved context can turn an individual-outcome question into an answer
 * (INV-3).
 *
 * STUB — the modes and routing below are the specification. The classifier
 * itself is not built. Refusal and handoff text is authored with Communications,
 * not by an engineer (Section 9), and lives in config/refusals.php.
 */

// Modes, matching GuAia\Answering\AnswerMode:
//   quoted   - authoritative text verbatim + link; generation is never called
//              for the figure itself (INV-2)
//   grounded - generated, cited, from retrieved context only (INV-1, INV-5)
//   refuse   - refusal template + named human contact (Section 9)
//
// Written as plain strings rather than file-scope constants so this file can be
// require()d more than once in a process without a redeclaration error.

return [
    'categories' => [
        'fees' => [
            'mode' => 'quoted',
            // Return the authoritative fees text and table verbatim, with the
            // link and the effective academic year. No generated figure, ever.
            'requires_academic_year' => true,
            'handoff' => 'finance',
        ],
        'entry_requirements' => [
            'mode' => 'quoted',
            'requires_academic_year' => true,
            'handoff' => 'registry',
        ],
        'deadlines_calendar' => [
            'mode' => 'quoted',
            'requires_academic_year' => true,
            'handoff' => 'registry',
        ],
        'application_process' => [
            'mode' => 'grounded',
            'handoff' => 'registry',
        ],
        'programme_information' => [
            'mode' => 'grounded',
            'handoff' => 'registry',
        ],
        'contact_directions' => [
            'mode' => 'grounded',
            'handoff' => 'communications',
        ],
        'individual_outcome' => [
            // "Will I get in", "do I qualify", "is my application approved".
            // Matched before retrieval. Never answered, never estimated,
            // never implied (INV-3).
            'mode' => 'refuse',
            'handoff' => 'registry',
        ],
        'individual_record' => [
            // "What is my balance", "what are my results".
            // Refused in Phase 1 — there is no Portal integration surface at all
            // in this codebase (INV-10).
            'mode' => 'refuse',
            'handoff' => 'portal',
        ],
        'off_topic' => [
            'mode' => 'refuse',
            'handoff' => 'site_search',
        ],
        'unsafe_or_abusive' => [
            // Templated refusal, logged, rate-limited. Nothing is generated in
            // response (Section 11).
            'mode' => 'refuse',
            'handoff' => null,
        ],
    ],

    // Uncategorised questions default to Grounded, and default to refusal when
    // retrieval is weak. Ambiguity resolves toward saying less (Section 7).
    'default_mode' => 'grounded',
];
