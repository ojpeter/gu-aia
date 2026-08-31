<?php

declare(strict_types=1);

/**
 * Spend controls (INV-8, requirements.md Section 11).
 *
 * "It never overspends and never silently fails."
 *
 * The budget check runs BEFORE every generation call. On reaching the ceiling
 * the system degrades to retrieval-only — links and extracts, no generation —
 * and alerts. Degraded mode is a tested code path, not a hypothetical: it has
 * its own invariant test and is exercised in CI.
 *
 * STUB — the ceiling is set by the Chief, ICT Services before launch
 * (Section 18, open question 4). Null means "not yet set", and the system must
 * refuse to run generation at all rather than assume an unlimited budget.
 */

return [
    // Monthly ceiling, in the currency agreed with DICTS. Null = not yet set.
    // A null ceiling must fail closed (retrieval-only), never fail open.
    'monthly_ceiling' => null,

    // Alert at 80% of ceiling; degrade at 100%.
    'alert_threshold' => 0.8,
    'degrade_threshold' => 1.0,

    'alert_recipients' => [
        // 'ict@gu.ac.ug',
    ],

    // Generation timeout falls back to retrieval-only results rather than an
    // error page (Section 11).
    'generation_timeout_seconds' => 8,

    // Latency budget: p95 under 4 seconds to first content. Stream if the
    // interface supports it; do not block on a spinner.
    'p95_latency_target_seconds' => 4,

    // Rate limiting, per IP and per session, with a clear message on breach.
    // Values are placeholders pending real traffic data.
    'rate_limit' => [
        'per_ip_per_hour' => 60,
        'per_session_per_hour' => 30,
    ],
];
