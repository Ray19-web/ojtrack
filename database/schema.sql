-- OJTrack schema only. No personal records or default passwords.
SET NAMES utf8mb4;
CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `announcements` (
  `id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `body` text NOT NULL,
  `tag` varchar(50) DEFAULT 'General',
  `target_role` enum('all','student','coordinator','company','admin') DEFAULT 'all',
  `created_by` int(11) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_pinned` tinyint(1) DEFAULT 0,
  `attachment_file` varchar(255) DEFAULT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  `expires_at` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `morning_in` time DEFAULT NULL,
  `morning_out` time DEFAULT NULL,
  `afternoon_in` time DEFAULT NULL,
  `afternoon_out` time DEFAULT NULL,
  `hours_rendered` decimal(4,2) DEFAULT 0.00,
  `remarks` varchar(255) DEFAULT NULL,
  `status` enum('present','absent','excused') DEFAULT 'present'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `companies` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `company_name` varchar(150) NOT NULL,
  `supervisor_name` varchar(100) DEFAULT NULL,
  `location` varchar(200) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `cert_template` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `coordinators` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `coordinator_id_no` varchar(50) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `contact_number` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `evaluations` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `evaluator_id` int(11) NOT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `evaluation_type` enum('midterm','final') NOT NULL,
  `technical_skills` int(11) DEFAULT NULL,
  `work_ethic` int(11) DEFAULT NULL,
  `communication` int(11) DEFAULT NULL,
  `teamwork` int(11) DEFAULT NULL,
  `initiative` int(11) DEFAULT NULL,
  `adaptability` int(11) DEFAULT NULL,
  `overall_score` decimal(5,2) DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `status` enum('pending','completed') DEFAULT 'pending',
  `evaluated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `evaluation_assignments` (
  `id` int(11) NOT NULL,
  `form_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `status` enum('pending','completed') DEFAULT 'pending',
  `answers` text DEFAULT NULL,
  `overall_score` decimal(5,2) DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `evaluation_forms` (
  `id` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `criteria` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` varchar(20) DEFAULT 'draft',
  `version` int(11) DEFAULT 1,
  `parent_id` int(11) DEFAULT 0,
  `score_mode` varchar(20) DEFAULT 'percentage',
  `rating_max` int(11) DEFAULT 100,
  `published_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `eval_answers` (
  `id` int(11) NOT NULL,
  `submission_id` int(11) NOT NULL,
  `section_title` varchar(200) DEFAULT NULL,
  `criterion_label` varchar(200) DEFAULT NULL,
  `score` decimal(5,1) DEFAULT NULL,
  `equivalent` decimal(4,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `eval_criteria` (
  `id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `label` varchar(200) NOT NULL,
  `sort_order` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `eval_rating_rules` (
  `id` int(11) NOT NULL,
  `form_id` int(11) NOT NULL,
  `score_min` int(11) NOT NULL,
  `score_max` int(11) NOT NULL,
  `equivalent` decimal(4,2) NOT NULL,
  `description` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `eval_sections` (
  `id` int(11) NOT NULL,
  `form_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `sort_order` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `eval_submissions` (
  `id` int(11) NOT NULL,
  `form_id` int(11) NOT NULL,
  `form_version` int(11) DEFAULT 1,
  `student_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `status` enum('pending','completed') DEFAULT 'pending',
  `overall_score` decimal(5,2) DEFAULT NULL,
  `overall_equivalent` decimal(4,2) DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `journal_entries` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `entry_date` date NOT NULL,
  `week_number` int(11) DEFAULT NULL,
  `activities` text NOT NULL,
  `learnings` text NOT NULL,
  `challenges` text NOT NULL,
  `hours_rendered` decimal(4,2) DEFAULT 0.00,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `coordinator_remarks` text DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `proof_image` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `thread_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `message_reads` (
  `id` int(11) NOT NULL,
  `message_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `read_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `message_threads` (
  `id` int(11) NOT NULL,
  `name` varchar(150) DEFAULT NULL,
  `thread_type` enum('group','direct') DEFAULT 'group',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `notif_type` enum('info','warning','error','success') DEFAULT 'info',
  `link` varchar(500) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ojt_requirements` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `document_name` varchar(150) NOT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `deadline` date DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `programs` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(150) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `reports` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `report_name` varchar(150) NOT NULL,
  `report_type` enum('initial','midterm','final','monthly') NOT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `deadline` date DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `status` enum('pending','approved','rejected','for_review') DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `requirement_templates` (
  `id` int(11) NOT NULL,
  `coordinator_id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `students` (
  `id` int(11) NOT NULL,
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
  `ojt_end_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `thread_members` (
  `id` int(11) NOT NULL,
  `thread_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('student','coordinator','company','admin') NOT NULL,
  `status` enum('active','inactive','archived') DEFAULT 'active',
  `avatar` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`);

ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`student_id`,`date`);

ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `coordinators`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `evaluations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `evaluator_id` (`evaluator_id`);

ALTER TABLE `evaluation_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `form_id` (`form_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `requested_by` (`requested_by`);

ALTER TABLE `evaluation_forms`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`);

ALTER TABLE `eval_answers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `submission_id` (`submission_id`);

ALTER TABLE `eval_criteria`
  ADD PRIMARY KEY (`id`),
  ADD KEY `section_id` (`section_id`);

ALTER TABLE `eval_rating_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `form_id` (`form_id`);

ALTER TABLE `eval_sections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `form_id` (`form_id`);

ALTER TABLE `eval_submissions`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `journal_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`);

ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `thread_id` (`thread_id`),
  ADD KEY `sender_id` (`sender_id`);

ALTER TABLE `message_reads`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_read` (`message_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `message_threads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`);

ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `ojt_requirements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `reviewed_by` (`reviewed_by`);

ALTER TABLE `programs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

ALTER TABLE `reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `reviewed_by` (`reviewed_by`);

ALTER TABLE `requirement_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `coordinator_id` (`coordinator_id`);

ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_id_no` (`student_id_no`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `coordinator_id` (`coordinator_id`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `program_id` (`program_id`);

ALTER TABLE `thread_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_member` (`thread_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

ALTER TABLE `activity_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `companies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `coordinators`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `evaluations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `evaluation_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `evaluation_forms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `eval_answers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `eval_criteria`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `eval_rating_rules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `eval_sections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `eval_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `journal_entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `message_reads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `message_threads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `ojt_requirements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `programs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `requirement_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `thread_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `activity_log`
  ADD CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `announcements`
  ADD CONSTRAINT `announcements_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

ALTER TABLE `companies`
  ADD CONSTRAINT `companies_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `coordinators`
  ADD CONSTRAINT `coordinators_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `evaluations`
  ADD CONSTRAINT `evaluations_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `evaluations_ibfk_2` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  ADD CONSTRAINT `evaluations_ibfk_3` FOREIGN KEY (`evaluator_id`) REFERENCES `users` (`id`);

ALTER TABLE `evaluation_assignments`
  ADD CONSTRAINT `evaluation_assignments_ibfk_1` FOREIGN KEY (`form_id`) REFERENCES `evaluation_forms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `evaluation_assignments_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `evaluation_assignments_ibfk_3` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `evaluation_assignments_ibfk_4` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`);

ALTER TABLE `evaluation_forms`
  ADD CONSTRAINT `evaluation_forms_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

ALTER TABLE `eval_answers`
  ADD CONSTRAINT `eval_answers_ibfk_1` FOREIGN KEY (`submission_id`) REFERENCES `eval_submissions` (`id`) ON DELETE CASCADE;

ALTER TABLE `eval_criteria`
  ADD CONSTRAINT `eval_criteria_ibfk_1` FOREIGN KEY (`section_id`) REFERENCES `eval_sections` (`id`) ON DELETE CASCADE;

ALTER TABLE `eval_rating_rules`
  ADD CONSTRAINT `eval_rating_rules_ibfk_1` FOREIGN KEY (`form_id`) REFERENCES `evaluation_forms` (`id`) ON DELETE CASCADE;

ALTER TABLE `eval_sections`
  ADD CONSTRAINT `eval_sections_ibfk_1` FOREIGN KEY (`form_id`) REFERENCES `evaluation_forms` (`id`) ON DELETE CASCADE;

ALTER TABLE `journal_entries`
  ADD CONSTRAINT `journal_entries_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`thread_id`) REFERENCES `message_threads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`);

ALTER TABLE `message_reads`
  ADD CONSTRAINT `message_reads_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `message_reads_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

ALTER TABLE `message_threads`
  ADD CONSTRAINT `message_threads_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `ojt_requirements`
  ADD CONSTRAINT `ojt_requirements_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ojt_requirements_ibfk_2` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`);

ALTER TABLE `reports`
  ADD CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reports_ibfk_2` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`);

ALTER TABLE `requirement_templates`
  ADD CONSTRAINT `requirement_templates_ibfk_1` FOREIGN KEY (`coordinator_id`) REFERENCES `coordinators` (`id`) ON DELETE CASCADE;

ALTER TABLE `students`
  ADD CONSTRAINT `students_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `students_ibfk_2` FOREIGN KEY (`coordinator_id`) REFERENCES `coordinators` (`id`),
  ADD CONSTRAINT `students_ibfk_3` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`);

ALTER TABLE `thread_members`
  ADD CONSTRAINT `thread_members_ibfk_1` FOREIGN KEY (`thread_id`) REFERENCES `message_threads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `thread_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
