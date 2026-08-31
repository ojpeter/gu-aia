# Migrations

Numbered, forward-only SQL, applied by `bin/migrate.php` in filename order (`requirements.md` Section 3).

## Conventions

- Filename: `NNNN_short_description.sql`, zero-padded, e.g. `0001_create_corpus_documents.sql`.
- **Forward-only.** There are no down-migrations, by design.
- **Never edit an applied migration.** Write a new one. The ledger (`schema_migrations`) records filenames, and an edited file would leave a database that no longer matches the migration it claims to have run.
- **Data-only** migrations run in a transaction and are atomic. **DDL migrations are not, and cannot be**: MySQL and MariaDB commit implicitly on every `CREATE`/`ALTER`/`DROP`/`RENAME`, so the first statement ends any transaction the runner opened. The runner detects which kind it is holding and only wraps the data-only ones — wrapping DDL produced a real `There is no active transaction` failure on the first run, and pretending otherwise would have been worse than the honest limitation. A DDL migration that fails halfway leaves partial state; the fix is a **new forward migration**, never an edit to the failed one.
- A failure stops the run; nothing after it is applied.
- Index every foreign key and every column used in `WHERE` / `ORDER BY` / `JOIN`.

## INV-12 is enforced here, not just reviewed

`bin/migrate.php` **refuses** any migration containing `DELETE FROM` or `TRUNCATE`. Nothing is deleted — superseded rows are marked superseded, so that a past answer can be reconstructed for a complaint. If a schema genuinely appears to need row removal, that is a specification question, not a local decision.

## The ledger

`schema_migrations` is created by the runner itself on first use, so it is not a migration.

## Usage

```
php bin/migrate.php --status    # list applied and pending, apply nothing
php bin/migrate.php             # apply pending migrations
```

Runs under `DB_MIGRATION_USER` — the only account with DDL rights, used here and nowhere else (CLAUDE.md Rule 3).

## Status

Seven migrations, all applied. 22 tables.

| File | What it creates |
|---|---|
| `0001_corpus.sql` | `offices`, `categories`, `documents`, `chunks` (embedding blob + `FULLTEXT`), `chunk_codes`, `curated_entries`, `source_conflicts` |
| `0002_interactions.sql` | `interactions`, `interaction_retrievals`, `interaction_citations`, `feedback`, `unanswered_questions` |
| `0003_safety_and_budget.sql` | `rate_limits`, `rate_limit_violations`, `budget_periods` |
| `0004_admin.sql` | `admin_users`, `admin_audit_log`, `ingest_runs` |
| `0005_evaluation.sql` | `eval_questions`, `eval_runs`, `eval_results` |
| `0006_seed_reference_data.sql` | The 3 offices and 10 answer categories `requirements.md` names. Nothing invented; no corpus content. |
| `0007_enforce_review_dates.sql` | `CHECK` constraints closing a real INV-11 bypass — read its header, it explains a trap that will recur |

The corpus itself is **empty and must stay that way** until Phase 0 completes: "No indexing before this completes."
