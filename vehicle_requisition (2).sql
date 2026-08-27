-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 23, 2026 at 07:53 AM
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
-- Database: `vehicle_requisition`
--

-- --------------------------------------------------------

--
-- Table structure for table `request_logs`
--

CREATE TABLE `request_logs` (
  `log_id` int(11) NOT NULL,
  `request_id` int(11) DEFAULT NULL,
  `action_by` int(11) DEFAULT NULL,
  `action` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `request_logs`
--



-- --------------------------------------------------------

--
-- Table structure for table `return_escalations`
--

CREATE TABLE `return_escalations` (
  `escalation_id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `escalated_at` datetime NOT NULL,
  `resolved` tinyint(1) DEFAULT 0,
  `resolved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `password_reset_required` tinyint(1) DEFAULT 0,
  `department` varchar(100) DEFAULT NULL,
  `role` enum('user','driver','supervisor','hrm','admin','viewer') DEFAULT 'user',
  `role_expiry_date` date DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `name`, `email`, `password`, `password_reset_required`, `department`, `role`, `role_expiry_date`, `active`, `created_at`) VALUES
(1, 'admin', 'admin@example.com', '$2y$10$yPrI6fXlQ3gO6CX2kXiJEuW8zRnaSe5qgD2lEsbFscptXmWfaxZIy', 0, 'IT', 'admin', NULL, 1, '2025-11-11 10:49:57'),
(2, 'simphiwe', 'simphiwes@pspf.co.sz', '$2y$10$75nQcvCJ65O9j9FjV5PIwOiZxAKA4.Q9dHenoJs/uUfBY8pqy18wy', 0, 'it', 'driver', NULL, 1, '2025-11-11 12:21:06'),
(3, 'nkosenhle', 'nkosenhlep@pspf.co.sz', '$2y$10$CmZNUNGiwcSUKIBjtuVFEuHgumJjX4TUejg0dmK.fHFDaWsLAncJi', 0, 'IT', 'user', NULL, 1, '2025-11-12 13:35:20'),
(4, 'ncamiso', 'ncamiso@pspf.co.sz', '$2y$10$oqu8ySw058A3Fu0fsUWdX.gTUgy87vtGLqq5dV5J3dtBwiqNkxrSm', 0, 'IT', 'supervisor', NULL, 1, '2025-11-12 13:40:42'),
(5, 'bavukile', 'bavukile@pspf.co.sz', '$2y$10$JoCERbO1GGWfkq4MJgkmKuqRVSMIEidGgbVbuD0xkUMHiK7.Pg/Ba', 0, 'IT', 'viewer', NULL, 1, '2025-11-17 12:25:50');

-- --------------------------------------------------------

--
-- Table structure for table `vehicles`
--

CREATE TABLE `vehicles` (
  `vehicle_id` int(11) NOT NULL,
  `registration` varchar(20) NOT NULL,
  `make` varchar(50) DEFAULT NULL,
  `model` varchar(50) DEFAULT NULL,
  `status` enum('available','allocated','maintenance') DEFAULT 'available',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicles`
--

INSERT INTO `vehicles` (`vehicle_id`, `registration`, `make`, `model`, `status`, `updated_at`) VALUES
(1, 'DSD 926 DH', 'Toyota', 'Corolla', 'available', '2025-12-16 10:53:32'),
(2, 'FSD 402 DH', 'Toyota', 'Fortuner', 'available', '2026-01-27 08:03:29'),
(3, 'QSD 141 DH', 'Toyota', 'Double Cab', 'available', '2026-01-27 08:02:09');

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_requests`
--

CREATE TABLE `vehicle_requests` (
  `request_id` int(11) NOT NULL,
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
  `selected_supervisor` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicle_requests`
--

INSERT INTO `vehicle_requests` (`request_id`, `requester_id`, `department`, `purpose`, `destination`, `passengers`, `date_requested`, `date_required`, `vehicle_id`, `driver_id`, `supervisor_id`, `hrm_id`, `status`, `rejection_reason`, `time_out`, `time_in`, `mileage_out`, `mileage_in`, `return_date`, `created_at`, `updated_at`, `time_required`, `expected_return_date`, `actual_return_date`, `selected_supervisor`) VALUES

--

--
-- Indexes for table `request_logs`
--
ALTER TABLE `request_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `request_id` (`request_id`),
  ADD KEY `action_by` (`action_by`);

--
-- Indexes for table `return_escalations`
--
ALTER TABLE `return_escalations`
  ADD PRIMARY KEY (`escalation_id`),
  ADD KEY `request_id` (`request_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`vehicle_id`),
  ADD UNIQUE KEY `registration` (`registration`);

--
-- Indexes for table `vehicle_requests`
--
ALTER TABLE `vehicle_requests`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `requester_id` (`requester_id`),
  ADD KEY `vehicle_id` (`vehicle_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `request_logs`
--
ALTER TABLE `request_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=58;

--
-- AUTO_INCREMENT for table `return_escalations`
--
ALTER TABLE `return_escalations`
  MODIFY `escalation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `vehicle_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `vehicle_requests`
--
ALTER TABLE `vehicle_requests`
  MODIFY `request_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `request_logs`
--
ALTER TABLE `request_logs`
  ADD CONSTRAINT `request_logs_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `vehicle_requests` (`request_id`),
  ADD CONSTRAINT `request_logs_ibfk_2` FOREIGN KEY (`action_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `return_escalations`
--
ALTER TABLE `return_escalations`
  ADD CONSTRAINT `return_escalations_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `vehicle_requests` (`request_id`);

--
-- Constraints for table `vehicle_requests`
--
ALTER TABLE `vehicle_requests`
  ADD CONSTRAINT `vehicle_requests_ibfk_1` FOREIGN KEY (`requester_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `vehicle_requests_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
