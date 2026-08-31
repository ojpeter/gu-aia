<?php

declare(strict_types=1);

/**
 * Corpus sources (requirements.md Section 5).
 *
 * STUB — deliberately empty of real sources.
 *
 * Phase 0 gates this file: "Content audit with Communications and the Registry.
 * One authoritative source per fact; owners and review dates assigned.
 * NO INDEXING BEFORE THIS COMPLETES. It will take longer than the build."
 * (Section 15.)
 *
 * Populating this file with plausible-looking gu.ac.ug paths before that audit
 * would start indexing content with no owner and no reviewed_at, which INV-11
 * forbids outright — a document without an owning office, a reviewed_at date and
 * a review interval is not indexed.
 */

return [
    'crawl' => [
        // Restricted to the University domain and an allow-list of paths.
        // Login-protected, draft, and archived pages are NEVER crawled.
        'domain' => 'gu.ac.ug',
        'allowed_paths' => [
            // Populated during Phase 0, per path, each with an owning office.
        ],
        'excluded_paths' => [
            // Anything login-protected, draft, or archived.
        ],
        'refresh' => 'nightly',
    ],

    'documents' => [
        // Prospectus, fees structures, academic calendar: PDF text extraction,
        // refreshed on publication and verified weekly.
        //
        // Scanned PDFs without a text layer are REJECTED at ingestion with a
        // report to the owning office — never silently OCR'd into noise
        // (Section 5.2).
        'reject_without_text_layer' => true,
        'sources' => [
            // Registered through the admin console during Phase 0.
        ],
    ],

    // Every document carries these three, or it is not indexed (INV-11).
    'required_metadata' => ['owning_office', 'reviewed_at', 'review_interval_days'],

    // Chunking (Section 5.3): structure-aware, split on headings.
    'chunking' => [
        'target_tokens_min' => 500,
        'target_tokens_max' => 800,
        'overlap_tokens' => 60,

        // Never split a fees table or an entry-requirements list across chunks.
        // Tables are extracted whole and stored with their caption.
        'atomic_blocks' => ['table', 'fees_table', 'entry_requirements_list'],
    ],

    // Where two sources conflict, the one marked authoritative for that category
    // wins, and the conflict is reported to the admin console. Conflicts are a
    // content defect to be fixed, not a retrieval problem to be tuned around.
    'report_conflicts' => true,
];
