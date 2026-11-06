-- OJTRACK web release: empty installation schema.
-- Contains no accounts, passwords, documents, rosters, or business records.
-- Import ONLY into a newly created empty database. Never import over an existing installation.

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `academic_terms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `academic_year_start` smallint(5) unsigned NOT NULL,
  `academic_year_end` smallint(5) unsigned NOT NULL,
  `semester` enum('1st','2nd','summer') NOT NULL,
  `starts_on` date DEFAULT NULL,
  `ends_on` date DEFAULT NULL,
  `status` enum('draft','active','closed') NOT NULL DEFAULT 'draft',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_academic_term` (`academic_year_start`,`academic_year_end`,`semester`),
  KEY `fk_academic_terms_created_by` (`created_by`),
  CONSTRAINT `fk_academic_terms_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `announcement_attachments` AS SELECT
 1 AS `announcement_post_id`,
  1 AS `attachment_id` */;
SET character_set_client = @saved_cs_client;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `announcement_posts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `body` text NOT NULL,
  `tag` varchar(50) DEFAULT 'General',
  `target_role` enum('all','student','coordinator','company','admin') NOT NULL DEFAULT 'all',
  `created_by` int(11) NOT NULL,
  `author_role_snapshot` enum('admin','coordinator','company','student') NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `expires_at` date DEFAULT NULL,
  `attachment_id` bigint(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_announcement_posts_active` (`is_active`,`expires_at`,`created_at`),
  KEY `fk_announcement_posts_creator` (`created_by`),
  KEY `fk_announcement_posts_attachment` (`attachment_id`),
  CONSTRAINT `fk_announcement_posts_attachment` FOREIGN KEY (`attachment_id`) REFERENCES `attachments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_announcement_posts_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `announcement_recipients` (
  `announcement_post_id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role_snapshot` enum('student','coordinator','company') NOT NULL,
  `snapshot_basis` varchar(120) NOT NULL,
  `captured_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`announcement_post_id`,`user_id`),
  KEY `idx_announcement_recipients_user` (`user_id`,`announcement_post_id`),
  CONSTRAINT `fk_announcement_recipients_post` FOREIGN KEY (`announcement_post_id`) REFERENCES `announcement_posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_announcement_recipients_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attachments` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `storage_key` varchar(500) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `detected_mime` varchar(150) NOT NULL,
  `size_bytes` bigint(20) NOT NULL,
  `sha256` char(64) NOT NULL,
  `uploaded_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attachments_storage_key` (`storage_key`),
  KEY `idx_attachments_sha256` (`sha256`),
  KEY `fk_attachments_uploaded_by` (`uploaded_by`),
  CONSTRAINT `fk_attachments_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance_days` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `placement_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `status` enum('present','absent','excused','leave') NOT NULL DEFAULT 'present',
  `credited_minutes` int(11) NOT NULL DEFAULT 0,
  `remarks` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_day` (`placement_id`,`attendance_date`),
  KEY `idx_attendance_days_date_status` (`attendance_date`,`status`),
  CONSTRAINT `fk_attendance_days_placement` FOREIGN KEY (`placement_id`) REFERENCES `placements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance_sessions` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `attendance_day_id` bigint(20) NOT NULL,
  `time_in` datetime DEFAULT NULL,
  `time_out` datetime DEFAULT NULL,
  `credited_minutes` int(11) NOT NULL DEFAULT 0,
  `source` enum('company','coordinator','migration','correction') NOT NULL DEFAULT 'company',
  `recorded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_attendance_sessions_day` (`attendance_day_id`),
  KEY `fk_attendance_sessions_recorded_by` (`recorded_by`),
  CONSTRAINT `fk_attendance_sessions_day` FOREIGN KEY (`attendance_day_id`) REFERENCES `attendance_days` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attendance_sessions_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `certificate_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `template_body` mediumtext NOT NULL,
  `logo_attachment_id` bigint(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_certificate_templates_company_active` (`company_id`,`is_active`),
  KEY `fk_certificate_templates_logo` (`logo_attachment_id`),
  KEY `fk_certificate_templates_creator` (`created_by`),
  CONSTRAINT `fk_certificate_templates_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_certificate_templates_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_certificate_templates_logo` FOREIGN KEY (`logo_attachment_id`) REFERENCES `attachments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `certificates` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `placement_id` int(11) NOT NULL,
  `certificate_template_id` int(11) DEFAULT NULL,
  `certificate_no` varchar(100) NOT NULL,
  `issued_by` int(11) NOT NULL,
  `issued_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `rendered_snapshot` mediumtext NOT NULL,
  `attachment_id` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_certificates_no` (`certificate_no`),
  UNIQUE KEY `uq_certificates_placement` (`placement_id`),
  KEY `fk_certificates_template` (`certificate_template_id`),
  KEY `fk_certificates_issuer` (`issued_by`),
  KEY `fk_certificates_attachment` (`attachment_id`),
  CONSTRAINT `fk_certificates_attachment` FOREIGN KEY (`attachment_id`) REFERENCES `attachments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_certificates_issuer` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_certificates_placement` FOREIGN KEY (`placement_id`) REFERENCES `placements` (`id`),
  CONSTRAINT `fk_certificates_template` FOREIGN KEY (`certificate_template_id`) REFERENCES `certificate_templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `companies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `company_name` varchar(150) NOT NULL,
  `supervisor_name` varchar(100) DEFAULT NULL,
  `location` varchar(200) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `cert_template` text DEFAULT NULL,
  `supervisor_name_extension_type` varchar(30) DEFAULT NULL,
  `supervisor_name_extension_value` varchar(50) DEFAULT NULL,
  `supervisor_first_name` varchar(60) DEFAULT NULL,
  `supervisor_middle_name` varchar(60) DEFAULT NULL,
  `supervisor_last_name` varchar(60) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `companies_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `company_supervisor_name_extensions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `extension_type` varchar(30) NOT NULL,
  `extension_value` varchar(50) NOT NULL,
  `sort_order` tinyint(3) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_supervisor_name_extensions_order` (`company_id`,`sort_order`),
  CONSTRAINT `fk_company_supervisor_name_extensions_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `company_users` AS SELECT
 1 AS `id`,
  1 AS `company_id`,
  1 AS `user_id`,
  1 AS `position_title`,
  1 AS `company_role`,
  1 AS `is_primary`,
  1 AS `status`,
  1 AS `created_at` */;
SET character_set_client = @saved_cs_client;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `coordinators` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `coordinator_id_no` varchar(50) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `first_name` varchar(60) DEFAULT NULL,
  `middle_name` varchar(60) DEFAULT NULL,
  `last_name` varchar(60) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `coordinators_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `enrollment_coordinators` AS SELECT
 1 AS `id`,
  1 AS `ojt_enrollment_id`,
  1 AS `coordinator_id`,
  1 AS `assigned_by`,
  1 AS `assigned_at`,
  1 AS `ended_at` */;
SET character_set_client = @saved_cs_client;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `evaluation_answers` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `evaluation_submission_id` bigint(20) NOT NULL,
  `evaluation_criterion_id` int(11) NOT NULL,
  `section_title_snapshot` varchar(200) DEFAULT NULL,
  `criterion_label_snapshot` varchar(255) NOT NULL,
  `score` decimal(7,2) NOT NULL,
  `equivalent` decimal(7,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_evaluation_answer_criterion` (`evaluation_submission_id`,`evaluation_criterion_id`),
  KEY `fk_evaluation_answers_criterion` (`evaluation_criterion_id`),
  CONSTRAINT `fk_evaluation_answers_criterion` FOREIGN KEY (`evaluation_criterion_id`) REFERENCES `evaluation_version_criteria` (`id`),
  CONSTRAINT `fk_evaluation_answers_submission` FOREIGN KEY (`evaluation_submission_id`) REFERENCES `evaluation_submissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `evaluation_definition_versions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `evaluation_definition_id` int(11) NOT NULL,
  `version_no` int(11) NOT NULL,
  `score_mode` enum('percentage','rating','points') NOT NULL DEFAULT 'percentage',
  `rating_max` int(11) NOT NULL DEFAULT 100,
  `status` enum('draft','published','retired') NOT NULL DEFAULT 'draft',
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_evaluation_definition_version` (`evaluation_definition_id`,`version_no`),
  CONSTRAINT `fk_evaluation_definition_versions_definition` FOREIGN KEY (`evaluation_definition_id`) REFERENCES `evaluation_definitions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `evaluation_definitions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_by` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','archived') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_evaluation_definitions_creator` (`created_by`),
  CONSTRAINT `fk_evaluation_definitions_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `evaluation_requests` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `evaluation_definition_version_id` int(11) NOT NULL,
  `placement_id` int(11) NOT NULL,
  `evaluator_company_user_id` int(11) NOT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `evaluation_kind` enum('midterm','final','custom') NOT NULL DEFAULT 'custom',
  `due_date` date DEFAULT NULL,
  `status` enum('pending','submitted','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_evaluation_requests_evaluator_status` (`evaluator_company_user_id`,`status`),
  KEY `idx_evaluation_requests_placement` (`placement_id`),
  KEY `fk_evaluation_requests_version` (`evaluation_definition_version_id`),
  KEY `fk_evaluation_requests_requester` (`requested_by`),
  CONSTRAINT `fk_evaluation_requests_evaluator_user` FOREIGN KEY (`evaluator_company_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_evaluation_requests_placement` FOREIGN KEY (`placement_id`) REFERENCES `placements` (`id`),
  CONSTRAINT `fk_evaluation_requests_requester` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_evaluation_requests_version` FOREIGN KEY (`evaluation_definition_version_id`) REFERENCES `evaluation_definition_versions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `evaluation_submissions` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `evaluation_request_id` bigint(20) NOT NULL,
  `submitted_by` int(11) NOT NULL,
  `overall_score` decimal(7,2) DEFAULT NULL,
  `overall_equivalent` decimal(7,2) DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_evaluation_submission_request` (`evaluation_request_id`),
  KEY `fk_evaluation_submissions_submitter` (`submitted_by`),
  CONSTRAINT `fk_evaluation_submissions_request` FOREIGN KEY (`evaluation_request_id`) REFERENCES `evaluation_requests` (`id`),
  CONSTRAINT `fk_evaluation_submissions_submitter` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `evaluation_version_criteria` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `evaluation_version_section_id` int(11) NOT NULL,
  `criterion_code` varchar(80) NOT NULL,
  `label` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `weight` decimal(6,3) NOT NULL DEFAULT 1.000,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_evaluation_version_criterion_code` (`evaluation_version_section_id`,`criterion_code`),
  CONSTRAINT `fk_evaluation_version_criteria_section` FOREIGN KEY (`evaluation_version_section_id`) REFERENCES `evaluation_version_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `evaluation_version_rating_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `evaluation_definition_version_id` int(11) NOT NULL,
  `score_min` decimal(6,2) NOT NULL,
  `score_max` decimal(6,2) NOT NULL,
  `equivalent` decimal(6,2) DEFAULT NULL,
  `description` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_evaluation_version_rating_rules` (`evaluation_definition_version_id`,`score_min`,`score_max`),
  CONSTRAINT `fk_evaluation_version_rating_rules_version` FOREIGN KEY (`evaluation_definition_version_id`) REFERENCES `evaluation_definition_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `evaluation_version_sections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `evaluation_definition_version_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_evaluation_version_sections_order` (`evaluation_definition_version_id`,`sort_order`),
  CONSTRAINT `fk_evaluation_version_sections_version` FOREIGN KEY (`evaluation_definition_version_id`) REFERENCES `evaluation_definition_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `journal_additional_attachments` (
  `journal_revision_id` bigint(20) unsigned NOT NULL,
  `attachment_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`journal_revision_id`,`attachment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `journal_days` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `placement_id` int(11) NOT NULL,
  `entry_date` date NOT NULL,
  `week_number` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_journal_day` (`placement_id`,`entry_date`),
  KEY `idx_journal_days_date` (`entry_date`),
  CONSTRAINT `fk_journal_days_placement` FOREIGN KEY (`placement_id`) REFERENCES `placements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `journal_revision_attachments` AS SELECT
 1 AS `journal_revision_id`,
  1 AS `attachment_id` */;
SET character_set_client = @saved_cs_client;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `journal_revisions` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `journal_day_id` bigint(20) NOT NULL,
  `revision_no` int(11) NOT NULL,
  `activities` text NOT NULL,
  `learnings` text NOT NULL,
  `challenges` text NOT NULL,
  `claimed_minutes` int(11) NOT NULL DEFAULT 0,
  `status` enum('draft','submitted','returned','approved') NOT NULL DEFAULT 'draft',
  `submitted_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `attachment_id` bigint(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_journal_revision` (`journal_day_id`,`revision_no`),
  KEY `idx_journal_revisions_status` (`status`,`submitted_at`),
  KEY `fk_journal_revisions_reviewer` (`reviewed_by`),
  KEY `fk_journal_revisions_attachment` (`attachment_id`),
  CONSTRAINT `fk_journal_revisions_attachment` FOREIGN KEY (`attachment_id`) REFERENCES `attachments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_journal_revisions_day` FOREIGN KEY (`journal_day_id`) REFERENCES `journal_days` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_journal_revisions_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `message_reads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `message_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `read_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_read` (`message_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `message_reads_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `message_reads_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `message_threads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) DEFAULT NULL,
  `thread_type` enum('group','direct') DEFAULT 'group',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `message_threads_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `thread_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `thread_id` (`thread_id`),
  KEY `sender_id` (`sender_id`),
  CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`thread_id`) REFERENCES `message_threads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `notif_type` enum('info','warning','error','success') DEFAULT 'info',
  `link` varchar(500) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ojt_enrollments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `academic_term_id` int(11) NOT NULL,
  `program_id` int(11) NOT NULL,
  `coordinator_id` int(11) DEFAULT NULL,
  `coordinator_assigned_by` int(11) DEFAULT NULL,
  `coordinator_assigned_at` timestamp NULL DEFAULT NULL,
  `year_level` varchar(30) DEFAULT NULL,
  `required_hours` int(11) NOT NULL DEFAULT 486,
  `status` enum('pending','not_started','ongoing','on_hold','completed','withdrawn') NOT NULL DEFAULT 'pending',
  `onboarding_completed_at` timestamp NULL DEFAULT NULL,
  `status_notes` text DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ojt_enrollment_student_term` (`student_id`,`academic_term_id`),
  KEY `idx_ojt_enrollments_term_status` (`academic_term_id`,`status`),
  KEY `idx_ojt_enrollments_program` (`program_id`),
  KEY `fk_ojt_enrollments_coordinator` (`coordinator_id`),
  KEY `fk_ojt_enrollments_coordinator_assigner` (`coordinator_assigned_by`),
  CONSTRAINT `fk_ojt_enrollments_coordinator` FOREIGN KEY (`coordinator_id`) REFERENCES `coordinators` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ojt_enrollments_coordinator_assigner` FOREIGN KEY (`coordinator_assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ojt_enrollments_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`),
  CONSTRAINT `fk_ojt_enrollments_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`),
  CONSTRAINT `fk_ojt_enrollments_term` FOREIGN KEY (`academic_term_id`) REFERENCES `academic_terms` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `placements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ojt_enrollment_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `status` enum('planned','active','on_hold','completed','terminated','transferred') NOT NULL DEFAULT 'planned',
  `starts_on` date DEFAULT NULL,
  `ends_on` date DEFAULT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_placements_enrollment_status` (`ojt_enrollment_id`,`status`),
  KEY `idx_placements_company_status` (`company_id`,`status`),
  KEY `fk_placements_assigned_by` (`assigned_by`),
  CONSTRAINT `fk_placements_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_placements_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_placements_enrollment` FOREIGN KEY (`ojt_enrollment_id`) REFERENCES `ojt_enrollments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `programs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(150) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `report_assignments` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `report_template_version_id` int(11) NOT NULL,
  `ojt_enrollment_id` int(11) NOT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `period_start` date DEFAULT NULL,
  `period_end` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `status` enum('assigned','closed','waived') NOT NULL DEFAULT 'assigned',
  `assigned_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_report_assignments_enrollment` (`ojt_enrollment_id`,`status`),
  KEY `idx_report_assignments_version` (`report_template_version_id`),
  KEY `fk_report_assignments_assigner` (`assigned_by`),
  CONSTRAINT `fk_report_assignments_assigner` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_report_assignments_enrollment` FOREIGN KEY (`ojt_enrollment_id`) REFERENCES `ojt_enrollments` (`id`),
  CONSTRAINT `fk_report_assignments_version` FOREIGN KEY (`report_template_version_id`) REFERENCES `report_template_versions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `report_submission_attachments` AS SELECT
 1 AS `report_submission_id`,
  1 AS `attachment_id` */;
SET character_set_client = @saved_cs_client;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `report_submission_files` (
  `report_submission_id` bigint(20) NOT NULL,
  `attachment_id` bigint(20) NOT NULL,
  PRIMARY KEY (`report_submission_id`,`attachment_id`),
  KEY `idx_report_submission_files_attachment` (`attachment_id`),
  CONSTRAINT `fk_report_submission_files_attachment` FOREIGN KEY (`attachment_id`) REFERENCES `attachments` (`id`),
  CONSTRAINT `fk_report_submission_files_submission` FOREIGN KEY (`report_submission_id`) REFERENCES `report_submissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `report_submissions` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `report_assignment_id` bigint(20) NOT NULL,
  `version_no` int(11) NOT NULL,
  `submitted_by` int(11) NOT NULL,
  `status` enum('submitted','returned','approved') NOT NULL DEFAULT 'submitted',
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `student_note` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `evidence_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`evidence_snapshot`)),
  `attachment_id` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_report_submission_version` (`report_assignment_id`,`version_no`),
  KEY `fk_report_submissions_submitter` (`submitted_by`),
  KEY `fk_report_submissions_reviewer` (`reviewed_by`),
  KEY `fk_report_submissions_attachment` (`attachment_id`),
  CONSTRAINT `fk_report_submissions_assignment` FOREIGN KEY (`report_assignment_id`) REFERENCES `report_assignments` (`id`),
  CONSTRAINT `fk_report_submissions_attachment` FOREIGN KEY (`attachment_id`) REFERENCES `attachments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_report_submissions_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_report_submissions_submitter` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `report_template_versions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `report_template_id` int(11) NOT NULL,
  `version_no` int(11) NOT NULL,
  `report_type` enum('initial','midterm','final','monthly','custom') NOT NULL,
  `instructions` text DEFAULT NULL,
  `status` enum('draft','published','retired') NOT NULL DEFAULT 'draft',
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_report_template_version` (`report_template_id`,`version_no`),
  CONSTRAINT `fk_report_template_versions_template` FOREIGN KEY (`report_template_id`) REFERENCES `report_templates` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `report_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_by` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `status` enum('active','archived') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_report_templates_title` (`title`),
  KEY `fk_report_templates_creator` (`created_by`),
  CONSTRAINT `fk_report_templates_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `requirement_assignments` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `requirement_definition_version_id` int(11) NOT NULL,
  `ojt_enrollment_id` int(11) NOT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `assignment_note` text DEFAULT NULL,
  `status` enum('assigned','closed','waived') NOT NULL DEFAULT 'assigned',
  `assigned_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_requirement_assignments_enrollment` (`ojt_enrollment_id`,`status`),
  KEY `idx_requirement_assignments_version` (`requirement_definition_version_id`),
  KEY `fk_requirement_assignments_assigner` (`assigned_by`),
  CONSTRAINT `fk_requirement_assignments_assigner` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_requirement_assignments_enrollment` FOREIGN KEY (`ojt_enrollment_id`) REFERENCES `ojt_enrollments` (`id`),
  CONSTRAINT `fk_requirement_assignments_version` FOREIGN KEY (`requirement_definition_version_id`) REFERENCES `requirement_definition_versions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `requirement_definition_versions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `requirement_definition_id` int(11) NOT NULL,
  `version_no` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `status` enum('draft','published','retired') NOT NULL DEFAULT 'draft',
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_requirement_definition_version` (`requirement_definition_id`,`version_no`),
  CONSTRAINT `fk_requirement_definition_versions_definition` FOREIGN KEY (`requirement_definition_id`) REFERENCES `requirement_definitions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `requirement_definitions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_by` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `status` enum('active','archived') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_requirement_definitions_title` (`title`),
  KEY `fk_requirement_definitions_creator` (`created_by`),
  CONSTRAINT `fk_requirement_definitions_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `requirement_submission_attachments` AS SELECT
 1 AS `requirement_submission_id`,
  1 AS `attachment_id` */;
SET character_set_client = @saved_cs_client;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `requirement_submission_files` (
  `requirement_submission_id` bigint(20) NOT NULL,
  `attachment_id` bigint(20) NOT NULL,
  PRIMARY KEY (`requirement_submission_id`,`attachment_id`),
  KEY `idx_requirement_submission_files_attachment` (`attachment_id`),
  CONSTRAINT `fk_requirement_submission_files_attachment` FOREIGN KEY (`attachment_id`) REFERENCES `attachments` (`id`),
  CONSTRAINT `fk_requirement_submission_files_submission` FOREIGN KEY (`requirement_submission_id`) REFERENCES `requirement_submissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `requirement_submissions` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `requirement_assignment_id` bigint(20) NOT NULL,
  `version_no` int(11) NOT NULL,
  `submitted_by` int(11) NOT NULL,
  `status` enum('submitted','returned','approved') NOT NULL DEFAULT 'submitted',
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `attachment_id` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_requirement_submission_version` (`requirement_assignment_id`,`version_no`),
  KEY `fk_requirement_submissions_submitter` (`submitted_by`),
  KEY `fk_requirement_submissions_reviewer` (`reviewed_by`),
  KEY `fk_requirement_submissions_attachment` (`attachment_id`),
  CONSTRAINT `fk_requirement_submissions_assignment` FOREIGN KEY (`requirement_assignment_id`) REFERENCES `requirement_assignments` (`id`),
  CONSTRAINT `fk_requirement_submissions_attachment` FOREIGN KEY (`attachment_id`) REFERENCES `attachments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_requirement_submissions_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_requirement_submissions_submitter` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `schema_migrations` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `version` varchar(50) NOT NULL,
  `description` varchar(255) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_schema_migrations_version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `students` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `student_id_no` varchar(20) NOT NULL,
  `program_id` int(11) DEFAULT NULL,
  `program` varchar(100) DEFAULT 'BS Information Technology',
  `department` varchar(100) DEFAULT NULL,
  `year_level` varchar(20) DEFAULT '4th Year',
  `contact_number` varchar(20) DEFAULT NULL,
  `coordinator_id` int(11) DEFAULT NULL,
  `company_id` int(11) DEFAULT NULL,
  `ojt_status` enum('pending','not_started','ongoing','completed','on_hold','withdrawn') DEFAULT 'pending',
  `onboarding_completed_at` timestamp NULL DEFAULT NULL,
  `status_notes` text DEFAULT NULL,
  `is_archived` tinyint(1) DEFAULT 0,
  `required_hours` int(11) DEFAULT 486,
  `rendered_hours` decimal(8,2) DEFAULT 0.00,
  `ojt_start_date` date DEFAULT NULL,
  `ojt_end_date` date DEFAULT NULL,
  `first_name` varchar(60) DEFAULT NULL,
  `middle_name` varchar(60) DEFAULT NULL,
  `last_name` varchar(60) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_id_no` (`student_id_no`),
  KEY `user_id` (`user_id`),
  KEY `coordinator_id` (`coordinator_id`),
  KEY `company_id` (`company_id`),
  KEY `program_id` (`program_id`),
  CONSTRAINT `students_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `students_ibfk_2` FOREIGN KEY (`coordinator_id`) REFERENCES `coordinators` (`id`),
  CONSTRAINT `students_ibfk_3` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `thread_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `thread_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_member` (`thread_id`,`user_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_thread_members_archive` (`user_id`,`archived_at`),
  CONSTRAINT `thread_members_ibfk_1` FOREIGN KEY (`thread_id`) REFERENCES `message_threads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `thread_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_name_extensions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `extension_type` varchar(30) NOT NULL,
  `extension_value` varchar(50) NOT NULL,
  `sort_order` tinyint(3) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_name_extensions_order` (`user_id`,`sort_order`),
  CONSTRAINT `fk_user_name_extensions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('student','coordinator','company','admin') NOT NULL,
  `status` enum('active','inactive','archived') DEFAULT 'active',
  `avatar` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `name_extension_type` varchar(30) DEFAULT NULL,
  `name_extension_value` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50001 DROP VIEW IF EXISTS `announcement_attachments`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY INVOKER */
/*!50001 VIEW `announcement_attachments` AS select `announcement_posts`.`id` AS `announcement_post_id`,`announcement_posts`.`attachment_id` AS `attachment_id` from `announcement_posts` where `announcement_posts`.`attachment_id` is not null */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `company_users`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY INVOKER */
/*!50001 VIEW `company_users` AS select `c`.`user_id` AS `id`,`c`.`id` AS `company_id`,`c`.`user_id` AS `user_id`,`c`.`supervisor_name` AS `position_title`,'supervisor' AS `company_role`,1 AS `is_primary`,case when `c`.`status` = 'active' and `u`.`status` = 'active' then 'active' else 'inactive' end AS `status`,`u`.`created_at` AS `created_at` from (`companies` `c` join `users` `u` on(`u`.`id` = `c`.`user_id`)) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `enrollment_coordinators`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY INVOKER */
/*!50001 VIEW `enrollment_coordinators` AS select `oe`.`id` AS `id`,`oe`.`id` AS `ojt_enrollment_id`,`oe`.`coordinator_id` AS `coordinator_id`,`oe`.`coordinator_assigned_by` AS `assigned_by`,coalesce(`oe`.`coordinator_assigned_at`,`oe`.`created_at`) AS `assigned_at`,NULL AS `ended_at` from `ojt_enrollments` `oe` where `oe`.`coordinator_id` is not null */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `journal_revision_attachments`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY INVOKER */
/*!50001 VIEW `journal_revision_attachments` AS select `journal_revisions`.`id` AS `journal_revision_id`,`journal_revisions`.`attachment_id` AS `attachment_id` from `journal_revisions` where `journal_revisions`.`attachment_id` is not null union select `journal_additional_attachments`.`journal_revision_id` AS `journal_revision_id`,`journal_additional_attachments`.`attachment_id` AS `attachment_id` from `journal_additional_attachments` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `report_submission_attachments`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY INVOKER */
/*!50001 VIEW `report_submission_attachments` AS select `report_submissions`.`id` AS `report_submission_id`,`report_submissions`.`attachment_id` AS `attachment_id` from `report_submissions` where `report_submissions`.`attachment_id` is not null */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `requirement_submission_attachments`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY INVOKER */
/*!50001 VIEW `requirement_submission_attachments` AS select `requirement_submissions`.`id` AS `requirement_submission_id`,`requirement_submissions`.`attachment_id` AS `attachment_id` from `requirement_submissions` where `requirement_submissions`.`attachment_id` is not null */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


INSERT INTO schema_migrations (version,description) VALUES
('001_core_identity_and_terms','Included in the web release install schema'),
('002_attendance','Included in the web release install schema'),
('003_journals_and_attachments','Included in the web release install schema'),
('004_requirements','Included in the web release install schema'),
('005_reports','Included in the web release install schema'),
('006_evaluations','Included in the web release install schema'),
('007_certificates_and_announcements','Included in the web release install schema'),
('008_application_cutover','Included in the web release install schema'),
('009_legacy_retirement','Included in the web release install schema'),
('010_lean_schema','Included in the web release install schema'),
('011_optional_user_email','Included in the web release install schema'),
('012_conversation_archiving','Included in the web release install schema'),
('013_evaluation_points','Included in the web release install schema'),
('014_student_name_parts','Included in the web release install schema'),
('015_name_extensions','Included in the web release install schema'),
('016_multiple_name_extensions','Included in the web release install schema'),
('017_person_name_parts','Included in the web release install schema'),
('018_multiple_submission_files','Included in the web release install schema'),
('011_journal_multiple_images','Included in the web release install schema');
