-- IT Access Request Feature — Migration
-- Run against pspf_helpdesk database after schema.sql has been applied.
-- Safe to re-run: uses IF NOT EXISTS / INSERT IGNORE.

USE `pspf_helpdesk`;

-- ── New roles ────────────────────────────────────────────────
INSERT IGNORE INTO `roles` (`name`, `description`) VALUES
  ('it_officer',  'IT Officer — can claim and review IT access requests'),
  ('it_director', 'IT Director — provides final sign-off on access requests');

-- ── Auto-assign it_officer to all active ICT-department agents ─
-- ICT agents are the ones who physically action access requests.
-- Re-running this is safe: INSERT IGNORE skips existing assignments.
INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`)
SELECT u.id, r.id
FROM users u
JOIN user_roles ur_agent ON ur_agent.user_id = u.id
JOIN roles     r_agent   ON r_agent.id = ur_agent.role_id AND r_agent.name = 'agent'
JOIN roles     r         ON r.name = 'it_officer'
WHERE u.department = 'ICT'
  AND u.is_active  = 1;

-- ── Main request table ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `it_access_requests` (
  `id`             int(11)      NOT NULL AUTO_INCREMENT,
  `ref_number`     varchar(20)  NOT NULL,
  `request_type`   enum('new','change') NOT NULL DEFAULT 'new',
  `employee_name`  varchar(255) NOT NULL,
  `employee_id`    varchar(100) DEFAULT NULL,
  `department`     varchar(255) NOT NULL,
  `division`       varchar(255) DEFAULT NULL,
  `job_title`      varchar(255) NOT NULL,
  `start_date`     date         NOT NULL,
  `justification`  text         NOT NULL,
  `submitted_by`   int(11)      NOT NULL,
  `submitted_at`   datetime     NOT NULL DEFAULT current_timestamp(),
  `status`         enum('new','claimed','awaiting-director','provisioned','rejected') NOT NULL DEFAULT 'new',
  `claimed_by`     int(11)      DEFAULT NULL,
  `provisioned_at` datetime     DEFAULT NULL,
  `pdf_filename`   varchar(255) DEFAULT NULL,
  `sharepoint_id`  varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ref_number` (`ref_number`),
  KEY `submitted_by` (`submitted_by`),
  KEY `claimed_by`   (`claimed_by`),
  KEY `status`       (`status`),
  CONSTRAINT `it_access_requests_submitted_by` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`),
  CONSTRAINT `it_access_requests_claimed_by`   FOREIGN KEY (`claimed_by`)   REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── Systems requested per request ────────────────────────────
CREATE TABLE IF NOT EXISTS `it_request_systems` (
  `id`         int(11)      NOT NULL AUTO_INCREMENT,
  `request_id` int(11)      NOT NULL,
  `system_id`  varchar(100) NOT NULL,
  `role`       varchar(255) DEFAULT NULL,
  `sub_values` text         DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  CONSTRAINT `it_request_systems_request_id` FOREIGN KEY (`request_id`) REFERENCES `it_access_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── Approval chain records ────────────────────────────────────
CREATE TABLE IF NOT EXISTS `it_request_approvals` (
  `id`               int(11)   NOT NULL AUTO_INCREMENT,
  `request_id`       int(11)   NOT NULL,
  `step_role`        enum('manager','officer-1','director') NOT NULL,
  `approver_id`      int(11)   NOT NULL,
  `action`           enum('approved','rejected') NOT NULL,
  `acted_at`         datetime  NOT NULL DEFAULT current_timestamp(),
  `reason`           text      DEFAULT NULL,
  `sig_kind`         varchar(20) DEFAULT NULL,
  `sig_data`         longtext  DEFAULT NULL,
  `actioned_systems` text      DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `request_id`  (`request_id`),
  KEY `approver_id` (`approver_id`),
  CONSTRAINT `it_request_approvals_request_id`  FOREIGN KEY (`request_id`)  REFERENCES `it_access_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `it_request_approvals_approver_id` FOREIGN KEY (`approver_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
