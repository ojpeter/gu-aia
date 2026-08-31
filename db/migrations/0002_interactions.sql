-- 0002_interactions.sql
--
-- Interaction logging, feedback and the unanswered-question stream.
-- requirements.md Section 13, INV-7, INV-12.
--
-- "InteractionLogger writes in the same transaction as the response is served."
-- A response served without its log entry is a bug, so every column the logger
-- needs exists here from the start rather than being bolted on later.
--
-- These tables hold PERSONAL DATA. A question can identify the person who asked
-- it even with no login (docs/data-protection.md, DF-1). Therefore:
--   - the raw IP is never stored, only a keyed hash, which is all rate limiting
--     actually needs;
--   - retention expiry REDACTS (redacted_at set, text blanked) rather than
--     deleting, so a past answer stays reconstructible for a complaint (INV-12);
--   - no account on this schema is granted DELETE.

CREATE TABLE interactions (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- One correlation ID ties the query, the retrieved set, the answer, the
    -- citations and the feedback together (Section 13).
    correlation_id      CHAR(36)        NOT NULL,
    created_at          DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    query_text          VARCHAR(2000)   NOT NULL,
    normalised_query    VARCHAR(2000)   NULL,
    query_language      VARCHAR(20)     NULL,

    category_key        VARCHAR(40)     NULL,
    mode                ENUM('quoted','grounded','refuse','degraded') NOT NULL,

    model               VARCHAR(120)    NULL,
    prompt_version      VARCHAR(60)     NULL,

    answer              MEDIUMTEXT      NULL,

    -- A refusal is a successful outcome (Section 9), so it is a first-class
    -- recorded state, not an error.
    refusal_reason      VARCHAR(120)    NULL,

    -- INV-8: set when the budget ceiling or a generation timeout forced
    -- retrieval-only. Degraded mode is a tested code path, and this is how a
    -- test and an operator can both see it happened.
    degraded            TINYINT(1)      NOT NULL DEFAULT 0,
    degraded_reason     VARCHAR(120)    NULL,

    latency_ms          INT UNSIGNED    NULL,
    tokens_prompt       INT UNSIGNED    NULL,
    tokens_completion   INT UNSIGNED    NULL,
    cost                DECIMAL(12,6)   NULL,

    -- Technical/abuse data (docs/data-protection.md, DF-2). Keyed hash, never
    -- the address itself.
    ip_hash             CHAR(64)        NULL,
    session_id          CHAR(64)        NULL,

    -- INV-12: retention expiry redacts, it does not delete.
    redacted_at         DATETIME        NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_interactions_correlation (correlation_id),
    KEY idx_interactions_created (created_at),
    KEY idx_interactions_category (category_key),
    KEY idx_interactions_mode (mode),
    KEY idx_interactions_refusal (refusal_reason),
    -- Rate limiting and abuse review.
    KEY idx_interactions_ip (ip_hash, created_at),
    KEY idx_interactions_session (session_id, created_at),
    -- Retention sweep.
    KEY idx_interactions_redaction (redacted_at, created_at),
    -- Cost roll-up for the budget check (INV-8).
    KEY idx_interactions_cost (created_at, cost),
    CONSTRAINT fk_interactions_category FOREIGN KEY (category_key)
        REFERENCES categories (category_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- What retrieval returned, and with what score (Section 13)
--
-- Kept as rows rather than a JSON blob so that retrieval quality can actually be
-- analysed — which chunks win, which never surface, what scores cluster near the
-- threshold. That analysis is how the threshold gets tuned against the eval set
-- rather than by feel (Section 6).
-- ---------------------------------------------------------------------------
CREATE TABLE interaction_retrievals (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    interaction_id  BIGINT UNSIGNED NOT NULL,
    chunk_id        BIGINT UNSIGNED NOT NULL,
    rank_position   SMALLINT UNSIGNED NOT NULL,
    score           DECIMAL(8,6)    NOT NULL,
    -- Whether this chunk was passed to answering, as opposed to being a
    -- candidate that lost the rerank.
    passed_to_answer TINYINT(1)     NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_retrieval_rank (interaction_id, rank_position),
    KEY idx_retrieval_interaction (interaction_id),
    KEY idx_retrieval_chunk (chunk_id),
    CONSTRAINT fk_retrieval_interaction FOREIGN KEY (interaction_id)
        REFERENCES interactions (id),
    CONSTRAINT fk_retrieval_chunk FOREIGN KEY (chunk_id)
        REFERENCES chunks (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Citations actually bound to the answer (requirements.md Section 8, INV-1)
--
-- The citation binder verifies that every cited reference exists in the
-- retrieved set and that at least one citation is present. A response failing
-- either check is discarded, not repaired. These rows are what makes INV-1
-- auditable after the fact: an interaction with mode='grounded' and zero rows
-- here is, by definition, a violation.
-- ---------------------------------------------------------------------------
CREATE TABLE interaction_citations (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    interaction_id      BIGINT UNSIGNED NOT NULL,
    chunk_id            BIGINT UNSIGNED NOT NULL,
    reference_number    SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_citation_ref (interaction_id, reference_number),
    KEY idx_citation_interaction (interaction_id),
    KEY idx_citation_chunk (chunk_id),
    CONSTRAINT fk_citation_interaction FOREIGN KEY (interaction_id)
        REFERENCES interactions (id),
    CONSTRAINT fk_citation_chunk FOREIGN KEY (chunk_id)
        REFERENCES chunks (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Feedback (requirements.md Section 10, 13 — DF-3)
-- ---------------------------------------------------------------------------
CREATE TABLE feedback (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    interaction_id  BIGINT UNSIGNED NOT NULL,
    rating          ENUM('up','down') NOT NULL,
    comment         VARCHAR(2000)   NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    redacted_at     DATETIME        NULL,
    PRIMARY KEY (id),
    KEY idx_feedback_interaction (interaction_id),
    KEY idx_feedback_rating_created (rating, created_at),
    CONSTRAINT fk_feedback_interaction FOREIGN KEY (interaction_id)
        REFERENCES interactions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Unanswered questions (requirements.md Section 13)
--
-- "Treat this report as a primary deliverable. It is a ranked list of what the
-- public comes to the University's website looking for and cannot find, and it
-- is likely to be worth more to the institution than the assistant itself."
--
-- A separate row rather than a query over interactions, because the report
-- groups by NORMALISED question text and must be cheap to rank weekly; the
-- normalised form is computed once at write time and indexed.
-- ---------------------------------------------------------------------------
CREATE TABLE unanswered_questions (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    interaction_id      BIGINT UNSIGNED NOT NULL,
    normalised_question VARCHAR(500)    NOT NULL,
    category_key        VARCHAR(40)     NULL,
    refusal_reason      VARCHAR(120)    NOT NULL,
    -- The office the refusal handed the user off to, so the weekly report can be
    -- split and sent to the offices that own the missing content.
    handoff_office_id   INT UNSIGNED    NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    redacted_at         DATETIME        NULL,
    PRIMARY KEY (id),
    KEY idx_unanswered_interaction (interaction_id),
    -- Drives the weekly ranking.
    KEY idx_unanswered_grouping (normalised_question, created_at),
    KEY idx_unanswered_category_created (category_key, created_at),
    KEY idx_unanswered_office (handoff_office_id),
    CONSTRAINT fk_unanswered_interaction FOREIGN KEY (interaction_id)
        REFERENCES interactions (id),
    CONSTRAINT fk_unanswered_category FOREIGN KEY (category_key)
        REFERENCES categories (category_key),
    CONSTRAINT fk_unanswered_office FOREIGN KEY (handoff_office_id)
        REFERENCES offices (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
