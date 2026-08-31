-- 0007_enforce_review_dates.sql
--
-- Closes a real hole found while verifying 0001, not a theoretical one.
--
-- WHAT HAPPENED
--
-- documents.reviewed_at is declared DATE NOT NULL and review_interval_days
-- SMALLINT UNSIGNED NOT NULL, which was supposed to make INV-11 structurally
-- unbypassable: "a document without an owning office, a reviewed_at date and a
-- review interval cannot be inserted, so it cannot be indexed."
--
-- It was bypassable. The development server (MariaDB 10.4, XAMPP default) runs
-- with sql_mode = NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION and
-- WITHOUT STRICT_TRANS_TABLES. In that mode, omitting a NOT NULL DATE does not
-- raise an error: the server substitutes '0000-00-00' and inserts the row. A
-- test insert with no reviewed_at and no review_interval_days succeeded, and
-- produced exactly the row INV-11 exists to make impossible — a document with a
-- zero review date, which would be served as though freshly reviewed and would
-- never trip the stale-content caution.
--
-- The FK on owning_office_id did behave correctly and refused its half.
--
-- THE FIX, IN TWO LAYERS
--
--   1. CHECK constraints, below. These are evaluated regardless of sql_mode, so
--      the guarantee no longer depends on how the server happens to be
--      configured — which matters especially because production is MySQL 8 and
--      development is MariaDB 10.4, and their default modes differ.
--
--   2. Every application connection sets a strict sql_mode explicitly
--      (config/pdo_options.php). Silent coercion is not something to rely on
--      being off by luck.
--
-- The general lesson, recorded because it will apply again: NOT NULL is a weaker
-- guarantee than it looks in MySQL-family databases. Where an invariant depends
-- on a column genuinely holding a real value, say so with a CHECK.

ALTER TABLE documents
    ADD CONSTRAINT chk_documents_reviewed_at
        CHECK (reviewed_at > '2000-01-01'),
    ADD CONSTRAINT chk_documents_review_interval
        CHECK (review_interval_days > 0);

-- chunks carry reviewed_at denormalised from their document, and every answer
-- renders it (INV-11). A zero there is the same failure one table along.
ALTER TABLE chunks
    ADD CONSTRAINT chk_chunks_reviewed_at
        CHECK (reviewed_at > '2000-01-01');
