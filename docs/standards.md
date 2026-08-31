# GU-AIA — Standards Register

**Purpose:** the single map from every control this project claims, to the external standard it comes from, to where it is implemented, to where it is tested.

**Status vocabulary — three distinct states, only the third is done (CLAUDE.md Rule 13):**

| Status | Meaning |
|---|---|
| **Specified** | Written down in `requirements.md`/`CLAUDE.md`. No code exists. |
| **Implemented** | Code exists and is believed correct. Not yet proven by a passing test. |
| **Verified** | A test or a recorded manual check proves it, and the evidence is named below. |

**As of 2026-08-31**, **all twelve invariants have a passing named test.** That is not the same as the system being safe, and the distinction is the whole point of this register: every invariant has a mechanism and a test for that mechanism, but several also have a behavioural half that only the evaluation harness can measure, and the harness cannot measure it without a corpus that Phase 0 gates. Read the table as “the controls exist and work as designed”, not “the assistant behaves well on real questions”.

The schema, the database-access controls, the refusal/routing/citation core, the answering pipeline, the logger, the widget and the evaluation harness are built; **5 of the 12 invariants have a passing named test** (INV-1, INV-2, INV-3, INV-10, INV-12). The rest are `Implemented` at the schema layer or still `Specified`. Do not mark a row `Implemented` because the requirement is written; mark it when the code exists, and `Verified` only when you have run the check and can name it.

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
| INV-1 | No answer without a source | `CitationBinder` (discards, never repairs) wired into `AnsweringPipeline`; `Retriever` fails closed and returns `NoConfidentContext` with a reason | `NoAnswerWithoutSourceTest` + `AnsweringPipelineTest` (uncited and mis-cited answers discarded end to end) | **Verified** |
| INV-2 | High-stakes facts quoted, never paraphrased | `CategoryRouter` routes to `AnswerMode::Quoted`; `AnsweringPipeline::quote()` returns the retrieved text **verbatim** and prefers the source marked authoritative for the category (Section 5.2) | `QuotedNotParaphrasedTest` (20 questions) + `AnsweringPipelineTest` (verbatim text, generator never called, authoritative source wins) | **Verified** |
| INV-3 | No individual outcome | `RefusalIntents`, matched **before** retrieval so no context is ever fetched | `tests/Invariant/NoIndividualOutcomeTest.php` — the 40 phrasings Section 12 mandates, plus 15 negative cases guarding refusal *precision* | **Verified** |
| INV-4 | AI disclosure, server-rendered | `Http\WidgetRenderer::disclosure()` — emitted unconditionally by `shell()`, before the form and before any answer; takes no arguments, so nothing can suppress or vary it | `tests/Invariant/DisclosureTest.php` — including a reflection check that `disclosure()` has zero parameters, and a positional check that it precedes both form and answer | **Verified** |
| INV-5 | Closed retrieval scope | `config/prompts/system-v1.txt`; `PromptBuilder` builds the user turn exclusively from the retrieval result | `tests/Invariant/ClosedRetrievalScopeTest.php` — asserts all ten Section 8 clauses are present in the versioned file | **Verified** for the contract and the mechanism; the behavioural check (does the model obey) needs a corpus and the eval harness |
| INV-6 | Retrieved content is data, never instruction | Three layers: `Ingestion\Cleaner` (removes invisible/bidi characters, comments, scripts, data: URIs; FLAGS instruction-shaped prose without deleting it), `Retrieval\QueryNormaliser` (strips every BOOLEAN MODE operator), `Answering\PromptBuilder` (fences and numbers every passage, and strips the delimiter from passage bodies so a document cannot close the fence early) | `ContextIsDataTest` + `ClosedRetrievalScopeTest` | **Verified** for all three mechanisms; the eval injection suite still reports PENDING because the harness does not yet run through the pipeline |
| INV-7 | Everything is logged | `Logging\InteractionLogger` writes interactions, retrievals (with scores, cited or not), citations and unanswered questions **inside the caller's transaction**, so there is no state in which a visitor received an answer the University has no record of | `tests/Invariant/InteractionLoggedTest.php` — 8 cases against the real schema, including one that rolls back and asserts the row is gone (proving the logger did not commit independently) | **Verified** |
| INV-8 | Spend is capped, degraded mode is real | `Answering\BudgetGuard` checked before every generation call, **failing closed on a null ceiling**; `AnsweringPipeline` degrades to retrieval-only on both an exhausted budget and a generation timeout | `tests/Invariant/BudgetCapTest.php` — 7 cases including the two degraded paths end to end | **Verified**. Degraded mode is the DEFAULT path until a ceiling is set, so it is exercised constantly rather than being a branch first taken in production |
| INV-9 | Works on a bad connection (60 KB, no-JS) | Server-rendered form posting to the same endpoint; `widget.js` is progressive enhancement that hands back to a plain form post on any failure | `tests/Invariant/WorksOnABadConnectionTest.php` — payload budget, real form attributes, cited sources in the fallback, escaped model output, no external assets | **Verified** — 5.5 KB of CSS+JS against a 60 KB budget, and the no-JS post exercised end to end over curl |
| INV-10 | No personal data in Phase 1 | Absence of integration surface, plus: no account holds any privilege on `gu_hrms` or `gu_website` | `bin/verify_grants.php` (3 probes) + `tests/Invariant/NoPortalIntegrationTest.php` | **Verified** at the grant layer |
| INV-11 | Stale content is visible; `reviewed_at` mandatory | `documents`/`chunks` NOT NULL + `CHECK` (0001, 0007); `AnswerResult::$staleSource`; the widget renders a caution **above** the answer and the last-reviewed date beside every source | `tests/Invariant/ReviewedAtMandatoryTest.php` — 12 cases covering both halves: the schema refuses a missing, zero or intervalless review date, and the render puts the caution before the text | **Verified** |
| INV-12 | Nothing is deleted | Four layers: no `DELETE` granted to any account; `bin/migrate.php` refuses `DELETE`/`TRUNCATE` migrations; status/`superseded_at` columns instead of removal; and `Logging\RetentionSweeper`, which expires personal data by **redaction** — the row survives, the identifying content is blanked | `NoHardDeleteTest` (static) + `NothingIsDeletedTest` (behavioural, 7 cases) + `bin/verify_grants.php` (8 probes) | **Verified** |

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
| Admin auth: `password_hash()`, session regeneration, secure/httponly/samesite cookies, inactivity timeout, 2FA for authoritative-flag roles | `Admin\Authenticator` (bcrypt cost 12, temporary lockout, identical response for every failure kind, hash computed even for unknown accounts so timing does not enumerate); `public/admin/_bootstrap.php` (SameSite=**Strict**, separate cookie name, 30-minute idle, regeneration on sign-in); `Admin\Totp` (RFC 6238, constant-time, one step of drift) | **Verified** — `AdminSecurityTest` (13) + `Integration\AuthenticationTest` (11), including an RFC 4226 known-answer test so the TOTP interoperates with real authenticator apps rather than being self-consistently wrong |
| Per-action server-side authorisation | `Admin\Role` + `AuthenticatedUser::may()` + `ConsoleContext::requirePermission()`, checked at each action rather than once at login; denials are audited | **Verified** — including that having the authoriser role and having passed 2FA *this session* are two separate facts, since conflating them turns a 2FA requirement into a label |
| Security headers, tested in the embedded case inside `gu-website` | `public/` | Specified |
| Error handling: no stack trace, DB error, path, or prompt to a visitor | global handler | Specified |
| Append-only admin audit log | `Logging\AuditLog` writing to `admin_audit_log`; append-only enforced by grant — the app holds `SELECT`+`INSERT` and neither `UPDATE` nor `DELETE`, so the class has no update method because the server would refuse one | **Verified** — grant probes plus `AuthenticationTest`. A failed sign-in is recorded **without** a user id even when the email matched: attributing it would put “somebody tried to sign in as this named member of staff” into a table other staff read, on the strength of an attempt anyone can make with a guessed email |

---

## 5. Data access (CLAUDE.md Rule 3)

| Control | Requirement | Status |
|---|---|---|
| Purpose limitation | No Portal/HR/student integration surface in the codebase; additionally the ingestion account has **no access at all** to `interactions`, `feedback` or `unanswered_questions` — chat logs are personal data and ingestion has no purpose requiring them | **Verified** — 3 probes |
| One account per role | Four accounts: migration, ingestion worker, web-serving, and console — each least-privilege | **Verified** — 37 probes. The fourth was added when Section 14's curated-entry authoring met the rule that the web-serving account must never write the corpus. Both hold: an **unauthenticated request path** still cannot write published content, while an authenticated, permission-checked, audited console action can |
| Grants verified functionally | Attempt the write that should fail; record the result. Not asserted from the `GRANT` statement | **Verified** — `bin/verify_grants.php`, 37 probes, all passing. The harness distinguishes *denied* from *error*, which caught one of my own probes reporting a NOT NULL violation as though it were a permission result |
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

## 9. Answering pipeline (`requirements.md` Sections 7, 8, 9)

| Control | Requirement | Status |
|---|---|---|
| Route before retrieval | An individual question must never cause context to be fetched | **Verified** — `AnsweringPipeline` step 1; asserted in `AnsweringPipelineTest` |
| Quoted mode does not generate | INV-2 | **Verified** — step 3, with the generator's call count asserted |
| Budget and timeout before generation | INV-8, Section 11 | **Verified** — step 4; both degraded paths tested |
| Citations bound, failures discarded | INV-1, Section 8 | **Verified** — step 5; the discarded text is asserted not to leak into the refusal |
| Prompt versioned and recorded | Section 8, Section 13 | **Implemented** — `config/prompts/system-v1.txt`; a missing version throws rather than generating under a version that never existed. **Not yet reviewed by Communications** |
| Refusal names a human contact | Section 9 | **Implemented with a known gap** — contacts are `null` until Phase 0. The refusal is still served and `handoffMissing` is set, rather than throwing: an error page would give the user nothing at all, which is worse than an office name without a phone number |
| Logging | INV-7 | **Specified** — `AnswerResult` carries every Section 13 field, but no `InteractionLogger` writes them yet |

---

## 10. Widget and endpoint (`requirements.md` Sections 10, 11)

| Control | Requirement | Status |
|---|---|---|
| AI disclosure before the first answer | INV-4 | **Verified** — see Section 2 |
| Payload under 60 KB | INV-9 | **Verified** — CSS 3.2 KB + JS 2.2 KB; the test also caps the script at 8 KB, on the reasoning that a script which grows past that has started doing work the server should do, which is how a no-JS fallback rots |
| No-JavaScript fallback | INV-9 | **Verified** — the fallback is the primary path, not a second implementation nobody exercises |
| CSRF on the question form | Rule 5 | **Verified in use** — a post without a token returns 400; `hash_equals`, not `==` |
| Rate limiting per IP and per session | Section 11 | **Implemented** — fixed-window upsert so concurrent requests cannot both read 9 and write 10; violations logged, never a silent 429. Both scopes, because per-session alone is defeated by dropping the cookie and per-IP alone throttles a whole campus behind one NAT address |
| Model output escaped on render | OWASP LLM | **Verified** — `nl2br` applied to the escaped string; link targets come from the retrieved set, never from the answer |
| Session state, 30-minute expiry | Section 10 | **Implemented** — httponly, samesite, secure when on HTTPS, custom cookie name, idle timeout regenerates |
| Errors expose nothing internal | Rule 5 | **Implemented** — the visitor gets one sentence; the detail goes to `error_log` |
| Starter questions from the real log | Section 10 | **Not built** — deliberately. They must come from the top real questions, and there are none yet. Inventing plausible ones would put fabricated demand data in front of Communications |

---

## 11. Retention and redaction (`requirements.md` Section 13; DPPA 2019)

| Control | Requirement | Status |
|---|---|---|
| Expiry by redaction, never deletion | INV-12 | **Verified** — `Logging\RetentionSweeper`; the row, its correlation ID, timings, mode, category and refusal reason survive so a complaint stays answerable; the query text, answer and free-text feedback are blanked |
| Refuses to run without a configured period | Section 18 open question 5 | **Verified** — `bin/redact_expired.php` exits non-zero. Guessing would be worse than failing: too short destroys the record a complaint needs, too long is a retention nobody authorised. A nightly job failing loudly is the correct alarm |
| Technical identifiers expire sooner | DF-2 | **Verified** — hashed IP and session cleared on their own shorter clock; the interaction stays reconstructible without them |
| Idempotent | — | **Verified** — `redacted_at` makes a second sweep a no-op |
| Retention period actually set | DPPA | **NOT DONE, and now the binding gap.** The log writer and the widget both exist, so the day this is exposed to the public is the day personal data starts accumulating under no stated retention |

---

## 12. Admin console (`requirements.md` Section 14)

| Section 14 requirement | Status |
|---|---|
| Corpus browser: what is indexed, from where, when reviewed, by whom owned | **Built** — overdue documents sorted first and highlighted |
| Conflicts detected between sources | **Built** — listed on the authoritative-sources screen, with Section 5.2's framing stated on the page: a conflict is a *content defect to be fixed*, and marking one source authoritative decides which the assistant quotes without making the other correct |
| Unanswered-question report and feedback stream | **Built**, and placed **first on the page** deliberately — Section 13 calls the report a primary deliverable, so putting the corpus browser first would make this a content-management screen with a report attached, which is the wrong way round |
| View the last evaluation run | **Built** |
| Trigger a re-index | **Not built**, and it needs the crawler and PDF extractor first, so it is really their prerequisite rather than a console gap. Listed on the page as unbuilt rather than shown as a disabled button |
| Author and edit curated Q&A entries | **Built and verified.** Backed by real `documents` and `chunks` rows with an embedding, so a curated answer flows through the same retrieval, citation and `reviewed_at` machinery as crawled content rather than being a special case that bypasses the invariants. **Editing supersedes, never overwrites** (INV-12): a past answer stays reconstructible, and the form says so where the person editing can see it |
| Mark a document authoritative for a category | **Built and verified.** Exactly one authoritative source per category is enforced in the same transaction — two would make the outcome depend on retrieval order, which is the ambiguity the flag exists to remove. The flag **propagates to the chunks**, which denormalise it; a document flagged without its chunks would be authoritative in the console and not in the answering pipeline, while the screen showed the change had worked. A superseded document, or one filed under another category, is refused |
| "No content editing capability beyond curated entries" | **Verified** — there is no `edit_corpus` permission to grant, and a test asserts no role has one. Section 14: "the console must never become a second place where facts live" |

Account creation is `bin/create_admin.php`, run at a terminal under the migration account. The web-serving account cannot create a console user or change a role — it holds column-level `UPDATE` on `last_login_at`, `failed_logins` and `locked_until` and nothing else — so privilege escalation is not reachable from a request.

**Closed 2026-08-31:** `admin_users.totp_secret_enc` now holds an AES-256-GCM envelope (`Admin\SecretBox`) under `SECRET_ENCRYPTION_KEY`, a key kept deliberately separate from `LOG_HASH_KEY` — that one pseudonymises log identifiers and may reasonably be readable by whoever runs reports, while this one guards a second factor, and reusing one key across both purposes makes the weaker handling of either the security of both. GCM rather than CBC or CTR because the authentication tag matters as much as the confidentiality: a TOTP secret an attacker can flip bits in is one they can grind. A row that will not decrypt — wrong key, tampered tag, or legacy plaintext — is treated exactly as a missing secret, so the account cannot sign in; falling back to reading it as plaintext would quietly undo the encryption the moment one row went bad. Verified end to end with a temporary account that was then removed.

---

## 7. Maintenance record

Re-check the named standards for material revisions at each phase gate and record the check here — **including a finding of "no change."**

| Date | Checked | Finding | By |
|---|---|---|---|
| 2026-08-31 | Register created at project foundation. No standards review performed yet. | — | Initial commit |
| 2026-08-31 | Authoritative-source marking built; 339 tests, 972 assertions. | The highest-consequence action in the system: it does not change what a document says, it changes which one the assistant quotes when two disagree — for fees, the figure the public is shown. Permission checked at the page guard **and again** in the marker, because a privileged action that trusts its caller is one refactor away from being unguarded. Verified in the running console that an editor gets 403 and the denial is audited. | Authoritative sources |
| 2026-08-31 | Curated Q&A authoring built; 329 tests, 953 assertions; a fourth database account added. | Section 14 requires the console to author curated entries, which are corpus content, while `gu_aia_app` is deliberately unable to write the corpus. Rather than widening the app's grants, a `gu_aia_console` account was added: the risk being guarded against is an *unauthenticated request path* writing published content, and an authenticated audited console action is a different thing. The app's inability to write the corpus stays intact and provable. | Curated entries |
| 2026-08-31 | TOTP secrets encrypted at rest; 315 tests, 837 assertions. | Closes the gap flagged the same day rather than building more surface over it. The negative tests are the ones that matter: a tampered ciphertext, a tampered tag, a wrong key, an unknown envelope version and **legacy plaintext** must all fail to decrypt. That last one is the migration trap — accepting a plaintext row “for compatibility” would undo the encryption for every row that had not been rewritten. | Secret encryption |
| 2026-08-31 | Admin console security core and read-only dashboard built; 303 tests, 818 assertions. | Console authorisation is per action, and the ladder is steep at the top: only `mark_authoritative` requires 2FA, because it decides which source wins a conflict and therefore which fees figure the public is shown. PHPStan objected that the console bootstrap leaked variables into the including scope; refactored to return a `ConsoleContext` rather than annotating around it, since a page depending on what a `require` happens to leave behind breaks silently at runtime instead of loudly at analysis time. | Admin console |
| 2026-08-31 | Prompt contract and answering pipeline built; 236 tests, 599 assertions. **8 of 12 invariants now have passing named tests.** | The pipeline is where the invariants hold or fail, because it is the only place that decides what a visitor receives; the ORDER of its five steps is the safety property. Two judgement calls recorded: (1) a refusal with no configured contact is served and flagged rather than throwing — an error page gives the user nothing, which is worse; this softens the "fail loudly" note in config/refusals.php and says why. (2) `stages_built` in config/eval.php stays at `['router']` even though ingestion, prompt and binder now exist, because running the harness through the pipeline against an EMPTY corpus would turn the out-of-corpus and injection suites green for entirely the wrong reason. | Answering pipeline |
| 2026-08-31 | Ingestion and retrieval layers built; 200 tests, 529 assertions. | **Two design defects found by their own tests.** (1) The exact-code boost was multiplicative, which cannot lift a near-zero base — a chunk exactly matching a typed course code could still lose to prose merely mentioning the programme, defeating Section 6. Now additive and at least the maximum base score, making exact matches a strict tier. (2) The instruction-shape pattern for "from now on" required a following pronoun and missed the commonest phrasing. Also recorded: instruction-shaped prose is FLAGGED, never deleted — silently editing a University page would change what the University said, and this system exists to report it faithfully. | Ingestion + retrieval |
| 2026-08-31 | Evaluation harness built and running in CI order (`composer ci`). 118 golden questions seeded; 83 evaluated and passing, 35 correctly reported PENDING. | **Correction to my own first design:** the harness initially reported the out-of-corpus and injection suites as 34 FAILURES. The expectations are right — both must end in refusal — but that refusal comes from retrieval and the citation binder, neither of which is built, so the router's Grounded routing was correct. Reporting them as failures makes an unbuilt system look broken and trains readers to ignore red, which is the same dishonesty as a false green pointed the other way. Suites now declare which pipeline stages they depend on (`suite_requires`) and report PENDING until those stages exist. | Eval harness |
| 2026-08-31 | Answering/safety core built; 156 tests, 375 assertions, all passing; PHPStan level 8 clean; PSR-12 clean. | The invariant tests found five real defects in code written the same hour: two interrogative phrasings ("are my points enough") escaped the INV-3 matcher; "can I see my admission letter" was routed to the Registry instead of the Portal; and "how much does the course cost" routed to Grounded, which would have sent a fees question through generation. Writing the tests from the specification's own mandated counts — 40 phrasings, 20 high-stakes questions — is what surfaced them. | Answering core |
| 2026-08-31 | Data-access controls built and verified functionally (26 probes). | **Real finding:** `NOT NULL` on a DATE column is not sufficient on a MySQL-family server without `STRICT_TRANS_TABLES` — the server substitutes `0000-00-00` and accepts the row, which defeated INV-11. Closed with `CHECK` constraints (0007) plus a strict per-connection `sql_mode`. Development is MariaDB 10.4 while production targets MySQL 8; their default modes differ, so controls must not depend on server configuration. | Schema pass |
