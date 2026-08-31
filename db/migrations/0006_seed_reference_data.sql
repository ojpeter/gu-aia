-- 0006_seed_reference_data.sql
--
-- Reference data only: the offices and the answer categories that
-- requirements.md itself names. Nothing here is invented.
--
-- What is deliberately NOT seeded:
--
--   * Office email addresses and telephone numbers. Section 9 requires every
--     refusal to name a real human contact; a wrong one sends a user who already
--     could not get an answer to a dead end. Confirmed in Phase 0, not guessed.
--
--   * Any office requirements.md does not name. A "Finance Department" row would
--     be a plausible guess, and a plausible guess about who owns fees content is
--     exactly the kind of thing this project must not do. Fees handoff is left
--     unassigned until Phase 0 assigns it.
--
--   * Any document, chunk or corpus content whatsoever. Phase 0 gates indexing:
--     "No indexing before this completes."

-- ---------------------------------------------------------------------------
-- Offices named in requirements.md
-- ---------------------------------------------------------------------------
INSERT INTO offices (name, email, telephone, url) VALUES
    ('Directorate of ICT Services',      NULL, NULL, NULL),
    ('Directorate of Communications',    NULL, NULL, NULL),
    ('Office of the Academic Registrar', NULL, NULL, NULL);

-- ---------------------------------------------------------------------------
-- Answer categories (requirements.md Section 7)
--
-- handoff_office_id is set ONLY where Section 7 states the destination:
--   individual_outcome -> "refuse and route to the Registry"
-- Everything else is NULL pending Phase 0. "individual_record" routes to the
-- Portal, which is a system rather than an office, so it has no office row; that
-- destination is a URL in config/refusals.php.
-- ---------------------------------------------------------------------------
INSERT INTO categories
    (category_key, label, mode, requires_academic_year, handoff_office_id, refuse_before_retrieval, sort_order)
VALUES
    ('fees', 'Fees', 'quoted', 1, NULL, 0, 10),
    ('entry_requirements', 'Entry requirements', 'quoted', 1, NULL, 0, 20),
    ('deadlines_calendar', 'Deadlines and calendar', 'quoted', 1, NULL, 0, 30),
    ('application_process', 'Application process', 'grounded', 0, NULL, 0, 40),
    ('programme_information', 'Programme information', 'grounded', 0, NULL, 0, 50),
    ('contact_directions', 'Contact and directions', 'grounded', 0, NULL, 0, 60),
    -- INV-3. Matched before retrieval, so no retrieved context can turn it into
    -- an answer. Routed to the Registry, per Section 7.
    ('individual_outcome', 'Individual outcome', 'refuse', 0,
        (SELECT id FROM offices WHERE name = 'Office of the Academic Registrar'), 1, 70),
    -- INV-10. Phase 1 has no Portal integration at all.
    ('individual_record', 'Individual record', 'refuse', 0, NULL, 1, 80),
    ('off_topic', 'Off topic', 'refuse', 0, NULL, 1, 90),
    ('unsafe_or_abusive', 'Unsafe or abusive', 'refuse', 0, NULL, 1, 100);
