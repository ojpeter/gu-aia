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

const MODE_QUOTED   = 'quoted';   // Authoritative text verbatim + link. No generation of the figure (INV-2).
const MODE_GROUNDED = 'grounded'; // Generated, cited, from retrieved context only (INV-1, INV-5).
const MODE_REFUSE   = 'refuse';   // Refusal template + named human contact (Section 9).

return [
    'categories' => [
        'fees' => [
            'mode' => MODE_QUOTED,
            // Return the authoritative fees text and table verbatim, with the
            // link and the effective academic year. No generated figure, ever.
            'requires_academic_year' => true,
            'handoff' => 'finance',
        ],
        'entry_requirements' => [
            'mode' => MODE_QUOTED,
            'requires_academic_year' => true,
            'handoff' => 'registry',
        ],
        'deadlines_calendar' => [
            'mode' => MODE_QUOTED,
            'requires_academic_year' => true,
            'handoff' => 'registry',
        ],
        'application_process' => [
            'mode' => MODE_GROUNDED,
            'handoff' => 'registry',
        ],
        'programme_information' => [
            'mode' => MODE_GROUNDED,
            'handoff' => 'registry',
        ],
        'contact_directions' => [
            'mode' => MODE_GROUNDED,
            'handoff' => 'communications',
        ],
        'individual_outcome' => [
            // "Will I get in", "do I qualify", "is my application approved".
            // Matched before retrieval. Never answered, never estimated,
            // never implied (INV-3).
            'mode' => MODE_REFUSE,
            'handoff' => 'registry',
        ],
        'individual_record' => [
            // "What is my balance", "what are my results".
            // Refused in Phase 1 — there is no Portal integration surface at all
            // in this codebase (INV-10).
            'mode' => MODE_REFUSE,
            'handoff' => 'portal',
        ],
        'off_topic' => [
            'mode' => MODE_REFUSE,
            'handoff' => 'site_search',
        ],
        'unsafe_or_abusive' => [
            // Templated refusal, logged, rate-limited. Nothing is generated in
            // response (Section 11).
            'mode' => MODE_REFUSE,
            'handoff' => null,
        ],
    ],

    // Uncategorised questions default to Grounded, and default to refusal when
    // retrieval is weak. Ambiguity resolves toward saying less (Section 7).
    'default_mode' => MODE_GROUNDED,
];
