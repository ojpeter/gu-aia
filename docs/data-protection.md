# GU-AIA — Data Protection

**Governing law:** Uganda Data Protection and Privacy Act 2019, and the Data Protection and Privacy Regulations 2021.
**Governing policy:** `DICTS/POL/AI/001` — University Policy on the Use of Artificial Intelligence.

> **Rule (CLAUDE.md Rule 3, Rule 11):** no data-collecting feature is built before its row exists in the flow table below, with a stated lawful basis and retention period. Adding the row is part of building the feature, not paperwork after it.

**Status as of 2026-08-31:** foundations only. **No data is collected, stored or processed yet** — the flows below are the designed state, not the current one. Nothing in this document may be read as a description of a running system.

---

## 1. The Phase 1 boundary

Phase 1 handles **no personal data drawn from University systems** — no login, no student records, no account lookup, no fees balance, no results (INV-10). This is enforced structurally: *the integration surface does not exist in the codebase*, and must not be added in anticipation of Phase 2.

What Phase 1 *does* process is personal data nonetheless, and the most commonly made mistake here is assuming otherwise: **a question can identify the person who asked it.** "Can I still apply for BSc Computer Science with 12 points from Gulu SS in 2025" is personal data whether or not anyone logged in. Chat logs are therefore in scope for the DPPA throughout.

---

## 2. Data flow table

| # | Flow | Data collected | Lawful basis (DPPA 2019) | Retention | Access | Status |
|---|---|---|---|---|---|---|
| DF-1 | Assistant query and answer log (INV-7) | Query text, category, retrieved chunk IDs and scores, mode, model and prompt version, answer, citations, refusal reason, latency, tokens, cost, correlation ID, timestamp | To be confirmed with the University's data protection function — proposed: performance of a task carried out in the public interest (provision of public University information) | **Configuration value, not yet set** — Section 18 open question 5. Must be set before launch and published in the privacy notice. Redaction at expiry, never deletion (INV-12) | Named DICTS roles only; aggregate reports to Communications and the Registry | Designed, not built |
| DF-2 | Technical/abuse data | IP address (for rate limiting), session identifier | Legitimate interest — service protection against abuse | Shorter than DF-1; to be set with DF-1 | DICTS only | Designed, not built |
| DF-3 | Feedback | Thumbs up/down, optional free-text comment, linked to the DF-1 correlation ID | Consent — the user chooses to submit it | As DF-1 | As DF-1 | Designed, not built |
| DF-4 | Unanswered Questions Report | Aggregated, ranked question text grouped by category | Derived from DF-1 | Aggregate; retained as an institutional content record | Communications, copied to the Registry (Section 18 open question 6 — proposed, not confirmed) | Designed, not built |
| DF-5 | Corpus content | Published University content only. **Personal data in a crawled page is an ingestion defect**, not an accepted flow — exclude and report to the owning office | n/a — published institutional content | Superseded, never deleted (INV-12) | Public | Designed, not built |
| DF-6 | Admin console accounts | Staff name, work email, role, credential hash, audit trail of actions | Employment/administrative necessity | Duration of role plus the audit retention period | DICTS | Designed, not built |
| DF-7 | Query text sent to the generation API | The user's question plus retrieved context | See cross-border transfer below | Per provider contract — **must be established before launch** | The generation provider | Designed, not built |

---

## 3. Cross-border transfer

`requirements.md` Section 18, open question 2. **If generation runs on a hosted API, DF-7 means user queries leave Uganda.**

The interim position is the honest one: **assume it does, and state it plainly in the privacy notice.** Do not launch with a privacy notice that is silent on this. Revisit if a locally-deployed open-weight model is funded — which is exactly why the `Generator` interface exists (open question 1), so this remains a reversible decision.

Before launch, confirm: the provider's data-processing terms, whether inputs are retained by the provider, and — critically — that inputs are **not used to train the provider's models**, which `DICTS/POL/AI/001` prohibits without consent and Committee approval.

---

## 4. Data subject rights

The DPPA gives rights of access, correction, and objection. Because Phase 1 stores no account identity, a subject access request against DF-1 is only satisfiable where the requester can identify their own session — this limitation must be stated in the privacy notice rather than papered over.

**Erasure is handled by redaction, not deletion** (INV-12): the record remains so that a past answer can be reconstructed for a complaint, with the identifying content redacted. State this in the privacy notice too — it is a deliberate design choice with a stated reason, and it is defensible only if it is disclosed.

---

## 5. Before launch — required, not optional

- [ ] Retention periods set in configuration (DF-1, DF-2) and agreed with the University's data protection function
- [ ] Privacy notice published, linked from the widget (`requirements.md` Section 16), covering: what is logged, for how long, who sees it, cross-border transfer, redaction-not-deletion, and the AI disclosure
- [ ] Generation provider terms confirmed, including no-training-on-inputs
- [ ] Access control on logs implemented and verified
- [ ] Entry in the Register of Approved AI Tools under `DICTS/POL/AI/001`
- [ ] Data protection impact assessment completed with the University's data protection function
