# GU-AIA — AI Risk Register

**Framework:** NIST AI Risk Management Framework 1.0 — this document is the **MAP** and **MEASURE** artefact. GOVERN lives in `CLAUDE.md` and `DICTS/POL/AI/001`; MANAGE is the treatment column below plus the eval harness.
**Supporting guidance:** ISO/IEC 23894, ISO/IEC 42001.

> **Update trigger (CLAUDE.md Rule 11): on every new capability, corpus source, model change, or incident — not on a calendar.** A register reviewed annually is a register that is wrong for eleven months.

**Status as of 2026-08-31:** identified at project foundation. No treatment is implemented yet; the "Treatment" column describes the designed control and the "State" column says so honestly.

---

## MAP — context

| | |
|---|---|
| **Purpose** | Answer public questions about Gulu University from published University content, with a citation on every answer. |
| **Users** | Prospective students, parents, current students, the public. Predominantly mobile, often on constrained connections, many deciding whether to apply. |
| **Decisions it influences** | Whether to apply; what fees to budget for; when to meet a deadline. **Materially consequential to individuals**, which is why the invariants are strict. |
| **Explicitly not** | A general-purpose chatbot; an adviser; anything that speaks to an individual's admission, eligibility or standing. |
| **Autonomy** | None. The generator has no tools and cannot act. It produces text from supplied context. |
| **Human oversight** | Communications and the Registry own the content; the unanswered-question report and feedback stream make behaviour visible outside DICTS; Communications signs off before Phase 1 exits pilot. |

---

## MAP + MEASURE — risks

Impact and likelihood are the assessed pre-treatment position.

| # | Risk | Impact | Likelihood | Treatment (designed) | Measured by | State |
|---|---|---|---|---|---|---|
| R-1 | **A fabricated fees figure.** Assistant generates a plausible amount not in the corpus; a family budgets on it | Severe — financial harm to an individual | High without treatment; generation invents figures readily | INV-2 quoted mode: fees never pass through generation. INV-1: no answer without a source | 20 quoted fees/entry-requirement eval cases | Not implemented |
| R-2 | **An implied admission outcome.** "With those grades you should be fine" | Severe — an individual forgoes other options | High; it is the most natural thing for a model to say | INV-3: refusal intent matched *before* retrieval, routed to the Registry | 40 individual-outcome phrasings in the eval set | Not implemented |
| R-3 | **Stale content served as current.** Last year's deadline answered as this year's | High — a missed deadline is unrecoverable | High; content ages silently | INV-11: `reviewed_at` mandatory, unindexed without it; visible caution past review interval; academic year stated on every figure and date | `reviewed_at` invariant test; conflict reports | Not implemented |
| R-4 | **Prompt injection via the corpus.** A crawled page or uploaded PDF carries instructions the model follows | High — the assistant's behaviour is subverted in public | Moderate, rising as the system becomes known | INV-6: instruction-stripping at ingestion, context delimited and labelled as data, no instruction followed from context or user input | ≥15 injection cases in the eval suite | Not implemented |
| R-5 | **Answering from parametric knowledge.** Model "knows" something about Gulu University and says it, unsourced | High — confidently wrong, uncitable, untraceable | Moderate to high | INV-5: closed retrieval scope in the prompt contract; citation binder discards uncited responses | Eval cases asking real-world facts absent from the corpus | Not implemented |
| R-6 | **Silent overspend or silent failure.** Budget exhausted mid-admissions-cycle | Moderate — service degrades at the worst moment | Moderate | INV-8: 80% alert, 100% degrade to retrieval-only. Degraded mode is a tested code path | Budget cap invariant test; degraded-mode exercise | Not implemented |
| R-7 | **Exclusion of the intended audience.** Widget too heavy, or unusable by keyboard/screen reader | High — excludes exactly the constrained-connection, mobile-first majority | Moderate | INV-9: 60 KB budget in CI, no-JS fallback with its own tests. WCAG 2.1 AA | CI payload check; axe/Lighthouse; manual keyboard + screen-reader pass | Not implemented |
| R-8 | **Chat logs mishandled.** Personal data retained indefinitely or over-shared | Moderate to high — DPPA breach, reputational | Moderate | Rule 3 + `data-protection.md`: stated basis and retention, restricted access, redaction not deletion | Access-control verification; retention configured before launch | Not implemented |
| R-9 | **Cross-border transfer undisclosed.** Queries leave Uganda via a hosted API without the notice saying so | Moderate — transparency failure, DPPA exposure | High if unaddressed — it is the default outcome of a hosted API | State it plainly in the privacy notice; confirm no-training-on-inputs; `Generator` interface keeps the decision reversible | Pre-launch checklist in `data-protection.md` | Open question (Section 18 #2) |
| R-10 | **Overclaimed language support.** Acholi or Luganda advertised, quality poor | Moderate — worse than not offering it, because it is trusted | Moderate | Test in the eval set and report honestly before claiming support | Eval results per language | Open question (Section 18 #3) |
| R-11 | **Scope creep to a general chatbot.** "It would be easy to also let it…" | High — Section 17's first prohibition, and the route to every other risk here | Moderate; pressure is social, not technical | Section 17 out-of-scope list; category router refuses off-topic | Off-topic refusal cases | Not implemented |
| R-12 | **The mirror effect.** The assistant surfaces the website's own contradictions and staleness at scale, in public (Section 19) | Reputational, but **this is the system working, not a defect** | Certain | Conflict detection reported to the admin console; the Unanswered Questions Report routed to owning offices. **Sponsor must understand this before Phase 0 begins** | Conflict reports; weekly report | Not implemented |
| R-13 | **Phase 2 surface added early.** Portal/student-record integration stubbed "ready for later" | Severe — turns a no-personal-data system into a personal-data system without the controls | Moderate; it is a natural engineering instinct | INV-10: the integration surface does not exist in the codebase. Phase 2 is a separate specification, explicitly not begun in this repository | `NoPortalIntegrationTest` | Not implemented |

---

## MANAGE — review log

| Date | Trigger | Change | By |
|---|---|---|---|
| 2026-08-31 | Project foundation | Register created; R-1 to R-13 identified. No treatment implemented. | Initial commit |
