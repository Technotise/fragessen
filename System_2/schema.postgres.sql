-- =============================================================
-- KommRAG / FragEssen — System 2 (RAG-Backend)
-- PostgreSQL-Schema: raw, rag, ops
--
-- Voraussetzungen: PostgreSQL 15+, Extensions pgvector und pg_trgm
-- Import:  psql -U <user> -d kommrag -f schema.postgres.sql
-- =============================================================

CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- =============================================================
-- Schema: raw — importierte Dokumente, kuratierte Metadaten,
--               Segmente (aus System 1 via SFTP-Paket)
-- =============================================================

CREATE SCHEMA raw;

CREATE TABLE raw.documents (
	id bigserial NOT NULL,
	gremium_key text NOT NULL,
	file_hash_sha256 text NOT NULL,
	source_filename text NULL,
	source_path text NULL,
	meeting_date date NULL,
	mime_type text DEFAULT 'application/pdf'::text NULL,
	file_size_bytes int8 NULL,
	meta jsonb DEFAULT '{}'::jsonb NOT NULL,
	created_at timestamptz DEFAULT now() NOT NULL,
	updated_at timestamptz DEFAULT now() NOT NULL,
	package_id int4 NULL,
	pipeline_ok bool NULL,
	pipeline_score numeric(5, 2) NULL,
	pipeline_stage text NULL,
	pipeline_used_docai bool NULL,
	pipeline_recommendation text NULL,
	pipeline_span_coverage numeric(5, 4) NULL,
	tops_total int4 NULL,
	tops_found int4 NULL,
	pages_total int4 NULL,
	document_key text NULL,
	gremium_name text NULL,
	CONSTRAINT documents_gremium_key_file_hash_sha256_key UNIQUE (gremium_key, file_hash_sha256),
	CONSTRAINT documents_pkey PRIMARY KEY (id)
);
CREATE INDEX documents_gremium_date_idx ON raw.documents USING btree (gremium_key, meeting_date);
CREATE UNIQUE INDEX documents_package_id_key ON raw.documents USING btree (package_id) WHERE (package_id IS NOT NULL);

CREATE TABLE raw.document_core (
	document_id int8 NOT NULL,
	extracted_json jsonb DEFAULT '{}'::jsonb NOT NULL,
	curated_sitzungsdatum date NULL,
	curated_uhrzeit_start time NULL,
	curated_ort text NULL,
	curated_sitzungstyp text NULL,
	curated_niederschrift_nr text NULL,
	needs_review bool DEFAULT true NOT NULL,
	updated_at timestamptz DEFAULT now() NOT NULL,
	curated_periodenbezug text NULL,
	CONSTRAINT document_core_pkey PRIMARY KEY (document_id),
	CONSTRAINT document_core_document_id_fkey FOREIGN KEY (document_id) REFERENCES raw.documents(id) ON DELETE CASCADE
);

CREATE TABLE raw.document_agenda_items (
	id bigserial NOT NULL,
	document_id int8 NOT NULL,
	row_index int4 NOT NULL,
	extracted_json jsonb DEFAULT '{}'::jsonb NOT NULL,
	top_key_extracted text NULL,
	title_extracted text NULL,
	drucksache_extracted text NULL,
	section_extracted text NULL,
	top_key_curated text NULL,
	title_curated text NULL,
	drucksache_curated text NULL,
	section_curated text NULL,
	needs_review bool DEFAULT true NOT NULL,
	updated_at timestamptz DEFAULT now() NOT NULL,
	top_num int4 NULL,
	top_suffix text NULL,
	top_sub text NULL,
	top_norm text NULL,
	CONSTRAINT document_agenda_items_document_id_row_index_key UNIQUE (document_id, row_index),
	CONSTRAINT document_agenda_items_pkey PRIMARY KEY (id),
	CONSTRAINT document_agenda_items_document_id_fkey FOREIGN KEY (document_id) REFERENCES raw.documents(id) ON DELETE CASCADE
);
CREATE INDEX agenda_doc_topkey_idx ON raw.document_agenda_items USING btree (document_id, COALESCE(top_key_curated, top_key_extracted));

CREATE TABLE raw.document_attendance_rows (
	id bigserial NOT NULL,
	document_id int8 NOT NULL,
	row_index int4 NOT NULL,
	extracted_json jsonb DEFAULT '{}'::jsonb NOT NULL,
	role_extracted text NULL,
	last_name_extracted text NULL,
	faction_extracted text NULL,
	role_curated text NULL,
	last_name_curated text NULL,
	faction_curated text NULL,
	needs_review bool DEFAULT true NOT NULL,
	updated_at timestamptz DEFAULT now() NOT NULL,
	salutation text NULL,
	title_akad text NULL,
	name_norm text NULL,
	base_norm text NULL,
	row_hash text NULL,
	CONSTRAINT document_attendance_rows_document_id_row_index_key UNIQUE (document_id, row_index),
	CONSTRAINT document_attendance_rows_pkey PRIMARY KEY (id),
	CONSTRAINT document_attendance_rows_document_id_fkey FOREIGN KEY (document_id) REFERENCES raw.documents(id) ON DELETE CASCADE
);
CREATE INDEX attendance_doc_name_idx ON raw.document_attendance_rows USING btree (document_id, COALESCE(last_name_curated, last_name_extracted));
CREATE INDEX attendance_row_hash_idx ON raw.document_attendance_rows USING btree (row_hash) WHERE (row_hash IS NOT NULL);

CREATE TABLE raw.segments (
	id bigserial NOT NULL,
	document_id int8 NOT NULL,
	"type" text NOT NULL,
	top_key text NULL,
	title text NULL,
	start_page int4 NULL,
	end_page int4 NULL,
	start_char int4 NULL,
	end_char int4 NULL,
	hint_anchor text NULL,
	text_full text NULL,
	meta jsonb DEFAULT '{}'::jsonb NOT NULL,
	created_at timestamptz DEFAULT now() NOT NULL,
	updated_at timestamptz DEFAULT now() NOT NULL,
	text_tsv tsvector GENERATED ALWAYS AS (to_tsvector('german'::regconfig, COALESCE(text_full, ''::text))) STORED NULL,
	match_method text NULL,
	match_score numeric(10, 4) NULL,
	confidence numeric(5, 4) NULL,
	content_hash text NULL,
	embedding vector(1024) NULL,
	CONSTRAINT segments_pkey PRIMARY KEY (id),
	CONSTRAINT segments_type_check CHECK ((type = ANY (ARRAY['vorwort'::text, 'top'::text, 'nachwort'::text, 'anhang'::text]))),
	CONSTRAINT segments_document_id_fkey FOREIGN KEY (document_id) REFERENCES raw.documents(id) ON DELETE CASCADE
);
CREATE INDEX segments_content_hash_idx ON raw.segments USING btree (content_hash) WHERE (content_hash IS NOT NULL);
CREATE INDEX segments_doc_topkey_idx ON raw.segments USING btree (document_id, top_key) WHERE (top_key IS NOT NULL);
CREATE INDEX segments_doc_type_idx ON raw.segments USING btree (document_id, type);
CREATE INDEX segments_embedding_idx ON raw.segments USING hnsw (embedding vector_cosine_ops);
CREATE INDEX segments_text_gin_idx ON raw.segments USING gin (text_tsv);

-- =============================================================
-- Schema: rag — Retrieval-Layer (Chunks, Seiten, Synonyme)
-- =============================================================

CREATE SCHEMA rag;

CREATE TABLE rag.synonyms (
	term text NOT NULL,
	variants text[] DEFAULT '{}'::text[] NOT NULL,
	"source" text DEFAULT 'llm'::text NOT NULL,
	hit_count int4 DEFAULT 0 NOT NULL,
	created_at timestamptz DEFAULT now() NOT NULL,
	updated_at timestamptz DEFAULT now() NOT NULL,
	stale_at timestamptz NULL,
	CONSTRAINT synonyms_pkey PRIMARY KEY (term),
	CONSTRAINT synonyms_source_check CHECK ((source = ANY (ARRAY['llm'::text, 'manual'::text])))
);
CREATE INDEX idx_synonyms_hit_count ON rag.synonyms USING btree (hit_count DESC);
CREATE INDEX idx_synonyms_source ON rag.synonyms USING btree (source);

CREATE TABLE rag.chunks (
	id bigserial NOT NULL,
	document_id int8 NOT NULL,
	segment_id int8 NULL,
	top_key text NULL,
	chunk_index int4 NOT NULL,
	start_page int4 NULL,
	end_page int4 NULL,
	chunk_text text NOT NULL,
	meta jsonb DEFAULT '{}'::jsonb NOT NULL,
	tsv tsvector GENERATED ALWAYS AS (to_tsvector('german'::regconfig, chunk_text)) STORED NULL,
	embedding vector(1024) NULL,
	created_at timestamptz DEFAULT now() NOT NULL,
	updated_at timestamptz DEFAULT now() NOT NULL,
	chunk_source text DEFAULT 'segment'::text NULL,
	CONSTRAINT chunks_chunk_source_check CHECK ((chunk_source = ANY (ARRAY['segment'::text, 'page'::text]))),
	CONSTRAINT chunks_document_id_chunk_index_key UNIQUE (document_id, chunk_index),
	CONSTRAINT chunks_pkey PRIMARY KEY (id),
	CONSTRAINT chunks_document_id_fkey FOREIGN KEY (document_id) REFERENCES raw.documents(id) ON DELETE CASCADE,
	CONSTRAINT chunks_segment_id_fkey FOREIGN KEY (segment_id) REFERENCES raw.segments(id) ON DELETE SET NULL
);
CREATE INDEX chunks_doc_idx ON rag.chunks USING btree (document_id, chunk_index);
CREATE INDEX chunks_embedding_hnsw_idx ON rag.chunks USING hnsw (embedding vector_cosine_ops);
CREATE INDEX chunks_source_idx ON rag.chunks USING btree (chunk_source);
CREATE INDEX chunks_text_trgm_idx ON rag.chunks USING gin (chunk_text gin_trgm_ops);
CREATE INDEX chunks_tsv_gin_idx ON rag.chunks USING gin (tsv);

COMMENT ON COLUMN rag.chunks.chunk_source IS 'Quelle des Chunks: segment = aus raw.segments, page = aus PDF-Seitenextraktion';

CREATE TABLE rag.pages (
	id bigserial NOT NULL,
	document_id int8 NOT NULL,
	page_no int4 NOT NULL,
	page_text text DEFAULT ''::text NOT NULL,
	meta jsonb DEFAULT '{}'::jsonb NOT NULL,
	tsv tsvector GENERATED ALWAYS AS (to_tsvector('german'::regconfig, page_text)) STORED NULL,
	created_at timestamptz DEFAULT now() NOT NULL,
	updated_at timestamptz DEFAULT now() NOT NULL,
	CONSTRAINT pages_document_id_page_no_key UNIQUE (document_id, page_no),
	CONSTRAINT pages_pkey PRIMARY KEY (id),
	CONSTRAINT pages_document_id_fkey FOREIGN KEY (document_id) REFERENCES raw.documents(id) ON DELETE CASCADE
);
CREATE INDEX pages_doc_page_idx ON rag.pages USING btree (document_id, page_no);
CREATE INDEX pages_tsv_gin_idx ON rag.pages USING gin (tsv);

-- =============================================================
-- Schema: ops — Job-Queue und Logging der Ingest-Pipeline
-- =============================================================

CREATE SCHEMA ops;

CREATE TYPE ops."job_status" AS ENUM (
	'queued',
	'running',
	'done',
	'failed',
	'retry');

CREATE TABLE ops.ingest_jobs (
	id bigserial NOT NULL,
	job_type text NOT NULL,
	package_dir text NULL,
	document_key text NULL,
	status ops."job_status" DEFAULT 'queued'::ops.job_status NOT NULL,
	priority int4 DEFAULT 100 NOT NULL,
	attempts int4 DEFAULT 0 NOT NULL,
	max_attempts int4 DEFAULT 3 NOT NULL,
	locked_by text NULL,
	locked_at timestamptz NULL,
	last_error text NULL,
	meta jsonb DEFAULT '{}'::jsonb NOT NULL,
	created_at timestamptz DEFAULT now() NOT NULL,
	updated_at timestamptz DEFAULT now() NOT NULL,
	CONSTRAINT ingest_jobs_job_type_check CHECK ((job_type = ANY (ARRAY['import_json'::text, 'extract_pages'::text, 'make_chunks'::text, 'embed_chunks'::text]))),
	CONSTRAINT ingest_jobs_pkey PRIMARY KEY (id)
);
CREATE UNIQUE INDEX ingest_jobs_jobtype_dockey_uq ON ops.ingest_jobs USING btree (job_type, document_key);
CREATE INDEX ingest_jobs_status_prio_idx ON ops.ingest_jobs USING btree (status, priority, created_at);

CREATE TABLE ops.ingest_logs (
	id bigserial NOT NULL,
	job_id int8 NULL,
	"level" text NOT NULL,
	"scope" text NULL,
	message text NOT NULL,
	details jsonb DEFAULT '{}'::jsonb NOT NULL,
	created_at timestamptz DEFAULT now() NOT NULL,
	CONSTRAINT ingest_logs_level_check CHECK ((level = ANY (ARRAY['debug'::text, 'info'::text, 'warn'::text, 'error'::text]))),
	CONSTRAINT ingest_logs_pkey PRIMARY KEY (id),
	CONSTRAINT ingest_logs_job_id_fkey FOREIGN KEY (job_id) REFERENCES ops.ingest_jobs(id) ON DELETE SET NULL
);
CREATE INDEX ingest_logs_job_idx ON ops.ingest_logs USING btree (job_id, created_at);

CREATE TABLE ops.raw_import_jobs (
	id bigserial NOT NULL,
	job_id int8 NOT NULL,
	status text DEFAULT 'pending'::text NOT NULL,
	raw_document_id int8 NULL,
	started_at timestamptz NULL,
	imported_at timestamptz NULL,
	error text NULL,
	CONSTRAINT raw_import_jobs_job_id_key UNIQUE (job_id),
	CONSTRAINT raw_import_jobs_pkey PRIMARY KEY (id),
	CONSTRAINT raw_import_jobs_status_check CHECK ((status = ANY (ARRAY['pending'::text, 'imported'::text, 'failed'::text]))),
	CONSTRAINT raw_import_jobs_job_id_fkey FOREIGN KEY (job_id) REFERENCES ops.ingest_jobs(id) ON DELETE CASCADE,
	CONSTRAINT raw_import_jobs_raw_document_id_fkey FOREIGN KEY (raw_document_id) REFERENCES raw.documents(id) ON DELETE SET NULL
);
CREATE INDEX raw_import_jobs_status_idx ON ops.raw_import_jobs USING btree (status) WHERE (status = 'pending'::text);
