# Migrations

Numbered, forward-only SQL, applied by `bin/migrate.php` in filename order (`requirements.md` Section 3).

## Conventions

- Filename: `NNNN_short_description.sql`, zero-padded, e.g. `0001_create_corpus_documents.sql`.
- **Forward-only.** There are no down-migrations, by design.
- **Never edit an applied migration.** Write a new one. The ledger (`schema_migrations`) records filenames, and an edited file would leave a database that no longer matches the migration it claims to have run.
- Each migration runs in its own transaction. A failure rolls that migration back and stops the run; nothing after it is applied.
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

**No migrations written yet.** The repository is foundations only. The corpus schema (documents, chunks with their embedding blob and `FULLTEXT` index, interaction log, feedback) is the next piece of work — see `progress.md`.
