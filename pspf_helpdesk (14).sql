-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 20, 2026 at 02:20 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

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
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `department_name` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `department_name`) VALUES
(1, 'CEO Office'),
(2, 'Corporate Services'),
(3, 'Finance'),
(4, 'IAR'),
(5, 'ICT'),
(6, 'Investments'),
(7, 'Operations');

-- --------------------------------------------------------

--
-- Table structure for table `divisions`
--

CREATE TABLE `divisions` (
  `id` int(11) NOT NULL,
  `department_id` int(11) NOT NULL,
  `division_name` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `divisions`
--

INSERT INTO `divisions` (`id`, `department_id`, `division_name`) VALUES
(1, 1, 'PA to Executive'),
(2, 1, 'Company Secretary'),
(3, 2, 'Human Resources'),
(4, 2, 'Marketing'),
(5, 2, 'Facilities'),
(6, 3, 'Accounting'),
(7, 3, 'Investment Monitoring'),
(8, 3, 'Procurement'),
(9, 4, 'Auditing'),
(10, 5, 'IT Support'),
(11, 6, 'Investment'),
(12, 7, 'Benefits'),
(13, 7, 'Legal');

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
(1, 3, 'Nkosenhlep', 'Testing the workflow of all emails', 'reopen', '2026-01-19 09:21:11'),
(2, 5, 'Nkosenhlep', 'Testing escaltion', 'reopen', '2026-01-19 13:44:15'),
(3, 13, 'Nkosenhlep', 'testing escalation', 'reopen', '2026-01-19 13:47:09'),
(4, 21, 'Intercesor', 'the reason modal is not working for closing a ticket', 'reopen', '2026-01-22 12:12:51'),
(5, 44, 'simphiwe', 'Open', 'reopen', '2026-01-30 13:44:00'),
(6, 45, 'Intercesor', 'Please action', 'reopen', '2026-01-30 13:44:50'),
(7, 44, 'Ncamiso', 'failing to complete this task. I need help.', 'reopen', '2026-01-30 13:45:26'),
(8, 43, 'Simphiwes', 'dhdjhf', 'reopen', '2026-02-04 08:39:49');

-- --------------------------------------------------------

--
-- Table structure for table `feedback_tokens`
--

CREATE TABLE `feedback_tokens` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `feedback_tokens`
--

INSERT INTO `feedback_tokens` (`id`, `ticket_id`, `token`, `expires_at`, `used`, `created_at`) VALUES
(1, 39, 'e3ef135e5fb5d297fa9cb0b3cc1c61188aa5c953d1129a43b9ca6ed4ac2f1a1c', '2026-02-26 13:59:41', 1, '2026-02-19 14:59:41'),
(2, 35, '46c0c9dd45774c51cd2dab02361e123fc4e5c0f9a689e0bd7a673cd27bbce36e', '2026-02-26 14:52:34', 0, '2026-02-19 15:52:34'),
(3, 32, '9e8c74a369f7d5aa6f1fb4b567333296f676e346b9b0d8f4384f671ab7478715', '2026-02-26 15:19:47', 1, '2026-02-19 16:19:47'),
(4, 37, '5d7f005ff29211eeb5c4a677e70f8bef0d43c1749c4a0a97c483e031388ea4c1', '2026-02-27 08:34:55', 1, '2026-02-20 09:34:55');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `recipient` varchar(100) NOT NULL,
  `message` text NOT NULL,
  `query_id` int(11) NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `recipient`, `message`, `query_id`, `is_read`, `created_at`) VALUES
(1, 'simphiwe', 'New IT help desk ticket submitted by Nkosenhlep: \"software issues testing\"', 0, 0, '2025-11-20 15:51:09');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `outlet_id` int(11) NOT NULL,
  `order_date` date NOT NULL,
  `order_items` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `overfee_amount` decimal(10,2) DEFAULT 0.00,
  `order_type` varchar(100) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `user_id`, `outlet_id`, `order_date`, `order_items`, `total_amount`, `overfee_amount`, `order_type`, `notes`, `created_at`, `updated_at`) VALUES
(5, 1, 3, '2026-01-13', '[{\"description\":\"Oxtail curry with dumplings\",\"price\":70,\"deliveryFee\":0,\"total\":70}]', 70.00, 0.00, 'food_order', '', '2026-01-13 10:43:34', '2026-01-13 10:43:34'),
(6, 1, 0, '2026-01-13', '[{\"description\":\"Cash request for food ordering\",\"price\":70,\"deliveryFee\":0,\"total\":70}]', 70.00, 0.00, 'cash_request', '', '2026-01-13 10:51:51', '2026-01-13 10:51:51'),
(7, 1, 3, '2026-01-13', '[{\"description\":\"chicken wrap\",\"price\":20,\"deliveryFee\":0,\"total\":20},{\"description\":\"chicken livers\",\"price\":15,\"deliveryFee\":0,\"total\":15}]', 35.00, 0.00, 'food_order', '', '2026-01-13 14:18:19', '2026-01-13 14:18:19'),
(8, 1, 3, '2026-01-19', '', 20.00, 0.00, 'food_order', '', '2026-01-19 13:15:57', '2026-01-19 13:15:57'),
(9, 7, 0, '2026-01-19', '', 40.00, 0.00, 'cash_request', '', '2026-01-19 13:16:20', '2026-01-19 13:16:20'),
(10, 8, 1, '2026-01-19', '', 60.02, 0.00, 'food_order', '', '2026-01-19 13:21:30', '2026-01-19 13:21:30'),
(11, 8, 3, '2026-01-19', '', 10.00, 0.00, 'food_order', '', '2026-01-19 13:21:30', '2026-01-19 13:21:30'),
(12, 7, 2, '2026-01-19', '', 89.98, 19.98, 'food_order', '', '2026-01-19 13:24:56', '2026-01-19 13:24:56'),
(13, 8, 0, '2026-01-19', '', 82.00, 12.00, 'cash_request', '', '2026-01-19 13:26:31', '2026-01-19 13:26:31'),
(14, 8, 1, '2026-01-19', '', 120.00, 50.00, 'food_order', '', '2026-01-19 13:30:13', '2026-01-19 13:30:13'),
(15, 1, 3, '2026-01-19', '', 0.00, 0.00, 'food_order', '', '2026-01-19 14:11:12', '2026-01-19 14:11:12'),
(16, 1, 3, '2026-01-19', '', 35.00, 0.00, 'food_order', '', '2026-01-19 14:15:37', '2026-01-19 14:15:37'),
(17, 1, 2, '2026-01-19', 'krusher kitkat @ 31.99', 31.99, 0.00, 'food_order', '', '2026-01-19 14:21:23', '2026-01-19 14:21:23'),
(18, 1, 3, '2026-01-19', 'chicken wrap @ 20.00, \nchicken livers @ 15.00, \nsamp @ 10.00', 45.00, 0.00, 'food_order', '', '2026-01-19 14:24:38', '2026-01-19 14:24:38'),
(19, 10, 1, '2026-01-21', 'Quarter Leg Barbeque with Chips @ 70.00', 70.00, 0.00, 'food_order', '', '2026-01-21 09:56:05', '2026-01-21 09:56:05'),
(20, 1, 0, '2026-01-21', 'cash @ 70.00', 70.00, 0.00, 'food_order', '', '2026-01-21 10:11:57', '2026-01-21 10:11:57'),
(21, 1, 2, '2026-01-28', 'streetwise 2 @ 43.90', 43.90, 0.00, 'food_order', '', '2026-01-28 09:03:43', '2026-01-28 09:03:43'),
(22, 1, 3, '2026-01-28', 'chicken wrap @ 20.00,chicken livers @ 15.00', 35.00, 0.00, 'food_order', '', '2026-01-28 12:33:51', '2026-01-28 12:33:51'),
(23, 1, 0, '2026-01-28', 'Cash Request @ 35.00', 35.00, 0.00, 'cash_request', '', '2026-01-28 12:33:51', '2026-01-28 12:33:51'),
(24, 1, 0, '2026-01-28', 'Cash Request @ 70.00', 70.00, 0.00, 'cash_request', '', '2026-01-28 12:58:35', '2026-01-28 12:58:35'),
(25, 1, 3, '2026-01-30', 'Goat meat with dumplings @ 65.00,chicken livers @ 15.00', 80.00, 10.00, 'food_order', '', '2026-01-30 14:12:18', '2026-01-30 14:12:18'),
(26, 4, 2, '2026-01-30', 'All star meal @ 90.00', 90.00, 20.00, 'food_order', '', '2026-01-30 14:14:49', '2026-01-30 14:14:49'),
(27, 1, 0, '2026-01-30', 'Cash Request @ 70.00', 70.00, 0.00, 'cash_request', '', '2026-01-30 14:15:43', '2026-01-30 14:15:43'),
(28, 7, 0, '2026-01-30', 'Cash Request @ 70.00', 70.00, 0.00, 'cash_request', '', '2026-01-30 14:16:01', '2026-01-30 14:16:01'),
(29, 8, 0, '2026-01-30', '10 Regular wings @ 1.00', 1.00, 0.00, 'food_order', '', '2026-01-30 14:16:22', '2026-01-30 14:16:22'),
(30, 5, 2, '2026-01-30', 'Two piece @ 41.90', 41.90, 0.00, 'food_order', '', '2026-01-30 14:17:05', '2026-01-30 14:17:05'),
(31, 8, 0, '2026-01-30', '10 regular wings @ 85.00', 85.00, 15.00, 'food_order', '', '2026-01-30 14:17:24', '2026-01-30 14:17:24'),
(32, 11, 1, '2026-02-04', 'Chicken Salad @ 90.00', 90.00, 20.00, 'food_order', '', '2026-02-04 12:53:15', '2026-02-04 12:53:15'),
(33, 11, 2, '2026-02-04', '4 piece dunked wings @ 30.00', 30.00, 0.00, 'food_order', '', '2026-02-04 12:59:02', '2026-02-04 12:59:02'),
(34, 11, 3, '2026-02-04', 'Oxtail and Rice @ 70.00', 70.00, 0.00, 'food_order', '', '2026-02-04 12:59:02', '2026-02-04 12:59:02'),
(35, 11, 0, '2026-02-04', 'Cash Request @ 70.00', 70.00, 0.00, 'cash_request', '', '2026-02-04 13:19:50', '2026-02-04 13:19:50'),
(36, 11, 1, '2026-02-04', 'Chicken Wrap (Hot) @ 85.00', 85.00, 15.00, 'food_order', 'No cucumber', '2026-02-04 13:29:12', '2026-02-04 13:29:12'),
(37, 1, 3, '2026-02-10', 'Chicken wrap @ 20.00,chicken liver @ 15.00,Rice @ 10.00,coleslaw and beetroot @ 10.00,Liqui fruit @ 25.00', 80.00, 10.00, 'food_order', 'Add a spoonful of chilli sauce', '2026-02-10 08:17:18', '2026-02-10 08:20:37'),
(38, 1, 0, '2026-02-10', 'Cash Request @ 70.00', 70.00, 0.00, 'cash_request', '', '2026-02-10 08:21:37', '2026-02-10 08:21:37'),
(39, 1, 2, '2026-02-10', 'Hawaiian Twister @ 61.90', 61.90, 0.00, 'food_order', '', '2026-02-10 08:30:20', '2026-02-10 08:30:20'),
(40, 1, 0, '2026-02-10', 'Cash Request @ 60.00', 60.00, 0.00, 'cash_request', '', '2026-02-10 08:32:14', '2026-02-10 08:32:14'),
(41, 1, 0, '2026-02-10', 'Cash Request @ 65.00', 65.00, 0.00, 'cash_request', '', '2026-02-10 08:32:39', '2026-02-10 08:32:39'),
(42, 1, 3, '2026-02-10', 'chicken wrap @ 20.00', 20.00, 0.00, 'food_order', 'with extra cheese', '2026-02-10 08:45:16', '2026-02-10 08:45:16'),
(43, 4, 0, '2026-02-13', 'Cash Request @ 50.00', 50.00, 0.00, 'cash_request', '', '2026-02-13 12:55:38', '2026-02-13 12:55:38');

-- --------------------------------------------------------

--
-- Table structure for table `outlets`
--

CREATE TABLE `outlets` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `url` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `logo` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `outlets`
--

INSERT INTO `outlets` (`id`, `name`, `description`, `url`, `is_active`, `created_at`, `logo`) VALUES
(1, 'Nando\'s ', 'Your  Portuguese flame-grilled, peri-peri style chicken.', 'https://www.nandos.co.za/eat/order/menu', 1, '2025-11-19 09:43:08', 'nandos_logo.jpg'),
(2, 'KFC', 'Deep fried chicken.', 'https://thumo.app/browse-stores/enterprise/5', 1, '2025-11-19 09:43:08', 'kfc-2006.jpg'),
(3, 'Jazz Friends restaurant ', 'Homey meals for breakfast and lunch.', 'http://172.16.1.183/pspf_helpdesk/api/uploads/Jazz_friends_menu.jpeg', 1, '2025-11-19 09:44:16', 'JAZZ_FRIENDS.png');

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
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`, `description`, `created_at`) VALUES
(1, 'user', 'Regular user', '2025-11-11 14:22:16'),
(2, 'admin', 'Middle Management ', '2025-11-11 14:22:16'),
(3, 'superadmin', 'IT administrators ', '2025-11-11 14:25:02'),
(4, 'agent', 'assigned task users', '2025-11-11 14:25:02');

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
  `assigned_to` varchar(255) DEFAULT NULL,
  `department_reason` text NOT NULL,
  `division_id` int(11) DEFAULT NULL,
  `last_updated_by` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tickets`
--

INSERT INTO `tickets` (`id`, `title`, `member_type`, `region`, `source`, `query_type`, `description`, `priority`, `phone_number`, `query_date`, `created_by`, `status`, `attachment_path`, `assigned_to`, `department_reason`, `division_id`, `last_updated_by`, `updated_at`) VALUES
(1, 'Documented testing 1', 'Employee', 'Manzini', 'Phone', 'Testing', 'Testing the whole ticketing logging operation.', 'High', '78466683', '2025-12-11 14:48:05', 'Nkosenhlep', 'Escalated', NULL, 'nkosenhlep@pspf.co.sz', '', NULL, '', '2026-01-28 10:28:24'),
(2, 'Documented testing 1', 'Employee', 'Manzini', 'Phone', 'Testing', 'Testing the whole ticketing logging operation.', 'High', '78466683', '2025-12-11 14:52:48', 'Nkosenhlep', 'Escalated', NULL, 'simphiwe', '', NULL, '', '2026-01-28 10:28:24'),
(3, 'Documented testing 1', 'Employee', 'Manzini', 'Phone', 'Testing', 'Testing the whole ticketing logging operation.', 'High', '78466683', '2025-12-11 14:58:31', 'Nkosenhlep', 'Escalated', NULL, 'nkosenhlep@pspf.co.sz', '', NULL, '', '2026-01-28 10:28:24'),
(4, 'Documented testing 2', 'Employee', 'Manzini', 'Walk-in', 'Testing', 'Testing to fix the ticket logging flow', 'High', '', '2025-12-11 15:16:02', 'Nkosenhlep', 'Closed', NULL, 'nkosenhlep@pspf.co.sz', '', NULL, '', '2026-01-28 10:28:24'),
(5, '', '', '', '', '', '', '', '', '2025-12-11 14:22:40', 'Nkosenhlep', 'Escalated', NULL, 'Nkosenhlep', '', NULL, '', '2026-01-28 10:28:24'),
(6, '', '', '', '', '', '', '', '', '2025-12-11 15:10:43', 'Nkosenhlep', 'Closed', NULL, 'Nkosenhlep', '', NULL, '', '2026-01-28 10:28:24'),
(7, '', '', '', '', '', '', '', '', '2025-12-17 08:30:16', 'Intercesor', 'Open', NULL, 'Nkosenhlep', '', NULL, '', '2026-01-28 10:28:24'),
(8, 'Ticket tracking test 2', 'Employee', 'Manzini', 'PSPF Staff', 'Testing', 'Testing ticket tracking flow and fixing errors', 'High', '', '2025-12-17 09:28:54', 'Intercesor', 'Escalated', NULL, 'Nkosenhlep', '', NULL, '', '2026-01-28 10:28:24'),
(9, 'System Test 1', 'Employee', 'Manzini', 'Phone', 'Testing', 'Final testing preparations.', 'Medium', '78466683', '2026-01-19 08:44:10', 'Intercesor', 'Escalated', '../uploads/tickets/1768808650_1767340778010.jpg', 'nkosenhlep@pspf.co.sz', '', NULL, '', '2026-01-28 10:28:24'),
(10, 'Help', 'Employee', 'Hhohho', 'PSPF Staff', 'IT help desk', 'Hello', 'Medium', '', '2026-01-19 13:42:25', 'simphiwe', 'Escalated', '../uploads/tickets/1768826545_Signature 2.0.jpg', 'Nkosenhlep', '', NULL, '', '2026-01-28 10:28:24'),
(11, 'Add a new annuitant on verification database', 'Annuitant', 'Hhohho', 'E-mail', 'Annuities', 'Kindly add the annuitant on verification system.', 'High', '76177818', '2026-01-19 13:50:52', 'Ncamiso', 'Escalated', '../uploads/tickets/1768827052_Master Command IT.jpg', 'Nkosenhlep', '', NULL, '', '2026-01-28 10:28:24'),
(12, 'mass testing', 'Dependent', 'Shiselweni', 'Walk-in', 'Testing', 'testing ticket success', 'Low', '', '2026-01-19 13:51:02', 'Nkosenhlep', 'Escalated', NULL, 'Nkosenhlep', '', NULL, '', '2026-01-28 10:28:24'),
(13, 'mass testing', 'Dependent', 'Shiselweni', 'Walk-in', 'Testing', 'Testing ticket success page', 'Low', '79473850', '2026-01-19 13:56:59', 'Nkosenhlep', 'Escalated', NULL, 'simphiwes@pspf.co.sz', '', NULL, '', '2026-01-28 10:28:24'),
(14, 'Ticket success display test', 'Annuitant', 'Lubombo', 'Social Media', 'Testing', 'ticket success testing 1', 'Medium', '79470055', '2026-01-19 23:21:06', 'Nkosenhlep', 'Closed', '../uploads/tickets/1768861266_Tree blue.png', 'Nkosenhlep', '', NULL, '', '2026-01-28 10:28:24'),
(15, 'Ticket success display test 2', 'Active', 'Lubombo', 'PSPF Staff', 'Testing', 'Testing the page ticket_success2.php if it views', 'Low', '', '2026-01-20 07:29:35', 'Nkosenhlep', 'Escalated', '../uploads/tickets/1768890575_Jazz_friends_menu.jpeg', 'nkosenhlep@pspf.co.sz', '', NULL, '', '2026-01-28 10:28:24'),
(16, 'Ticket success display test 3', 'Employee', 'Manzini', 'Walk-in', 'Testing', 'Testing ticket_success2.php page visibility', 'Medium', '78466683', '2026-01-20 08:18:37', 'Nkosenhlep', 'Closed', 'tickets/1768893517_Closure_Report_2026-01-19.pdf', 'nkosenhlep@pspf.co.sz', '', NULL, '', '2026-01-28 10:28:24'),
(17, 'Ticket success display test 4', 'Employee', 'Lubombo', 'Social Media', 'Testing', 'Still testing ticket_success2.php visibility', 'Medium', '', '2026-01-20 08:56:41', 'Nkosenhlep', 'Closed', NULL, 'nkosenhlep@pspf.co.sz', '', NULL, '', '2026-01-28 10:28:24'),
(18, 'testing ticket success work flow', 'Dependent', 'Manzini', 'E-mail', 'Testing', 'We have changed the flow from  ticket_success page to redirect to dashboard', 'Low', '76543210', '2026-01-20 09:16:52', 'Nkosenhlep', 'Closed', 'tickets/1768897012_nandos-logo.jpg', 'nkosenhlep@pspf.co.sz', '', NULL, '', '2026-01-28 10:28:24'),
(19, 'Ticket success display test 5', 'Annuitant', 'Hhohho', 'Phone', 'Testing', 'Testing for modal display', 'Low', '78901234', '2026-01-20 09:21:20', 'Nkosenhlep', 'Escalated', NULL, 'nkosenhlep@pspf.co.sz', '', NULL, '', '2026-01-28 10:28:24'),
(20, 'NYANDZALEYO', 'Employee', 'Manzini', 'PSPF Staff', 'Testing', 'Hello, is it me your looking for', 'High', '', '2026-01-21 10:20:56', 'simphiwe', 'Escalated', 'tickets/1768987256_PSPF logo.png', 'nkosenhlep@pspf.co.sz', '', NULL, '', '2026-01-28 10:28:24'),
(21, 'Testing specialized dashboard', 'Employee', 'Manzini', 'Phone', 'Other', 'We are checking if the admin dashboard will strictly reflect departmental tickets', 'High', '78466683', '2026-01-22 09:42:38', 'Nkosenhlep', 'Closed', NULL, 'mandainterbicenhle@gmail.com', '', NULL, '', '2026-01-28 10:28:24'),
(22, 'software issues', 'Active', 'Manzini', 'PSPF Staff', 'Database Administration', 'Testing the new assignment flow', 'Low', '', '2026-01-26 14:24:42', 'Intercesor', 'Escalated', NULL, 'info@pspf.co.sz', '', NULL, '', '2026-01-28 10:28:24'),
(23, 'CRM testing', 'Active', 'Manzini', 'Phone', 'IT Support', 'testing the new framework of the database', 'Medium', '76543210', '2026-01-27 09:20:28', 'Nkosenhlep', 'Escalated', NULL, 'info1@pspf.co.sz', '', NULL, '', '2026-01-28 10:28:24'),
(24, 'CRM testing', 'Active', 'Manzini', 'Phone', 'IT Support', 'Testing database framework', 'Medium', '23456789', '2026-01-27 09:26:23', 'Nkosenhlep', 'Escalated', 'tickets/1769502383_IT OFFICER SYSTEM ANALYST EMPLOYMENT OFFER.pdf', 'info1@pspf.co.sz', '', NULL, '', '2026-01-28 10:28:24'),
(25, 'CRM testing', 'Active', 'Manzini', 'Phone', 'IT Support', 'Testing the reframing of the database', 'Medium', '12345678', '2026-01-27 09:35:46', 'Nkosenhlep', 'Escalated', NULL, 'info1@pspf.co.sz', '', NULL, '', '2026-01-28 10:28:24'),
(26, 'CRM testing', 'Active', 'Hhohho', 'E-mail', 'IT Support', 'Testing again the database change', 'High', '123456789', '2026-01-27 09:43:18', 'Nkosenhlep', 'Closed', NULL, 'nkosenhlep@pspf.co.sz', '', 10, '', '2026-01-28 10:28:24'),
(27, 'CRM testing', 'Annuitant', 'Shiselweni', 'Walk-in', '10', 'testing assigning logic of tickets', 'Medium', '12345678', '2026-01-27 09:57:06', 'Nkosenhlep', 'Escalated', NULL, 'info1@pspf.co.sz', '', NULL, '', '2026-01-28 10:28:24'),
(28, 'CRM testing', 'Active', 'Hhohho', 'Walk-in', '10', 'testing automated assigning', 'Low', '12345678', '2026-01-27 10:06:21', 'Nkosenhlep', 'Escalated', NULL, 'info@pspf.co.sz', '', NULL, '', '2026-01-28 10:28:24'),
(29, 'CRM testing', 'Active', 'Hhohho', 'Walk-in', '10', 'testing automated assigning', 'Low', '12345678', '2026-01-27 10:09:58', 'Nkosenhlep', 'Escalated', NULL, 'info@pspf.co.sz', '', NULL, '', '2026-01-28 10:28:24'),
(30, 'CRM testing 2', 'Annuitant', 'Manzini', 'Phone', '10', 'I am changing the insertion of division_id', 'Low', '78901234', '2026-01-27 13:24:44', 'Nkosenhlep', 'Escalated', NULL, 'Nkosenhlep', '', NULL, '', '2026-01-28 10:28:24'),
(32, 'CRM testing', 'Active', 'Manzini', 'Phone', 'Accounting', 'configuring submission pages', 'Medium', '12345678', '2026-01-27 15:19:31', 'Nkosenhlep', 'Resolved', NULL, '', '', 6, 'Intercesor', '2026-02-19 14:19:47'),
(35, 'adgdh', 'Active', 'Manzini', 'PSPF Staff', 'Accounting', 'dryyt', 'Low', '', '2026-01-27 15:31:18', 'Simphiwes', 'Pending Feedback', NULL, '', '', 6, 'Intercesor', '2026-02-19 13:52:34'),
(36, 'CRM testing', 'Employee', 'Hhohho', 'PSPF Staff', '6', 'testing the mapping of query type to division table if it has been successful done', 'High', '12345678', '2026-01-28 07:26:59', 'Nkosenhlep', 'Escalated', '../uploads/tickets/1769581619_AvatarMaker.png', 'mandainterbicenhle@gmail.com', '', NULL, '', '2026-01-28 10:28:24'),
(37, 'CRM testing', 'Employee', 'Hhohho', 'E-mail', 'Accounting', 'Still testing the mapping to divisions table', 'High', '76543219', '2026-01-28 07:43:10', 'Nkosenhlep', 'Resolved', '../uploads/tickets/1769582590_feedback1.png', 'mandainterbicenhle@gmail.com', '', 6, 'Intercesor', '2026-02-20 07:34:55'),
(38, 'Page flow', 'Annuitant', 'Hhohho', 'Walk-in', 'Investment Monitoring', 'we are checking up on ticket success page', 'Low', '', '2026-01-28 08:11:01', 'Nkosenhlep', 'Closed', NULL, 'simphiweshongwe11@gmail.com, Ncamiso@pspf.co.sz', '', 7, '', '2026-01-28 10:28:24'),
(39, 'Hosting testing', 'Employee', 'Hhohho', 'PSPF Staff', 'Accounting', 'Testing if attachment is visible in email.', 'Low', '', '2026-01-29 11:51:31', 'Nkosenhlep', 'Resolved', '../uploads/tickets/1769683891_crmlogo.png', 'mandainterbicenhle@gmail.com', '', 6, 'Intercesor', '2026-02-19 12:59:41'),
(40, 'Request for friday money for kudla', 'Employee', 'Hhohho', 'PSPF Staff', 'Accounting', 'May we have money for Friday lunch', 'High', '053', '2026-01-30 14:30:14', 'Ncamiso', 'Escalated', NULL, 'mandainterbicenhle@gmail.com', '', 6, 'Intercesor', '2026-02-11 09:59:20'),
(41, 'Live testing', 'Employee', 'Hhohho', 'PSPF Staff', 'IT Support', 'Meeting room test', 'Medium', '', '2026-01-30 14:30:49', 'Nkosenhlep', 'Closed', '../uploads/tickets/1769779849_Nkosenhle Phiri -  ICT logo.png', 'mandainterbicenhle@gmail.com', 'Testing assignment', 6, '', '2026-02-10 10:12:10'),
(42, 'TESTING', 'Employee', 'Hhohho', 'PSPF Staff', 'IT Support', 'oisfnongnioendgioniodg', 'Low', '', '2026-01-30 14:31:13', 'Simphiwes', 'Closed', '../uploads/tickets/1769779873_PSPF logo.png', 'nkosenhlep@pspf.co.sz', '', NULL, '', '2026-02-10 07:36:40'),
(43, 'Phone', 'Employee', 'Hhohho', 'PSPF Staff', 'IT Support', 'Phone off', 'High', '055', '2026-01-30 14:31:34', 'simphiwe', 'Escalate', NULL, 'simphiwes@pspf.co.sz', '', NULL, '', '2026-02-04 08:39:49'),
(44, 'Tindleko kuhamba njani', 'Employee', 'Lubombo', 'PSPF Staff', 'Investment Monitoring', 'Kunamalini lapho.', 'High', '078', '2026-01-30 14:32:33', 'Ncamiso', 'Closed', NULL, 'simphiweshongwe11@gmail.com, Ncamiso@pspf.co.sz', '', 7, '', '2026-01-30 13:46:26'),
(45, 'Push payment on smartstream', 'Employee', 'Hhohho', 'PSPF Staff', 'Accounting', 'Kindly push payments to smartstream', 'High', '042', '2026-01-30 14:32:57', 'Intercesor', 'Closed', NULL, 'mandainterbicenhle@gmail.com', '', 6, '', '2026-01-30 13:44:50'),
(46, 'qwerty', 'Employee', 'Headquarters', 'PSPF Staff', '10', 'ghh', 'Low', '', '2026-02-10 11:59:21', 'Simphiwes', 'Escalated', NULL, '', '', NULL, '', '2026-02-10 10:59:21'),
(47, 'help', 'Employee', 'Headquarters', 'PSPF Staff', '10', 'bdnjjj', 'Low', '', '2026-02-11 08:36:52', 'Simphiwes', 'Open', NULL, NULL, 'We are testing this function', 7, '', '2026-02-11 07:36:52'),
(48, 'test', 'Employee', 'Headquarters', 'PSPF Staff', '10', 'ghjkk', 'Low', '', '2026-02-11 08:42:29', 'Simphiwes', 'Open', NULL, 'simphiweshongwe11@gmail.com, Ncamiso@pspf.co.sz', 'Testing database insertion', NULL, '', '2026-02-11 07:42:29'),
(49, 'Test', 'Employee', 'Headquarters', 'PSPF Staff', '7', 'Testing', 'Low', '', '2026-02-13 13:40:28', 'Sam', 'Open', NULL, 'Ncamiso@pspf.co.sz', '', 7, '', '2026-02-13 12:40:28'),
(51, 'testing', 'Employee', 'Headquarters', 'PSPF Staff', '10', 'qwertyy', 'Low', '', '2026-02-16 14:56:41', 'simphiwe', 'In Progress', NULL, 'simphiwes@pspf.co.sz', '', 10, 'Simphiwes', '2026-02-19 07:27:18');

--
-- Triggers `tickets`
--
DELIMITER $$
CREATE TRIGGER `after_ticket_status_update` AFTER UPDATE ON `tickets` FOR EACH ROW BEGIN
    IF OLD.status != NEW.status THEN
        INSERT INTO ticket_status_logs (ticket_id, old_status, new_status, changed_by)
        VALUES (NEW.id, OLD.status, NEW.status, NEW.assigned_to);
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `ticket_assignments`
--

CREATE TABLE `ticket_assignments` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `assigned_to` varchar(100) NOT NULL,
  `assigned_by` varchar(100) NOT NULL,
  `assignment_method` varchar(50) NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ticket_assignments`
--

INSERT INTO `ticket_assignments` (`id`, `ticket_id`, `assigned_to`, `assigned_by`, `assignment_method`, `assigned_at`, `notes`) VALUES
(1, 30, 'simphiwes@pspf.co.sz', 'Nkosenhlep', 'manual', '2026-01-27 13:35:01', NULL),
(2, 36, 'mandainterbicenhle@gmail.com', 'Intercesor', 'manual', '2026-01-28 06:45:59', NULL),
(3, 26, 'nkosenhlep@pspf.co.sz', 'Nkosenhlep', 'manual', '2026-01-28 07:00:54', NULL),
(4, 43, 'simphiwes@pspf.co.sz', 'Nkosenhlep', 'manual', '2026-02-05 08:49:30', NULL),
(5, 42, 'nkosenhlep@pspf.co.sz', 'Nkosenhlep', 'manual', '2026-02-10 10:06:53', NULL),
(6, 30, 'Nkosenhlep', 'Simphiwes', 'manual', '2026-02-10 10:56:35', NULL),
(7, 48, 'Simphiwes', 'Nkosenhlep', 'manual', '2026-02-11 07:47:09', NULL),
(8, 47, 'department:Investment Monitoring', 'Nkosenhlep', 'manual', '2026-02-11 07:53:59', NULL),
(9, 48, 'department:Investment Monitoring', 'Nkosenhlep', 'manual', '2026-02-11 08:14:22', NULL),
(10, 41, 'simphiwes@pspf.co.sz', 'Nkosenhlep', 'manual', '2026-02-11 08:24:57', NULL),
(11, 41, 'department:Accounting', 'Nkosenhlep', 'manual', '2026-02-11 08:26:10', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `ticket_closures`
--

CREATE TABLE `ticket_closures` (
  `closure_id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `closed_by` int(11) NOT NULL,
  `closed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `closure_reason` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Dumping data for table `ticket_closures`
--

INSERT INTO `ticket_closures` (`closure_id`, `ticket_id`, `closed_by`, `closed_at`, `closure_reason`) VALUES
(1, 4, 1, '2026-01-19 09:20:15', ''),
(2, 16, 1, '2026-01-20 09:18:12', ''),
(3, 17, 1, '2026-01-20 09:18:18', ''),
(4, 18, 1, '2026-01-20 09:18:32', ''),
(5, 14, 1, '2026-01-20 23:29:36', ''),
(6, 6, 1, '2026-01-21 00:06:28', 'testing closure reason modal'),
(7, 21, 1, '2026-01-22 12:54:35', 'testing closure reason modal'),
(8, 26, 1, '2026-01-27 13:13:51', 'a bit of progress has been made.'),
(9, 42, 1, '2026-02-10 07:36:40', 'fng'),
(10, 41, 1, '2026-02-10 10:12:10', 'ngggng'),
(12, 39, 1, '2026-02-19 12:59:41', 'Testing feedback module'),
(13, 35, 1, '2026-02-19 13:52:34', 'Testing the full functionality of the feedback submission'),
(14, 32, 1, '2026-02-19 14:19:47', 'testing feedback'),
(15, 37, 5, '2026-02-20 07:34:55', 'testing the full workflow of feedback');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_escalations`
--

CREATE TABLE `ticket_escalations` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `escalated_by` varchar(150) NOT NULL,
  `escalated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `escalation_reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ticket_escalations`
--

INSERT INTO `ticket_escalations` (`id`, `ticket_id`, `escalated_by`, `escalated_at`, `escalation_reason`) VALUES
(1, 3, 'Nkosenhlep', '2026-01-19 11:21:11', 'Testing the workflow of all emails'),
(2, 5, 'Nkosenhlep', '2026-01-19 15:44:15', 'Testing escaltion'),
(3, 13, 'Nkosenhlep', '2026-01-19 15:47:09', 'testing escalation'),
(4, 21, 'Intercesor', '2026-01-22 14:12:51', 'the reason modal is not working for closing a ticket'),
(5, 44, 'simphiwe', '2026-01-30 15:44:00', 'Open'),
(6, 45, 'Intercesor', '2026-01-30 15:44:50', 'Please action'),
(7, 44, 'Ncamiso', '2026-01-30 15:45:26', 'failing to complete this task. I need help.'),
(8, 43, 'Simphiwes', '2026-02-04 10:39:49', 'dhdjhf'),
(9, 1, 'SYSTEM', '2026-02-18 12:05:09', 'Automatically escalated due to SLA timeout.'),
(10, 2, 'SYSTEM', '2026-02-18 12:05:09', 'Automatically escalated due to SLA timeout.'),
(11, 8, 'SYSTEM', '2026-02-18 12:05:09', 'Automatically escalated due to SLA timeout.'),
(12, 9, 'SYSTEM', '2026-02-18 12:05:09', 'Automatically escalated due to SLA timeout.'),
(13, 10, 'SYSTEM', '2026-02-18 12:05:09', 'Automatically escalated due to SLA timeout.'),
(14, 11, 'SYSTEM', '2026-02-18 12:05:09', 'Automatically escalated due to SLA timeout.'),
(15, 12, 'SYSTEM', '2026-02-18 12:05:09', 'Automatically escalated due to SLA timeout.'),
(16, 15, 'SYSTEM', '2026-02-18 12:05:09', 'Automatically escalated due to SLA timeout.'),
(17, 19, 'SYSTEM', '2026-02-18 12:05:09', 'Automatically escalated due to SLA timeout.'),
(18, 20, 'SYSTEM', '2026-02-18 12:05:09', 'Automatically escalated due to SLA timeout.'),
(19, 22, 'SYSTEM', '2026-02-18 12:05:09', 'Automatically escalated due to SLA timeout.'),
(20, 23, 'SYSTEM', '2026-02-18 12:05:09', 'Automatically escalated due to SLA timeout.'),
(21, 24, 'SYSTEM', '2026-02-18 12:05:09', 'Automatically escalated due to SLA timeout.'),
(22, 25, 'SYSTEM', '2026-02-18 12:05:09', 'Automatically escalated due to SLA timeout.'),
(23, 27, 'SYSTEM', '2026-02-18 12:05:09', 'Automatically escalated due to SLA timeout.'),
(24, 28, 'SYSTEM', '2026-02-18 12:05:09', 'Automatically escalated due to SLA timeout.'),
(25, 29, 'SYSTEM', '2026-02-18 12:05:09', 'Automatically escalated due to SLA timeout.'),
(26, 30, 'SYSTEM', '2026-02-18 12:05:09', 'Automatically escalated due to SLA timeout.'),
(27, 32, 'SYSTEM', '2026-02-18 12:05:09', 'Automatically escalated due to SLA timeout.'),
(28, 35, 'SYSTEM', '2026-02-18 12:05:10', 'Automatically escalated due to SLA timeout.'),
(29, 39, 'SYSTEM', '2026-02-18 12:05:10', 'Automatically escalated due to SLA timeout.'),
(30, 40, 'SYSTEM', '2026-02-18 12:05:10', 'Automatically escalated due to SLA timeout.'),
(31, 46, 'SYSTEM', '2026-02-18 12:05:10', 'Automatically escalated due to SLA timeout.');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_feedback`
--

CREATE TABLE `ticket_feedback` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `rating` int(11) NOT NULL CHECK (`rating` between 1 and 5),
  `comment` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ticket_feedback`
--

INSERT INTO `ticket_feedback` (`id`, `ticket_id`, `user_id`, `rating`, `comment`, `created_at`) VALUES
(7, 39, 5, 3, 'Testing feedback submission', '2026-02-19 15:27:03'),
(13, 32, 5, 3, 'testing submission', '2026-02-20 08:48:49'),
(18, 37, 1, 4, 'checking', '2026-02-20 14:57:17');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_history`
--

CREATE TABLE `ticket_history` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `changed_by` varchar(100) NOT NULL,
  `old_status` varchar(50) NOT NULL,
  `new_status` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ticket_history`
--

INSERT INTO `ticket_history` (`id`, `ticket_id`, `changed_by`, `old_status`, `new_status`, `description`, `changed_at`) VALUES
(1, 9, 'Nkosenhlep', 'open', 'in progress', 'ticket was unassigned and then assigned to Nkosenhlep successfully.', '2026-01-19 08:53:28'),
(2, 4, 'Nkosenhlep', 'open', 'closed', '', '2026-01-19 09:20:15'),
(3, 3, 'Nkosenhlep', 'open', 'escalated', 'Testing the workflow of all emails', '2026-01-19 09:21:11'),
(4, 1, 'Nkosenhlep', 'open', 'in progress', 'I think i should make the superadmin functions be against agents emails not usernames', '2026-01-19 09:22:25'),
(5, 5, 'Nkosenhlep', 'open', 'escalated', 'Testing escaltion', '2026-01-19 13:44:15'),
(6, 13, 'Nkosenhlep', 'open', 'escalated', 'testing escalation', '2026-01-19 13:47:09'),
(7, 15, 'Nkosenhlep', 'open', 'in progress', 'test was unsuccessful', '2026-01-20 09:15:58'),
(8, 16, 'Nkosenhlep', 'open', 'closed', '', '2026-01-20 09:18:12'),
(9, 17, 'Nkosenhlep', 'open', 'closed', '', '2026-01-20 09:18:18'),
(10, 18, 'Nkosenhlep', 'open', 'closed', '', '2026-01-20 09:18:32'),
(11, 6, 'Nkosenhlep', 'open', 'in progress', 'testing status change', '2026-01-20 10:16:50');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_progress`
--

CREATE TABLE `ticket_progress` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `description` text NOT NULL,
  `updated_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ticket_progress`
--

INSERT INTO `ticket_progress` (`id`, `ticket_id`, `description`, `updated_by`, `created_at`) VALUES
(1, 9, 'ticket was unassigned and then assigned to Nkosenhlep successfully.', 'Nkosenhlep', '2026-01-19 08:53:28'),
(2, 1, 'I think i should make the superadmin functions be against agents emails not usernames', 'Nkosenhlep', '2026-01-19 09:22:25'),
(3, 15, 'test was unsuccessful', 'Nkosenhlep', '2026-01-20 09:15:58'),
(4, 6, 'testing status change', 'Nkosenhlep', '2026-01-20 10:16:50'),
(6, 14, 'testing ticket_status_logs insertion', 'Nkosenhlep', '2026-01-20 13:26:11'),
(7, 12, '', 'Nkosenhlep', '2026-01-21 00:13:11'),
(8, 49, 'Assigned to Ncamiso@pspf.co.sz (manual)', 'simphiwe', '2026-02-13 12:46:24'),
(9, 51, 'Assigned to simphiwes@pspf.co.sz (manual)', 'Simphiwes', '2026-02-16 13:59:19');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_reopens`
--

CREATE TABLE `ticket_reopens` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `reopened_by` varchar(150) NOT NULL,
  `reopened_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reopen_reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ticket_reopens`
--

INSERT INTO `ticket_reopens` (`id`, `ticket_id`, `reopened_by`, `reopened_at`, `reopen_reason`) VALUES
(1, 14, 'Nkosenhlep', '2026-01-21 01:31:09', 'testing reopen function');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_resolved`
--

CREATE TABLE `ticket_resolved` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `resolved_by` int(11) NOT NULL,
  `closed_by` int(11) NOT NULL,
  `rating` tinyint(4) NOT NULL,
  `comment` text DEFAULT NULL,
  `resolved_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ticket_resolved`
--

INSERT INTO `ticket_resolved` (`id`, `ticket_id`, `resolved_by`, `closed_by`, `rating`, `comment`, `resolved_at`) VALUES
(2, 32, 5, 1, 3, 'testing submission', '2026-02-20 08:48:49'),
(3, 37, 1, 5, 4, 'checking', '2026-02-20 14:57:17');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_status_logs`
--

CREATE TABLE `ticket_status_logs` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) NOT NULL,
  `changed_by` varchar(100) NOT NULL,
  `change_reason` text DEFAULT NULL,
  `change_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ticket_status_logs`
--

INSERT INTO `ticket_status_logs` (`id`, `ticket_id`, `old_status`, `new_status`, `changed_by`, `change_reason`, `change_date`, `created_at`) VALUES
(1, 6, 'Open', 'In Progress', 'Nkosenhlep', NULL, '2026-01-20 10:16:50', '2026-01-20 10:16:50'),
(3, 14, 'Open', 'In Progress', 'Nkosenhlep', NULL, '2026-01-20 13:26:11', '2026-01-20 13:26:11'),
(4, 14, 'open', 'In Progress', 'Nkosenhlep', 'testing ticket_status_logs insertion', '2026-01-20 13:26:11', '2026-01-20 13:26:11'),
(5, 14, 'In Progress', 'Closed', 'Nkosenhlep', NULL, '2026-01-20 23:29:36', '2026-01-20 23:29:36'),
(6, 14, 'in progress', 'closed', 'Nkosenhlep', '', '2026-01-20 23:29:36', '2026-01-20 23:29:36'),
(7, 14, 'Closed', 'Open', 'Nkosenhlep', NULL, '2026-01-20 23:31:09', '2026-01-20 23:31:09'),
(8, 14, 'closed', 'open', 'Nkosenhlep', 'testing reopen function', '2026-01-20 23:31:09', '2026-01-20 23:31:09'),
(11, 14, 'Open', 'Closed', 'Nkosenhlep', NULL, '2026-01-20 23:47:39', '2026-01-20 23:47:39'),
(12, 14, 'open', 'closed', 'Nkosenhlep', '', '2026-01-20 23:47:39', '2026-01-20 23:47:39'),
(13, 6, 'In Progress', 'Closed', 'Nkosenhlep', NULL, '2026-01-21 00:06:28', '2026-01-21 00:06:28'),
(14, 6, 'in progress', 'closed', 'Nkosenhlep', 'testing closure reason modal', '2026-01-21 00:06:28', '2026-01-21 00:06:28'),
(15, 12, 'Open', 'In Progress', 'Nkosenhlep', NULL, '2026-01-21 00:13:11', '2026-01-21 00:13:11'),
(16, 12, 'open', 'in progress', 'Nkosenhlep', '', '2026-01-21 00:13:11', '2026-01-21 00:13:11'),
(17, 19, 'Open', 'In Progress', 'nkosenhlep@pspf.co.sz', NULL, '2026-01-21 01:58:55', '2026-01-21 01:58:55'),
(18, 20, 'Open', 'In Progress', 'nkosenhlep@pspf.co.sz', NULL, '2026-01-21 09:37:33', '2026-01-21 09:37:33'),
(19, 21, 'Open', 'In Progress', 'mandainterbicenhle@gmail.com', NULL, '2026-01-22 08:50:37', '2026-01-22 08:50:37'),
(20, 21, 'In Progress', 'Closed', 'mandainterbicenhle@gmail.com', NULL, '2026-01-22 10:53:22', '2026-01-22 10:53:22'),
(21, 21, 'closed', 'closed', 'Intercesor', '', '2026-01-22 12:11:45', '2026-01-22 12:11:45'),
(22, 21, 'Closed', 'Escalated', 'mandainterbicenhle@gmail.com', NULL, '2026-01-22 12:12:51', '2026-01-22 12:12:51'),
(23, 21, 'closed', 'escalated', 'Intercesor', 'the reason modal is not working for closing a ticket', '2026-01-22 12:12:51', '2026-01-22 12:12:51'),
(24, 21, 'Escalated', 'Closed', 'mandainterbicenhle@gmail.com', NULL, '2026-01-22 12:54:35', '2026-01-22 12:54:35'),
(25, 21, 'Escalated', 'Closed', 'Intercesor', 'testing closure reason modal', '2026-01-22 12:54:35', '2026-01-22 12:54:35'),
(26, 26, NULL, 'Open', 'Nkosenhlep', NULL, '2026-01-27 08:43:18', '2026-01-27 08:43:18'),
(27, 27, NULL, 'Open', 'Nkosenhlep', NULL, '2026-01-27 08:57:06', '2026-01-27 08:57:06'),
(28, 28, NULL, 'Open', 'Nkosenhlep', NULL, '2026-01-27 09:06:21', '2026-01-27 09:06:21'),
(29, 29, NULL, 'Open', 'Nkosenhlep', NULL, '2026-01-27 09:09:58', '2026-01-27 09:09:58'),
(30, 26, 'Open', 'Closed', 'info1@pspf.co.sz', NULL, '2026-01-27 13:13:51', '2026-01-27 13:13:51'),
(31, 26, 'Open', 'Closed', 'Nkosenhlep', 'a bit of progress has been made.', '2026-01-27 13:13:51', '2026-01-27 13:13:51'),
(32, 32, NULL, 'Open', 'Nkosenhlep', NULL, '2026-01-27 14:19:31', '2026-01-27 14:19:31'),
(33, 35, NULL, 'Open', 'Simphiwes', NULL, '2026-01-27 14:31:18', '2026-01-27 14:31:18'),
(34, 36, NULL, 'Open', 'Nkosenhlep', NULL, '2026-01-28 06:26:59', '2026-01-28 06:26:59'),
(35, 37, NULL, 'Open', 'Nkosenhlep', NULL, '2026-01-28 06:43:10', '2026-01-28 06:43:10'),
(36, 37, 'Open', 'In Progress', 'mandainterbicenhle@gmail.com', NULL, '2026-01-28 06:44:50', '2026-01-28 06:44:50'),
(37, 37, 'Open', 'In Progress', 'Intercesor', NULL, '2026-01-28 06:44:50', '2026-01-28 06:44:50'),
(38, 36, 'Open', 'Closed', 'mandainterbicenhle@gmail.com', NULL, '2026-01-28 06:46:09', '2026-01-28 06:46:09'),
(39, 36, 'Open', 'Closed', 'Intercesor', NULL, '2026-01-28 06:46:09', '2026-01-28 06:46:09'),
(40, 36, 'Closed', 'Escalated', 'mandainterbicenhle@gmail.com', NULL, '2026-01-28 06:52:41', '2026-01-28 06:52:41'),
(41, 36, 'Closed', 'Escalated', 'Intercesor', NULL, '2026-01-28 06:52:41', '2026-01-28 06:52:41'),
(42, 26, 'Closed', 'Escalated', 'nkosenhlep@pspf.co.sz', NULL, '2026-01-28 07:01:04', '2026-01-28 07:01:04'),
(43, 26, 'Closed', 'Escalated', 'Nkosenhlep', NULL, '2026-01-28 07:01:04', '2026-01-28 07:01:04'),
(44, 26, 'Escalated', 'In Progress', 'nkosenhlep@pspf.co.sz', NULL, '2026-01-28 07:01:11', '2026-01-28 07:01:11'),
(45, 26, 'Escalated', 'In Progress', 'Nkosenhlep', NULL, '2026-01-28 07:01:11', '2026-01-28 07:01:11'),
(46, 26, 'In Progress', 'Closed', 'nkosenhlep@pspf.co.sz', NULL, '2026-01-28 07:02:30', '2026-01-28 07:02:30'),
(47, 26, 'In Progress', 'Closed', 'Nkosenhlep', NULL, '2026-01-28 07:02:30', '2026-01-28 07:02:30'),
(48, 30, 'Open', 'In Progress', 'simphiwes@pspf.co.sz', NULL, '2026-01-28 07:03:59', '2026-01-28 07:03:59'),
(49, 30, 'Open', 'In Progress', 'Nkosenhlep', '', '2026-01-28 07:03:59', '2026-01-28 07:03:59'),
(50, 38, NULL, 'Open', 'Nkosenhlep', NULL, '2026-01-28 07:11:01', '2026-01-28 07:11:01'),
(51, 38, 'Open', 'Closed', 'simphiweshongwe11@gmail.com, Ncamiso@pspf.co.sz', NULL, '2026-01-28 07:21:24', '2026-01-28 07:21:24'),
(52, 38, 'Open', 'Closed', 'Ncamiso', NULL, '2026-01-28 07:21:24', '2026-01-28 07:21:24'),
(53, 39, NULL, 'Open', 'Nkosenhlep', NULL, '2026-01-29 10:51:31', '2026-01-29 10:51:31'),
(54, 40, NULL, 'Open', 'Ncamiso', NULL, '2026-01-30 13:30:14', '2026-01-30 13:30:14'),
(55, 41, NULL, 'Open', 'Nkosenhlep', NULL, '2026-01-30 13:30:49', '2026-01-30 13:30:49'),
(56, 42, NULL, 'Open', 'Simphiwes', NULL, '2026-01-30 13:31:13', '2026-01-30 13:31:13'),
(57, 43, NULL, 'Open', 'simphiwe', NULL, '2026-01-30 13:31:34', '2026-01-30 13:31:34'),
(58, 44, NULL, 'Open', 'Ncamiso', NULL, '2026-01-30 13:32:33', '2026-01-30 13:32:33'),
(59, 45, NULL, 'Open', 'Intercesor', NULL, '2026-01-30 13:32:57', '2026-01-30 13:32:57'),
(60, 45, 'Open', 'In Progress', 'mandainterbicenhle@gmail.com', NULL, '2026-01-30 13:43:57', '2026-01-30 13:43:57'),
(61, 45, 'Open', 'In Progress', 'Intercesor', '', '2026-01-30 13:43:57', '2026-01-30 13:43:57'),
(62, 44, 'Open', 'Escalate', 'simphiweshongwe11@gmail.com, Ncamiso@pspf.co.sz', NULL, '2026-01-30 13:44:00', '2026-01-30 13:44:00'),
(63, 44, 'Open', 'Escalate', 'simphiwe', 'Open', '2026-01-30 13:44:00', '2026-01-30 13:44:00'),
(64, 44, 'Escalate', 'In Progress', 'simphiweshongwe11@gmail.com, Ncamiso@pspf.co.sz', NULL, '2026-01-30 13:44:32', '2026-01-30 13:44:32'),
(65, 44, 'Escalate', 'In Progress', 'Ncamiso', '', '2026-01-30 13:44:32', '2026-01-30 13:44:32'),
(66, 44, 'In Progress', 'In Progress', 'Ncamiso', '', '2026-01-30 13:44:43', '2026-01-30 13:44:43'),
(67, 45, 'In Progress', 'Escalate', 'mandainterbicenhle@gmail.com', NULL, '2026-01-30 13:44:50', '2026-01-30 13:44:50'),
(68, 45, 'In Progress', 'Escalate', 'Intercesor', 'Please action', '2026-01-30 13:44:50', '2026-01-30 13:44:50'),
(69, 44, 'In Progress', 'Escalate', 'simphiweshongwe11@gmail.com, Ncamiso@pspf.co.sz', NULL, '2026-01-30 13:45:26', '2026-01-30 13:45:26'),
(70, 44, 'In Progress', 'Escalate', 'Ncamiso', 'failing to complete this task. I need help.', '2026-01-30 13:45:26', '2026-01-30 13:45:26'),
(71, 44, 'Escalate', 'In Progress', 'simphiweshongwe11@gmail.com, Ncamiso@pspf.co.sz', NULL, '2026-01-30 13:46:26', '2026-01-30 13:46:26'),
(72, 44, 'Escalate', 'In Progress', 'Ncamiso', '', '2026-01-30 13:46:26', '2026-01-30 13:46:26'),
(73, 43, 'Open', 'In Progress', 'nkosenhlep@pspf.co.sz, simphiwes@pspf.co.sz', NULL, '2026-01-30 13:53:38', '2026-01-30 13:53:38'),
(74, 43, 'Open', 'In Progress', 'Nkosenhlep', NULL, '2026-01-30 13:53:38', '2026-01-30 13:53:38'),
(75, 44, 'In Progress', 'Closed', 'simphiweshongwe11@gmail.com, Ncamiso@pspf.co.sz', NULL, '2026-01-30 13:53:44', '2026-01-30 13:53:44'),
(76, 44, 'In Progress', 'Closed', 'simphiwe', NULL, '2026-01-30 13:53:44', '2026-01-30 13:53:44'),
(77, 44, 'Closed', 'Closed', 'Ncamiso', NULL, '2026-01-30 13:54:00', '2026-01-30 13:54:00'),
(78, 45, 'Escalate', 'Closed', 'mandainterbicenhle@gmail.com', NULL, '2026-01-30 13:54:19', '2026-01-30 13:54:19'),
(79, 45, 'Escalate', 'Closed', 'Intercesor', NULL, '2026-01-30 13:54:19', '2026-01-30 13:54:19'),
(80, 43, 'In Progress', 'Escalate', 'nkosenhlep@pspf.co.sz, simphiwes@pspf.co.sz', NULL, '2026-02-04 08:39:49', '2026-02-04 08:39:49'),
(81, 43, 'In Progress', 'Escalate', 'Simphiwes', 'dhdjhf', '2026-02-04 08:39:49', '2026-02-04 08:39:49'),
(82, 42, 'Open', 'In Progress', 'nkosenhlep@pspf.co.sz, simphiwes@pspf.co.sz', NULL, '2026-02-05 06:07:09', '2026-02-05 06:07:09'),
(83, 42, 'Open', 'In Progress', 'Nkosenhlep', 'testing the In progress', '2026-02-05 06:07:09', '2026-02-05 06:07:09'),
(84, 42, 'In Progress', 'Closed', 'nkosenhlep@pspf.co.sz, simphiwes@pspf.co.sz', NULL, '2026-02-10 07:36:40', '2026-02-10 07:36:40'),
(85, 42, 'In Progress', 'Closed', 'Simphiwes', 'fng', '2026-02-10 07:36:40', '2026-02-10 07:36:40'),
(86, 41, 'Open', 'In Progress', 'nkosenhlep@pspf.co.sz, simphiwes@pspf.co.sz', NULL, '2026-02-10 10:07:26', '2026-02-10 10:07:26'),
(87, 41, 'Open', 'In Progress', 'Nkosenhlep', NULL, '2026-02-10 10:07:26', '2026-02-10 10:07:26'),
(88, 41, 'In Progress', 'Closed', 'nkosenhlep@pspf.co.sz, simphiwes@pspf.co.sz', NULL, '2026-02-10 10:09:02', '2026-02-10 10:09:02'),
(89, 41, 'In Progress', 'Closed', 'Nkosenhlep', NULL, '2026-02-10 10:09:02', '2026-02-10 10:09:02'),
(90, 41, 'Closed', 'In Progress', 'nkosenhlep@pspf.co.sz, simphiwes@pspf.co.sz', NULL, '2026-02-10 10:09:58', '2026-02-10 10:09:58'),
(91, 41, 'Closed', 'In Progress', 'Nkosenhlep', NULL, '2026-02-10 10:09:58', '2026-02-10 10:09:58'),
(92, 41, 'In Progress', 'Closed', 'nkosenhlep@pspf.co.sz, simphiwes@pspf.co.sz', NULL, '2026-02-10 10:12:10', '2026-02-10 10:12:10'),
(93, 41, 'In Progress', 'Closed', 'Simphiwes', 'ngggng', '2026-02-10 10:12:10', '2026-02-10 10:12:10'),
(94, 46, NULL, 'Open', 'Simphiwes', NULL, '2026-02-10 10:59:21', '2026-02-10 10:59:21'),
(95, 47, NULL, 'Open', 'Simphiwes', NULL, '2026-02-11 07:36:52', '2026-02-11 07:36:52'),
(96, 48, NULL, 'Open', 'Simphiwes', NULL, '2026-02-11 07:42:29', '2026-02-11 07:42:29'),
(97, 40, 'Open', 'In Progress', 'mandainterbicenhle@gmail.com', NULL, '2026-02-11 09:59:20', '2026-02-11 09:59:20'),
(98, 40, 'Open', 'In Progress', 'Intercesor', 'Testing function', '2026-02-11 09:59:20', '2026-02-11 09:59:20'),
(99, 37, 'In Progress', 'Escalated', 'mandainterbicenhle@gmail.com', NULL, '2026-02-11 10:01:36', '2026-02-11 10:01:36'),
(100, 37, 'In Progress', 'Escalated', 'Intercesor', 'Testing escalation function', '2026-02-11 10:01:36', '2026-02-11 10:01:36'),
(101, 49, NULL, 'Open', 'Sam', NULL, '2026-02-13 12:40:28', '2026-02-13 12:40:28'),
(103, 51, NULL, 'Open', 'simphiwe', NULL, '2026-02-16 13:56:41', '2026-02-16 13:56:41'),
(104, 1, 'In Progress', 'Escalated', 'nkosenhlep@pspf.co.sz', NULL, '2026-02-18 10:05:09', '2026-02-18 10:05:09'),
(105, 2, 'Open', 'Escalated', 'simphiwe', NULL, '2026-02-18 10:05:09', '2026-02-18 10:05:09'),
(106, 8, 'Open', 'Escalated', 'Nkosenhlep', NULL, '2026-02-18 10:05:09', '2026-02-18 10:05:09'),
(107, 9, 'In Progress', 'Escalated', 'nkosenhlep@pspf.co.sz', NULL, '2026-02-18 10:05:09', '2026-02-18 10:05:09'),
(108, 10, 'Open', 'Escalated', 'Nkosenhlep', NULL, '2026-02-18 10:05:09', '2026-02-18 10:05:09'),
(109, 11, 'Open', 'Escalated', 'Nkosenhlep', NULL, '2026-02-18 10:05:09', '2026-02-18 10:05:09'),
(110, 12, 'In Progress', 'Escalated', 'Nkosenhlep', NULL, '2026-02-18 10:05:09', '2026-02-18 10:05:09'),
(111, 15, 'In Progress', 'Escalated', 'nkosenhlep@pspf.co.sz', NULL, '2026-02-18 10:05:09', '2026-02-18 10:05:09'),
(112, 19, 'In Progress', 'Escalated', 'nkosenhlep@pspf.co.sz', NULL, '2026-02-18 10:05:09', '2026-02-18 10:05:09'),
(113, 20, 'In Progress', 'Escalated', 'nkosenhlep@pspf.co.sz', NULL, '2026-02-18 10:05:09', '2026-02-18 10:05:09'),
(114, 22, 'Open', 'Escalated', 'info@pspf.co.sz', NULL, '2026-02-18 10:05:09', '2026-02-18 10:05:09'),
(115, 23, 'Open', 'Escalated', 'info1@pspf.co.sz', NULL, '2026-02-18 10:05:09', '2026-02-18 10:05:09'),
(116, 24, 'Open', 'Escalated', 'info1@pspf.co.sz', NULL, '2026-02-18 10:05:09', '2026-02-18 10:05:09'),
(117, 25, 'Open', 'Escalated', 'info1@pspf.co.sz', NULL, '2026-02-18 10:05:09', '2026-02-18 10:05:09'),
(118, 27, 'Open', 'Escalated', 'info1@pspf.co.sz', NULL, '2026-02-18 10:05:09', '2026-02-18 10:05:09'),
(119, 28, 'Open', 'Escalated', 'info@pspf.co.sz', NULL, '2026-02-18 10:05:09', '2026-02-18 10:05:09'),
(120, 29, 'Open', 'Escalated', 'info@pspf.co.sz', NULL, '2026-02-18 10:05:09', '2026-02-18 10:05:09'),
(121, 30, 'In Progress', 'Escalated', 'Nkosenhlep', NULL, '2026-02-18 10:05:09', '2026-02-18 10:05:09'),
(122, 32, 'Open', 'Escalated', '', NULL, '2026-02-18 10:05:09', '2026-02-18 10:05:09'),
(123, 35, 'Open', 'Escalated', '', NULL, '2026-02-18 10:05:09', '2026-02-18 10:05:09'),
(124, 39, 'Open', 'Escalated', 'mandainterbicenhle@gmail.com', NULL, '2026-02-18 10:05:10', '2026-02-18 10:05:10'),
(125, 40, 'In Progress', 'Escalated', 'mandainterbicenhle@gmail.com', NULL, '2026-02-18 10:05:10', '2026-02-18 10:05:10'),
(126, 46, 'Open', 'Escalated', '', NULL, '2026-02-18 10:05:10', '2026-02-18 10:05:10'),
(127, 51, 'Open', 'In Progress', 'simphiwes@pspf.co.sz', NULL, '2026-02-19 07:27:18', '2026-02-19 07:27:18'),
(128, 51, 'Open', 'In Progress', 'Simphiwes', 'fgg', '2026-02-19 07:27:18', '2026-02-19 07:27:18'),
(130, 39, 'Escalated', 'Pending Feedback', 'mandainterbicenhle@gmail.com', NULL, '2026-02-19 12:59:41', '2026-02-19 12:59:41'),
(131, 39, 'Escalated', 'Pending Feedback', 'Intercesor', 'Testing feedback module', '2026-02-19 12:59:41', '2026-02-19 12:59:41'),
(132, 39, 'Pending Feedback', 'Resolved', 'mandainterbicenhle@gmail.com', NULL, '2026-02-19 13:27:03', '2026-02-19 13:27:03'),
(133, 35, 'Escalated', 'Pending Feedback', '', NULL, '2026-02-19 13:52:34', '2026-02-19 13:52:34'),
(134, 35, 'Escalated', 'Pending Feedback', 'Intercesor', 'Testing the full functionality of the feedback submission', '2026-02-19 13:52:34', '2026-02-19 13:52:34'),
(135, 32, 'Escalated', 'Pending Feedback', '', NULL, '2026-02-19 14:19:47', '2026-02-19 14:19:47'),
(136, 32, 'Escalated', 'Pending Feedback', 'Intercesor', 'testing feedback', '2026-02-19 14:19:47', '2026-02-19 14:19:47'),
(137, 32, 'Pending Feedback', 'Resolved', '', NULL, '2026-02-20 06:48:49', '2026-02-20 06:48:49'),
(138, 37, 'Escalated', 'Pending Feedback', 'mandainterbicenhle@gmail.com', NULL, '2026-02-20 07:34:55', '2026-02-20 07:34:55'),
(139, 37, 'Escalated', 'Pending Feedback', 'Intercesor', 'testing the full workflow of feedback', '2026-02-20 07:34:55', '2026-02-20 07:34:55'),
(140, 37, 'Pending Feedback', 'Resolved', 'mandainterbicenhle@gmail.com', NULL, '2026-02-20 12:57:17', '2026-02-20 12:57:17');

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

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `Username` varchar(100) NOT NULL,
  `department` varchar(100) NOT NULL,
  `division_id` int(11) NOT NULL,
  `Email` varchar(255) NOT NULL,
  `Password` varchar(255) NOT NULL,
  `Created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `Updated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `Username`, `department`, `division_id`, `Email`, `Password`, `Created_at`, `Updated_at`, `reset_token`, `reset_expires`) VALUES
(1, 'Nkosenhlep', 'ICT', 10, 'nkosenhlep@pspf.co.sz', '$2y$10$V1M0QtBQ6X47OCcD6FAug.H/kHPfhaZyzj5CWEwDkwCoEKd4Mcoey', '2025-11-20 09:06:57', '2025-11-20 09:06:57', NULL, NULL),
(4, 'Simphiwes', 'ICT', 10, 'simphiwes@pspf.co.sz', '$2y$10$hmJXQFHjjKIp74nMdlq6dui5e803c0rEmBnNIof2KttXTchTYLcHC', '2025-11-25 10:36:35', '2025-11-25 10:36:35', NULL, NULL),
(5, 'Intercesor', 'Finance', 6, 'mandainterbicenhle@gmail.com', '$2y$10$E6N0wTlR/AhG1xPo2AITZuNIlo5o1zr6/MUtfGLXlcvs.yIP2wA0a', '2025-12-17 06:35:12', '2025-12-17 06:35:12', NULL, NULL),
(7, 'simphiwe', 'Finance', 7, 'simphiweshongwe11@gmail.com', '$2y$10$ODFLn7heinGf/LgcG4AiueT0UtQ.jXCWfNH1hpDX3FbobFCtWSSXC', '2026-01-19 12:39:18', '2026-01-19 12:39:18', NULL, NULL),
(8, 'Ncamiso', 'Finance', 7, 'Ncamiso@pspf.co.sz', '$2y$10$as1ON4/BSEivzdBbcPquUeGhBwBgMWEEl3b2j/6MQrIJgolETAC4y', '2026-01-19 12:39:45', '2026-01-19 12:39:45', NULL, NULL),
(9, 'Thuthukani Maziya', 'Corporate Services', 4, 'thuthukanim@pspf.co.sz', '$2y$10$xoZADS3yW8KmsrKEAg5Q8u9y8FzbOyrlq5Wxqu0mgI5...0hh20Pa', '2026-01-21 09:20:37', '2026-01-21 09:20:37', NULL, NULL),
(10, 'Tibuyile', 'Corporate Services', 3, 'tibuyile@pspf.co.sz', '$2y$10$VkCx3cmhjL32DCV8VKEPQe6uyM13aDzubEBhjLmc8vbMCLLL/w03m', '2026-01-21 09:49:58', '2026-01-21 09:49:58', NULL, NULL),
(11, 'Siphamandlad', 'Corporate Services', 3, 'siphamandlad@pspf.co.sz', '$2y$10$UfaJf7MG9o267kVH63.yGuusp3fmHlxVTxhc7QmVdV/HjVhc./kXS', '2026-02-04 08:05:03', '2026-02-04 08:05:03', NULL, NULL),
(12, 'administrator', 'CEO Office', 2, 'a@gmail.com', '$2y$10$/phCTxpDjlrIBK/3JhJHRuUjKA61lHqVEJQg4ss/VyWsBqbqxO4am', '2026-02-04 12:21:26', '2026-02-04 12:21:26', NULL, NULL),
(13, 'Thabang', 'Corporate Services', 4, 'thabang@pspf.co.sz', '$2y$10$mgvaWOJNb6wJI7NG/clenex0tsdRvZZ3Fcok05U94vQ2YygCeewKe', '2026-02-05 07:45:09', '2026-02-05 07:45:09', NULL, NULL),
(14, 'Sam', 'ICT', 10, 'samuel@pspf.co.sz', '$2y$10$mcNBqzzBrAY1qXO.pSVH6uIX.1G4auhevJ.2pX7LN/s9mlDaWszs6', '2026-02-13 12:38:20', '2026-02-13 12:38:20', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

CREATE TABLE `user_roles` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_roles`
--

INSERT INTO `user_roles` (`id`, `user_id`, `role_id`, `created_at`) VALUES
(5, 1, 4, '2025-11-20 12:18:28'),
(12, 7, 1, '2026-01-19 12:43:29'),
(15, 7, 4, '2026-01-19 13:32:35'),
(17, 1, 2, '2026-01-21 08:31:10'),
(18, 1, 1, '2026-01-21 08:49:30'),
(19, 9, 1, '2026-01-21 09:21:07'),
(22, 10, 1, '2026-01-21 09:50:17'),
(23, 10, 2, '2026-01-21 09:50:23'),
(25, 1, 3, '2026-01-26 07:20:12'),
(27, 4, 1, '2026-01-27 14:10:17'),
(29, 5, 1, '2026-01-30 13:15:56'),
(30, 8, 1, '2026-01-30 13:16:06'),
(31, 5, 4, '2026-01-30 13:37:14'),
(32, 8, 4, '2026-01-30 13:37:21'),
(33, 5, 2, '2026-01-30 13:46:43'),
(34, 8, 2, '2026-01-30 13:46:49'),
(35, 7, 2, '2026-01-30 13:46:55'),
(36, 4, 4, '2026-01-30 13:47:13'),
(37, 4, 2, '2026-01-30 13:47:22'),
(38, 14, 1, '2026-02-13 12:39:23'),
(39, 14, 4, '2026-02-13 12:39:28'),
(40, 14, 2, '2026-02-13 12:39:36'),
(41, 14, 3, '2026-02-13 12:39:41'),
(44, 7, 3, '2026-02-13 12:50:51');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `divisions`
--
ALTER TABLE `divisions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `department_id` (`department_id`);

--
-- Indexes for table `escalations`
--
ALTER TABLE `escalations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_id` (`ticket_id`);

--
-- Indexes for table `feedback_tokens`
--
ALTER TABLE `feedback_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `ticket_id` (`ticket_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_outlet_id` (`outlet_id`),
  ADD KEY `idx_order_date` (`order_date`);

--
-- Indexes for table `outlets`
--
ALTER TABLE `outlets`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `tickets`
--
ALTER TABLE `tickets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `closure_id` (`division_id`);

--
-- Indexes for table `ticket_assignments`
--
ALTER TABLE `ticket_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_assigned_to` (`assigned_to`),
  ADD KEY `idx_assigned_at` (`assigned_at`),
  ADD KEY `idx_ticket_id` (`ticket_id`);

--
-- Indexes for table `ticket_closures`
--
ALTER TABLE `ticket_closures`
  ADD PRIMARY KEY (`closure_id`),
  ADD KEY `ticket_id` (`ticket_id`);

--
-- Indexes for table `ticket_escalations`
--
ALTER TABLE `ticket_escalations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_id` (`ticket_id`);

--
-- Indexes for table `ticket_feedback`
--
ALTER TABLE `ticket_feedback`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ticket_id` (`ticket_id`),
  ADD KEY `fk_feedback_user` (`user_id`);

--
-- Indexes for table `ticket_history`
--
ALTER TABLE `ticket_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_id` (`ticket_id`);

--
-- Indexes for table `ticket_progress`
--
ALTER TABLE `ticket_progress`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_id` (`ticket_id`);

--
-- Indexes for table `ticket_reopens`
--
ALTER TABLE `ticket_reopens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_id` (`ticket_id`);

--
-- Indexes for table `ticket_resolved`
--
ALTER TABLE `ticket_resolved`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_id` (`ticket_id`),
  ADD KEY `resolved_by` (`resolved_by`),
  ADD KEY `closed_by` (`closed_by`);

--
-- Indexes for table `ticket_status_logs`
--
ALTER TABLE `ticket_status_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ticket_id` (`ticket_id`),
  ADD KEY `idx_change_date` (`change_date`);

--
-- Indexes for table `ticket_success`
--
ALTER TABLE `ticket_success`
  ADD PRIMARY KEY (`ticket_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `role_id` (`role_id`),
  ADD KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `divisions`
--
ALTER TABLE `divisions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `escalations`
--
ALTER TABLE `escalations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `feedback_tokens`
--
ALTER TABLE `feedback_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `outlets`
--
ALTER TABLE `outlets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tickets`
--
ALTER TABLE `tickets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=52;

--
-- AUTO_INCREMENT for table `ticket_assignments`
--
ALTER TABLE `ticket_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `ticket_closures`
--
ALTER TABLE `ticket_closures`
  MODIFY `closure_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `ticket_escalations`
--
ALTER TABLE `ticket_escalations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `ticket_feedback`
--
ALTER TABLE `ticket_feedback`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `ticket_history`
--
ALTER TABLE `ticket_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `ticket_progress`
--
ALTER TABLE `ticket_progress`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `ticket_reopens`
--
ALTER TABLE `ticket_reopens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `ticket_resolved`
--
ALTER TABLE `ticket_resolved`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `ticket_status_logs`
--
ALTER TABLE `ticket_status_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=141;

--
-- AUTO_INCREMENT for table `ticket_success`
--
ALTER TABLE `ticket_success`
  MODIFY `ticket_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `user_roles`
--
ALTER TABLE `user_roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `divisions`
--
ALTER TABLE `divisions`
  ADD CONSTRAINT `divisions_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`);

--
-- Constraints for table `feedback_tokens`
--
ALTER TABLE `feedback_tokens`
  ADD CONSTRAINT `feedback_tokens_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tickets`
--
ALTER TABLE `tickets`
  ADD CONSTRAINT `tickets_ibfk_1` FOREIGN KEY (`division_id`) REFERENCES `divisions` (`id`);

--
-- Constraints for table `ticket_assignments`
--
ALTER TABLE `ticket_assignments`
  ADD CONSTRAINT `ticket_assignments_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ticket_feedback`
--
ALTER TABLE `ticket_feedback`
  ADD CONSTRAINT `fk_feedback_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_feedback_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ticket_progress`
--
ALTER TABLE `ticket_progress`
  ADD CONSTRAINT `ticket_progress_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`);

--
-- Constraints for table `ticket_resolved`
--
ALTER TABLE `ticket_resolved`
  ADD CONSTRAINT `ticket_resolved_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ticket_resolved_ibfk_2` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ticket_resolved_ibfk_3` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ticket_status_logs`
--
ALTER TABLE `ticket_status_logs`
  ADD CONSTRAINT `ticket_status_logs_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD CONSTRAINT `user_roles_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  ADD CONSTRAINT `user_roles_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
