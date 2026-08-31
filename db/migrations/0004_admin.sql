-- 0004_admin.sql
--
-- Admin console accounts and the append-only audit log.
-- requirements.md Section 14; CLAUDE.md Rule 5.
--
-- The console is for Communications and the Registry, not for engineers. A
-- compromised console account can change what the University appears to say in
-- public, which is why 2FA is required for any role that can mark a document
-- authoritative, and why every action is audited.

CREATE TABLE admin_users (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    name            VARCHAR(160)    NOT NULL,
    email           VARCHAR(190)    NOT NULL,
    -- password_hash(), always. Never a reversible or unsalted form.
    password_hash   VARCHAR(255)    NOT NULL,
    office_id       INT UNSIGNED    NULL,

    -- reader          : sees the corpus browser and the reports
    -- editor          : authors curated Q&A entries, triggers re-index
    -- authoriser      : additionally marks a document authoritative for a
    --                   category — the highest-consequence action in the
    --                   console, since it decides which source wins a conflict
    role            ENUM('reader','editor','authoriser') NOT NULL DEFAULT 'reader',

    -- CLAUDE.md Rule 5: 2FA required for any role that can mark a document
    -- authoritative. Enforced in code at login; the flag is here so the
    -- requirement is visible in the data and auditable.
    totp_secret_enc VARBINARY(255)  NULL,
    totp_enabled    TINYINT(1)      NOT NULL DEFAULT 0,

    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    last_login_at   DATETIME        NULL,
    failed_logins   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until    DATETIME        NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_email (email),
    KEY idx_admin_office (office_id),
    KEY idx_admin_active_role (is_active, role),
    CONSTRAINT fk_admin_office FOREIGN KEY (office_id)
        REFERENCES offices (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Append-only audit log (CLAUDE.md Rule 5)
--
-- Who, what, when — for every console action: authoritative-flag changes,
-- curated-entry edits, re-index triggers, corpus additions, role changes.
--
-- Append-only is enforced by grant, not by convention: the application account
-- receives INSERT and SELECT on this table and neither UPDATE nor DELETE, so a
-- bug or a compromised console session cannot rewrite history.
-- ---------------------------------------------------------------------------
CREATE TABLE admin_audit_log (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_user_id   INT UNSIGNED    NULL,  -- NULL for system/worker actions
    action          VARCHAR(80)     NOT NULL,
    entity_type     VARCHAR(60)     NULL,
    entity_id       VARCHAR(64)     NULL,
    detail          VARCHAR(2000)   NULL,
    ip_hash         CHAR(64)        NULL,
    occurred_at     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_audit_user_time (admin_user_id, occurred_at),
    KEY idx_audit_action_time (action, occurred_at),
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_time (occurred_at),
    CONSTRAINT fk_audit_admin FOREIGN KEY (admin_user_id)
        REFERENCES admin_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Ingestion / re-index runs (requirements.md Section 14)
--
-- "Trigger a re-index of a single document or the whole corpus" — and then be
-- able to see what happened. Section 12 also requires the evaluation harness to
-- be re-run and recorded after every corpus re-index, so a re-index needs an
-- identity that an eval run can point at.
-- ---------------------------------------------------------------------------
CREATE TABLE ingest_runs (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope               ENUM('document','full') NOT NULL,
    document_id         BIGINT UNSIGNED NULL,
    triggered_by        INT UNSIGNED    NULL,
    started_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at         DATETIME        NULL,
    status              ENUM('running','succeeded','failed') NOT NULL DEFAULT 'running',
    documents_seen      INT UNSIGNED    NOT NULL DEFAULT 0,
    documents_ingested  INT UNSIGNED    NOT NULL DEFAULT 0,
    documents_rejected  INT UNSIGNED    NOT NULL DEFAULT 0,
    chunks_written      INT UNSIGNED    NOT NULL DEFAULT 0,
    chunks_superseded   INT UNSIGNED    NOT NULL DEFAULT 0,
    error_detail        VARCHAR(2000)   NULL,
    PRIMARY KEY (id),
    KEY idx_ingest_started (started_at),
    KEY idx_ingest_status (status),
    KEY idx_ingest_document (document_id),
    KEY idx_ingest_triggered_by (triggered_by),
    CONSTRAINT fk_ingest_document FOREIGN KEY (document_id)
        REFERENCES documents (id),
    CONSTRAINT fk_ingest_admin FOREIGN KEY (triggered_by)
        REFERENCES admin_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
