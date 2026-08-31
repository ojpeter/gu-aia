<?php

declare(strict_types=1);

/**
 * Retrieval configuration (requirements.md Section 6).
 *
 * STUB — values here are the specified shape, not tuned values. The threshold in
 * particular MUST be tuned against the evaluation set before launch, and
 * "a configuration that never refuses is misconfigured" (Section 6). Do not
 * raise recall by lowering the threshold to make a demo look better; that trades
 * a refusal, which costs a user thirty seconds, for a wrong answer, which does
 * not (Section 0).
 */

return [
    // Candidate generation: MySQL FULLTEXT, BOOLEAN MODE.
    // The user's raw question reaches this clause — it is the highest-risk query
    // in the system. Bind it, and sanitise BOOLEAN MODE operators separately
    // (CLAUDE.md Rule 3).
    'candidate_limit' => 200,

    // Chunks passed to answering after rerank.
    'top_k' => 6,

    // Cosine similarity floor. Below this, retrieval returns NoConfidentContext
    // and the system refuses and hands off (INV-1).
    // NOT TUNED. Placeholder pending the eval set.
    'score_threshold' => null,

    // Programme and course codes are matched exactly and boosted — a user typing
    // a code knows what they want (Section 6).
    'exact_match_boost' => 2.0,
    'code_pattern' => '/\b[A-Z]{2,4}\s?\d{3,4}\b/',

    // Abbreviation expansion is configuration, seeded with the University's own
    // vocabulary. To be authored with Communications and the Registry — the
    // entries below are illustrative of the FORM only and must be replaced with
    // the real institutional vocabulary before use.
    'abbreviations' => [
        // 'fees structure' => ['fees', 'tuition', 'functional fees'],
    ],

    // Category filtering is a safety control, not an optimisation: it is what
    // stops a fees question being answered from a news article. A category leak
    // is a safety defect (CLAUDE.md Rule 6).
    'filter_by_category' => true,
];
