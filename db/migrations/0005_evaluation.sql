-- 0005_evaluation.sql
--
-- The evaluation harness's storage. requirements.md Section 12, Section 14.
--
-- "This is not optional and is not a nice-to-have. Build it in the first sprint."
-- "The harness runs in CI and blocks a merge on regression. Retrieval quality is
--  a test, not a feeling."
--
-- The golden set lives in the database rather than only in a fixture file so
-- that Registry and Communications can see and review it through the console —
-- Section 12 requires the questions to be authored WITH them, and a set only
-- engineers can read is not authored with anyone.

-- ---------------------------------------------------------------------------
-- The golden question set (at least 200 questions, Section 12)
-- ---------------------------------------------------------------------------
CREATE TABLE eval_questions (
    id                      INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    question                VARCHAR(500)    NOT NULL,

    -- Section 12 requires Acholi, Luganda and code-switched English in the set.
    -- Section 18 open question 3: support is not claimed until the harness says
    -- so, which is exactly what this column makes measurable per language.
    language                VARCHAR(20)     NOT NULL DEFAULT 'en',

    expected_mode           ENUM('quoted','grounded','refuse') NOT NULL,
    expected_category_key   VARCHAR(40)     NULL,
    expected_document_id    BIGINT UNSIGNED NULL,

    -- Which mandated block of the set this question belongs to, so the harness
    -- can assert the composition Section 12 requires rather than just the total:
    --   individual_outcome     40 phrasings that must refuse (INV-3)
    --   out_of_corpus          20 whose answers are absent and must refuse (INV-1)
    --   injection              15 prompt-injection attempts (INV-6)
    --   quoted_high_stakes     20 fees/entry-requirement questions (INV-2)
    --   general                everything else
    suite                   ENUM('individual_outcome','out_of_corpus','injection','quoted_high_stakes','general') NOT NULL DEFAULT 'general',

    -- Who authored it. Section 12: authored with the Registry and Communications.
    authored_by_office_id   INT UNSIGNED    NULL,
    notes                   VARCHAR(1000)   NULL,
    is_active               TINYINT(1)      NOT NULL DEFAULT 1,
    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_eval_q_suite_active (suite, is_active),
    KEY idx_eval_q_language (language),
    KEY idx_eval_q_category (expected_category_key),
    KEY idx_eval_q_document (expected_document_id),
    KEY idx_eval_q_office (authored_by_office_id),
    CONSTRAINT fk_eval_q_category FOREIGN KEY (expected_category_key)
        REFERENCES categories (category_key),
    CONSTRAINT fk_eval_q_document FOREIGN KEY (expected_document_id)
        REFERENCES documents (id),
    CONSTRAINT fk_eval_q_office FOREIGN KEY (authored_by_office_id)
        REFERENCES offices (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- One evaluation run (bin/evaluate.php)
--
-- Section 12: reports retrieval hit rate at k, refusal precision and recall,
-- citation validity, and mean latency. Re-run and recorded after every corpus
-- re-index, because content changes break retrieval as surely as code does —
-- hence ingest_run_id.
-- ---------------------------------------------------------------------------
CREATE TABLE eval_runs (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    started_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at             DATETIME        NULL,

    git_ref                 VARCHAR(120)    NULL,
    prompt_version          VARCHAR(60)     NULL,
    model                   VARCHAR(120)    NULL,
    -- The re-index this run followed, if any.
    ingest_run_id           BIGINT UNSIGNED NULL,
    -- The threshold in force, so a run is reproducible and a threshold change is
    -- visible as the cause of a score change.
    score_threshold         DECIMAL(8,6)    NULL,

    questions_total         INT UNSIGNED    NOT NULL DEFAULT 0,
    questions_passed        INT UNSIGNED    NOT NULL DEFAULT 0,

    retrieval_hit_rate_at_k DECIMAL(6,4)    NULL,
    refusal_precision       DECIMAL(6,4)    NULL,
    refusal_recall          DECIMAL(6,4)    NULL,
    citation_validity       DECIMAL(6,4)    NULL,
    mean_latency_ms         INT UNSIGNED    NULL,

    -- Whether the run met its thresholds. CI blocks a merge on false.
    passed                  TINYINT(1)      NULL,
    notes                   VARCHAR(2000)   NULL,

    PRIMARY KEY (id),
    KEY idx_eval_run_started (started_at),
    KEY idx_eval_run_passed (passed, started_at),
    KEY idx_eval_run_ingest (ingest_run_id),
    CONSTRAINT fk_eval_run_ingest FOREIGN KEY (ingest_run_id)
        REFERENCES ingest_runs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Per-question result within a run
-- ---------------------------------------------------------------------------
CREATE TABLE eval_results (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    eval_run_id         BIGINT UNSIGNED NOT NULL,
    eval_question_id    INT UNSIGNED    NOT NULL,

    actual_mode         ENUM('quoted','grounded','refuse','degraded') NULL,
    expected_mode       ENUM('quoted','grounded','refuse') NOT NULL,
    mode_matched        TINYINT(1)      NOT NULL DEFAULT 0,

    -- Did the expected source document appear in the retrieved set, and where.
    expected_doc_rank   SMALLINT UNSIGNED NULL,
    top_score           DECIMAL(8,6)    NULL,

    -- Every citation resolved to a chunk that was actually retrieved (Section 8).
    citations_valid     TINYINT(1)      NULL,
    citation_count      SMALLINT UNSIGNED NULL,

    latency_ms          INT UNSIGNED    NULL,
    passed              TINYINT(1)      NOT NULL DEFAULT 0,
    failure_detail      VARCHAR(1000)   NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_eval_result (eval_run_id, eval_question_id),
    KEY idx_eval_result_run_passed (eval_run_id, passed),
    KEY idx_eval_result_question (eval_question_id),
    CONSTRAINT fk_eval_result_run FOREIGN KEY (eval_run_id)
        REFERENCES eval_runs (id),
    CONSTRAINT fk_eval_result_question FOREIGN KEY (eval_question_id)
        REFERENCES eval_questions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
