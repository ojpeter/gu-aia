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
        'finance' => [
            'office' => 'Finance Department',
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
        'no_confident_context' =>
            'I could not find this in Gulu University\'s published information, '
            . 'so I will not guess. Please contact {office}.',

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
