# GU-AIA — Standards Register

**Purpose:** the single map from every control this project claims, to the external standard it comes from, to where it is implemented, to where it is tested.

**Status vocabulary — three distinct states, only the third is done (CLAUDE.md Rule 13):**

| Status | Meaning |
|---|---|
| **Specified** | Written down in `requirements.md`/`CLAUDE.md`. No code exists. |
| **Implemented** | Code exists and is believed correct. Not yet proven by a passing test. |
| **Verified** | A test or a recorded manual check proves it, and the evidence is named below. |

**As of 2026-08-31**, the schema, the database-access controls, the refusal/routing/citation core, and the evaluation harness are built; **5 of the 12 invariants have a passing named test** (INV-1, INV-2, INV-3, INV-10, INV-12). The rest are `Implemented` at the schema layer or still `Specified`. Do not mark a row `Implemented` because the requirement is written; mark it when the code exists, and `Verified` only when you have run the check and can name it.

Where a control is enforced at more than one layer, the row says so — several of the data-access controls below are now enforced in the **grant table**, not only in application code that does not yet exist.

---

## 1. Governing instruments

| Ref | Instrument | Applies to | Notes |
|---|---|---|---|
| GOV-1 | `DICTS/POL/AI/001` — University Policy on the Use of Artificial Intelligence | The whole system | **Governing document. Outranks every external framework below where they differ.** Prohibits fine-tuning on University content and training external models on University/student content without consent and Committee approval. Requires entry in the Register of Approved AI Tools before launch. |
| GOV-2 | Uganda Data Protection and Privacy Act 2019, and the Data Protection and Privacy Regulations 2021 | Chat logs, feedback, any personal data in the corpus | Chat logs are personal data even with no login. Lawful basis and retention per flow live in `data-protection.md`. |
| GOV-3 | NIST AI Risk Management Framework 1.0 | Risk process | GOVERN / MAP / MEASURE / MANAGE. MAP+MEASURE artefact is `ai-risk-register.md`. |
| GOV-4 | ISO/IEC 42001 (AI management system) | Lifecycle controls | Documented purpose and scope, defined roles, pre-deployment impact assessment, post-deployment monitoring, decommissioning path. |
| GOV-5 | ISO/IEC 23894 (AI risk management guidance) | Risk process | Used alongside GOV-3 for risk identification and treatment shape. |
| GOV-6 | EU AI Act Article 50 (transparency toward natural persons) | Disclosure (INV-4) | **Used as a benchmark, not claimed as compliance.** Uganda does not impose this. "A person must be told plainly they are interacting with an AI system" is the correct floor for a public university service regardless. |
| GOV-7 | OWASP Top 10 for LLM Applications | LLM-specific security | See Section 3. |
| GOV-8 | OWASP Application Security Verification Standard (ASVS) | Ordinary web security | See Section 4. |
| GOV-9 | WCAG 2.1 Level AA | Widget and admin console | `requirements.md` Section 10. |
| GOV-10 | PSR-12, PSR-4 | Code style, autoloading | `requirements.md` Section 3. |

---

## 2. Invariants (`requirements.md` Section 2) — the project's own top-level controls

Each requires a named test in `tests/Invariant/`. An invariant without a passing test is not implemented, whatever the code appears to do.

| Invariant | Control | Implementation | Test | Status |
|---|---|---|---|---|
| INV-1 | No answer without a source | `CitationBinder` — discards, never repairs; zero citations or a citation outside the retrieved set both fail | `tests/Invariant/NoAnswerWithoutSourceTest.php` (8 cases) | **Verified** for the binder; retrieval threshold half still Specified |
| INV-2 | High-stakes facts quoted, never paraphrased | `CategoryRouter` routes fees/entry-requirements/deadlines to `AnswerMode::Quoted`, which `callsGenerator()` refuses | `tests/Invariant/QuotedNotParaphrasedTest.php` — 20 questions, each asserting the generator was **never invoked** | **Verified** for routing; the quoted-answer renderer is still Specified |
| INV-3 | No individual outcome | `RefusalIntents`, matched **before** retrieval so no context is ever fetched | `tests/Invariant/NoIndividualOutcomeTest.php` — the 40 phrasings Section 12 mandates, plus 15 negative cases guarding refusal *precision* | **Verified** |
| INV-4 | AI disclosure, server-rendered | *not built* | `tests/Invariant/DisclosureTest.php` | Specified |
| INV-5 | Closed retrieval scope | *not built* | `tests/Invariant/ClosedRetrievalScopeTest.php` | Specified |
| INV-6 | Retrieved content is data, never instruction | `Ingestion\Cleaner` removes invisible/bidi characters, HTML comments, script blocks and data: URIs, and FLAGS instruction-shaped prose without deleting it; `Retrieval\QueryNormaliser` strips every BOOLEAN MODE operator | `tests/Invariant/ContextIsDataTest.php` (17 cases) | **Partly verified** — ingestion and query halves tested; the prompt-delimiting half needs `config/prompts/system-v1.txt`, and the eval injection suite reports PENDING accordingly |
| INV-7 | Everything is logged | Schema in place (`interactions`, `interaction_retrievals`, `interaction_citations`) with the full Section 13 field list | `tests/Invariant/InteractionLoggedTest.php` | **Implemented** (schema only) — no logger written |
| INV-8 | Spend is capped, degraded mode is real | `budget_periods` in place; `AnswerMode::Degraded` cannot call the generator; `FakeGenerator::willTimeOut()` exists to exercise the fallback | `tests/Invariant/BudgetCapTest.php` | **Implemented** (schema + mode) — no budget guard written |
| INV-9 | Works on a bad connection (60 KB, no-JS) | *not built* | `tests/Invariant/PayloadBudgetTest.php`, `NoJsFallbackTest.php` | Specified |
| INV-10 | No personal data in Phase 1 | Absence of integration surface, plus: no account holds any privilege on `gu_hrms` or `gu_website` | `bin/verify_grants.php` (3 probes) + `tests/Invariant/NoPortalIntegrationTest.php` | **Verified** at the grant layer |
| INV-11 | Stale content is visible; `reviewed_at` mandatory | `documents`/`chunks`: NOT NULL + `CHECK` constraints (0001, 0007) | `tests/Invariant/ReviewedAtMandatoryTest.php` | **Implemented** (schema layer) — bypass via non-strict `sql_mode` found and closed, see 0007; the answer-rendering half is still Specified |
| INV-12 | Nothing is deleted | Three layers: no `DELETE` granted to any account (`db/accounts.sql`); `bin/migrate.php` refuses `DELETE`/`TRUNCATE` migrations; status/`superseded_at` columns instead of removal | `bin/verify_grants.php` (5 probes) + `tests/Invariant/NoHardDeleteTest.php` | **Verified** at the grant and runner layers; CI grep still Specified |

---

## 3. LLM security — OWASP Top 10 for LLM Applications (GOV-7)

| Risk | Control required | Where | Status |
|---|---|---|---|
| Prompt injection | Instruction-stripping at ingestion; context and user input delimited and labelled as data; ≥15 injection cases in the eval suite | `src/Ingestion/Cleaner.php`; the 15 cases in `config/eval/golden_set.php`; delimiting still needs the prompt | **Partly implemented** — the harness reports the injection suite as PENDING, not as passing |
| Insecure output handling | Model output escaped on render; no raw echo; link targets restricted to the retrieved set; never reaches shell/SQL/filesystem | `src/Answering/`, `templates/` | Specified |
| Excessive agency | Generator has no tools, no function calling, no ability to act | `src/Answering/Generator.php` — the interface takes two strings and returns text; it cannot fetch, write, or reach the database | **Implemented** |
| Unbounded consumption | Rate limit per IP and per session; monthly budget ceiling, 80% alert, 100% degrade to retrieval-only | `src/Safety/`, `config/budget.php` | Specified |
| Sensitive information disclosure | System prompt, retrieval scores, chunk IDs logged but never rendered | `src/Logging/`, `templates/` | Specified |
| Supply chain | Dependencies reviewed and pinned; model provider isolated behind `Generator` | `composer.json` + `composer.lock` (29 dev packages, no runtime dependencies); `src/Answering/Generator.php` | **Implemented** |
| Misinformation (ungrounded generation) | INV-1 + INV-2 + INV-5, measured by the eval harness | `src/Answering/CitationBinder.php`, `CategoryRouter.php`, `tests/Eval/` | **Partly verified** — the binder and the quoted-mode routing are tested; the eval harness is not built |

---

## 4. Application security — OWASP ASVS (GOV-8)

| Control | Where | Status |
|---|---|---|
| Server-side validation through one shared validator | `src/Safety/` | Specified |
| Upload: extension whitelist, size cap, `finfo` MIME re-validation; reject scanned PDFs with no text layer | `src/Ingestion/` | Specified |
| Output encoding via one shared escaping helper | `templates/` | Specified |
| CSRF token on every state-changing form, incl. feedback and the no-JS post | `public/`, `src/Safety/` | Specified |
| Admin auth: `password_hash()`, session regeneration, secure/httponly/samesite cookies, inactivity timeout, 2FA for authoritative-flag roles | `src/Admin/` | Specified |
| Per-action server-side authorisation | `src/Admin/` | Specified |
| Security headers, tested in the embedded case inside `gu-website` | `public/` | Specified |
| Error handling: no stack trace, DB error, path, or prompt to a visitor | global handler | Specified |
| Append-only admin audit log | `admin_audit_log` (0004); append-only enforced by grant — the app holds `SELECT`+`INSERT` and neither `UPDATE` nor `DELETE`, so a compromised console session cannot rewrite history | **Verified** at the grant layer (2 probes); the writing code in `src/Logging/` is still Specified |

---

## 5. Data access (CLAUDE.md Rule 3)

| Control | Requirement | Status |
|---|---|---|
| Purpose limitation | No Portal/HR/student integration surface in the codebase; additionally the ingestion account has **no access at all** to `interactions`, `feedback` or `unanswered_questions` — chat logs are personal data and ingestion has no purpose requiring them | **Verified** — 3 probes |
| One account per role | Web-serving, ingestion worker, and migration accounts separate, each least-privilege | **Verified** — `db/accounts_bootstrap.sql` + `db/accounts.sql` |
| Grants verified functionally | Attempt the write that should fail; record the result. Not asserted from the `GRANT` statement | **Verified** — `bin/verify_grants.php`, 26 probes, 26 passed on 2026-08-31. Re-run after any grant change or new table |
| Prepared statements everywhere | Including the `FULLTEXT ... AGAINST` clause — the highest-risk query in the system. Bind it; sanitise BOOLEAN MODE operators separately | Specified — but `PDO::ATTR_EMULATE_PREPARES => false` is already set for every connection in `config/pdo_options.php`, so prepares are real rather than client-side interpolation |
| Explicit column whitelists | Every `INSERT`/`UPDATE` | Specified |
| No hard deletion | No account granted `DELETE` or `DROP`; runner refuses `DELETE`/`TRUNCATE`; CI grep still to build | **Verified** at grant + runner layers |
| Secrets out of the repository | `.env` gitignored; `.env.example` carries key names and empty values only; rotation schedule stated | **Implemented** (`.gitignore`, `.env.example` present) |
| Corpus minimisation | Never index login-protected, draft, or archived pages; personal data in a crawled page is an ingestion defect | Specified |
| Encryption in transit | TLS to site, to generation API, to MySQL if not localhost | Specified |

---

## 6. Accessibility and performance (GOV-9)

| Control | Requirement | Status |
|---|---|---|
| WCAG 2.1 AA | Keyboard reachable, screen-reader announced, focus managed onto new answers | Specified |
| Payload budget | Widget total under 60 KB incl. CSS and JS, checked in CI (INV-9) | Specified |
| No-JS fallback | Plain form post returning a cited answer as HTML, with its own tests | Specified |
| Reduced motion | `prefers-reduced-motion` respected; streaming animation defeatable | Specified |
| Release checks | axe-core/Lighthouse per release **plus** a manual keyboard and screen-reader pass before major releases | Specified |

---

## 8. Retrieval controls (`requirements.md` Section 6)

| Control | Requirement | Status |
|---|---|---|
| Bound `FULLTEXT` parameter | The user's raw question reaches `MATCH ... AGAINST`. Bound, always | **Implemented** — `Retrieval\CandidateGenerator`; `LIMIT` is cast and clamped because it cannot be bound under native prepares |
| BOOLEAN MODE operators sanitised | Binding stops injection; it does **not** stop a stranger steering the search. `-fees` instructs the engine to exclude every document mentioning fees | **Verified** — `Retrieval\QueryNormaliser`, 7 operator cases in `ContextIsDataTest` |
| Category filtering as a safety control | Stops a fees question being answered from a news article | **Implemented** — optional category clause on both retrieval arms; a leak is a safety defect, not a relevance bug |
| Exact code match boosted | "A user typing a code knows what they want" | **Verified** — `Retrieval\Reranker`; boost is **additive and ≥ the maximum base score**, after a multiplicative version was found unable to lift a low base |
| Threshold expected to produce refusals | "A configuration that never refuses is misconfigured" | **Implemented, fails closed** — `score_threshold` is `null` until tuned against the eval set, and a null threshold refuses everything with reason `retrieval_threshold_not_configured` |
| Never split a fees table | Section 5.3 | **Verified** — `Ingestion\Chunker`, atomic blocks are their own chunk at any size and keep their caption |
| Embeddings on the chunk row | No separate vector database | **Implemented** — `Retrieval\VectorCodec` (little-endian float32); `Ingestion\HashingEmbedder` is a **lexical, not semantic** baseline pending Section 18 open question 1, and is documented as such |

---

## 7. Maintenance record

Re-check the named standards for material revisions at each phase gate and record the check here — **including a finding of "no change."**

| Date | Checked | Finding | By |
|---|---|---|---|
| 2026-08-31 | Register created at project foundation. No standards review performed yet. | — | Initial commit |
| 2026-08-31 | Ingestion and retrieval layers built; 200 tests, 529 assertions. | **Two design defects found by their own tests.** (1) The exact-code boost was multiplicative, which cannot lift a near-zero base — a chunk exactly matching a typed course code could still lose to prose merely mentioning the programme, defeating Section 6. Now additive and at least the maximum base score, making exact matches a strict tier. (2) The instruction-shape pattern for "from now on" required a following pronoun and missed the commonest phrasing. Also recorded: instruction-shaped prose is FLAGGED, never deleted — silently editing a University page would change what the University said, and this system exists to report it faithfully. | Ingestion + retrieval |
| 2026-08-31 | Evaluation harness built and running in CI order (`composer ci`). 118 golden questions seeded; 83 evaluated and passing, 35 correctly reported PENDING. | **Correction to my own first design:** the harness initially reported the out-of-corpus and injection suites as 34 FAILURES. The expectations are right — both must end in refusal — but that refusal comes from retrieval and the citation binder, neither of which is built, so the router's Grounded routing was correct. Reporting them as failures makes an unbuilt system look broken and trains readers to ignore red, which is the same dishonesty as a false green pointed the other way. Suites now declare which pipeline stages they depend on (`suite_requires`) and report PENDING until those stages exist. | Eval harness |
| 2026-08-31 | Answering/safety core built; 156 tests, 375 assertions, all passing; PHPStan level 8 clean; PSR-12 clean. | The invariant tests found five real defects in code written the same hour: two interrogative phrasings ("are my points enough") escaped the INV-3 matcher; "can I see my admission letter" was routed to the Registry instead of the Portal; and "how much does the course cost" routed to Grounded, which would have sent a fees question through generation. Writing the tests from the specification's own mandated counts — 40 phrasings, 20 high-stakes questions — is what surfaced them. | Answering core |
| 2026-08-31 | Data-access controls built and verified functionally (26 probes). | **Real finding:** `NOT NULL` on a DATE column is not sufficient on a MySQL-family server without `STRICT_TRANS_TABLES` — the server substitutes `0000-00-00` and accepts the row, which defeated INV-11. Closed with `CHECK` constraints (0007) plus a strict per-connection `sql_mode`. Development is MariaDB 10.4 while production targets MySQL 8; their default modes differ, so controls must not depend on server configuration. | Schema pass |
