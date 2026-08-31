-- 0003_safety_and_budget.sql
--
-- Rate limiting, throttle logging, and the spend ceiling.
-- requirements.md Section 11, INV-8.

-- ---------------------------------------------------------------------------
-- Rate limiting, per IP and per session (requirements.md Section 11)
--
-- Fixed-window counters keyed on a scope plus a hashed identifier. The window
-- start is part of the unique key so a new window is an INSERT and an existing
-- one an UPDATE, with no read-modify-write race.
-- ---------------------------------------------------------------------------
CREATE TABLE rate_limits (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope           ENUM('ip','session') NOT NULL,
    -- Hashed, never the raw address (docs/data-protection.md, DF-2).
    bucket_key      CHAR(64)        NOT NULL,
    window_start    DATETIME        NOT NULL,
    hit_count       INT UNSIGNED    NOT NULL DEFAULT 0,
    last_hit_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rate_window (scope, bucket_key, window_start),
    -- Expired-window sweep. Note these rows are operational counters, not
    -- content or personal history: they are the one thing that may legitimately
    -- age out, and even so no account holds DELETE — they are cleared by
    -- UPDATE-to-zero or left to age, never removed (INV-12).
    KEY idx_rate_window_start (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Throttle violations
--
-- Log them; never silently 429. A spike here is either abuse worth seeing or a
-- limit set too low, and neither is visible if the refusal is anonymous.
-- ---------------------------------------------------------------------------
CREATE TABLE rate_limit_violations (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope           ENUM('ip','session') NOT NULL,
    bucket_key      CHAR(64)        NOT NULL,
    route           VARCHAR(120)    NOT NULL,
    hit_count       INT UNSIGNED    NOT NULL,
    limit_value     INT UNSIGNED    NOT NULL,
    occurred_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_violation_bucket (bucket_key, occurred_at),
    KEY idx_violation_occurred (occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Budget periods (INV-8)
--
-- "It never overspends and never silently fails."
--
-- One row per calendar month. The budget check runs BEFORE every generation
-- call and reads this row, rather than summing the interaction log on the hot
-- path.
--
-- ceiling_amount is NULL until the Chief, ICT Services sets it (Section 18,
-- open question 4). A NULL ceiling must FAIL CLOSED — retrieval-only — never
-- fail open into unlimited spend. That behaviour belongs in code, but the column
-- is nullable here precisely so that "not yet set" is representable and cannot be
-- confused with zero.
-- ---------------------------------------------------------------------------
CREATE TABLE budget_periods (
    period              CHAR(7)         NOT NULL,  -- 'YYYY-MM'
    ceiling_amount      DECIMAL(12,2)   NULL,
    currency            CHAR(3)         NOT NULL DEFAULT 'USD',
    spend_amount        DECIMAL(12,6)   NOT NULL DEFAULT 0,
    tokens_prompt       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    tokens_completion   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    generation_calls    INT UNSIGNED    NOT NULL DEFAULT 0,
    -- Alert fires once at 80%; degradation begins at 100%.
    alerted_at          DATETIME        NULL,
    degraded_since      DATETIME        NULL,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (period),
    KEY idx_budget_degraded (degraded_since)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
