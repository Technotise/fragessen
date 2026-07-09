-- =============================================================
-- KommRAG / FragEssen — geteiltes MySQL-Schema (System 1 + System 3)
--
-- Import:  mysql -u <user> -p <dbname> < schema.mysql.sql
--
-- Tabellen-Zuordnung:
--
--   System 1 — Ingest (Upload, Extraktion, Review, Export):
--     gremien, documents, document_agenda, document_attendance,
--     document_core, document_locks, document_logs, document_state
--
--   System 3 — Frontend (Chat, Zugangscodes, Rate-Limiting):
--     chat_logs, chat_feedback, access_codes, unlocked_sessions,
--     code_usage_stats, rate_limits
--
--   System 3 — Evaluations-Umfrage:
--     survey_participants, survey_responses
--
--   Gemeinsam (Admin-Login System 1/3):
--     users
-- =============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;


-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `access_codes`
--

CREATE TABLE `access_codes` (
  `id` int UNSIGNED NOT NULL,
  `code` varchar(32) NOT NULL,
  `label` varchar(255) DEFAULT NULL,
  `code_type` enum('session','persistent') NOT NULL DEFAULT 'session',
  `created_by` int UNSIGNED DEFAULT NULL,
  `used_count` int UNSIGNED DEFAULT '0',
  `max_uses` int UNSIGNED DEFAULT '1',
  `is_active` tinyint(1) DEFAULT '1',
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Daten für Tabelle `access_codes`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `chat_feedback`
--

CREATE TABLE `chat_feedback` (
  `id` bigint UNSIGNED NOT NULL,
  `chat_log_id` bigint UNSIGNED NOT NULL,
  `session_id` varchar(64) NOT NULL,
  `rating` tinyint NOT NULL,
  `comment` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Daten für Tabelle `chat_feedback`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `chat_logs`
--

CREATE TABLE `chat_logs` (
  `id` bigint UNSIGNED NOT NULL,
  `session_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `query` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `condensed_query` text COLLATE utf8mb4_unicode_ci,
  `answer` text COLLATE utf8mb4_unicode_ci,
  `clarify` text COLLATE utf8mb4_unicode_ci,
  `sources_json` json DEFAULT NULL,
  `top_k` tinyint UNSIGNED DEFAULT '10',
  `gremium_key` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `year_from` smallint UNSIGNED DEFAULT NULL,
  `year_to` smallint UNSIGNED DEFAULT NULL,
  `answer_length` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT 'normal',
  `quality` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT 'small',
  `search_mode` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT 'relevant',
  `elapsed_ms` int UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Daten für Tabelle `chat_logs`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `code_usage_stats`
--

CREATE TABLE `code_usage_stats` (
  `id` bigint UNSIGNED NOT NULL,
  `access_code_id` int UNSIGNED NOT NULL,
  `quality` varchar(10) NOT NULL DEFAULT 'small',
  `request_count` int UNSIGNED NOT NULL DEFAULT '0',
  `last_used_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Daten für Tabelle `code_usage_stats`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `documents`
--

CREATE TABLE `documents` (
  `id` bigint UNSIGNED NOT NULL,
  `gremium_id` int UNSIGNED NOT NULL,
  `original_filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` bigint UNSIGNED NOT NULL,
  `storage_path` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_hash_sha256` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  `extraction_retry_allowed` tinyint(1) NOT NULL DEFAULT '0',
  `processing_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `processing_locked_at` datetime DEFAULT NULL,
  `processing_lock_until` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Daten für Tabelle `documents`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `document_agenda`
--

CREATE TABLE `document_agenda` (
  `id` bigint UNSIGNED NOT NULL,
  `document_id` bigint UNSIGNED NOT NULL,
  `row_index` int UNSIGNED NOT NULL,
  `extracted_json` json NOT NULL,
  `top_key_curated` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title_curated` text COLLATE utf8mb4_unicode_ci,
  `drucksache_curated` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `section_curated` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `top_num` int DEFAULT NULL,
  `top_suffix` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `top_sub` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `top_norm` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `extracted_flag` tinyint(1) NOT NULL DEFAULT '1',
  `curated_flag` tinyint(1) NOT NULL DEFAULT '0',
  `needs_review` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Daten für Tabelle `document_agenda`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `document_attendance`
--

CREATE TABLE `document_attendance` (
  `id` bigint UNSIGNED NOT NULL,
  `document_id` bigint UNSIGNED NOT NULL,
  `row_index` int UNSIGNED NOT NULL,
  `extracted_json` json NOT NULL,
  `role_curated` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `salutation_curated` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title_curated` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name_curated` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `faction_raw_curated` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `free_text_curated` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name_norm` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `base_norm` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `row_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `party_single_norm` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_coalition` tinyint(1) NOT NULL DEFAULT '0',
  `extracted_flag` tinyint(1) NOT NULL DEFAULT '1',
  `curated_flag` tinyint(1) NOT NULL DEFAULT '0',
  `needs_review` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Daten für Tabelle `document_attendance`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `document_core`
--

CREATE TABLE `document_core` (
  `document_id` bigint UNSIGNED NOT NULL,
  `extracted_json` json NOT NULL,
  `curated_sitzungsdatum` date DEFAULT NULL,
  `curated_uhrzeit_start` time DEFAULT NULL,
  `curated_ort` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `curated_sitzungstyp` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'regulaer',
  `curated_periodenbezug` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `curated_niederschrift_nr` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Daten für Tabelle `document_core`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `document_locks`
--

CREATE TABLE `document_locks` (
  `document_id` int NOT NULL,
  `locked_by` int NOT NULL,
  `locked_by_name` varchar(120) NOT NULL,
  `lock_token` char(64) NOT NULL,
  `locked_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Daten für Tabelle `document_locks`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `document_logs`
--

CREATE TABLE `document_logs` (
  `id` bigint UNSIGNED NOT NULL,
  `document_id` bigint UNSIGNED NOT NULL,
  `level` enum('info','warning','error','success') NOT NULL,
  `task` varchar(64) DEFAULT NULL,
  `message` text NOT NULL,
  `actor` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

--
-- Daten für Tabelle `document_logs`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `document_state`
--

CREATE TABLE `document_state` (
  `id` bigint UNSIGNED NOT NULL,
  `document_id` bigint UNSIGNED NOT NULL,
  `task` varchar(64) NOT NULL,
  `task_order` int NOT NULL,
  `status` enum('geplant','gestartet','fertig','fehlgeschlagen') NOT NULL DEFAULT 'geplant',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

--
-- Daten für Tabelle `document_state`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `gremien`
--

CREATE TABLE `gremien` (
  `id` int UNSIGNED NOT NULL,
  `key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `typ` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `aktiv` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Daten für Tabelle `gremien`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `rate_limits`
--

CREATE TABLE `rate_limits` (
  `ip_hash` varchar(64) NOT NULL,
  `window_start` datetime NOT NULL,
  `request_count` smallint UNSIGNED DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Daten für Tabelle `rate_limits`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `survey_participants`
--

CREATE TABLE `survey_participants` (
  `id` bigint NOT NULL,
  `session_token` char(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('bv','rat','verwaltung','buergerschaft') COLLATE utf8mb4_unicode_ci NOT NULL,
  `gender` enum('weiblich','maennlich','divers','keine_angabe') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'keine_angabe',
  `ris_familiarity` enum('nutze','kenne_nur','kenne_nicht','na') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'na',
  `source` enum('banner','mail','direct') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'direct',
  `consent_given` tinyint(1) NOT NULL DEFAULT '0',
  `consent_timestamp` datetime NOT NULL,
  `started_at` datetime NOT NULL,
  `stage1_completed_at` datetime DEFAULT NULL,
  `stage2_completed_at` datetime DEFAULT NULL,
  `ip_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Daten für Tabelle `survey_participants`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `survey_responses`
--

CREATE TABLE `survey_responses` (
  `id` bigint NOT NULL,
  `session_token` char(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `question_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `answer_numeric` smallint DEFAULT NULL,
  `answer_text` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Daten für Tabelle `survey_responses`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `unlocked_sessions`
--

CREATE TABLE `unlocked_sessions` (
  `session_id` varchar(64) NOT NULL,
  `access_code_id` int UNSIGNED DEFAULT NULL,
  `ip_hash` varchar(64) NOT NULL,
  `unlocked_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Daten für Tabelle `unlocked_sessions`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `admin` tinyint(1) NOT NULL DEFAULT '0',
  `username` varchar(64) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Daten für Tabelle `users`
--

--
-- Indizes der exportierten Tabellen
--

--
-- Indizes für die Tabelle `access_codes`
--
ALTER TABLE `access_codes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_code` (`code`);

--
-- Indizes für die Tabelle `chat_feedback`
--
ALTER TABLE `chat_feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_log` (`chat_log_id`),
  ADD KEY `idx_rating` (`rating`);

--
-- Indizes für die Tabelle `chat_logs`
--
ALTER TABLE `chat_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_session` (`session_id`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_ip` (`ip_hash`);

--
-- Indizes für die Tabelle `code_usage_stats`
--
ALTER TABLE `code_usage_stats`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_code_quality` (`access_code_id`,`quality`);

--
-- Indizes für die Tabelle `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_documents_hash_gremium` (`gremium_id`,`file_hash_sha256`),
  ADD KEY `idx_documents_gremium_created` (`gremium_id`,`created_at`),
  ADD KEY `idx_documents_not_deleted` (`deleted_at`),
  ADD KEY `idx_documents_gremium_date` (`gremium_id`),
  ADD KEY `idx_docs_processing` (`processing_token`,`processing_lock_until`),
  ADD KEY `idx_docs_retry` (`extraction_retry_allowed`,`deleted_at`);

--
-- Indizes für die Tabelle `document_agenda`
--
ALTER TABLE `document_agenda`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_agenda_doc_row` (`document_id`,`row_index`),
  ADD KEY `idx_agenda_doc` (`document_id`),
  ADD KEY `idx_agenda_top` (`top_num`,`top_suffix`,`top_sub`);

--
-- Indizes für die Tabelle `document_attendance`
--
ALTER TABLE `document_attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_att_doc_row` (`document_id`,`row_index`),
  ADD KEY `idx_att_doc_needs_review` (`document_id`,`needs_review`),
  ADD KEY `idx_att_base_norm` (`base_norm`),
  ADD KEY `idx_att_party_single` (`party_single_norm`);

--
-- Indizes für die Tabelle `document_core`
--
ALTER TABLE `document_core`
  ADD PRIMARY KEY (`document_id`),
  ADD KEY `idx_core_curated_date` (`curated_sitzungsdatum`);

--
-- Indizes für die Tabelle `document_locks`
--
ALTER TABLE `document_locks`
  ADD PRIMARY KEY (`document_id`),
  ADD KEY `idx_expires_at` (`expires_at`);

--
-- Indizes für die Tabelle `document_logs`
--
ALTER TABLE `document_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_document` (`document_id`),
  ADD KEY `idx_task` (`task`),
  ADD KEY `idx_level` (`level`);

--
-- Indizes für die Tabelle `document_state`
--
ALTER TABLE `document_state`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_document_task` (`document_id`,`task`),
  ADD KEY `idx_next_task` (`status`,`task_order`),
  ADD KEY `idx_document` (`document_id`);

--
-- Indizes für die Tabelle `gremien`
--
ALTER TABLE `gremien`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_gremien_key` (`key`);

--
-- Indizes für die Tabelle `rate_limits`
--
ALTER TABLE `rate_limits`
  ADD PRIMARY KEY (`ip_hash`,`window_start`),
  ADD KEY `idx_window` (`window_start`);

--
-- Indizes für die Tabelle `survey_participants`
--
ALTER TABLE `survey_participants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_token` (`session_token`),
  ADD KEY `idx_role` (`role`),
  ADD KEY `idx_source` (`source`),
  ADD KEY `idx_stage1` (`stage1_completed_at`),
  ADD KEY `idx_stage2` (`stage2_completed_at`);

--
-- Indizes für die Tabelle `survey_responses`
--
ALTER TABLE `survey_responses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_token` (`session_token`),
  ADD KEY `idx_question` (`question_key`);

--
-- Indizes für die Tabelle `unlocked_sessions`
--
ALTER TABLE `unlocked_sessions`
  ADD PRIMARY KEY (`session_id`),
  ADD KEY `idx_ip` (`ip_hash`);

--
-- Indizes für die Tabelle `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT für exportierte Tabellen
--

--
-- AUTO_INCREMENT für Tabelle `access_codes`
--
ALTER TABLE `access_codes`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `chat_feedback`
--
ALTER TABLE `chat_feedback`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `chat_logs`
--
ALTER TABLE `chat_logs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `code_usage_stats`
--
ALTER TABLE `code_usage_stats`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `documents`
--
ALTER TABLE `documents`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `document_agenda`
--
ALTER TABLE `document_agenda`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `document_attendance`
--
ALTER TABLE `document_attendance`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `document_logs`
--
ALTER TABLE `document_logs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `document_state`
--
ALTER TABLE `document_state`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `gremien`
--
ALTER TABLE `gremien`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `survey_participants`
--
ALTER TABLE `survey_participants`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `survey_responses`
--
ALTER TABLE `survey_responses`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- Constraints der exportierten Tabellen
--

--
-- Constraints der Tabelle `code_usage_stats`
--
ALTER TABLE `code_usage_stats`
  ADD CONSTRAINT `fk_usage_code` FOREIGN KEY (`access_code_id`) REFERENCES `access_codes` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `documents`
--
ALTER TABLE `documents`
  ADD CONSTRAINT `fk_documents_gremium` FOREIGN KEY (`gremium_id`) REFERENCES `gremien` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints der Tabelle `document_agenda`
--
ALTER TABLE `document_agenda`
  ADD CONSTRAINT `fk_document_agenda_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `document_attendance`
--
ALTER TABLE `document_attendance`
  ADD CONSTRAINT `fk_document_attendance_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `document_core`
--
ALTER TABLE `document_core`
  ADD CONSTRAINT `fk_document_core_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `document_logs`
--
ALTER TABLE `document_logs`
  ADD CONSTRAINT `fk_document_logs_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `document_state`
--
ALTER TABLE `document_state`
  ADD CONSTRAINT `fk_document_state_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `survey_responses`
--
ALTER TABLE `survey_responses`
  ADD CONSTRAINT `fk_survey_token` FOREIGN KEY (`session_token`) REFERENCES `survey_participants` (`session_token`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
