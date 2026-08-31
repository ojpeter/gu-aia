-- 0001_corpus.sql
--
-- The corpus: offices, answer categories, documents, chunks.
-- requirements.md Sections 5, 6, 7.
--
-- Two invariants are enforced structurally here rather than left to application
-- code:
--
--   INV-11  documents.reviewed_at and review_interval_days are NOT NULL, and
--           owning_office_id is a NOT NULL foreign key. A document without an
--           owning office, a reviewed_at date and a review interval literally
--           cannot be inserted, so it cannot be indexed.
--
--   INV-12  Nothing is deleted. Every table that holds content carries a
--           status/superseded_at pair instead. No account is granted DELETE on
--           this schema (see db/accounts.sql), so this holds even against a
--           bug in application code.
--
-- Portability note: written for both MySQL 8 (production target) and MariaDB
-- 10.4 (the XAMPP development environment). No utf8mb4_0900_* collations, no
-- functional indexes, no JSON columns.

-- ---------------------------------------------------------------------------
-- Offices that own content (requirements.md Section 5.2)
-- ---------------------------------------------------------------------------
CREATE TABLE offices (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(160) NOT NULL,
    -- Contact details are deliberately nullable and are NOT seeded: publishing a
    -- wrong office email in a refusal sends a user who already could not get an
    -- answer to a dead end. Filled in during Phase 0.
    email           VARCHAR(190) NULL,
    telephone       VARCHAR(60)  NULL,
    url             VARCHAR(500) NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_offices_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Answer categories and their answering mode (requirements.md Section 7)
--
-- A lookup table rather than an ENUM so that category is a real foreign key and
-- can be validated against actual rows, and so the admin console can show which
-- categories exist without reading application config.
-- ---------------------------------------------------------------------------
CREATE TABLE categories (
    category_key            VARCHAR(40)  NOT NULL,
    label                   VARCHAR(120) NOT NULL,
    -- quoted   : authoritative text verbatim + link; generation is never called
    --            for the figure itself (INV-2)
    -- grounded : generated, cited, from retrieved context only (INV-1, INV-5)
    -- refuse   : refusal template + named human contact (Section 9)
    mode                    ENUM('quoted','grounded','refuse') NOT NULL,
    requires_academic_year  TINYINT(1)   NOT NULL DEFAULT 0,
    handoff_office_id       INT UNSIGNED NULL,
    -- Matched before retrieval, so no amount of retrieved context can turn the
    -- question into an answer (INV-3).
    refuse_before_retrieval TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order              SMALLINT     NOT NULL DEFAULT 0,
    PRIMARY KEY (category_key),
    KEY idx_categories_handoff (handoff_office_id),
    CONSTRAINT fk_categories_office FOREIGN KEY (handoff_office_id)
        REFERENCES offices (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Documents (requirements.md Section 5)
-- ---------------------------------------------------------------------------
CREATE TABLE documents (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_type             ENUM('web_page','pdf','curated','registry_feed') NOT NULL,
    -- URL or document reference. Hashed alongside because a full URL is too long
    -- to carry a unique index under utf8mb4.
    source_ref              VARCHAR(1000) NOT NULL,
    source_ref_hash         CHAR(64)      NOT NULL,
    title                   VARCHAR(500)  NOT NULL,

    -- INV-11: all three are mandatory. No default on reviewed_at on purpose —
    -- a defaulted review date is a fabricated review date.
    owning_office_id        INT UNSIGNED  NOT NULL,
    reviewed_at             DATE          NOT NULL,
    review_interval_days    SMALLINT UNSIGNED NOT NULL,

    category_key            VARCHAR(40)   NULL,
    -- Section 5.2: where two sources conflict, the one marked authoritative for
    -- that category wins.
    is_authoritative        TINYINT(1)    NOT NULL DEFAULT 0,

    -- Set when ingestion rejects the document (e.g. a scanned PDF with no text
    -- layer, Section 5.2) so the owning office can be told what was refused and
    -- why, rather than the document silently never appearing.
    ingest_status           ENUM('pending','ingested','rejected') NOT NULL DEFAULT 'pending',
    ingest_rejection_reason VARCHAR(500)  NULL,

    content_hash            CHAR(64)      NULL,
    last_ingested_at        DATETIME      NULL,

    -- INV-12: superseded, never removed.
    status                  ENUM('active','superseded') NOT NULL DEFAULT 'active',
    superseded_at           DATETIME      NULL,
    superseded_by_id        BIGINT UNSIGNED NULL,

    created_at              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_documents_source (source_ref_hash),
    KEY idx_documents_office (owning_office_id),
    KEY idx_documents_category (category_key),
    KEY idx_documents_status (status),
    KEY idx_documents_ingest_status (ingest_status),
    -- Drives the "past its review interval" sweep (INV-11).
    KEY idx_documents_review (reviewed_at, review_interval_days),
    -- Finds the authoritative document for a category in one hit (Section 5.2).
    KEY idx_documents_authoritative (category_key, is_authoritative, status),
    KEY idx_documents_superseded_by (superseded_by_id),
    CONSTRAINT fk_documents_office FOREIGN KEY (owning_office_id)
        REFERENCES offices (id),
    CONSTRAINT fk_documents_category FOREIGN KEY (category_key)
        REFERENCES categories (category_key),
    CONSTRAINT fk_documents_superseded_by FOREIGN KEY (superseded_by_id)
        REFERENCES documents (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Chunks (requirements.md Sections 5.3, 6)
--
-- Deliberately denormalised: category_key, reviewed_at, is_authoritative and
-- owning_office_id are copied down from the document. Retrieval is the hot path
-- and it filters by category and needs reviewed_at for every answer (INV-11);
-- carrying them here keeps candidate generation a single-table FULLTEXT scan
-- with no join. Ingestion is responsible for keeping them in step, and re-index
-- rewrites them.
-- ---------------------------------------------------------------------------
CREATE TABLE chunks (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id         BIGINT UNSIGNED NOT NULL,
    ordinal             INT UNSIGNED    NOT NULL,

    heading_path        VARCHAR(1000)   NULL,
    page_number         SMALLINT UNSIGNED NULL,
    caption             VARCHAR(500)    NULL,

    body                MEDIUMTEXT      NOT NULL,
    token_count         SMALLINT UNSIGNED NULL,

    -- Section 5.3: a fees table or an entry-requirements list is extracted whole
    -- and never split. Flagged so the chunker's own tests, and quoted mode, can
    -- assert it.
    is_atomic_block     TINYINT(1)      NOT NULL DEFAULT 0,
    atomic_block_kind   VARCHAR(40)     NULL,

    -- Section 3: embeddings are generated at ingestion and stored as a compact
    -- binary blob on the chunk row. No separate vector database. Dimension and
    -- model are recorded so a model change is detectable and re-indexable rather
    -- than silently mixing incompatible vectors.
    embedding           BLOB            NULL,
    embedding_dims      SMALLINT UNSIGNED NULL,
    embedding_model     VARCHAR(120)    NULL,
    embedded_at         DATETIME        NULL,

    -- Denormalised from documents; see the note above.
    category_key        VARCHAR(40)     NULL,
    reviewed_at         DATE            NOT NULL,
    is_authoritative    TINYINT(1)      NOT NULL DEFAULT 0,
    owning_office_id    INT UNSIGNED    NOT NULL,

    -- INV-12.
    status              ENUM('active','superseded') NOT NULL DEFAULT 'active',
    superseded_at       DATETIME        NULL,

    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_chunks_document_ordinal (document_id, ordinal),
    KEY idx_chunks_document (document_id),
    KEY idx_chunks_office (owning_office_id),
    -- Section 6: retrieval filters by category, which is what stops a fees
    -- question being answered from a news article.
    KEY idx_chunks_category_status (category_key, status),
    KEY idx_chunks_status (status),
    KEY idx_chunks_embedding_model (embedding_model),
    -- Candidate generation: MySQL/MariaDB FULLTEXT, BOOLEAN MODE, limit 200.
    FULLTEXT KEY ft_chunks_body (body),
    CONSTRAINT fk_chunks_document FOREIGN KEY (document_id)
        REFERENCES documents (id),
    CONSTRAINT fk_chunks_category FOREIGN KEY (category_key)
        REFERENCES categories (category_key),
    CONSTRAINT fk_chunks_office FOREIGN KEY (owning_office_id)
        REFERENCES offices (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Programme and course codes appearing in a chunk (requirements.md Section 6)
--
-- "Programme and course codes are matched exactly and boosted; a user typing a
-- code knows what they want." Extracted at ingestion so the exact-match arm of
-- retrieval is an index lookup, not a LIKE scan.
-- ---------------------------------------------------------------------------
CREATE TABLE chunk_codes (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    chunk_id    BIGINT UNSIGNED NOT NULL,
    code        VARCHAR(40)     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_chunk_code (chunk_id, code),
    KEY idx_chunk_codes_code (code),
    CONSTRAINT fk_chunk_codes_chunk FOREIGN KEY (chunk_id)
        REFERENCES chunks (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Curated question-and-answer entries (requirements.md Section 5.1, 14)
--
-- "Authored in the admin console for facts that live on no page." Each one is
-- backed by a document row of source_type='curated' so that it flows through
-- exactly the same retrieval, citation and reviewed_at machinery as crawled
-- content — a curated answer is not a special case that bypasses INV-1/INV-11.
--
-- Section 14: this is the ONLY content the console may author. The website
-- remains the source of truth.
-- ---------------------------------------------------------------------------
CREATE TABLE curated_entries (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id     BIGINT UNSIGNED NOT NULL,
    question        VARCHAR(500)    NOT NULL,
    answer          TEXT            NOT NULL,
    category_key    VARCHAR(40)     NULL,
    status          ENUM('active','superseded') NOT NULL DEFAULT 'active',
    superseded_at   DATETIME        NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_curated_document (document_id),
    KEY idx_curated_category_status (category_key, status),
    CONSTRAINT fk_curated_document FOREIGN KEY (document_id)
        REFERENCES documents (id),
    CONSTRAINT fk_curated_category FOREIGN KEY (category_key)
        REFERENCES categories (category_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Source conflicts (requirements.md Section 5.2)
--
-- "Conflicts are a content defect to be fixed, not a retrieval problem to be
-- tuned around." Recorded here and surfaced in the admin console for the owning
-- office to resolve.
-- ---------------------------------------------------------------------------
CREATE TABLE source_conflicts (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_key    VARCHAR(40)     NULL,
    document_a_id   BIGINT UNSIGNED NOT NULL,
    document_b_id   BIGINT UNSIGNED NOT NULL,
    detail          VARCHAR(1000)   NOT NULL,
    detected_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at     DATETIME        NULL,
    resolution_note VARCHAR(1000)   NULL,
    PRIMARY KEY (id),
    KEY idx_conflicts_category (category_key),
    KEY idx_conflicts_unresolved (resolved_at),
    KEY idx_conflicts_doc_a (document_a_id),
    KEY idx_conflicts_doc_b (document_b_id),
    CONSTRAINT fk_conflicts_doc_a FOREIGN KEY (document_a_id)
        REFERENCES documents (id),
    CONSTRAINT fk_conflicts_doc_b FOREIGN KEY (document_b_id)
        REFERENCES documents (id),
    CONSTRAINT fk_conflicts_category FOREIGN KEY (category_key)
        REFERENCES categories (category_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
