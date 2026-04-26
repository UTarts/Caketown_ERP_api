-- ============================================================
-- CAKETOWN VAULT - DATABASE MIGRATION v2
-- Run this ONCE on your existing database.
-- It is safe to run: uses ALTER TABLE IF NOT EXISTS pattern
-- and only ADDS new columns / tables without touching existing data.
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+05:30";

-- ============================================================
-- 1. PATCH: users table
-- Add created_at and updated_at for audit trail
-- ============================================================
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  ADD COLUMN IF NOT EXISTS `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp();

-- ============================================================
-- 2. PATCH: employee_contracts table
-- Add max_paid_leaves_cap (2 or 4), pre_advance running balance,
-- final_advance running balance
-- ============================================================
ALTER TABLE `employee_contracts`
  ADD COLUMN IF NOT EXISTS `max_paid_leaves_cap` int(11) NOT NULL DEFAULT 4 COMMENT '2 or 4 - determines which leave tier formula applies',
  ADD COLUMN IF NOT EXISTS `pre_advance_balance` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Running outstanding pre-advance balance',
  ADD COLUMN IF NOT EXISTS `final_advance_balance` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Running outstanding final-advance balance';

-- ============================================================
-- 3. PATCH: payroll_ledgers table
-- Add final_advance, remarks, paid_amount, advance_due to match
-- the client spreadsheet Spreadsheet 2 exactly.
-- Spreadsheet 2 columns:
-- DEPARTMENT | SALARY | PRE ADVANCE | FINAL ADVANCE | SHOP ADVANCE
-- | SHOP BILL | PAID LEAVES | TOTAL DUTY | PAID DUTY
-- | TOTAL ADVANCE | DEDUCTION | SALARY TO PAY | PAID | ADVANCE/DUE | REMARK
-- ============================================================
ALTER TABLE `payroll_ledgers`
  ADD COLUMN IF NOT EXISTS `final_advance` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `pre_advance`,
  ADD COLUMN IF NOT EXISTS `paid_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Actual cash paid to employee',
  ADD COLUMN IF NOT EXISTS `advance_due` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Outstanding advance after this month salary',
  ADD COLUMN IF NOT EXISTS `remarks` text DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `finalized_by` int(11) DEFAULT NULL COMMENT 'user_id of who locked the payroll',
  ADD COLUMN IF NOT EXISTS `finalized_at` timestamp NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp();

-- ============================================================
-- 4. NEW TABLE: advance_ledger
-- Every single penny of advance logged with full audit trail.
-- This powers the hover-to-see-timestamp feature on the frontend.
-- type: pre_advance | final_advance | shop_advance | shop_bill | fine | repayment
-- ============================================================
CREATE TABLE IF NOT EXISTS `advance_ledger` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `logged_by` int(11) NOT NULL COMMENT 'user_id of manager or admin who logged this entry',
  `type` enum('pre_advance','final_advance','shop_advance','shop_bill','fine','repayment','other') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `month` int(11) DEFAULT NULL COMMENT 'Which payroll month this belongs to',
  `year` int(11) DEFAULT NULL COMMENT 'Which payroll year this belongs to',
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `branch_id` (`branch_id`),
  KEY `logged_by` (`logged_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `advance_ledger`
  ADD CONSTRAINT IF NOT EXISTS `advance_ledger_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT IF NOT EXISTS `advance_ledger_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT IF NOT EXISTS `advance_ledger_ibfk_3` FOREIGN KEY (`logged_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- ============================================================
-- 5. PATCH: branch_leave_rules table
-- Add max_leaves_cap so we store both the 2-cap and 4-cap
-- tier tables per branch.
-- Scenario A (cap=4): 0-9d=0, 10-13d=1, 14-19d=2, 20-23d=3, 24+d=4
-- Scenario B (cap=2): 0-13d=0, 14-23d=1, 24+d=2
-- ============================================================
ALTER TABLE `branch_leave_rules`
  ADD COLUMN IF NOT EXISTS `max_leaves_cap` int(11) NOT NULL DEFAULT 4 COMMENT '4 or 2 - which scenario this row belongs to',
  ADD COLUMN IF NOT EXISTS `max_days_exclusive` decimal(4,1) DEFAULT NULL COMMENT 'Upper bound (exclusive) of working days range. NULL = no upper limit (last tier)';

-- Seed the default leave rules for branch 1 (Caketown Chowk)
-- Only inserts if the table is empty to avoid duplicates
INSERT INTO `branch_leave_rules` (`branch_id`, `min_working_days`, `earned_paid_leaves`, `max_leaves_cap`, `max_days_exclusive`)
SELECT 1, 0,  0, 4, 10  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM branch_leave_rules WHERE branch_id=1 AND max_leaves_cap=4 AND min_working_days=0);
INSERT INTO `branch_leave_rules` (`branch_id`, `min_working_days`, `earned_paid_leaves`, `max_leaves_cap`, `max_days_exclusive`)
SELECT 1, 10, 1, 4, 14  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM branch_leave_rules WHERE branch_id=1 AND max_leaves_cap=4 AND min_working_days=10);
INSERT INTO `branch_leave_rules` (`branch_id`, `min_working_days`, `earned_paid_leaves`, `max_leaves_cap`, `max_days_exclusive`)
SELECT 1, 14, 2, 4, 20  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM branch_leave_rules WHERE branch_id=1 AND max_leaves_cap=4 AND min_working_days=14);
INSERT INTO `branch_leave_rules` (`branch_id`, `min_working_days`, `earned_paid_leaves`, `max_leaves_cap`, `max_days_exclusive`)
SELECT 1, 20, 3, 4, 24  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM branch_leave_rules WHERE branch_id=1 AND max_leaves_cap=4 AND min_working_days=20);
INSERT INTO `branch_leave_rules` (`branch_id`, `min_working_days`, `earned_paid_leaves`, `max_leaves_cap`, `max_days_exclusive`)
SELECT 1, 24, 4, 4, NULL FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM branch_leave_rules WHERE branch_id=1 AND max_leaves_cap=4 AND min_working_days=24);

INSERT INTO `branch_leave_rules` (`branch_id`, `min_working_days`, `earned_paid_leaves`, `max_leaves_cap`, `max_days_exclusive`)
SELECT 1, 0,  0, 2, 14  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM branch_leave_rules WHERE branch_id=1 AND max_leaves_cap=2 AND min_working_days=0);
INSERT INTO `branch_leave_rules` (`branch_id`, `min_working_days`, `earned_paid_leaves`, `max_leaves_cap`, `max_days_exclusive`)
SELECT 1, 14, 1, 2, 24  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM branch_leave_rules WHERE branch_id=1 AND max_leaves_cap=2 AND min_working_days=14);
INSERT INTO `branch_leave_rules` (`branch_id`, `min_working_days`, `earned_paid_leaves`, `max_leaves_cap`, `max_days_exclusive`)
SELECT 1, 24, 2, 2, NULL FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM branch_leave_rules WHERE branch_id=1 AND max_leaves_cap=2 AND min_working_days=24);

-- ============================================================
-- 6. NEW TABLE: face_registration_log
-- Full audit trail of every face registration event
-- ============================================================
CREATE TABLE IF NOT EXISTS `face_registration_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL COMMENT 'User whose face was registered',
  `registered_by` int(11) NOT NULL COMMENT 'Manager or admin who performed the registration',
  `branch_id` int(11) DEFAULT NULL,
  `registered_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  KEY `registered_by` (`registered_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `face_registration_log`
  ADD CONSTRAINT IF NOT EXISTS `face_reg_log_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT IF NOT EXISTS `face_reg_log_ibfk_2` FOREIGN KEY (`registered_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- ============================================================
-- 7. NEW TABLE: attendance_overrides
-- Allows admin to manually override F/H/A status for a specific day
-- (e.g. mark a missed-punch day as Full Day after reviewing)
-- ============================================================
CREATE TABLE IF NOT EXISTS `attendance_overrides` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `override_status` enum('F','H','A','WO','PH') NOT NULL COMMENT 'F=Full, H=Half, A=Absent, WO=WeekOff, PH=PaidHoliday',
  `overridden_by` int(11) NOT NULL,
  `reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_date_unique` (`user_id`, `date`),
  KEY `user_id` (`user_id`),
  KEY `overridden_by` (`overridden_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `attendance_overrides`
  ADD CONSTRAINT IF NOT EXISTS `att_override_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT IF NOT EXISTS `att_override_ibfk_2` FOREIGN KEY (`overridden_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- ============================================================
-- Done. Migration v2 applied successfully.
-- ============================================================
