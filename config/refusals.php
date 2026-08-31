<?php

declare(strict_types=1);

/**
 * Refusal and handoff configuration (requirements.md Section 9).
 *
 * "A refusal is a successful outcome, and it must not read as a failure."
 *
 * Every refusal: says plainly that it cannot answer from the University's
 * published information; names a human contact — office, email, telephone —
 * appropriate to the category; offers the closest relevant page if retrieval
 * found anything at all; and is logged as an unanswered question.
 *
 * STUB — the text below is NOT the final copy.
 *
 * Section 9 is explicit: "Refusal text is configuration, authored with
 * Communications, not written by an engineer." The strings here are structural
 * placeholders so the code has a shape to compile against, and every one must be
 * replaced with Communications-authored copy before launch.
 *
 * The contact details are likewise NOT filled in: publishing a wrong office
 * email or telephone number in a refusal would send a user who already could not
 * get an answer to a dead end. Left null deliberately — the handoff renderer
 * must fail loudly on a null contact rather than emit a refusal with no route.
 */

return [
    'contacts' => [
        'registry' => [
            'office' => 'Office of the Academic Registrar',
            'email' => null,      // Confirm with the Registry before launch.
            'telephone' => null,
            'url' => null,
        ],
        // NOT an office name until somebody confirms it.
        //
        // This entry used to read 'Finance Department', and the widget duly told
        // a visitor asking about fees to "contact Finance Department" — an
        // office requirements.md never names and which may not exist under that
        // title. It was caught by looking at the rendered page, not by any test,
        // because it is not a code defect: it is a fabricated fact, rendered
        // confidently, of exactly the kind this whole project exists to prevent.
        // The same reasoning already kept it out of the seeded `offices` table
        // (see 0006_seed_reference_data.sql); it should never have survived here.
        //
        // Left null so the renderer falls back to a generic phrase and sets
        // handoffMissing, which is visible in the log and the weekly report.
        // Phase 0 supplies the real owner of fees content.
        'finance' => [
            'office' => null,
            'email' => null,
            'telephone' => null,
            'url' => null,
        ],
        'communications' => [
            'office' => 'Directorate of Communications',
            'email' => null,
            'telephone' => null,
            'url' => null,
        ],
        'portal' => [
            'office' => 'Gulu University eServices Portal',
            'email' => null,
            'telephone' => null,
            'url' => null,       // ESERVICES_URL equivalent; confirm before launch.
        ],
        'site_search' => [
            'office' => null,
            'email' => null,
            'telephone' => null,
            'url' => null,       // gu.ac.ug site search.
        ],
    ],

    // PLACEHOLDER COPY — to be replaced by Communications.
    'templates' => [
        // {office} falls back to a generic phrase when no contact is confirmed.
        // The sentence is written so that fallback still reads as a complete,
        // honest sentence rather than an obvious gap — but it is a placeholder
        // either way, and Communications authors the real copy (Section 9).
        'no_confident_context' =>
            'I could not find this in Gulu University\'s published information, '
            . 'so I will not guess. {office} can help.',

        'individual_outcome' =>
            'I cannot say whether any individual will be admitted or will qualify. '
            . 'Only {office} can answer that, using your actual application.',

        'individual_record' =>
            'I cannot see personal records. Please sign in to {office} for anything '
            . 'about your own account.',

        'off_topic' =>
            'That is outside what I can help with — I only answer from Gulu '
            . 'University\'s published information.',

        'unsafe_or_abusive' =>
            'I cannot help with that.',

        'degraded_mode' =>
            'I am currently returning source links rather than written answers. '
            . 'Here is the published information that matches your question.',
    ],

    // Every refusal is logged as an unanswered question and feeds the weekly
    // Unanswered Questions Report (Section 13) — a primary deliverable, being a
    // ranked list of what the public comes to the website looking for and cannot
    // find.
    'log_as_unanswered' => true,
];
