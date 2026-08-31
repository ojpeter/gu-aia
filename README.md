# GU-AIA — Gulu University AI Assistant

A retrieval assistant for gu.ac.ug. It answers questions **only** from a curated corpus of published University content, cites its source on every answer, and refuses and routes to a named human contact when the corpus does not support an answer.

Owned by the Directorate of ICT Services. Business-owned by the Directorate of Communications, with the Academic Registrar for admissions content. Governed by `DICTS/POL/AI/001`.

**It is not a general-purpose chatbot and must never behave as one.**

---

## Current status — foundations only

**Nothing works yet.** As of 2026-08-31 this repository contains the specification, the engineering rules, the standards register, and the scaffolding. There is no ingestion, no retrieval, no answering, no widget, and no schema. Do not read the configuration files as a description of a running system.

See `progress.md` for exactly what exists and what is next.

## Read these, in this order

| File | What it is |
|---|---|
| `requirements.md` | The engineering contract. 19 sections. **Read Section 2 (the twelve invariants) before writing any code.** |
| `CLAUDE.md` | How to build here — 13 project rules, including the AI-governance and data-access standards. |
| `progress.md` | Where the work actually stands. Read before starting, update before stopping. |
| `docs/standards.md` | Every control mapped to the external standard it comes from, and to its real state: Specified / Implemented / Verified. |
| `docs/ai-risk-register.md` | NIST AI RMF MAP+MEASURE artefact. Updated on every capability, source, model change or incident. |
| `docs/data-protection.md` | DPPA 2019 data-flow table, lawful basis, retention. **No data-collecting feature is built before its row exists.** |

## The one thing to understand first

> The failure mode that matters is not "the assistant could not answer." It is "the assistant answered confidently and was wrong." A refusal costs a user thirty seconds. A fabricated fees figure costs someone a term.

Every trade-off in this codebase resolves toward saying less.

## Stack

PHP 8.2+, MySQL 8 (schema `gu_aia`), hybrid retrieval (MySQL `FULLTEXT` candidate generation then in-process vector rerank), embeddings as a binary blob on the chunk row, generation behind a `Generator` interface with a working fake, server-rendered widget with no framework, forward-only numbered SQL migrations, PHPUnit + PHPStan.

**No vector database, no new language, no new database engine** — the reasoning is in `requirements.md` Section 3 and is settled.

## Local setup

```bash
cp .env.example .env          # then fill it in; .env is never committed
composer install
php bin/migrate.php --status  # list applied and pending migrations
php bin/migrate.php           # apply them
```

`GENERATOR_DRIVER=fake` is the default: the whole system must be testable and demonstrable **with no API key and no spend**.

## Checks

```bash
composer test             # full suite
composer test:invariant   # the release gate — requirements.md Section 2
composer analyse          # PHPStan
composer lint             # PSR-12
composer eval             # evaluation harness (Section 12)
```

## Related projects

Siblings in the same `htdocs` root, **not** the same project:

- **`gu-website`** — the public site the widget will be embedded in.
- **`gu-services`** — the eServices Portal. GU-AIA has **no** integration with it in Phase 1, and the integration surface must not exist in this codebase (INV-10). Portal-authenticated answering is Phase 2 and is a separate specification.
