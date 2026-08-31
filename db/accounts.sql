-- db/accounts.sql
--
-- Step 2 of 2. Table-level grants for the ingestion worker and the web-serving
-- account. CLAUDE.md Rule 3.
--
-- THIS FILE IS A TEMPLATE AND IS NOT RUN BY bin/migrate.php.
-- Run db/accounts_bootstrap.sql and then bin/migrate.php FIRST: every grant
-- below names an individual table, so the tables must already exist.
-- Substitute each __PASSWORD__ placeholder, put the same values in .env, then:
--
--   mysql -u root -p < db/accounts.sql
--
-- Real passwords never enter this repository.
--
-- The design point, and the reason this is a file rather than an afterthought:
--
--   NO ACCOUNT ON THIS SCHEMA IS GRANTED `DELETE`, AND NONE IS GRANTED `DROP`.
--
-- INV-12 says nothing is deleted. Enforcing that only in application code means
-- one careless line, or one compromised code path, can erase the record of what
-- the assistant told somebody. Enforcing it in the grant table means the server
-- refuses. bin/migrate.php also refuses any migration containing DELETE or
-- TRUNCATE, so the rule holds at three layers: review, runner, and server.
--
-- Least privilege is also PURPOSE LIMITATION here, not just damage control. The
-- ingestion worker is given no access at all to interactions, feedback or
-- unanswered_questions -- those are chat logs, they are personal data
-- (docs/data-protection.md DF-1), and ingestion has no business reading them.
--
-- Every grant below must be VERIFIED FUNCTIONALLY after it is applied -- attempt
-- the write that should fail and record the result -- not asserted from the text
-- of the GRANT statement. See docs/standards.md section 5.

-- ===========================================================================
-- 2. Ingestion worker — writes the corpus, and only the corpus.
--    Reads offices and categories to resolve foreign keys.
--    Deliberately has NO access to interactions, interaction_retrievals,
--    interaction_citations, feedback or unanswered_questions: those are chat
--    logs and personal data, and ingestion has no purpose that requires them.
--    No DELETE: superseded, never removed.
-- ===========================================================================
CREATE USER IF NOT EXISTS 'gu_aia_ingest'@'localhost' IDENTIFIED BY '__PASSWORD__';

GRANT SELECT                 ON gu_aia.offices          TO 'gu_aia_ingest'@'localhost';
GRANT SELECT                 ON gu_aia.categories       TO 'gu_aia_ingest'@'localhost';
GRANT SELECT, INSERT, UPDATE ON gu_aia.documents        TO 'gu_aia_ingest'@'localhost';
GRANT SELECT, INSERT, UPDATE ON gu_aia.chunks           TO 'gu_aia_ingest'@'localhost';
GRANT SELECT, INSERT, UPDATE ON gu_aia.chunk_codes      TO 'gu_aia_ingest'@'localhost';
GRANT SELECT, INSERT, UPDATE ON gu_aia.curated_entries  TO 'gu_aia_ingest'@'localhost';
GRANT SELECT, INSERT, UPDATE ON gu_aia.source_conflicts TO 'gu_aia_ingest'@'localhost';
GRANT SELECT, INSERT, UPDATE ON gu_aia.ingest_runs      TO 'gu_aia_ingest'@'localhost';
-- Ingestion records what it did, but cannot rewrite what it recorded.
GRANT SELECT, INSERT         ON gu_aia.admin_audit_log  TO 'gu_aia_ingest'@'localhost';

-- ===========================================================================
-- 3. Web-serving account — reads the corpus, writes the logs.
--    Cannot write the corpus: publishing content is ingestion's job, and a
--    request-handling path that could rewrite a fees chunk is a defacement risk.
--    Cannot UPDATE or DELETE the audit log: append-only is enforced by grant,
--    so a compromised console session cannot rewrite history.
--    No DELETE anywhere: retention expiry redacts by UPDATE (INV-12).
-- ===========================================================================
CREATE USER IF NOT EXISTS 'gu_aia_app'@'localhost' IDENTIFIED BY '__PASSWORD__';

-- The migration ledger. Read-only, and read-only is the whole point: a health
-- check needs to know whether the schema is up to date, and the app must never
-- be able to claim a migration ran that did not.
GRANT SELECT ON gu_aia.schema_migrations TO 'gu_aia_app'@'localhost';

-- Corpus: read only.
GRANT SELECT ON gu_aia.offices          TO 'gu_aia_app'@'localhost';
GRANT SELECT ON gu_aia.categories       TO 'gu_aia_app'@'localhost';
GRANT SELECT ON gu_aia.documents        TO 'gu_aia_app'@'localhost';
GRANT SELECT ON gu_aia.chunks           TO 'gu_aia_app'@'localhost';
GRANT SELECT ON gu_aia.chunk_codes      TO 'gu_aia_app'@'localhost';
GRANT SELECT ON gu_aia.curated_entries  TO 'gu_aia_app'@'localhost';
GRANT SELECT ON gu_aia.source_conflicts TO 'gu_aia_app'@'localhost';
GRANT SELECT ON gu_aia.ingest_runs      TO 'gu_aia_app'@'localhost';

-- Interaction log (INV-7). UPDATE is granted only so that retention expiry can
-- redact in place; there is no DELETE.
GRANT SELECT, INSERT, UPDATE ON gu_aia.interactions           TO 'gu_aia_app'@'localhost';
GRANT SELECT, INSERT         ON gu_aia.interaction_retrievals TO 'gu_aia_app'@'localhost';
GRANT SELECT, INSERT         ON gu_aia.interaction_citations  TO 'gu_aia_app'@'localhost';
GRANT SELECT, INSERT, UPDATE ON gu_aia.feedback               TO 'gu_aia_app'@'localhost';
GRANT SELECT, INSERT, UPDATE ON gu_aia.unanswered_questions   TO 'gu_aia_app'@'localhost';

-- Safety and spend.
GRANT SELECT, INSERT, UPDATE ON gu_aia.rate_limits           TO 'gu_aia_app'@'localhost';
GRANT SELECT, INSERT         ON gu_aia.rate_limit_violations TO 'gu_aia_app'@'localhost';
GRANT SELECT, INSERT, UPDATE ON gu_aia.budget_periods        TO 'gu_aia_app'@'localhost';

-- Admin console. Column-level UPDATE on admin_users: the app may record a login
-- and a lockout, and may not change a role, an email or a password hash through
-- an ordinary request path.
GRANT SELECT ON gu_aia.admin_users TO 'gu_aia_app'@'localhost';
GRANT UPDATE (last_login_at, failed_logins, locked_until)
  ON gu_aia.admin_users TO 'gu_aia_app'@'localhost';

-- Append-only: INSERT and SELECT, never UPDATE, never DELETE.
GRANT SELECT, INSERT ON gu_aia.admin_audit_log TO 'gu_aia_app'@'localhost';

-- Evaluation results are read by the console (Section 14: "View the last
-- evaluation run"). The harness itself runs under the migration account in CI.
GRANT SELECT ON gu_aia.eval_questions TO 'gu_aia_app'@'localhost';
GRANT SELECT ON gu_aia.eval_runs      TO 'gu_aia_app'@'localhost';
GRANT SELECT ON gu_aia.eval_results   TO 'gu_aia_app'@'localhost';


-- ===========================================================================
-- 4. Console account - the authenticated admin console.
--
--    WHY THIS EXISTS RATHER THAN WIDENING gu_aia_app.
--
--    Section 14 says the console authors curated question-and-answer entries,
--    and those ARE corpus content. But gu_aia_app is deliberately unable to
--    write the corpus: a request-handling path that could rewrite a fees chunk
--    is a defacement risk, and that grant is verified as absent.
--
--    Those two facts are not in conflict once the distinction is named. The risk
--    being guarded against is an UNAUTHENTICATED request path writing published
--    content. An authenticated, permission-checked, audited console action is a
--    different thing, and giving it its own account keeps the public path's
--    inability to write the corpus intact and provable.
--
--    Scoped tightly even so. It may WRITE only curated entries and the documents
--    and chunks that back them. It may READ the interaction log, unanswered
--    questions and feedback, because Section 14 requires the console to show
--    those reports -- but read-only, so a console session cannot edit or redact
--    what the assistant told somebody. It cannot change a role or a password
--    hash. It has no DELETE anywhere.
-- ===========================================================================
CREATE USER IF NOT EXISTS 'gu_aia_console'@'localhost' IDENTIFIED BY '__PASSWORD__';

-- Reads what the console displays.
GRANT SELECT ON gu_aia.offices             TO 'gu_aia_console'@'localhost';
GRANT SELECT ON gu_aia.categories          TO 'gu_aia_console'@'localhost';
GRANT SELECT ON gu_aia.admin_users         TO 'gu_aia_console'@'localhost';
GRANT SELECT ON gu_aia.eval_runs           TO 'gu_aia_console'@'localhost';
GRANT SELECT ON gu_aia.eval_results        TO 'gu_aia_console'@'localhost';
GRANT SELECT ON gu_aia.eval_questions      TO 'gu_aia_console'@'localhost';
GRANT SELECT ON gu_aia.ingest_runs         TO 'gu_aia_console'@'localhost';
GRANT SELECT ON gu_aia.schema_migrations   TO 'gu_aia_console'@'localhost';

-- The reports. Read-only: the console shows the unanswered-question report and
-- the feedback stream, and must not be able to edit either.
GRANT SELECT ON gu_aia.interactions          TO 'gu_aia_console'@'localhost';
GRANT SELECT ON gu_aia.unanswered_questions  TO 'gu_aia_console'@'localhost';
GRANT SELECT ON gu_aia.feedback              TO 'gu_aia_console'@'localhost';

-- Authoring curated entries, and the document and chunk rows that carry them.
-- No DELETE: an edit supersedes, so a past answer stays reconstructible (INV-12).
GRANT SELECT, INSERT, UPDATE ON gu_aia.curated_entries  TO 'gu_aia_console'@'localhost';
GRANT SELECT, INSERT, UPDATE ON gu_aia.documents        TO 'gu_aia_console'@'localhost';
GRANT SELECT, INSERT, UPDATE ON gu_aia.chunks           TO 'gu_aia_console'@'localhost';
GRANT SELECT, INSERT, UPDATE ON gu_aia.chunk_codes      TO 'gu_aia_console'@'localhost';
GRANT SELECT, UPDATE         ON gu_aia.source_conflicts TO 'gu_aia_console'@'localhost';

-- Records a login and a lockout; cannot change a role or a password hash.
GRANT UPDATE (last_login_at, failed_logins, locked_until)
  ON gu_aia.admin_users TO 'gu_aia_console'@'localhost';

-- Append-only, like every other writer of this table.
GRANT SELECT, INSERT ON gu_aia.admin_audit_log TO 'gu_aia_console'@'localhost';

-- NOTE deliberately not granted, and why:
--   * No account has DELETE or DROP on any table            (INV-12)
--   * gu_aia_app has no INSERT/UPDATE on documents or chunks (corpus integrity)
--   * gu_aia_ingest has no access to interactions or feedback (purpose limitation)
--   * gu_aia_console cannot read interactions' full text beyond SELECT for the
--     reports, cannot change a role or password hash, and cannot delete anything
--   * gu_aia_app STILL cannot write the corpus - that separation is the point
--     of adding a fourth account rather than widening the third
--   * No account has any privilege on any other schema — in particular not on
--     gu_hrms or gu_website, the sibling projects' databases. Phase 1 has no
--     cross-system integration surface at all (INV-10), and the grant table is
--     where that is made true rather than merely intended.
