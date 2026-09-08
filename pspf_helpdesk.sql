-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 10, 2025 at 02:53 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `pspf_helpdesk`
--

-- --------------------------------------------------------

--
-- Table structure for table `escalations`
--

CREATE TABLE `escalations` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `user` varchar(100) NOT NULL,
  `reason` text NOT NULL,
  `escalation_type` enum('reopen','escalate') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `escalations`
--

INSERT INTO `escalations` (`id`, `ticket_id`, `user`, `reason`, `escalation_type`, `created_at`) VALUES
(1, 20, 'IT1', 'kuyala', 'reopen', '2025-06-24 12:30:57'),
(2, 19, 'IT1', 'hjkk', 'reopen', '2025-06-26 09:36:53'),
(3, 18, 'IT1', 'dfh', 'reopen', '2025-06-26 10:15:02'),
(4, 17, '2', 'hello', 'reopen', '2025-06-26 10:28:18'),
(5, 16, 'IT1', 'xdhhd', 'reopen', '2025-06-26 10:34:05'),
(6, 15, '2', 'hard', 'reopen', '2025-06-26 13:34:01'),
(7, 15, '2', 'hh', 'reopen', '2025-06-26 13:34:34'),
(8, 22, 'IT1', 'issue', 'reopen', '2025-06-27 10:26:04'),
(9, 22, 'IT1', 'cvvv', 'reopen', '2025-06-27 10:30:04'),
(10, 20, 'IT1', 'ggh', 'reopen', '2025-06-27 10:33:35'),
(11, 19, 'IT1', 'fbb', 'reopen', '2025-06-27 10:34:40'),
(12, 19, 'IT1', 'ghh', 'reopen', '2025-06-27 10:41:21'),
(13, 23, 'IT1', 'incomplete', 'reopen', '2025-07-07 07:40:43'),
(14, 23, 'IT1', 'problem', 'reopen', '2025-07-07 07:59:13'),
(15, 23, 'IT1', 'fixing', 'reopen', '2025-07-07 08:08:27');

-- --------------------------------------------------------

--
-- Table structure for table `escalation_reads`
--

CREATE TABLE `escalation_reads` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `read_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications_seen`
--

CREATE TABLE `notifications_seen` (
  `id` int(11) NOT NULL,
  `escalation_id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `seen_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `query_closures`
--

CREATE TABLE `query_closures` (
  `ticket_id` int(11) NOT NULL,
  `closed_by` varchar(150) NOT NULL,
  `closed_at` datetime DEFAULT current_timestamp(),
  `reopened_by` varchar(150) DEFAULT NULL,
  `reopened_at` datetime DEFAULT NULL,
  `escalated_by` varchar(150) DEFAULT NULL,
  `escalated_at` datetime DEFAULT NULL,
  `escalation_reason` text DEFAULT NULL,
  `reopen_reason` text DEFAULT NULL,
  `final_status` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `query_closures`
--

INSERT INTO `query_closures` (`ticket_id`, `closed_by`, `closed_at`, `reopened_by`, `reopened_at`, `escalated_by`, `escalated_at`, `escalation_reason`, `reopen_reason`, `final_status`) VALUES
(8, 'administrator', '2025-06-18 16:25:41', NULL, NULL, NULL, NULL, NULL, NULL, 'Closed'),
(9, 'administrator', '2025-06-19 09:35:30', NULL, NULL, NULL, NULL, NULL, NULL, 'Closed'),
(10, 'administrator', '2025-06-19 11:44:12', NULL, NULL, NULL, NULL, NULL, NULL, 'Closed'),
(11, 'administrator', '2025-06-19 11:42:50', NULL, NULL, NULL, NULL, NULL, NULL, 'Closed'),
(13, 'administrator', '2025-06-24 09:40:59', NULL, NULL, NULL, NULL, NULL, NULL, 'Closed'),
(19, '', '2025-06-27 12:34:40', NULL, NULL, 'IT1', '2025-06-27 12:34:40', 'fbb', NULL, 'open'),
(20, '', '2025-06-27 12:33:35', NULL, NULL, NULL, NULL, NULL, NULL, 'open'),
(22, '', '2025-06-27 12:26:04', NULL, NULL, 'IT1', '2025-06-27 12:30:04', 'cvvv', NULL, 'Escalated'),
(23, '', '2025-07-07 09:40:43', NULL, NULL, 'IT1', '2025-07-07 09:59:13', 'problem', NULL, 'open'),
(101, 'jdoe', '2025-06-26 16:22:13', NULL, NULL, NULL, NULL, NULL, NULL, 'Closed');

-- --------------------------------------------------------

--
-- Table structure for table `tickets`
--

CREATE TABLE `tickets` (
  `id` int(11) NOT NULL,
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
  `assigned_to` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tickets`
--

INSERT INTO `tickets` (`id`, `title`, `member_type`, `region`, `source`, `query_type`, `description`, `priority`, `phone_number`, `query_date`, `created_by`, `status`, `attachment_path`, `assigned_to`) VALUES
(1, 'help', 'Active', 'Manzini', 'Phone', 'IT help desk', 'edff', 'Low', '12345678', '2025-06-18 11:27:15', 'a', 'Open', NULL, NULL),
(2, 'help', 'Active', 'Hhohho', 'Phone', 'IT help desk', 'gdj', 'Medium', '123456789', '2025-06-18 11:32:44', 'a', 'Open', NULL, NULL),
(3, 'nn', '', 'Hhohho', 'E-mail', 'Funeral Benefits', 'asfg', 'Low', '123456789', '2025-06-18 12:39:45', 'a', 'Open', NULL, NULL),
(4, '122', '', 'Manzini', 'Phone', 'IT help desk', 'rgfb', 'High', '12345678', '2025-06-18 12:48:36', 'a', 'Open', NULL, NULL),
(5, 'f', 'Active', 'Manzini', 'Phone', 'Other', 'fjgk', 'Medium', '12344567', '2025-06-18 12:56:48', 'a', 'Open', NULL, NULL),
(6, 'f', 'Spouse', 'Manzini', 'Phone', 'Other', 'ghjg', 'Low', '1234567', '2025-06-18 13:00:08', 'a', 'Open', NULL, NULL),
(7, 'hello', 'Dependent', 'Hhohho', 'Walk-in', 'IT help desk', 'sdh', 'High', '12345678', '2025-06-18 14:16:29', 'a', 'Closed', NULL, NULL),
(8, 'test', 'Annuitant', 'Hhohho', 'Phone', 'IT help desk', 'dffgg', 'Medium', '12345677', '2025-06-18 14:50:08', 'a', 'Open', NULL, NULL),
(9, 'test', 'Employee', 'Hhohho', 'E-mail', 'IT help desk', 'efhj', 'High', '123456', '2025-06-18 14:53:18', 'a', 'In Progress', NULL, NULL),
(10, 'printer', 'Active', 'Hhohho', 'Social Media', 'IT help desk', 'my printer is not working', 'Low', '12345679', '2025-06-19 09:51:31', 'a', 'Open', NULL, NULL),
(11, 'network', 'Employee', 'Hhohho', 'E-mail', 'IT help desk', 'frgtootjojojyoj', 'Medium', '12345678', '2025-06-19 10:06:42', 'john', 'Closed', NULL, NULL),
(12, 'Biometric Kit', 'Employee', 'Manzini', 'Phone', 'IT help desk', 'Kindly assist me with the Laptop', 'High', '76177818', '2025-06-19 12:56:06', 'a', 'Closed', NULL, NULL),
(13, 'adobe expiration', 'Employee', 'Hhohho', 'PSPF Staff', 'IT help desk', 'opened Adobe and indicated that it has expired.', 'High', '76543210', '2025-06-24 09:04:13', 't', 'Closed', NULL, NULL),
(14, 'help', 'Active', 'Hhohho', 'Walk-in', 'Tax/IRP5', 'scdvv', 'Low', '', '2025-06-24 11:10:11', 'a', 'Closed', '1750756211_Screenshot (2).png', NULL),
(15, 'help', 'Active', 'Hhohho', 'Walk-in', 'Tax/IRP5', 'scdvv', 'Low', '', '2025-06-24 11:10:18', 'a', 'Closed', '1750756218_Screenshot (2).png', NULL),
(16, 'help', 'Active', 'Hhohho', 'Walk-in', 'Tax/IRP5', 'scdvv', 'Low', '', '2025-06-24 11:11:33', 'a', 'Closed', '1750756293_Screenshot (2).png', NULL),
(17, 'help', 'Active', 'Hhohho', 'Walk-in', 'Tax/IRP5', 'scdvv', 'Low', '', '2025-06-24 11:12:31', 'a', 'Closed', '1750756351_Screenshot (2).png', NULL),
(18, 'help', 'Active', 'Hhohho', 'Walk-in', 'Tax/IRP5', 'scdvv', 'Low', '', '2025-06-24 11:19:57', 'a', 'Closed', '1750756797_Screenshot (2).png', NULL),
(19, 'help', 'Active', 'Hhohho', 'Walk-in', 'Tax/IRP5', 'scdvv', 'Low', '', '2025-06-24 11:20:03', 'a', 'Closed', '1750756803_Screenshot (2).png', 'IT1'),
(20, 'help', 'Active', 'Hhohho', 'Walk-in', 'Tax/IRP5', 'scdvv', 'Low', '', '2025-06-24 11:20:26', 'a', 'Closed', '1750756826_Screenshot (2).png', 'Ncamiso'),
(21, 'no', 'Annuitant', 'Manzini', 'Phone', 'IT help desk', 'jhgfd', 'Low', '12345678', '2025-06-26 15:59:27', 'a', 'Closed', '', NULL),
(22, 'please work', 'Employee', 'Shiselweni', 'Walk-in', 'IT help desk', 'j', 'Medium', '', '2025-06-27 11:59:13', 'a', 'Closed', '', 'IT1'),
(23, 'gffds', 'Annuitant', 'Manzini', 'E-mail', 'Tax/IRP5', 'kjndk', 'Medium', '', '2025-07-07 09:26:30', 'a', 'Closed', '1751873190_Meter_Count.pdf', 'it2');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_success`
--

CREATE TABLE `ticket_success` (
  `ticket_id` int(11) NOT NULL,
  `status` varchar(50) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `query_date` datetime DEFAULT NULL,
  `logged_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ticket_success`
--

INSERT INTO `ticket_success` (`ticket_id`, `status`, `department`, `created_by`, `query_date`, `logged_at`) VALUES
(8, 'Closed', NULL, 'a', '2025-06-18 14:50:08', '2025-06-18 12:50:08'),
(9, 'Closed', 'Legal', 'a', '2025-06-18 14:53:18', '2025-06-18 12:53:18'),
(10, 'Closed', 'Legal', 'a', '2025-06-19 09:51:31', '2025-06-19 07:51:31'),
(11, 'In Progress', 'Finance', 'john', '2025-06-19 10:06:42', '2025-06-19 08:06:42'),
(12, 'Open', 'Legal', 'a', '2025-06-19 12:56:06', '2025-06-19 10:56:06'),
(13, 'Closed', 'Legal', 't', '2025-06-24 09:04:13', '2025-06-24 07:04:13'),
(18, 'Open', 'Legal', 'a', '2025-06-24 11:19:57', '2025-06-24 09:19:57'),
(19, 'Open', 'Legal', 'a', '2025-06-24 11:20:03', '2025-06-24 09:20:03'),
(20, 'Open', 'Legal', 'a', '2025-06-24 11:20:26', '2025-06-24 09:20:26'),
(21, 'Open', 'Legal', 'a', '2025-06-26 15:59:27', '2025-06-26 13:59:27'),
(22, 'Open', 'Legal', 'a', '2025-06-27 11:59:13', '2025-06-27 09:59:13'),
(23, 'Open', 'Legal', 'a', '2025-07-07 09:26:30', '2025-07-07 07:26:30'),
(101, NULL, NULL, NULL, NULL, '2025-06-26 14:15:44');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `department` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `role` enum('user','it','superadmin') DEFAULT 'user'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `department`, `password`, `created_at`, `role`) VALUES
(1, 'zuma', 'zuma@gmail.com', 'Operations', '$2y$10$P0aYpFNnB6NwkUXzY/KMg.FNPap5sVVK6QVptGldlTnddTAaKmV1u', '2025-06-17 10:44:27', 'user'),
(2, 'mine', 'mine@gmail.com', 'Operations', '$2y$10$x0Ek116G4kaBWUqPK71slO/MjmYwy6r0yLQsaA4Xx4KR8zFPYOQWK', '2025-06-17 10:49:51', 'user'),
(3, '1', '1@gmail.com', 'Legal', '$2y$10$6LyPJtdL.Kw8I9mYgkO0..lh3QRQC6ogoi3KBTOszd6FE9Yy7fCf.', '2025-06-17 10:58:10', 'user'),
(4, 'admin', 'admin@gmail.com', 'Operations', '$2y$10$AwNDEO9pWn1clYDgnXB/PudDO0hrbyV1zbPeBH9Rdzgj/fxsVf14.', '2025-06-18 08:25:13', 'user'),
(5, 'admin1', 'admin1@gmail.com', 'Operations', '$2y$10$cjU2fZZsXetxnEnsQWYSZ.NMpnvWPL.NDFZfqKBWCqO7YkEbUlsfa', '2025-06-18 08:31:54', 'user'),
(6, 'admin2', 'admin2@gmail.com', 'Operations', '$2y$10$2d3Rnfbew6xAFi4RnU2o..IIWUn1LwDh5tAPq.34iWziWwpfGpbt6', '2025-06-18 08:34:18', 'user'),
(7, 'admin3', 'admin3@gmail.com', 'Operations', '$2y$10$bGegZMcdoHwNpJcOJYhws.E1hIqxoCA4Tbc5e.ekxhLQupNn0I0pO', '2025-06-18 08:36:19', 'user'),
(8, 'a', 'a@gmail.com', 'Legal', '$2y$10$898FGI0picEurNZ33y7mkugMnG5aZdpGuMXIwbgjl0VfoPOGw69am', '2025-06-18 08:42:04', 'user'),
(9, 'administrator', 'Ad@gmail.com', 'IT', '$2y$10$POYkSxY35Ymz/kLqo2aAsuGajsQXo6VvxP821AhhNHnCLMiWajxcC', '2025-06-18 12:17:23', 'superadmin'),
(10, 'john', 'john@gmail.com', 'Finance', '$2y$10$Nx5FTpbtwXwBJMbWXqda/uf2662AGMAOcXiYTGrHEYqwNn2Yomi2S', '2025-06-19 08:05:32', 'user'),
(11, 'b', 'b@gmail.com', 'Marketing', '$2y$10$SZUL/hM/Zq2OdSKHjUWtseUU3yfn3YiR1wQXDq/tRbLqroG0Hcbiy', '2025-06-19 08:08:07', 'user'),
(12, 'c', 'c@gmail.com', 'Corporate Services', '$2y$10$1A79uDjZ3GO33BD.hCjD8OqWL5MX1PpynpdjYeJcUwr7E1uxbyEpO', '2025-06-19 08:15:09', 'user'),
(13, 'd', 'd@gmail.cm', 'Corporate Services', '$2y$10$4omhBLAoDKFvcpcVtiurGuUHKSETucVElt42.842Ro7zwskb.A0bG', '2025-06-19 08:19:41', 'user'),
(14, 'Ncamiso', 'Ncamiso@pspf.co.sz', 'IT', '$2y$10$cnnzc6011qTdFZTDa000I.h1eTlO/YpmksXgOzyiU.X.1Pr5Jmu06', '2025-06-19 10:52:12', 'it'),
(15, 'Senhle', '14@gmail.com', 'Operations', '$2y$10$gHLBV3xCkSqiL1wlxo2nh.7TQKSe8kEu0O.fX51q9jl4H30F4/KvO', '2025-06-20 07:54:19', 'user'),
(16, 't', 't@gmail.com', 'Legal', '$2y$10$5/T2.3M2Ah/2uOgqxsnTr.Yp4lgrSMizPTfYEhgb09lR.bb0FDJCe', '2025-06-24 06:57:29', 'user'),
(17, 'IT1', 'IT1@gmail.com', 'IT', '$2y$10$HQ46DLQCp4d/395i0iE.zuMlLRDNJ2gD8adHqm0c53oQxejDYh30q', '2025-06-24 10:21:07', 'it'),
(19, '2', '2@fmail.com', 'IT', '$2y$10$VM5n5muBFBbc/Sa9/MifruET3ZvSzdPBnWcjO7V62MhOG9QyTZFYq', '2025-06-26 10:23:40', 'it'),
(20, 'it2', 'it2@gmail.com', 'IT', '$2y$10$IBI1zVIUQeiFZk88edgO.unEaG4ajpQdUmB/YM.t.C4D/tu9tAGGC', '2025-07-08 07:01:07', 'it');

--
-- Triggers `users`
--
DELIMITER $$
CREATE TRIGGER `trg_set_role_before_insert` BEFORE INSERT ON `users` FOR EACH ROW BEGIN
    IF LOWER(NEW.department) = 'it' THEN
        SET NEW.role = 'it';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_set_role_before_update` BEFORE UPDATE ON `users` FOR EACH ROW BEGIN
    IF LOWER(NEW.department) = 'it' THEN
        SET NEW.role = 'it';
    END IF;
END
$$
DELIMITER ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `escalations`
--
ALTER TABLE `escalations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_id` (`ticket_id`);

--
-- Indexes for table `escalation_reads`
--
ALTER TABLE `escalation_reads`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ticket_id` (`ticket_id`,`username`);

--
-- Indexes for table `notifications_seen`
--
ALTER TABLE `notifications_seen`
  ADD PRIMARY KEY (`id`),
  ADD KEY `escalation_id` (`escalation_id`);

--
-- Indexes for table `query_closures`
--
ALTER TABLE `query_closures`
  ADD PRIMARY KEY (`ticket_id`);

--
-- Indexes for table `tickets`
--
ALTER TABLE `tickets`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ticket_success`
--
ALTER TABLE `ticket_success`
  ADD PRIMARY KEY (`ticket_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `escalations`
--
ALTER TABLE `escalations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `escalation_reads`
--
ALTER TABLE `escalation_reads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications_seen`
--
ALTER TABLE `notifications_seen`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tickets`
--
ALTER TABLE `tickets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `escalations`
--
ALTER TABLE `escalations`
  ADD CONSTRAINT `escalations_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`);

--
-- Constraints for table `notifications_seen`
--
ALTER TABLE `notifications_seen`
  ADD CONSTRAINT `notifications_seen_ibfk_1` FOREIGN KEY (`escalation_id`) REFERENCES `escalations` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
