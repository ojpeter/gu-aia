-- db/accounts_bootstrap.sql
--
-- Step 1 of 2. Creates the schema and the migration account only.
-- CLAUDE.md Rule 3.
--
-- THIS FILE IS A TEMPLATE AND IS NOT RUN BY bin/migrate.php.
-- Substitute the placeholder below with the real value, put the same value in
-- .env as DB_MIGRATION_PASS, and run:
--
--   mysql -u root -p < db/accounts_bootstrap.sql
--   php bin/migrate.php
--   mysql -u root -p < db/accounts.sql          <-- step 2, needs the tables
--
-- The order matters: db/accounts.sql grants privileges on individual TABLES, so
-- it can only run after the migrations have created them. Granting on gu_aia.*
-- instead would be one line shorter and would hand both the ingestion worker and
-- the web-serving account the run of the whole schema, including the chat logs.
-- The extra step is the point.
--
-- Real passwords never enter this repository.

CREATE DATABASE IF NOT EXISTS gu_aia
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

-- The ONLY account with DDL rights. Used by bin/migrate.php and nowhere else.
--
-- No DROP: migrations are forward-only, so nothing is ever dropped.
-- No DELETE: INV-12, nothing is deleted.
--
-- Schema-level rather than table-level of necessity — it is the account that
-- creates the tables, so it cannot be granted on tables that do not yet exist.
CREATE USER IF NOT EXISTS 'gu_aia_migrate'@'localhost' IDENTIFIED BY '__PASSWORD__';

GRANT CREATE, ALTER, INDEX, REFERENCES, CREATE TEMPORARY TABLES,
      SELECT, INSERT, UPDATE
  ON gu_aia.* TO 'gu_aia_migrate'@'localhost';
