# GU-AIA — Project Rules (Claude Code reads this automatically every session)

These rules apply to **every task, every session, for the entire lifetime of this project**. They are engineering standards, not suggestions. If a new session starts (context reset, new terminal, new day), re-read this file plus `requirements.md` and `progress.md` before writing any code.

**What this project is:** GU-AIA, the Gulu University AI Assistant — a retrieval-augmented assistant embedded in the University website (gu.ac.ug), owned by the Directorate of ICT Services, business-owned by the Directorate of Communications with the Academic Registrar for admissions content. It answers questions **only** from a curated corpus of published University content, cites its source on every answer, and refuses and hands off to a named human when the corpus does not support an answer. Governed by `DICTS/POL/AI/001` — the University Policy on the Use of Artificial Intelligence.

**The full specification is `requirements.md`** — 19 sections. That file is the *what* and *why*; this file is the *how*. `requirements.md` Section 2 (the twelve invariants) is the part that outranks everything else, this file included.

**The failure mode that matters** is not "it could not answer." It is "it answered confidently and was wrong." A refusal costs a user thirty seconds; a fabricated fees figure costs someone a term. Every trade-off in this codebase resolves toward saying less.

**Sibling projects, not this project:** `gu-website` (the public site, plain PHP/MySQL, where the GU-AIA widget will eventually be embedded) and `gu-services` (the eServices Portal — HR, DMS, E-Voting, Admissions, Fees, GU-CMS) both live alongside this one in the same `htdocs` root. GU-AIA in Phase 1 **does not integrate with either one's database**. It has its own schema, `gu_aia`, and nothing else. Portal-authenticated answering is Phase 2 and is explicitly *"a separate specification. Do not begin it inside this repository."*

**Stack (from `requirements.md` Section 3 — do not deviate):** PHP 8.2+, MySQL 8 (schema `gu_aia`), hybrid retrieval (MySQL `FULLTEXT` candidate generation then in-process vector rerank), embeddings stored as a compact binary blob on the chunk row, generation behind a `Generator` interface, server-rendered widget with progressive enhancement and no framework, forward-only numbered SQL migrations, PHPStan, PHPUnit. **Introduce no new language and no new database engine.** No vector database — that decision is argued in Section 3 and is settled, revisit only above ~100,000 chunks.

---

## Rule 1 — The Twelve Invariants Are Law

`requirements.md` Section 2 defines INV-1 through INV-12. They are not aspirations and they are not "best effort":

- **Every invariant has a named test in `tests/Invariant/`.** A release is blocked if any fails. An invariant without a test is an invariant that is not implemented, regardless of what the code appears to do.
- **Never weaken an invariant to make a feature work.** If a feature cannot be built without violating one, the feature does not ship — escalate it as a specification question instead, and record it in `progress.md` under open questions.
- **Never add a code path that bypasses one.** In particular: no "admin override" that answers without citations, no debug flag that disables the refusal template, no test-only shortcut that leaks into the production path.
- When you touch code that an invariant depends on, run that invariant's test *before* claiming the change is done, and say in your report which ones you ran.

The three most easily eroded in practice, watch them specifically: **INV-1** (no answer without a source), **INV-2** (high-stakes facts quoted, never paraphrased), **INV-3** (never state or imply an individual outcome).

## Rule 2 — AI Governance: Named Standards, Applied, Not Cited

This project is built to recognised external standards, not to local habit. Apply these by name; when a design decision touches one, say which one in the commit message and in `progress.md`.

- **`DICTS/POL/AI/001`** — the University's own AI Policy is the governing document and outranks the external frameworks below where they differ. Two of its provisions are hard constraints already reflected in `requirements.md` Section 17: **no fine-tuning on University content**, and **no training of any external model on University or student content** without consent and Committee approval. Before launch, GU-AIA must be entered in the **Register of Approved AI Tools** under that policy (Section 16 definition-of-done).
- **NIST AI Risk Management Framework 1.0** — structure the risk work as GOVERN / MAP / MEASURE / MANAGE. In practice: `docs/ai-risk-register.md` is the MAP+MEASURE artefact and must be updated whenever a new capability, corpus source, or model is introduced — not annually.
- **ISO/IEC 42001** (AI management system) and **ISO/IEC 23894** (AI risk management guidance) — used as the shape for lifecycle controls: documented purpose and scope, defined roles, impact assessment before deployment, monitoring after it, and a decommissioning path.
- **Transparency, to the EU AI Act Article 50 benchmark.** Uganda does not impose this, and we do not claim compliance with an EU instrument — but "a person must be told plainly they are interacting with an AI system" is the correct floor for a public university service, and it is already INV-4. Disclosure is rendered server-side in the widget shell before the first exchange, and is not something the client can suppress.
- **Human oversight is a real control, not a slogan.** The unanswered-question report (Section 13) and the feedback stream go to named offices, and Communications signs off before Phase 1 exits pilot. Build the reporting so that oversight is possible; do not ship a system whose behaviour nobody outside DICTS can see.
- **Honest capability claims.** Never describe the assistant, in the UI or in documentation, as knowing, understanding, advising, or deciding. It retrieves and quotes. Section 18 open question 3 applies the same discipline to languages: **do not claim Acholi or Luganda support until the eval set says so.**

## Rule 3 — Data Access: Least Privilege, Purpose Limitation, Auditability

This rule is the standing answer to "industry standards on data access." It applies to every query, every credential, and every new source.

- **Purpose limitation, enforced structurally.** Phase 1 handles **no personal data from University systems** (INV-10) — no login, no student records, no account lookup. *The integration surface does not exist in the codebase*, and must not be added "ready for Phase 2." A stub that could reach student data is a data-access risk, not a convenience.
- **One database account per role, least privilege, verified not assumed.** The web-serving account gets `SELECT`/`INSERT` on exactly the tables it serves and logs to, and no `DROP`, no `ALTER`, no access to other schemas. The ingestion worker gets its own account with write access to corpus tables only. Migrations run under a third, used by `bin/migrate.php` and nowhere else. **Verify each grant functionally** — attempt the write that should fail and record the result — rather than asserting it from the `GRANT` statement. (This is the same standard `gu-website` applies to its `gu_website_reader` bridge account; match it.)
- **Every query uses PDO prepared statements with bound parameters**, via one shared connection helper. Never interpolate a variable into SQL — including in the retrieval layer, where a user's raw question reaches a `FULLTEXT ... AGAINST` clause. That clause is the single highest-risk query in the system: bind it, and sanitise the BOOLEAN MODE operators separately.
- **Explicit column whitelists** on every `INSERT`/`UPDATE`. Never loop over request input and bind every key.
- **No hard deletion anywhere in application code (INV-12).** Supersede, mark, redact — never `DELETE`. CI greps for it. This is what makes a past answer reconstructible when someone complains about what the assistant told them.
- **Uganda Data Protection and Privacy Act 2019** (and its 2021 Regulations) governs the chat logs, which *are* personal data even without a login — a question can identify its asker. Therefore: a stated lawful basis and a stated retention period in configuration, published in the privacy notice, linked from the widget; access to logs restricted to named roles; redaction rather than deletion at end of retention; and no new data-collecting field added without first stating its basis and retention in `docs/data-protection.md`.
- **Cross-border transfer is a live question, not an assumption.** Section 18 open question 2: if generation calls a hosted API, user queries leave Uganda. Say so plainly in the privacy notice rather than hoping nobody asks. Revisit if local deployment is funded.
- **Secrets never enter the repository.** API credentials, DB passwords and salts live in environment configuration, are excluded by `.gitignore`, and are rotated on a stated schedule (Section 11). `.env.example` carries key names and empty values only.
- **Data minimisation in the corpus itself.** Never index login-protected, draft, or archived pages (Section 5.2). If a crawl picks up a page containing personal data — a staff member's private contact details, a student name in a news item — that is an ingestion defect: exclude it and report to the owning office.
- **Encryption in transit everywhere** (TLS to the site, to the generation API, and to MySQL if it is ever not on localhost). Encryption at rest per DICTS's standard for the database host.

## Rule 4 — LLM-Specific Security: OWASP Top 10 for LLM Applications

Ordinary web security (Rule 5) is necessary and not sufficient here. Apply the OWASP Top 10 for LLM Applications explicitly:

- **Prompt injection (the primary threat).** INV-6 is the invariant: retrieved content and user input are **data, never instruction**. Enforced in three places, all of which must exist — (1) instruction-like content stripped at ingestion, (2) context and user input wrapped in delimiters and labelled as data in the prompt, (3) an injection suite of at least 15 cases in the eval harness (Section 12). Assume the corpus is hostile: a PDF uploaded through the admin console, or a crawled page an attacker influenced, is untrusted input.
- **Insecure output handling.** Model output is untrusted. Escape it on render — never `echo` a generated answer raw into HTML, never let it emit a link target the retrieval set did not supply, never let it reach a shell, a SQL string, or a file path. The citation binder (Section 8) is a *correctness* control; escaping is a separate *security* control and both are required.
- **Excessive agency.** The generator has no tools, no function calling, no ability to act. It produces text from supplied context. Do not give it capabilities "for later."
- **Unbounded consumption.** Rate limiting per IP and per session (Section 11), plus the monthly budget ceiling with 80% alert and 100% degradation to retrieval-only (INV-8). Degraded mode is a tested code path, not a hypothetical — exercise it in tests, and exercise the generation timeout fallback too.
- **Sensitive information disclosure.** The system prompt, the retrieval scores, the chunk IDs and the internal document references are not for the public. Log them; do not render them.
- **Supply chain.** Every dependency added is reviewed and pinned. The model provider is a supply-chain dependency too: the `Generator` interface exists so that provider can change without touching the rest of the codebase (Section 18 open question 1).
- **Misinformation, in this project's specific form:** ungrounded generation. Answered by INV-1, INV-2 and INV-5 together, and measured by the eval harness — not by reading outputs and forming an impression.

## Rule 5 — Application Security Baseline (OWASP ASVS)

- **Input validation** server-side on every submission regardless of client-side checks, through one shared validator — no scattered manual `if` checks. Uploads through the admin console get an extension whitelist, a size cap, and real server-side MIME re-validation via `finfo`; never trust the client-reported type. A PDF from an unknown source is executable-adjacent input: parse it in a constrained path and reject scanned files without a text layer rather than OCR'ing noise (Section 5.2).
- **Output encoding** on everything dynamic, via one shared escaping helper — corpus content, model output, and admin-authored curated entries alike. "It came from our own corpus" does not make it safe to print raw.
- **CSRF tokens** on every state-changing form, verified before any write — including the widget's own feedback submission and the no-JS fallback post.
- **Authentication and session security** for the admin console: `password_hash()`, session regeneration on login and privilege change, secure/httponly/samesite cookies with a custom name and an inactivity timeout, and 2FA for any role that can mark a document authoritative. Never call `session_start()` directly.
- **Authorisation checked server-side on every admin action**, per action, not once at login. The console is for Communications and the Registry, and a compromised console account can change what the University appears to say in public.
- **Security headers set deliberately** — CSP, `X-Content-Type-Options`, `X-Frame-Options`/`frame-ancestors`, `Referrer-Policy`, `Permissions-Policy`, HSTS — and tested against the widget's actual asset list, including when the widget is embedded in `gu-website`'s page. Copy-pasting a header block without checking the embed case will break the widget or silently weaken the policy.
- **Error handling** never exposes a stack trace, a DB error, a file path, or a prompt to a visitor. Verbose logging is gated by environment to a private log.
- **Audit logging** for every admin action — authoritative-flag changes, curated-entry edits, re-index triggers, corpus additions — append-only: who, what, when.

## Rule 6 — Retrieval and Grounding Discipline

- **Retrieval is expected to refuse.** A threshold configuration that never produces a refusal is misconfigured (Section 6). Tune against the evaluation set, never against a hunch or a demo.
- **Category filtering is a safety control, not an optimisation** — it is what stops a fees question being answered from a news article. Treat a category-leak bug as a safety defect.
- **Quoted mode does not call generation for the figure** (INV-2). Fees, entry requirements and deadlines are returned as authoritative text plus a link plus the academic year they belong to. If you find yourself building a "just tidy up the wording" step over a fees figure, stop: that is INV-2 being eroded.
- **Never split a fees table or an entry-requirements list across chunks** (Section 5.3). Tables are extracted whole with their caption.
- **`reviewed_at` is mandatory** — a document without an owning office, a `reviewed_at` and a review interval is not indexed (INV-11). Stale content is served with a visible caution, not silently.
- **Source conflicts are content defects**, reported to the admin console and fixed by the owning office — never tuned around in the retrieval layer.

## Rule 7 — Evaluation Is a Gate, Not a Report

- The harness in `tests/Eval/` and `bin/evaluate.php` is **built in the first sprint**, not at the end (Section 12).
- The golden set is at least 200 questions authored **with the Registry and Communications** — not invented by an engineer — and must include the specific counts in Section 12 (40 individual-outcome refusals, 20 out-of-corpus refusals, 15 injection attempts, 20 quoted fees/entry-requirement cases, plus Acholi, Luganda and code-switched English).
- **It runs in CI and blocks a merge on regression.** Retrieval quality is a test, not a feeling.
- **Re-run and record after every corpus re-index.** Content changes break retrieval as surely as code does — this is the failure mode most likely to go unnoticed in production.
- Never fabricate an eval number, a hit rate, or a latency figure. If the harness has not been run, say it has not been run.

## Rule 8 — Accessibility (WCAG 2.1 AA) and the Constrained-Connection Budget

- Semantic HTML, correct heading order, keyboard reachability, visible focus, and screen-reader announcement of new answers with focus managed onto them (Section 10). An assistant that a screen-reader user cannot follow is not shipped.
- **Total widget payload under 60 KB including CSS and JS**, verified automatically in CI (INV-9). This is a hard budget, not a target — much of GU's real audience is mobile-first on constrained connections.
- **The no-JavaScript fallback is a first-class path**, with its own tests: a plain form posting to the same endpoint, returning a cited answer as HTML.
- Respect `prefers-reduced-motion`. No streaming-text animation that cannot be turned off.
- Run automated scanning (axe-core/Lighthouse) per release **and** a real manual keyboard and screen-reader pass before major releases — automated tools catch roughly a third of real issues.

## Rule 9 — Logging and Observability

- Every interaction logs the full Section 13 field list under one correlation ID — query, category, retrieved chunk IDs and scores, mode, model and prompt version, answer, citations, refusal reason, latency, tokens, cost, feedback. `InteractionLogger` writes in the same transaction as the response is served (INV-7); a response served without its log entry is a bug.
- **The prompt version is recorded on every interaction.** Prompts are versioned in `config/prompts/` and changed only by merge request (Section 8).
- **The weekly Unanswered Questions Report is a primary deliverable**, not a by-product — ranked by frequency, grouped by category, distributed to the offices that own the content. Section 13 is explicit that it may be worth more to the University than the assistant itself. Build it accordingly.
- Logs are personal data — see Rule 3 for retention, access restriction and redaction-not-deletion.

## Rule 10 — Code Quality and Change Control

- **PSR-12** formatting, **PSR-4** autoloading under `src/`. **PHPStan clean on changed files** before any merge (Section 3).
- **Migrations are numbered, forward-only SQL** under `db/migrations/`, applied by `bin/migrate.php`. No migration framework, no down-migrations, no editing an applied migration — write a new one.
- **No `DELETE` in application code** (INV-12). CI enforces it. If a schema genuinely needs row removal, that is a specification question, not a local decision.
- Tests live in `tests/Unit/`, `tests/Integration/`, `tests/Invariant/` and `tests/Eval/` and mean what those names say. Invariant tests do not get mocked past.
- The `Generator` interface always has a working fake, so the whole system is testable and demonstrable **with no API key and no spend**.
- Add no dependency casually. Each one is reviewed, pinned, and justified in `progress.md`.

## Rule 11 — Standards Maintenance Is Continuous

"Maintain" is half of the instruction and it is the half that usually rots. Therefore:

- **`docs/standards.md`** maps every control in this file to the external standard it comes from and to where it is implemented and tested. When you implement a control, update its row. When you cannot yet, mark it **Not implemented** honestly — never mark a row done because the requirement is written down.
- **`docs/ai-risk-register.md`** (NIST AI RMF MAP/MEASURE) is updated on every new capability, corpus source, model change, or incident — not on a calendar.
- **`docs/data-protection.md`** carries the data-flow table, lawful basis and retention per flow. **No data-collecting feature is built before its row exists.**
- Re-check the named standards for material revisions at each phase gate, and record the check — including a finding of "no change."
- Any change affecting `gu-website` (the widget embed, CSP, shared assets) is a cross-project event: note it in **both** projects' `progress.md` files.

## Rule 12 — progress.md (mandatory, every session)

Maintain `progress.md` at the project root. This is how a new session with fresh context picks up exactly where the last one left off — **always read it before starting work, and always update it before ending a session or completing a task.**

It must contain: (1) these rules in summary, so it is self-contained; (2) a dated completed-tasks log, each entry tagged with its `requirements.md` section; (3) pending work organised by the Section 15 phases; (4) known issues and open questions, starting from Section 18's list; (5) a last-updated timestamp and a one-line "where to resume" note at the top; (6) cross-project notes for `gu-website` and `gu-services`.

Update it after every meaningful chunk of work, not just at the end of a feature.

---

## Rule 13 — Honesty About State

A project whose whole purpose is to not answer confidently when it lacks grounds must be built the same way it behaves.

- Never report a test as passing that was not run, an eval threshold as met that was not measured, or a control as implemented that is only specified.
- Distinguish in every report between **specified**, **implemented**, and **verified** — three different states, and only the third is done.
- When verification was partial, say which part. "Confirmed by reading the code, not by running it against a live corpus" is a complete and acceptable sentence.
