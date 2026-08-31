-- 0008_eval_precision_cases.sql
--
-- Two gaps in 0005 that only appeared once the golden set was actually written.
--
-- 1. THE PRECISION BLOCK HAS NO SINGLE EXPECTED MODE.
--
--    Section 12 measures refusal PRECISION as well as recall. The cases that
--    measure it are ordinary questions — "how do I apply", "what are the entry
--    requirements for BSc Computer Science" — and what matters about them is not
--    which mode they land in but that they are NOT REFUSED. Depending on the
--    corpus, "what are the entry requirements" is legitimately Quoted and "how do
--    I apply" is legitimately Grounded; pinning either to one mode would make the
--    harness fail for a correct answer.
--
--    So expected_mode becomes nullable, and a must_not_refuse flag carries the
--    weaker but correct expectation.
--
--    This matters more than it looks. Without a precision block, a system that
--    refused every question would score perfectly on every other block in the
--    set. These rows are the only thing standing between "refuse more" and a
--    passing grade.
--
-- 2. TWO SUITES WERE MISSING FROM THE ENUM.
--
--    'individual_record' (INV-10, refused and routed to the Portal) and
--    'precision' were being folded into 'general', which would have hidden them
--    from the composition check.

ALTER TABLE eval_questions
    MODIFY COLUMN expected_mode ENUM('quoted','grounded','refuse') NULL
        COMMENT 'NULL for precision cases, where the expectation is must_not_refuse',
    MODIFY COLUMN suite ENUM(
        'individual_outcome',
        'individual_record',
        'out_of_corpus',
        'injection',
        'quoted_high_stakes',
        'precision',
        'general'
    ) NOT NULL DEFAULT 'general',
    ADD COLUMN must_not_refuse TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Section 12 refusal precision: this question must not be refused'
        AFTER expected_mode;

-- A question either states the mode it expects or states that it must not be
-- refused. One with neither expectation asserts nothing and would silently pass.
ALTER TABLE eval_questions
    ADD CONSTRAINT chk_eval_question_has_an_expectation
        CHECK (expected_mode IS NOT NULL OR must_not_refuse = 1);

-- Provenance: which questions were seeded from config/eval/golden_set.php and
-- which were authored by an office. Section 12 requires the set to be authored
-- with the Registry and Communications, and a set that cannot distinguish the
-- two cannot report honestly on how much of that has actually happened.
ALTER TABLE eval_questions
    ADD COLUMN source ENUM('seed','office') NOT NULL DEFAULT 'seed'
        COMMENT 'seed = from the repository draft; office = authored by Registry/Communications'
        AFTER authored_by_office_id;
