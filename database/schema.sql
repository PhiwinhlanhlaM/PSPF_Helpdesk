-- PSPF CRM + Vehicle Requisition — Database Schema
-- Run this file once on a fresh install to initialise both databases.
-- After importing, run seed_admin.sql to create the default admin account.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET NAMES utf8mb4;

-- ============================================================
-- DATABASE: pspf_helpdesk
-- ============================================================

CREATE DATABASE IF NOT EXISTS `pspf_helpdesk`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `pspf_helpdesk`;

-- ------------------------------------------------------------

CREATE TABLE `departments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `department_name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `departments` (`id`, `department_name`) VALUES
(1, 'CEO Office'),
(2, 'Corporate Services'),
(3, 'Finance'),
(4, 'IAR'),
(5, 'ICT'),
(6, 'Investments'),
(7, 'Operations');

-- ------------------------------------------------------------

CREATE TABLE `divisions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `department_id` int(11) NOT NULL,
  `division_name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `department_id` (`department_id`),
  CONSTRAINT `divisions_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `divisions` (`id`, `department_id`, `division_name`) VALUES
(1,  1, 'PA to Executive'),
(2,  1, 'Company Secretary'),
(3,  2, 'Human Resources'),
(4,  2, 'Marketing'),
(5,  2, 'Facilities'),
(6,  3, 'Accounting'),
(7,  3, 'Investment Monitoring'),
(8,  3, 'Procurement'),
(9,  4, 'Auditing'),
(10, 5, 'IT Support'),
(11, 6, 'Investment'),
(12, 7, 'Benefits'),
(13, 7, 'Legal');

-- ------------------------------------------------------------

CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `roles` (`id`, `name`, `description`) VALUES
(1, 'user',       'Regular user'),
(2, 'admin',      'Middle Management'),
(3, 'superadmin', 'IT administrators'),
(4, 'agent',      'Assigned task users');

-- ------------------------------------------------------------

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `Username` varchar(100) NOT NULL,
  `department` varchar(100) NOT NULL,
  `division_id` int(11) NOT NULL,
  `Email` varchar(255) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `Password` varchar(255) NOT NULL,
  `Created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `Updated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------

CREATE TABLE `user_roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `role_id` (`role_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `user_roles_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `user_roles_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------

CREATE TABLE `tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `member_type` varchar(50) NOT NULL,
  `region` varchar(50) NOT NULL,
  `source` varchar(50) NOT NULL,
  `query_type` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `priority` varchar(20) NOT NULL,
  `phone_number` varchar(20) NOT NULL,
  `query_date` datetime NOT NULL,
  `created_by` varchar(100) NOT NULL,
  `status` varchar(50) DEFAULT 'Open',
  `attachment_path` varchar(255) DEFAULT NULL,
  `assigned_to` varchar(255) DEFAULT NULL,
  `department_reason` text NOT NULL,
  `division_id` int(11) DEFAULT NULL,
  `last_updated_by` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `closure_id` (`division_id`),
  CONSTRAINT `tickets_ibfk_1` FOREIGN KEY (`division_id`) REFERENCES `divisions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DELIMITER $$
CREATE TRIGGER `after_ticket_status_update`
AFTER UPDATE ON `tickets` FOR EACH ROW
BEGIN
  IF OLD.status != NEW.status THEN
    INSERT INTO ticket_status_logs (ticket_id, old_status, new_status, changed_by)
    VALUES (NEW.id, OLD.status, NEW.status, NEW.assigned_to);
  END IF;
END$$
DELIMITER ;

-- ------------------------------------------------------------

CREATE TABLE `ticket_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `assigned_to` varchar(100) NOT NULL,
  `assigned_by` varchar(100) NOT NULL,
  `assignment_method` varchar(50) NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_assigned_to` (`assigned_to`),
  KEY `idx_assigned_at` (`assigned_at`),
  KEY `idx_ticket_id` (`ticket_id`),
  CONSTRAINT `ticket_assignments_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------

CREATE TABLE `ticket_closures` (
  `closure_id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `closed_by` varchar(255) NOT NULL,
  `closed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `closure_reason` text NOT NULL,
  PRIMARY KEY (`closure_id`),
  KEY `ticket_id` (`ticket_id`),
  CONSTRAINT `ticket_closures_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- ------------------------------------------------------------

CREATE TABLE `ticket_escalations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `escalated_by` varchar(150) NOT NULL,
  `escalated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `escalation_reason` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------

CREATE TABLE `ticket_feedback` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `rating` int(11) NOT NULL CHECK (`rating` between 1 and 5),
  `comment` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ticket_id` (`ticket_id`),
  KEY `fk_feedback_user` (`user_id`),
  CONSTRAINT `fk_feedback_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_feedback_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------

CREATE TABLE `ticket_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `changed_by` varchar(100) NOT NULL,
  `old_status` varchar(50) NOT NULL,
  `new_status` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------

CREATE TABLE `ticket_progress` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `description` text NOT NULL,
  `updated_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`),
  CONSTRAINT `ticket_progress_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------

CREATE TABLE `ticket_reopens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `reopened_by` varchar(150) NOT NULL,
  `reopened_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reopen_reason` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------

CREATE TABLE `ticket_resolved` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `resolved_by` int(11) NOT NULL,
  `closed_by` int(11) NOT NULL,
  `rating` tinyint(4) NOT NULL,
  `comment` text DEFAULT NULL,
  `resolved_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`),
  KEY `resolved_by` (`resolved_by`),
  KEY `closed_by` (`closed_by`),
  CONSTRAINT `ticket_resolved_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_resolved_ibfk_2` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_resolved_ibfk_3` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------

CREATE TABLE `ticket_status_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) NOT NULL,
  `changed_by` varchar(100) NOT NULL,
  `change_reason` text DEFAULT NULL,
  `change_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ticket_id` (`ticket_id`),
  KEY `idx_change_date` (`change_date`),
  CONSTRAINT `ticket_status_logs_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------

CREATE TABLE `escalations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `user` varchar(100) NOT NULL,
  `reason` text NOT NULL,
  `escalation_type` enum('reopen','escalate') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------

CREATE TABLE `feedback_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `ticket_id` (`ticket_id`),
  CONSTRAINT `feedback_tokens_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recipient` varchar(100) NOT NULL,
  `message` text NOT NULL,
  `query_id` int(11) NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------

CREATE TABLE `request_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) DEFAULT NULL,
  `action_by` int(11) DEFAULT NULL,
  `action` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------

CREATE TABLE `return_escalations` (
  `escalation_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `escalated_at` datetime NOT NULL,
  `resolved` tinyint(1) DEFAULT 0,
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`escalation_id`),
  KEY `request_id` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------

CREATE TABLE `outlets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `url` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `logo` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `outlets` (`name`, `description`, `url`, `is_active`, `logo`) VALUES
('Nandos',          'Portuguese flame-grilled peri-peri chicken.',  'https://www.nandos.co.za/eat/order/menu',               1, 'nandos_logo.jpg'),
('KFC',             'Deep fried chicken.',                           'https://thumo.app/browse-stores/enterprise/5',          1, 'kfc-2006.jpg'),
('Jazz Friends',    'Homey meals for breakfast and lunch.',          NULL,                                                    1, 'JAZZ_FRIENDS.png');

-- ------------------------------------------------------------

CREATE TABLE `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `outlet_id` int(11) NOT NULL,
  `order_date` date NOT NULL,
  `order_items` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `overfee_amount` decimal(10,2) DEFAULT 0.00,
  `order_type` varchar(100) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_outlet_id` (`outlet_id`),
  KEY `idx_order_date` (`order_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ============================================================
-- DATABASE: vehicle_requisition
-- ============================================================

CREATE DATABASE IF NOT EXISTS `vehicle_requisition`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `vehicle_requisition`;

-- ------------------------------------------------------------

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `password_reset_required` tinyint(1) DEFAULT 0,
  `department` varchar(100) DEFAULT NULL,
  `role` enum('user','driver','supervisor','hrm','admin','viewer') DEFAULT 'user',
  `role_expiry_date` date DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------

CREATE TABLE `vehicles` (
  `vehicle_id` int(11) NOT NULL AUTO_INCREMENT,
  `registration` varchar(20) NOT NULL,
  `make` varchar(50) DEFAULT NULL,
  `model` varchar(50) DEFAULT NULL,
  `status` enum('available','allocated','maintenance') DEFAULT 'available',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`vehicle_id`),
  UNIQUE KEY `registration` (`registration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------

CREATE TABLE `vehicle_requests` (
  `request_id` int(11) NOT NULL AUTO_INCREMENT,
  `requester_id` int(11) NOT NULL,
  `department` varchar(100) DEFAULT NULL,
  `purpose` text NOT NULL,
  `destination` varchar(255) NOT NULL,
  `passengers` varchar(255) DEFAULT NULL,
  `date_requested` date DEFAULT curdate(),
  `date_required` date NOT NULL,
  `vehicle_id` int(11) DEFAULT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `supervisor_id` int(11) DEFAULT NULL,
  `hrm_id` int(11) DEFAULT NULL,
  `status` enum('pending_driver','pending_supervisor','pending_hrm','approved','rejected','closed') DEFAULT 'pending_driver',
  `rejection_reason` text DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `time_in` time DEFAULT NULL,
  `mileage_out` int(11) DEFAULT NULL,
  `mileage_in` int(11) DEFAULT NULL,
  `return_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `time_required` time NOT NULL,
  `expected_return_date` date DEFAULT NULL,
  `actual_return_date` datetime DEFAULT NULL,
  `selected_supervisor` int(11) DEFAULT NULL,
  PRIMARY KEY (`request_id`),
  KEY `requester_id` (`requester_id`),
  KEY `vehicle_id` (`vehicle_id`),
  CONSTRAINT `vehicle_requests_ibfk_1` FOREIGN KEY (`requester_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `vehicle_requests_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------

CREATE TABLE `request_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) DEFAULT NULL,
  `action_by` int(11) DEFAULT NULL,
  `action` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `request_id` (`request_id`),
  KEY `action_by` (`action_by`),
  CONSTRAINT `request_logs_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `vehicle_requests` (`request_id`),
  CONSTRAINT `request_logs_ibfk_2` FOREIGN KEY (`action_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------

CREATE TABLE `return_escalations` (
  `escalation_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `escalated_at` datetime NOT NULL,
  `resolved` tinyint(1) DEFAULT 0,
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`escalation_id`),
  KEY `request_id` (`request_id`),
  CONSTRAINT `return_escalations_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `vehicle_requests` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
