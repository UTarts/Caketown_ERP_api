-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 30, 2026 at 11:19 AM
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
-- Database: `caketown_v2`
--

-- --------------------------------------------------------

--
-- Table structure for table `advance_ledger`
--

CREATE TABLE `advance_ledger` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `logged_by` int(11) NOT NULL COMMENT 'user_id of manager or admin who logged this entry',
  `type` enum('pre_advance','final_advance','shop_advance','shop_bill','fine','repayment','other') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `month` int(11) DEFAULT NULL COMMENT 'Which payroll month this belongs to',
  `year` int(11) DEFAULT NULL COMMENT 'Which payroll year this belongs to',
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `advance_ledger`
--

INSERT INTO `advance_ledger` (`id`, `user_id`, `branch_id`, `logged_by`, `type`, `amount`, `month`, `year`, `remarks`, `created_at`) VALUES
(1, 4, 1, 4, 'shop_advance', 500.00, 4, 2026, 'Emergency Advance', '2026-04-28 08:24:14');

-- --------------------------------------------------------

--
-- Table structure for table `attendance_overrides`
--

CREATE TABLE `attendance_overrides` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `override_status` enum('F','H','A','WO','PH') NOT NULL COMMENT 'F=Full, H=Half, A=Absent, WO=WeekOff, PH=PaidHoliday',
  `overridden_by` int(11) NOT NULL,
  `reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_punches`
--

CREATE TABLE `attendance_punches` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `punch_time` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance_punches`
--

INSERT INTO `attendance_punches` (`id`, `user_id`, `punch_time`) VALUES
(1, 2, '2026-04-23 18:01:34'),
(2, 2, '2026-04-23 18:09:37'),
(3, 2, '2026-04-23 20:42:07'),
(4, 2, '2026-04-23 22:27:50'),
(5, 2, '2026-04-24 11:24:50'),
(6, 2, '2026-04-24 15:18:35'),
(7, 2, '2026-04-27 01:32:32'),
(8, 2, '2026-04-27 01:32:47'),
(9, 2, '2026-04-27 01:39:32'),
(10, 2, '2026-04-27 16:48:30'),
(11, 2, '2026-04-27 16:48:36'),
(12, 2, '2026-04-27 16:48:52'),
(13, 2, '2026-04-28 00:54:48'),
(14, 2, '2026-04-28 00:55:03'),
(15, 2, '2026-04-28 00:55:20'),
(16, 2, '2026-04-28 00:55:36'),
(17, 2, '2026-04-28 13:46:30'),
(18, 2, '2026-04-28 13:49:09'),
(19, 2, '2026-04-28 18:47:55'),
(20, 2, '2026-04-28 18:48:24'),
(21, 2, '2026-04-28 18:48:36'),
(22, 2, '2026-04-28 20:54:22'),
(23, 2, '2026-04-29 01:44:57'),
(24, 2, '2026-04-29 01:56:11'),
(25, 2, '2026-04-29 01:56:47'),
(26, 2, '2026-04-30 04:17:12'),
(27, 2, '2026-04-30 04:17:29');

-- --------------------------------------------------------

--
-- Table structure for table `branches`
--

CREATE TABLE `branches` (
  `id` int(11) NOT NULL,
  `branch_name` varchar(100) NOT NULL,
  `address` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `branches`
--

INSERT INTO `branches` (`id`, `branch_name`, `address`, `status`, `created_at`) VALUES
(1, 'Caketown Chowk', 'Bharat Chowk, Ghantaghar, Pratpgarh', 'active', '2026-04-20 06:43:31');

-- --------------------------------------------------------

--
-- Table structure for table `branch_leave_rules`
--

CREATE TABLE `branch_leave_rules` (
  `id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `min_working_days` decimal(4,1) NOT NULL,
  `earned_paid_leaves` int(11) NOT NULL,
  `max_leaves_cap` int(11) NOT NULL DEFAULT 4 COMMENT '4 or 2 - which scenario this row belongs to',
  `max_days_exclusive` decimal(4,1) DEFAULT NULL COMMENT 'Upper bound (exclusive) of working days range. NULL = no upper limit (last tier)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `branch_leave_rules`
--

INSERT INTO `branch_leave_rules` (`id`, `branch_id`, `min_working_days`, `earned_paid_leaves`, `max_leaves_cap`, `max_days_exclusive`) VALUES
(1, 1, 0.0, 0, 4, 10.0),
(2, 1, 10.0, 1, 4, 14.0),
(3, 1, 14.0, 2, 4, 20.0),
(4, 1, 20.0, 3, 4, 24.0),
(5, 1, 24.0, 4, 4, NULL),
(6, 1, 0.0, 0, 2, 14.0),
(7, 1, 14.0, 1, 2, 24.0),
(8, 1, 24.0, 2, 2, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `daily_attendance_ledger`
--

CREATE TABLE `daily_attendance_ledger` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `date` date NOT NULL,
  `total_active_minutes` int(11) DEFAULT 0,
  `status` enum('full_day','half_day','absent','holiday','pending') DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employee_contracts`
--

CREATE TABLE `employee_contracts` (
  `user_id` int(11) NOT NULL,
  `monthly_fixed_salary` decimal(10,2) NOT NULL,
  `monthly_paid_leaves` int(11) DEFAULT 0,
  `max_advance_percentage` decimal(5,2) DEFAULT 30.00,
  `standard_shift_hours` decimal(4,2) DEFAULT 9.00,
  `week_off_day` varchar(20) DEFAULT NULL,
  `max_paid_leaves_cap` int(11) NOT NULL DEFAULT 4 COMMENT '2 or 4 - determines which leave tier formula applies',
  `pre_advance_balance` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Running outstanding pre-advance balance',
  `final_advance_balance` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Running outstanding final-advance balance'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employee_contracts`
--

INSERT INTO `employee_contracts` (`user_id`, `monthly_fixed_salary`, `monthly_paid_leaves`, `max_advance_percentage`, `standard_shift_hours`, `week_off_day`, `max_paid_leaves_cap`, `pre_advance_balance`, `final_advance_balance`) VALUES
(2, 10000.00, 4, 30.00, 9.00, 'Sunday', 4, 0.00, 0.00),
(3, 0.00, 0, 30.00, 9.00, NULL, 4, 0.00, 0.00),
(4, 15000.00, 4, 30.00, 10.00, 'Sunday', 4, 0.00, 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `face_registration_log`
--

CREATE TABLE `face_registration_log` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL COMMENT 'User whose face was registered',
  `registered_by` int(11) NOT NULL COMMENT 'Manager or admin who performed the registration',
  `branch_id` int(11) DEFAULT NULL,
  `registered_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `face_registration_log`
--

INSERT INTO `face_registration_log` (`id`, `employee_id`, `registered_by`, `branch_id`, `registered_at`, `notes`) VALUES
(1, 2, 4, 1, '2026-04-28 04:48:44', NULL),
(2, 2, 4, 1, '2026-04-28 05:16:50', NULL),
(3, 2, 4, 1, '2026-04-28 06:32:13', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `financial_deductions`
--

CREATE TABLE `financial_deductions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `type` enum('advance','shop_bill','fine') NOT NULL,
  `remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `monthly_paystubs`
--

CREATE TABLE `monthly_paystubs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `month` int(11) NOT NULL,
  `year` int(11) NOT NULL,
  `total_days_in_month` int(11) NOT NULL,
  `worked_days` decimal(4,1) NOT NULL,
  `earned_leaves_applied` int(11) DEFAULT 0,
  `gross_salary` decimal(10,2) NOT NULL,
  `total_advances` decimal(10,2) DEFAULT 0.00,
  `total_shop_bills` decimal(10,2) DEFAULT 0.00,
  `net_payable_salary` decimal(10,2) NOT NULL,
  `status` enum('generated','paid') DEFAULT 'generated'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `monthly_paystubs`
--

INSERT INTO `monthly_paystubs` (`id`, `user_id`, `month`, `year`, `total_days_in_month`, `worked_days`, `earned_leaves_applied`, `gross_salary`, `total_advances`, `total_shop_bills`, `net_payable_salary`, `status`) VALUES
(1, 2, 4, 2026, 30, 1.0, 0, 0.00, 0.00, 0.00, 0.00, 'paid'),
(3, 4, 4, 2026, 30, 0.0, 0, 15000.00, 0.00, 0.00, 0.00, 'paid');

-- --------------------------------------------------------

--
-- Table structure for table `payroll_ledgers`
--

CREATE TABLE `payroll_ledgers` (
  `id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `payroll_month` int(11) NOT NULL,
  `payroll_year` int(11) NOT NULL,
  `total_duty` float NOT NULL,
  `paid_leaves` int(11) NOT NULL,
  `paid_duty` float NOT NULL,
  `base_salary` decimal(10,2) NOT NULL,
  `pre_advance` decimal(10,2) DEFAULT 0.00,
  `final_advance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `shop_advance` decimal(10,2) DEFAULT 0.00,
  `shop_bill` decimal(10,2) DEFAULT 0.00,
  `total_advance` decimal(10,2) DEFAULT 0.00,
  `deduction` decimal(10,2) DEFAULT 0.00,
  `salary_to_pay` decimal(10,2) NOT NULL,
  `status` varchar(50) DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `paid_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Actual cash paid to employee',
  `advance_due` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Outstanding advance after this month salary',
  `remarks` text DEFAULT NULL,
  `finalized_by` int(11) DEFAULT NULL COMMENT 'user_id of who locked the payroll',
  `finalized_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payroll_ledgers`
--

INSERT INTO `payroll_ledgers` (`id`, `branch_id`, `user_id`, `payroll_month`, `payroll_year`, `total_duty`, `paid_leaves`, `paid_duty`, `base_salary`, `pre_advance`, `final_advance`, `shop_advance`, `shop_bill`, `total_advance`, `deduction`, `salary_to_pay`, `status`, `created_at`, `paid_amount`, `advance_due`, `remarks`, `finalized_by`, `finalized_at`, `updated_at`) VALUES
(1, 1, 2, 4, 2026, 1, 0, 1, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 'paid', '2026-04-26 19:48:44', 0.00, 0.00, 'Marked paid via payroll master page', 3, '2026-04-28 08:23:24', '2026-04-28 08:23:24'),
(3, 1, 4, 4, 2026, 0, 0, 0, 15000.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 'paid', '2026-04-28 08:23:20', 0.00, 0.00, 'Marked paid via payroll master page', 3, '2026-04-28 08:23:27', '2026-04-28 08:23:27');

-- --------------------------------------------------------

--
-- Table structure for table `system_logs`
--

CREATE TABLE `system_logs` (
  `id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action_type` varchar(50) NOT NULL,
  `description` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_logs`
--

INSERT INTO `system_logs` (`id`, `branch_id`, `user_id`, `action_type`, `description`, `created_at`) VALUES
(1, 1, NULL, 'AUTH_LOGIN', 'Amit Garg logged into the system.', '2026-04-20 18:42:49'),
(2, 1, NULL, 'USER_CREATED', 'New manager (Test Manager 1) was registered.', '2026-04-20 19:44:16'),
(3, 1, 2, 'AUTH_LOGIN', 'Test Manager 1 logged into the system.', '2026-04-20 19:44:37'),
(4, 1, NULL, 'AUTH_LOGIN', 'Amit Garg logged into the system.', '2026-04-23 11:09:56'),
(5, 1, 2, 'AUTH_LOGIN', 'Test Manager 1 logged into the system.', '2026-04-23 11:11:30'),
(6, 1, 2, 'FACE_REGISTERED', 'Biometric data updated for user ID: 2.', '2026-04-23 11:26:06'),
(7, 1, 2, 'FACE_REGISTERED', 'Biometrics updated for UID: 2.', '2026-04-23 12:27:23'),
(8, 1, NULL, 'AUTH_LOGIN', 'Amit Garg logged in.', '2026-04-23 17:29:33'),
(9, NULL, NULL, 'USER_CREATED', 'New admin (UT Arts) registered.', '2026-04-23 17:43:05'),
(10, NULL, 3, 'AUTH_LOGIN', 'UT Arts logged in.', '2026-04-24 04:55:04'),
(11, 1, 2, 'AUTH_LOGIN', 'Test Manager 1 logged in.', '2026-04-24 05:52:20'),
(12, 1, NULL, 'USER_CREATED', 'New manager \'Manager 2.0\' (ID:4) created.', '2026-04-26 19:48:24'),
(13, 1, 3, 'SALARY_PAID', 'Salary marked paid for Test Manager 1 for 4/2026, amount ₹0.', '2026-04-26 19:48:44'),
(14, 1, 3, 'SALARY_PAID', 'Salary marked paid for Test Manager 1 for 4/2026, amount ₹0.', '2026-04-26 19:48:45'),
(15, 1, 4, 'AUTH_LOGIN', 'Manager 2.0 logged in.', '2026-04-26 20:01:59'),
(16, 1, 2, 'PUNCH_LOGGED', 'Punch In recorded for Test Manager 1.', '2026-04-26 20:02:32'),
(17, 1, 2, 'PUNCH_LOGGED', 'Punch Out recorded for Test Manager 1.', '2026-04-26 20:02:47'),
(18, 1, NULL, 'USER_UPDATED', 'Employee \'Manager 2.0\' (ID:4) updated.', '2026-04-26 20:09:00'),
(19, 1, 2, 'PUNCH_LOGGED', 'Punch In recorded for Test Manager 1.', '2026-04-26 20:09:32'),
(20, 1, 4, 'AUTH_LOGIN', 'Manager 2.0 logged in.', '2026-04-27 07:27:46'),
(21, 1, 2, 'PUNCH_LOGGED', 'Punch Out recorded for Test Manager 1.', '2026-04-27 11:18:30'),
(22, 1, 2, 'PUNCH_LOGGED', 'Punch In recorded for Test Manager 1.', '2026-04-27 11:18:36'),
(23, 1, 2, 'PUNCH_LOGGED', 'Punch Out recorded for Test Manager 1.', '2026-04-27 11:18:52'),
(24, 1, 2, 'PUNCH_LOGGED', 'Punch In recorded for Test Manager 1.', '2026-04-27 19:24:48'),
(25, 1, 2, 'PUNCH_LOGGED', 'Punch Out recorded for Test Manager 1.', '2026-04-27 19:25:03'),
(26, 1, 2, 'PUNCH_LOGGED', 'Punch In recorded for Test Manager 1.', '2026-04-27 19:25:20'),
(27, 1, 2, 'PUNCH_LOGGED', 'Punch Out recorded for Test Manager 1.', '2026-04-27 19:25:36'),
(28, 1, 4, 'FACE_REGISTERED', 'Face biometric registered/updated for \'Test Manager 1\' (ID:2).', '2026-04-28 04:48:44'),
(29, 1, 4, 'FACE_REGISTERED', 'Face biometric registered/updated for \'Test Manager 1\' (ID:2).', '2026-04-28 05:16:50'),
(30, 1, 4, 'FACE_REGISTERED', 'Face biometric registered/updated for \'Test Manager 1\' (ID:2).', '2026-04-28 06:32:13'),
(31, 1, 2, 'PUNCH_LOGGED', 'Punch In recorded for Test Manager 1.', '2026-04-28 08:16:30'),
(32, 1, 2, 'PUNCH_LOGGED', 'Punch Out recorded for Test Manager 1.', '2026-04-28 08:19:09'),
(33, NULL, 3, 'AUTH_LOGIN', 'UT Arts logged in.', '2026-04-28 08:22:33'),
(34, 1, 3, 'SALARY_PAID', 'Salary marked paid for Manager 2.0 for 4/2026, amount ₹0.', '2026-04-28 08:23:20'),
(35, 1, 3, 'SALARY_PAID', 'Salary marked paid for Manager 2.0 for 4/2026, amount ₹0.', '2026-04-28 08:23:21'),
(36, 1, 3, 'SALARY_PAID', 'Salary marked paid for Test Manager 1 for 4/2026, amount ₹0.', '2026-04-28 08:23:23'),
(37, 1, 3, 'SALARY_PAID', 'Salary marked paid for Test Manager 1 for 4/2026, amount ₹0.', '2026-04-28 08:23:23'),
(38, 1, 3, 'SALARY_PAID', 'Salary marked paid for Test Manager 1 for 4/2026, amount ₹0.', '2026-04-28 08:23:23'),
(39, 1, 3, 'SALARY_PAID', 'Salary marked paid for Test Manager 1 for 4/2026, amount ₹0.', '2026-04-28 08:23:24'),
(40, 1, 3, 'SALARY_PAID', 'Salary marked paid for Test Manager 1 for 4/2026, amount ₹0.', '2026-04-28 08:23:24'),
(41, 1, 3, 'SALARY_PAID', 'Salary marked paid for Test Manager 1 for 4/2026, amount ₹0.', '2026-04-28 08:23:24'),
(42, 1, 3, 'SALARY_PAID', 'Salary marked paid for Manager 2.0 for 4/2026, amount ₹0.', '2026-04-28 08:23:27'),
(43, 1, 4, 'ADVANCE_LOGGED', 'shop_advance of ₹500 logged for Manager 2.0.', '2026-04-28 08:24:14'),
(44, 1, NULL, 'USER_UPDATED', 'Employee \'Test Manager 1\' (ID:2) updated.', '2026-04-28 08:25:17'),
(45, 1, 2, 'PUNCH_LOGGED', 'Punch In recorded for Test Manager 1.', '2026-04-28 13:17:55'),
(46, 1, 2, 'PUNCH_LOGGED', 'Punch Out recorded for Test Manager 1.', '2026-04-28 13:18:24'),
(47, 1, 2, 'PUNCH_LOGGED', 'Punch In recorded for Test Manager 1.', '2026-04-28 13:18:36'),
(48, 1, 2, 'PUNCH_LOGGED', 'Punch Out recorded for Test Manager 1.', '2026-04-28 15:24:22'),
(49, 1, 2, 'ATTENDANCE_IN', 'Test Manager 1 marked Punch In at branch ID 1.', '2026-04-28 20:14:57'),
(50, 1, 2, 'ATTENDANCE_OUT', 'Test Manager 1 marked Punch Out at branch ID 1.', '2026-04-28 20:26:11'),
(51, 1, 2, 'ATTENDANCE_IN', 'Test Manager 1 marked Punch In at branch ID 1.', '2026-04-28 20:26:47'),
(52, 1, 2, 'ATTENDANCE_IN', 'Test Manager 1 marked Punch In at branch ID 1.', '2026-04-29 22:47:12'),
(53, 1, 2, 'ATTENDANCE_OUT', 'Test Manager 1 marked Punch Out at branch ID 1.', '2026-04-29 22:47:29');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `role` enum('admin','manager','staff') NOT NULL,
  `department` varchar(100) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `mobile_number` varchar(15) NOT NULL,
  `password` varchar(255) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `feature_permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`feature_permissions`)),
  `face_descriptor` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `branch_id`, `role`, `department`, `name`, `mobile_number`, `password`, `status`, `feature_permissions`, `face_descriptor`, `created_at`, `updated_at`) VALUES
(2, 1, 'manager', NULL, 'Test Manager 1', '8965485896', '$2y$10$HyL41D4HgbazojkefsvvIekRht2c03LUjTq5tX/efDPVDAU.fnC0O', 'active', '[]', '[-0.20987623929977417,0.050573647022247314,0.07184315472841263,0.009109726175665855,-0.11538089066743851,-0.02311897836625576,0.023694759234786034,-0.020323649048805237,0.08985752612352371,-0.03389113396406174,0.23995165526866913,-0.061651431024074554,-0.22368597984313965,-0.03939816355705261,-0.020484600216150284,0.07661648839712143,-0.07497888058423996,-0.1437568962574005,-0.029654916375875473,-0.11979574710130692,0.03465793654322624,-0.011952415108680725,-0.011161447502672672,0.07009785622358322,-0.1989530771970749,-0.2909795939922333,-0.08793969452381134,-0.1247231513261795,0.079556405544281,-0.11471032351255417,-0.09804695844650269,-0.02736496925354004,-0.20185071229934692,-0.10835524648427963,-0.004055577330291271,0.08090682327747345,0.04742061719298363,-0.054346632212400436,0.14898324012756348,-0.04151096194982529,-0.061923906207084656,-0.04381934925913811,0.06539103388786316,0.3664582371711731,0.10495107620954514,0.05022219568490982,0.0020586736500263214,0.048602551221847534,0.043345920741558075,-0.24189616739749908,0.09468132257461548,0.11284923553466797,0.10632817447185516,0.03802066296339035,0.05368485674262047,-0.07299519330263138,0.00903482548892498,0.14913657307624817,-0.18760526180267334,0.13377493619918823,0.039990611374378204,0.011511096730828285,0.01821061596274376,-0.10582797974348068,0.30728739500045776,0.10435505956411362,-0.10325893759727478,-0.07979805767536163,0.10547370463609695,-0.16079367697238922,-0.03103644773364067,-0.04214978590607643,-0.109845831990242,-0.1396188884973526,-0.2707815170288086,0.09973684698343277,0.3791256844997406,0.20154015719890594,-0.18083085119724274,0.09905273467302322,-0.12091132253408432,-0.036285459995269775,0.07681103050708771,-0.0012634219601750374,-0.06074734032154083,0.0742335245013237,-0.14127740263938904,0.04949267581105232,0.21012653410434723,0.016134697943925858,0.015735188499093056,0.19709926843643188,-0.004781709983944893,-0.03846808522939682,0.07856789976358414,0.018230361863970757,-0.15837930142879486,-0.05403299257159233,-0.08151955157518387,0.008965708315372467,-0.0012383852154016495,-0.10005635023117065,0.04806610196828842,0.1193414255976677,-0.19118665158748627,0.08795895427465439,-0.01886802539229393,-0.003199942409992218,-0.04205959290266037,0.049867894500494,-0.11065322160720825,-0.0920531153678894,0.10316300392150879,-0.2520557940006256,0.12343255430459976,0.14494945108890533,0.013876368291676044,0.14868195354938507,0.0516531877219677,0.028202073648571968,0.018692145124077797,0.06829607486724854,-0.07751555740833282,-0.05846599489450455,0.03913704678416252,-0.09773866087198257,0.08607643842697144,0.09625779092311859]', '2026-04-26 18:16:38', '2026-04-28 08:25:17'),
(3, NULL, 'admin', NULL, 'UT Arts', '8595881108', '$2y$10$CcjYP/pikzQLeo6gkGT6IuF9gC6LN5au2D9s/5ILrB.sdEw7OY0rS', 'active', NULL, NULL, '2026-04-26 18:16:38', '2026-04-26 18:16:38'),
(4, 1, 'manager', NULL, 'Manager 2.0', '1111111111', '$2y$10$4WoplpkaBf3Ln0H3Jo4B6uOjkZoVHGmCJaf3wJzhn47KjQyJHIbtC', 'active', '{\"view_live_attendance\":{\"read\":true,\"write\":false},\"view_attendance_history\":{\"read\":true,\"write\":false},\"manage_terminal\":{\"read\":false,\"write\":true},\"register_face\":{\"read\":false,\"write\":true},\"view_payroll\":{\"read\":true,\"write\":false},\"log_advance\":{\"read\":false,\"write\":true},\"log_shop_bill\":{\"read\":false,\"write\":true},\"log_shop_advance\":{\"read\":false,\"write\":true},\"view_staff_list\":{\"read\":true,\"write\":false},\"view_staff_profile\":{\"read\":true,\"write\":false}}', NULL, '2026-04-26 19:48:24', '2026-04-26 19:48:24');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `advance_ledger`
--
ALTER TABLE `advance_ledger`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `branch_id` (`branch_id`),
  ADD KEY `logged_by` (`logged_by`);

--
-- Indexes for table `attendance_overrides`
--
ALTER TABLE `attendance_overrides`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_date_unique` (`user_id`,`date`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `overridden_by` (`overridden_by`);

--
-- Indexes for table `attendance_punches`
--
ALTER TABLE `attendance_punches`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `branch_leave_rules`
--
ALTER TABLE `branch_leave_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `branch_id` (`branch_id`);

--
-- Indexes for table `daily_attendance_ledger`
--
ALTER TABLE `daily_attendance_ledger`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_date_unique` (`user_id`,`date`);

--
-- Indexes for table `employee_contracts`
--
ALTER TABLE `employee_contracts`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `face_registration_log`
--
ALTER TABLE `face_registration_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee_id`),
  ADD KEY `registered_by` (`registered_by`),
  ADD KEY `face_reg_log_ibfk_3` (`branch_id`);

--
-- Indexes for table `financial_deductions`
--
ALTER TABLE `financial_deductions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `monthly_paystubs`
--
ALTER TABLE `monthly_paystubs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_month_year` (`user_id`,`month`,`year`);

--
-- Indexes for table `payroll_ledgers`
--
ALTER TABLE `payroll_ledgers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_monthly_ledger` (`user_id`,`payroll_month`,`payroll_year`),
  ADD KEY `branch_id` (`branch_id`);

--
-- Indexes for table `system_logs`
--
ALTER TABLE `system_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `branch_id` (`branch_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `mobile_number` (`mobile_number`),
  ADD KEY `branch_id` (`branch_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `advance_ledger`
--
ALTER TABLE `advance_ledger`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `attendance_overrides`
--
ALTER TABLE `attendance_overrides`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance_punches`
--
ALTER TABLE `attendance_punches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `branches`
--
ALTER TABLE `branches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `branch_leave_rules`
--
ALTER TABLE `branch_leave_rules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `daily_attendance_ledger`
--
ALTER TABLE `daily_attendance_ledger`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `face_registration_log`
--
ALTER TABLE `face_registration_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `financial_deductions`
--
ALTER TABLE `financial_deductions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `monthly_paystubs`
--
ALTER TABLE `monthly_paystubs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `payroll_ledgers`
--
ALTER TABLE `payroll_ledgers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `system_logs`
--
ALTER TABLE `system_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=54;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `advance_ledger`
--
ALTER TABLE `advance_ledger`
  ADD CONSTRAINT `advance_ledger_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `advance_ledger_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `advance_ledger_ibfk_3` FOREIGN KEY (`logged_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `attendance_overrides`
--
ALTER TABLE `attendance_overrides`
  ADD CONSTRAINT `att_override_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `att_override_ibfk_2` FOREIGN KEY (`overridden_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `attendance_punches`
--
ALTER TABLE `attendance_punches`
  ADD CONSTRAINT `attendance_punches_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `branch_leave_rules`
--
ALTER TABLE `branch_leave_rules`
  ADD CONSTRAINT `branch_leave_rules_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `daily_attendance_ledger`
--
ALTER TABLE `daily_attendance_ledger`
  ADD CONSTRAINT `daily_attendance_ledger_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `employee_contracts`
--
ALTER TABLE `employee_contracts`
  ADD CONSTRAINT `employee_contracts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `face_registration_log`
--
ALTER TABLE `face_registration_log`
  ADD CONSTRAINT `face_reg_log_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `face_reg_log_ibfk_2` FOREIGN KEY (`registered_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `face_reg_log_ibfk_3` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `financial_deductions`
--
ALTER TABLE `financial_deductions`
  ADD CONSTRAINT `financial_deductions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `monthly_paystubs`
--
ALTER TABLE `monthly_paystubs`
  ADD CONSTRAINT `monthly_paystubs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payroll_ledgers`
--
ALTER TABLE `payroll_ledgers`
  ADD CONSTRAINT `payroll_ledgers_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payroll_ledgers_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `system_logs`
--
ALTER TABLE `system_logs`
  ADD CONSTRAINT `system_logs_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `system_logs_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
