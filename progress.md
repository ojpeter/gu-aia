# GU-AIA — Progress Log

**Last updated:** 2026-08-31

**Resume here — the repository is FOUNDATIONS ONLY. Nothing runs yet.** Created today as a new sibling project at `C:\xampp\htdocs\gu-aia`, alongside `gu-website` and `gu-services`. What exists: `requirements.md` (the client-supplied 19-section engineering contract, verbatim), `CLAUDE.md` (13 project rules encoding the AI-governance and data-access standards), the three standards artefacts under `docs/`, the Section 4 directory skeleton, a working forward-only migration runner (`bin/migrate.php`), configuration stubs, and the PHPUnit/PHPStan/Composer scaffolding. What does NOT exist: any schema, any ingestion, any retrieval, any answering, any widget, any test. **Next piece of work: the corpus schema migration** (documents, chunks with embedding blob + `FULLTEXT` index, interaction log, feedback) — see Pending, Phase 1. Note that **Phase 0 (content audit) gates indexing, not schema** — schema can be written now, but nothing may be indexed until Communications and the Registry have assigned an authoritative source, owner and review date per fact.

---

## The Rules in Brief (full text in `CLAUDE.md` — mirrored here so this file is self-contained)

1. **The twelve invariants are law.** Each needs a named test in `tests/Invariant/`. Never weaken one for a feature; never add a bypass path.
2. **AI governance by named standard**: `DICTS/POL/AI/001` governs and outranks the rest; NIST AI RMF 1.0 for risk shape; ISO/IEC 42001 + 23894 for lifecycle; EU AI Act Art. 50 as a transparency benchmark (not a compliance claim); honest capability claims always.
3. **Data access**: purpose limitation (no Phase 1 personal-data surface at all), one least-privilege DB account per role with grants verified functionally, prepared statements everywhere including the `FULLTEXT` clause, explicit column whitelists, no hard deletion, DPPA 2019 for the chat logs, secrets out of the repo, corpus minimisation, TLS.
4. **LLM security to OWASP Top 10 for LLM Apps**: prompt injection defended in three places, model output treated as untrusted on render, no agency, rate limits + budget cap, no internal detail rendered, provider isolated behind `Generator`.
5. **App security to OWASP ASVS**: server-side validation, `finfo` MIME re-validation on upload, output encoding, CSRF, admin auth + per-action authz, security headers tested in the embedded case, no error detail to visitors, append-only admin audit log.
6. **Retrieval discipline**: retrieval is expected to refuse; category filtering is a safety control; quoted mode never generates the figure; never split a fees table; `reviewed_at` mandatory; conflicts are content defects.
7. **Evaluation is a gate**: harness built in the first sprint, ≥200 golden questions authored with Registry + Communications, runs in CI and blocks merges, re-run after every re-index. Never fabricate an eval number.
8. **Accessibility WCAG 2.1 AA + 60 KB widget budget + no-JS fallback** as a first-class path.
9. **Logging**: full Section 13 field list under one correlation ID, prompt version recorded, weekly Unanswered Questions Report as a primary deliverable.
10. **Code quality**: PSR-12/PSR-4, PHPStan clean, forward-only migrations, no `DELETE`, working fake `Generator` so everything is testable with no spend.
11. **Standards maintenance is continuous**: `docs/standards.md`, `docs/ai-risk-register.md`, `docs/data-protection.md` updated on events, not on a calendar; re-check standards at each phase gate and record the check even when nothing changed.
12. **`progress.md` every session** — read before starting, update before stopping.
13. **Honesty about state**: distinguish *specified* / *implemented* / *verified*. Only the third is done.

---

## Completed Tasks Log

- **2026-08-31** — **Project created as a new sibling at `C:\xampp\htdocs\gu-aia`.** Client instruction arrived mid-session during the `gu-website` git push; confirmed with the client that GU-AIA is its own project and its own repository (not a `gu-website` subdirectory), consistent with `requirements.md` Section 0 calling this file "the engineering contract for this repository", Section 3's separate `gu_aia` MySQL schema, and Section 4's own repository layout. Scope for this first pass confirmed as **foundations only**. *(Sections 0, 3, 4)*
- **2026-08-31** — `requirements.md` added verbatim as supplied by the client — 19 sections, the twelve invariants, hybrid-retrieval decision, corpus rules, answer categories, prompt contract, refusal/handoff, interface, safety/cost, eval harness, logging, admin console, phases, definition of done, out-of-scope, open questions. Not paraphrased or edited. *(all sections)*
- **2026-08-31** — `CLAUDE.md` written: 13 project rules. Rules 2, 3 and 4 are the direct response to the client's standing instruction to *"implement and maintain industry standards on AI and Data Access"* — AI governance mapped to named external frameworks, data access mapped to least-privilege/purpose-limitation/DPPA controls, and LLM-specific security mapped to the OWASP Top 10 for LLM Applications. *(Sections 2, 3, 11)*
- **2026-08-31** — `docs/standards.md` created — the standards register: every control mapped to the external standard it derives from, to where it will be implemented, and to its test. **Every row honestly marked `Specified`**, since no code exists; the three-state vocabulary (Specified / Implemented / Verified) is defined at the top so a later session cannot quietly mark something done because it is written down. *(Sections 2, 11)*
- **2026-08-31** — `docs/ai-risk-register.md` created — NIST AI RMF MAP+MEASURE artefact, 13 risks identified (R-1 fabricated fees figure through R-13 Phase-2 surface added early), each with impact, likelihood, designed treatment, and how it will be measured. All treatments marked "Not implemented". *(Section 11, NIST AI RMF)*
- **2026-08-31** — `docs/data-protection.md` created — DPPA 2019 data-flow table, 7 flows (DF-1 interaction log through DF-7 query text sent to the generation API), each with lawful basis, retention and access. Records explicitly that **chat logs are personal data even with no login**, that retention values are not yet set (open question 5), and that cross-border transfer must be stated plainly rather than assumed away (open question 2). *(Sections 13, 18)*
- **2026-08-31** — Section 4 directory skeleton created (`bin/`, `config/prompts/`, `db/migrations/`, `public/`, `src/{Ingestion,Retrieval,Answering,Safety,Logging,Admin}/`, `templates/`, `tests/{Unit,Integration,Invariant,Eval}/`, `docs/`), plus `storage/{logs,cache,corpus}/`. *(Section 4)*
- **2026-08-31** — `bin/migrate.php` written — real, working, forward-only migration runner: reads `.env` with no dependency, connects under the dedicated `DB_MIGRATION_USER` account, creates the `schema_migrations` ledger on first use, applies pending `.sql` files in filename order each in its own transaction, stops on first failure. **Enforces INV-12 in code, not by review**: refuses any migration containing `DELETE FROM` or `TRUNCATE`. *(Sections 3, 2/INV-12)*
- **2026-08-31** — Configuration stubs written, each carrying the specification's own reasoning as comments so a later session cannot mistake a placeholder for a tuned value: `config/retrieval.php` (threshold deliberately `null` and marked NOT TUNED), `config/categories.php` (the Section 7 category→mode routing), `config/budget.php` (ceiling `null` — must fail closed to retrieval-only, never fail open), `config/corpus.php` (**deliberately empty of real sources — Phase 0 gates indexing**), `config/refusals.php` (**placeholder copy only; Section 9 requires Communications to author refusal text, and contact details left `null` so the renderer fails loudly rather than publishing a wrong office email**), `config/prompts/README.md` (versioning rules + the Section 8 prompt contract). *(Sections 5, 6, 7, 8, 9, 11)*
- **2026-08-31** — Scaffolding: `.gitignore` (`.env` excluded), `.env.example` (three separate least-privilege DB accounts per Rule 3, `GENERATOR_DRIVER=fake` default, budget and retention keys present but empty), `composer.json` (PSR-4 `GuAia\`, PHPUnit/PHPStan/PHPCS dev deps, a `check:no-delete` script for INV-12), `phpunit.xml.dist` (invariant suite listed first as the release gate), `phpstan.neon.dist` (level 8), `README.md`. *(Sections 3, 4)*

---

## Pending / In-Progress Tasks (by phase, per `requirements.md` Section 15)

### Phase 0 — Content audit (gates all indexing; not an engineering task)
- [ ] Content audit with Communications and the Registry: one authoritative source per fact, owners and review dates assigned. **No indexing before this completes.** Section 15 warns it will take longer than the build.
- [ ] Confirm real refusal/handoff contacts (office, email, telephone) for `config/refusals.php` — currently `null` on purpose.
- [ ] Communications to author the real refusal copy (Section 9).

### Phase 1 — Public assistant
**Immediate next:**
- [ ] **Corpus schema migration** — `documents` (owning office, `reviewed_at`, review interval, authoritative flag, category, source ref), `chunks` (text, heading path, page, embedding blob, `FULLTEXT` index, superseded flag — never deleted), `interactions` (full Section 13 field list), `feedback`, `unanswered_questions`, `rate_limits`, `budget_ledger`, `admin_users`, `audit_log`. Index every FK and every `WHERE`/`ORDER BY`/`JOIN` column.
- [ ] Create the three least-privilege MySQL accounts and **verify each grant functionally** (attempt the write that should fail, record the result) — not asserted from the `GRANT` statement.

**Then, roughly in this order:**
- [ ] `tests/Invariant/` — all twelve invariant tests, written against the interfaces before the implementations where possible.
- [ ] Evaluation harness + `bin/evaluate.php` (Section 12 says build it in the first sprint, not at the end). Golden set authored **with** Registry and Communications, ≥200 questions with the specific composition Section 12 mandates.
- [ ] Ingestion: fetchers, PDF extraction (reject scanned/no-text-layer), cleaner with instruction-stripping (INV-6), structure-aware chunker (never split a fees table), embedder.
- [ ] Retrieval: `FULLTEXT` candidate generation (bind the query; sanitise BOOLEAN MODE operators), exact code matching + boost, in-process cosine rerank, threshold, category filter.
- [ ] Answering: category router (refusal intents matched **before** retrieval), prompt builder, `Generator` interface + fake + real client, citation binder (discard, never repair).
- [ ] Safety: refusal intents, injection defences, rate limiting, budget guard + degraded mode as a tested path.
- [ ] Logging: `InteractionLogger` in the same transaction as the response, feedback, weekly Unanswered Questions Report.
- [ ] Widget: server-rendered shell with server-side AI disclosure (INV-4), under 60 KB total, no-JS fallback with its own tests, WCAG 2.1 AA, focus management on new answers.
- [ ] Admin console for Communications and the Registry (corpus browser, re-index trigger, curated Q&A, authoritative flag, conflicts, reports, last eval run). **No content editing beyond curated entries** — the website stays the source of truth.
- [ ] CI: invariant suite, eval harness blocking on regression, payload-weight check, `DELETE` grep, PHPStan.
- [ ] Privacy notice published and linked from the widget.
- [ ] Entry in the Register of Approved AI Tools under `DICTS/POL/AI/001`.
- [ ] Widget embed into `gu-website` — cross-project, see Cross-Project Notes.

### Phase 2 — Portal-authenticated assistant
- Explicitly **not begun in this repository**. A separate specification. INV-10 requires the integration surface to not exist here.

### Phase 3 — Staff assistant over the HR Manual and policies
- Section 15 note: **consider running Phase 3 before Phase 2** (lower risk, stable content, forgiving audience).

---

## Known Issues / Open Questions

From `requirements.md` Section 18, none yet resolved:

1. **Hosted API or self-hosted open-weight model** — interim: build behind the `Generator` interface so it stays reversible; start hosted with a hard cap.
2. **May query data leave Uganda, and what does the privacy notice say** — interim: assume it does, state it plainly. Blocks the privacy notice.
3. **Which languages at launch** — English at minimum; test Acholi and Luganda in the eval set and report honestly before claiming support.
4. **Monthly budget ceiling** — configuration, set by the Chief, ICT Services before launch. `config/budget.php` is `null` and must fail closed until set.
5. **Log retention period** — configuration, set with the University's data protection function. Blocks the privacy notice.
6. **Who owns the unanswered-question report** — proposed Communications, copied to the Registry. Not confirmed.

Additional, this project's own:

7. **Real refusal contacts unknown.** `config/refusals.php` has `null` emails/telephones deliberately — a refusal that routes a user to a dead end is worse than the refusal itself.
8. **Sponsor expectation on the "mirror effect" (Section 19, risk R-12).** The assistant will surface the website's contradictions and staleness at scale, in public. Section 19 is explicit that whoever sponsors this must understand that **before Phase 0 begins**. Not yet confirmed that they do.

---

## Standing User Directives (apply always, not just this session)

- **Implement and maintain industry standards on AI and Data Access, and everything else required** (client, 2026-08-31). Encoded as `CLAUDE.md` Rules 2, 3, 4, 5 and 11, with the three `docs/` artefacts as the maintenance mechanism. "Maintain" is the half that rots — update the register on events, not on a calendar.
- **Keep checking developed modules in a real browser using Claude in Chrome** (client, 2026-08-31). `curl` confirms bytes are served; it does not confirm what a browser renders, how relative paths resolve, or whether a control is operable. This project has **no browser-facing surface yet** — the first thing to check under this directive will be the widget and the no-JS fallback, and per INV-9 and Rule 8 the browser pass must include a keyboard-only run and a check that the fallback works with JavaScript disabled, not merely that the page loads.
- **SEO and full mobile responsiveness** apply to every GU web property (standing directives carried from `gu-website`) — relevant here to the widget and the admin console.

---

## Cross-Project Notes

- **`gu-website`** — the widget is embedded there (Section 10). When that happens it is a cross-project event: the CSP `frame-ancestors`/`X-Frame-Options` on both sides, the 60 KB payload budget counted against `gu-website`'s own Core Web Vitals budget (LCP < 2.5s, INP < 200ms, CLS < 0.1), and `gu-website`'s "never write a root-absolute internal path" rule all apply. Note any such change in **both** projects' `progress.md`. `WIDGET_ALLOWED_ORIGIN` in `.env` exists so the embed origin is configured, not assumed.
- **`gu-services`** — **no integration in Phase 1, deliberately** (INV-10). Do not add a bridge, a stub, or a "ready for Phase 2" surface. `gu-website` reads `gu-services` through its own bridge module; that pattern is **not** to be copied here yet, because the data classes are different — `gu-website` reads published CMS content, whereas Phase 2 here would reach personal student records, which needs its own specification and its own controls.
- Phase 3 (staff assistant over the HR Manual) would read `gu-services`-adjacent policy documents. Still a separate phase, still not started.
