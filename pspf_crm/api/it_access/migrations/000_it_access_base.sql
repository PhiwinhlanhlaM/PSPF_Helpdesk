-- =====================================================================
-- IT Access module — BASE schema install (production bootstrap)
-- Target database: pspf_helpdesk
--
-- This is the consolidated, idempotent install for the IT Access request
-- module. It creates the three module tables, the two permission roles the
-- code checks (it_officer / it_director), and the users.full_name column the
-- module reads/writes. It is safe to run on a database that already has some
-- or all of these (every statement is guarded), so it can be applied to live
-- without fear of clobbering existing data.
--
-- Reconstructed from the working local schema + a full audit of the module
-- code (submit.php, approve.php, claim.php, list.php, generate_pdf.php,
-- mailer.php, index.php, profile_name.php). It already folds in the two later
-- migrations (per-system claims + users.full_name), so on a fresh live DB you
-- run ONLY this file.
--
-- Run:  mysql -u root -p pspf_helpdesk < 000_it_access_base.sql
--
-- Requires MariaDB 10.4+/MySQL 8.0+ (uses ADD COLUMN IF NOT EXISTS).
-- Depends on an existing `users` table (users.id) and `roles`/`user_roles`
-- (standard CRM auth tables) — those are already on live.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. Permission roles the module checks via hasRole().
--    it_officer  — ICT staff who claim/action requests
--    it_director — signs off provisioning
--    These are PERMISSION roles (never the active persona). Assign them to
--    the relevant users afterwards via Settings → User Management.
-- ---------------------------------------------------------------------
INSERT INTO `roles` (`name`, `description`)
SELECT 'it_officer', 'ICT officer — claims and actions IT access requests'
WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `name` = 'it_officer');

INSERT INTO `roles` (`name`, `description`)
SELECT 'it_director', 'IT Director — reviews and signs off IT access provisioning'
WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `name` = 'it_director');

-- ---------------------------------------------------------------------
-- 2. users.full_name — captured once via the IT Access form prompt; read by
--    the module (and by User Management). Additive, idempotent.
-- ---------------------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `full_name` VARCHAR(150) DEFAULT NULL AFTER `Username`;

-- ---------------------------------------------------------------------
-- 3. it_access_requests — one row per request (REQ-YYYY-NNNN).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `it_access_requests` (
  `id`            INT(11) NOT NULL AUTO_INCREMENT,
  `ref_number`    VARCHAR(20)  NOT NULL,
  `request_type`  ENUM('new','change') NOT NULL DEFAULT 'new',
  `employee_name` VARCHAR(255) NOT NULL,
  `employee_id`   VARCHAR(100) DEFAULT NULL,
  `department`    VARCHAR(255) NOT NULL,
  `division`      VARCHAR(255) DEFAULT NULL,
  `job_title`     VARCHAR(255) NOT NULL,
  `start_date`    DATE NOT NULL,
  `justification` TEXT NOT NULL,
  `submitted_by`  INT(11) NOT NULL,
  `submitted_at`  DATETIME NOT NULL DEFAULT current_timestamp(),
  `status`        ENUM('new','claimed','awaiting-director','provisioned','rejected') NOT NULL DEFAULT 'new',
  `claimed_by`    INT(11) DEFAULT NULL,
  `provisioned_at` DATETIME DEFAULT NULL,
  `pdf_filename`  VARCHAR(255) DEFAULT NULL,
  `sharepoint_id` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ref_number` (`ref_number`),
  KEY `submitted_by` (`submitted_by`),
  KEY `claimed_by` (`claimed_by`),
  KEY `status` (`status`),
  CONSTRAINT `it_access_requests_submitted_by` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`),
  CONSTRAINT `it_access_requests_claimed_by`   FOREIGN KEY (`claimed_by`)   REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 4. it_request_systems — the systems requested, each independently
--    claimable/actionable by an IT officer.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `it_request_systems` (
  `id`          INT(11) NOT NULL AUTO_INCREMENT,
  `request_id`  INT(11) NOT NULL,
  `system_id`   VARCHAR(100) NOT NULL,
  `role`        VARCHAR(255) DEFAULT NULL,
  `sub_values`  TEXT DEFAULT NULL,
  `status`      ENUM('pending','claimed','actioned') NOT NULL DEFAULT 'pending',
  `claimed_by`  INT(11) DEFAULT NULL,
  `claimed_at`  DATETIME DEFAULT NULL,
  `actioned_by` INT(11) DEFAULT NULL,
  `actioned_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  CONSTRAINT `it_request_systems_request_id` FOREIGN KEY (`request_id`) REFERENCES `it_access_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 5. it_request_approvals — the approval/action trail (manager, officer,
--    director), including captured signatures.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `it_request_approvals` (
  `id`               INT(11) NOT NULL AUTO_INCREMENT,
  `request_id`       INT(11) NOT NULL,
  `step_role`        ENUM('manager','officer-1','director') NOT NULL,
  `approver_id`      INT(11) NOT NULL,
  `action`           ENUM('approved','rejected') NOT NULL,
  `acted_at`         DATETIME NOT NULL DEFAULT current_timestamp(),
  `reason`           TEXT DEFAULT NULL,
  `sig_kind`         VARCHAR(20) DEFAULT NULL,
  `sig_data`         LONGTEXT DEFAULT NULL,
  `actioned_systems` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  KEY `approver_id` (`approver_id`),
  CONSTRAINT `it_request_approvals_request_id`  FOREIGN KEY (`request_id`)  REFERENCES `it_access_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `it_request_approvals_approver_id` FOREIGN KEY (`approver_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
