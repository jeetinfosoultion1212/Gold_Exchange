-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 31, 2026 at 05:08 PM
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
-- Database: `mormukut`
--

-- --------------------------------------------------------

--
-- Table structure for table `account_balances`
--

CREATE TABLE `account_balances` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `account_type` enum('Cash','Bank','UPI') NOT NULL,
  `opening_balance` decimal(15,2) DEFAULT 0.00,
  `current_balance` decimal(15,2) DEFAULT 0.00,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `account_balances`
--

INSERT INTO `account_balances` (`id`, `company_id`, `account_type`, `opening_balance`, `current_balance`, `last_updated`, `created_at`) VALUES
(1, 1, 'Cash', 0.00, 0.00, '2025-12-20 21:12:04', '2025-12-20 21:12:04'),
(2, 1, 'Bank', 0.00, 0.00, '2025-12-20 21:12:04', '2025-12-20 21:12:04'),
(3, 3, 'Cash', 0.00, 0.00, '2025-12-20 21:12:04', '2025-12-20 21:12:04'),
(4, 3, 'Bank', 0.00, 0.00, '2025-12-20 21:12:04', '2025-12-20 21:12:04'),
(5, 9, 'Cash', 0.00, -1213448.00, '2025-12-22 15:22:07', '2025-12-20 21:12:04'),
(6, 9, 'Bank', 0.00, 0.00, '2025-12-20 21:12:04', '2025-12-20 21:12:04'),
(7, 5, 'Cash', 0.00, 0.00, '2025-12-20 21:12:04', '2025-12-20 21:12:04'),
(8, 5, 'Bank', 0.00, 0.00, '2025-12-20 21:12:04', '2025-12-20 21:12:04'),
(9, 7, 'Cash', 0.00, 0.00, '2025-12-20 21:12:04', '2025-12-20 21:12:04'),
(10, 7, 'Bank', 0.00, 0.00, '2025-12-20 21:12:04', '2025-12-20 21:12:04'),
(22, 12, 'Cash', 0.00, 0.00, '2026-03-31 13:22:04', '2026-03-30 15:02:50');

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `companies`
--

CREATE TABLE `companies` (
  `id` int(11) NOT NULL,
  `company_name` varchar(255) NOT NULL,
  `company_address` text DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `gstin` varchar(50) DEFAULT NULL,
  `company_contact` varchar(20) DEFAULT NULL,
  `company_email` varchar(100) DEFAULT NULL,
  `company_logo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `pin` varchar(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `companies`
--

INSERT INTO `companies` (`id`, `company_name`, `company_address`, `state`, `city`, `gstin`, `company_contact`, `company_email`, `company_logo`, `created_at`, `status`, `pin`) VALUES
(1, 'ABC PVT LTD', 'Default Address', NULL, NULL, NULL, '1234567890', 'info@abc.com', NULL, '2025-10-13 12:34:30', 'Active', NULL),
(3, 'dasd', '', NULL, NULL, NULL, '9810359332', '', NULL, '2025-10-13 12:36:50', 'Active', NULL),
(5, 'sds', '', NULL, NULL, NULL, '89898989', '', NULL, '2025-11-08 04:16:27', 'Active', NULL),
(7, 'xyz demo', '', NULL, NULL, NULL, '9810359114', '', NULL, '2025-11-18 07:47:05', 'Active', NULL),
(9, 'sasgfsg', '', NULL, NULL, NULL, '9810741236', '', NULL, '2025-11-23 09:48:39', 'Active', NULL),
(10, 'PROSENJIT TOUNCH', '', NULL, NULL, NULL, '9810359331', '', NULL, '2025-12-28 16:25:21', 'Active', NULL),
(12, 'ABC JEWELLERS PVT LTD', '', 'Delhi', '', '', '9810359441', '', NULL, '2026-03-30 13:35:26', 'Active', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `company_banks`
--

CREATE TABLE `company_banks` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `account_holder_name` varchar(255) DEFAULT NULL,
  `bank_name` varchar(255) DEFAULT NULL,
  `account_no` varchar(100) DEFAULT NULL,
  `ifsc_code` varchar(50) DEFAULT NULL,
  `branch_name` varchar(255) DEFAULT NULL,
  `balance` decimal(15,2) DEFAULT 0.00,
  `is_primary` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `company_banks`
--

INSERT INTO `company_banks` (`id`, `company_id`, `account_holder_name`, `bank_name`, `account_no`, `ifsc_code`, `branch_name`, `balance`, `is_primary`, `created_at`) VALUES
(1, 12, 'halder', 'axis bank ', '556565656', '', NULL, 0.00, 0, '2026-03-31 12:21:27');

-- --------------------------------------------------------

--
-- Table structure for table `exchange_items`
--

CREATE TABLE `exchange_items` (
  `id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `item_type` enum('received','issued') NOT NULL COMMENT 'received = old gold items, issued = fine gold given',
  `weight` decimal(10,3) DEFAULT NULL COMMENT 'Weight in grams',
  `purity` decimal(5,2) DEFAULT NULL COMMENT 'Purity percentage',
  `fine_weight` decimal(10,3) DEFAULT NULL COMMENT 'Calculated fine weight (gold or silver)',
  `material` enum('Gold','Silver') NOT NULL DEFAULT 'Gold' COMMENT 'Scrap metal received',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stores individual items for multi-item gold exchange transactions';

-- --------------------------------------------------------

--
-- Table structure for table `gold_purchase`
--

CREATE TABLE `gold_purchase` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `party_id` int(11) NOT NULL,
  `receipt_id` varchar(50) NOT NULL,
  `purchase_date` datetime NOT NULL,
  `gold_weight` decimal(10,3) NOT NULL,
  `purity` decimal(5,2) NOT NULL,
  `rate` decimal(10,2) NOT NULL,
  `total_amount` decimal(15,2) NOT NULL,
  `payment_method` enum('Cash','Bank','UPI','Cheque') DEFAULT 'Cash',
  `payment_status` enum('Paid','Pending','Partial') DEFAULT 'Paid',
  `narration` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gold_sale_items`
--

CREATE TABLE `gold_sale_items` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `receipt_id` varchar(50) NOT NULL,
  `stock_name` varchar(100) NOT NULL,
  `gold_weight` decimal(15,3) NOT NULL,
  `purity` decimal(10,2) NOT NULL,
  `fine_weight` decimal(15,3) NOT NULL,
  `rate` decimal(15,2) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `gold_sale_items`
--

INSERT INTO `gold_sale_items` (`id`, `company_id`, `transaction_id`, `receipt_id`, `stock_name`, `gold_weight`, `purity`, `fine_weight`, `rate`, `amount`, `created_at`) VALUES
(2, 12, 4, 'S12001', 'FINE GOLD', 10.640, 99.90, 10.629, 13640.00, 145130.00, '2026-03-31 14:35:58'),
(3, 12, 5, 'S12002', 'FINE GOLD', 10.650, 99.90, 10.639, 13640.00, 145266.00, '2026-03-31 14:36:44'),
(4, 12, 6, 'S12003', 'FINE GOLD', 130.640, 99.90, 130.509, 13260.00, 1732286.00, '2026-03-31 14:53:58');

-- --------------------------------------------------------

--
-- Table structure for table `gold_purchase_items`
--

CREATE TABLE `gold_purchase_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `receipt_id` varchar(50) NOT NULL,
  `stock_name` varchar(100) NOT NULL,
  `gold_weight` decimal(15,3) NOT NULL,
  `purity` decimal(10,2) NOT NULL,
  `fine_weight` decimal(15,3) NOT NULL,
  `rate` decimal(15,2) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gp_tx` (`transaction_id`),
  KEY `idx_gp_co` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gold_stock`
--

CREATE TABLE `gold_stock` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `category` enum('Gold','Silver') DEFAULT 'Gold',
  `mode` enum('Cash','Bank') DEFAULT 'Cash' COMMENT 'Cash=Kachha, Bank=Pakka',
  `purity` decimal(5,2) NOT NULL,
  `stock_name` varchar(50) NOT NULL,
  `current_stock` decimal(10,3) NOT NULL DEFAULT 0.000,
  `last_updated` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `gold_stock`
--

INSERT INTO `gold_stock` (`id`, `company_id`, `category`, `mode`, `purity`, `stock_name`, `current_stock`, `last_updated`, `created_at`) VALUES
(1, 12, 'Gold', 'Cash', 99.90, 'FINE GOLD', -51.930, '2026-03-31 14:53:58', '2026-03-31 13:22:25');

-- --------------------------------------------------------

--
-- Table structure for table `parties`
--

CREATE TABLE `parties` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `party_name` varchar(255) NOT NULL,
  `contact_no` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `gstin` varchar(50) DEFAULT NULL,
  `bank_name` varchar(255) DEFAULT NULL,
  `account_no` varchar(100) DEFAULT NULL,
  `ifsc_code` varchar(20) DEFAULT NULL,
  `cash_balance` decimal(15,2) DEFAULT 0.00,
  `bank_balance` decimal(15,2) DEFAULT 0.00,
  `gold_balance` decimal(10,3) DEFAULT 0.000,
  `silver_balance` decimal(15,3) DEFAULT 0.000,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `parties`
--

INSERT INTO `parties` (`id`, `company_id`, `party_name`, `contact_no`, `address`, `city`, `state`, `gstin`, `bank_name`, `account_no`, `ifsc_code`, `cash_balance`, `bank_balance`, `gold_balance`, `silver_balance`, `created_at`, `updated_at`) VALUES
(1, 12, 'prosenjit halder', '', '', '', '', 'N/A', '', '', '', 1877416.00, 149624.00, -151.930, 0.000, '2026-03-31 13:23:28', '2026-03-31 14:53:58');

-- --------------------------------------------------------

--
-- Table structure for table `payment_transactions`
--

CREATE TABLE `payment_transactions` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `party_id` int(11) NOT NULL,
  `receipt_id` varchar(50) NOT NULL,
  `payment_date` datetime NOT NULL,
  `payment_amount` decimal(15,2) NOT NULL,
  `payment_method` enum('Cash','Bank','UPI','Cheque') NOT NULL,
  `payment_type` enum('Payment_In','Payment_Out') NOT NULL,
  `transaction_reference` varchar(100) DEFAULT NULL,
  `narration` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` enum('string','number','boolean','json') DEFAULT 'string',
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `company_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES
(1, 1, 'default_currency', 'INR', 'string', 'Default currency for the system', '2025-10-13 12:34:31', '2025-10-13 12:34:31'),
(2, 1, 'gold_purity_options', '[\"99.50\", \"99.90\", \"91.60\"]', 'json', 'Available gold purity options', '2025-10-13 12:34:31', '2025-10-13 12:34:31'),
(3, 1, 'payment_methods', '[\"Cash\", \"Bank\", \"UPI\", \"Cheque\"]', 'json', 'Available payment methods', '2025-10-13 12:34:31', '2025-10-13 12:34:31'),
(4, 1, 'receipt_prefix_booking', 'BK', 'string', 'Receipt prefix for booking transactions', '2025-10-13 12:34:31', '2025-10-13 12:34:31'),
(5, 1, 'receipt_prefix_sale', 'SL', 'string', 'Receipt prefix for sale transactions', '2025-10-13 12:34:31', '2025-10-13 12:34:31'),
(6, 1, 'receipt_prefix_purchase', 'PU', 'string', 'Receipt prefix for purchase transactions', '2025-10-13 12:34:31', '2025-10-13 12:34:31'),
(7, 1, 'receipt_prefix_payment', 'PAY', 'string', 'Receipt prefix for payment transactions', '2025-10-13 12:34:31', '2025-10-13 12:34:31'),
(8, 1, 'receipt_prefix_gold_receipt', 'GR', 'string', 'Receipt prefix for gold receipt transactions', '2025-10-13 12:34:31', '2025-10-13 12:34:31');

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `party_id` int(11) DEFAULT NULL,
  `receipt_id` varchar(50) NOT NULL,
  `transaction_type` enum('Booking','Sale','Purchase','Received','Payment','Gold_Received','Exchange','Personal_Expense') NOT NULL,
  `date_of_transaction` datetime NOT NULL,
  `gold_weight` decimal(10,3) NOT NULL DEFAULT 0.000,
  `purity` decimal(5,2) DEFAULT NULL,
  `rate` decimal(10,2) DEFAULT NULL,
  `gold_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payment_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('Cash','Bank','UPI','Cheque') DEFAULT 'Cash',
  `payment_type` enum('Payment_In','Payment_Out') DEFAULT 'Payment_In',
  `receipt_method` enum('Cash','Bank') DEFAULT 'Cash',
  `mode` varchar(20) DEFAULT 'Cash',
  `party_balance_before` decimal(15,2) DEFAULT 0.00,
  `party_balance_after` decimal(15,2) DEFAULT 0.00,
  `party_gold_balance_before` decimal(10,3) DEFAULT 0.000,
  `party_gold_balance_after` decimal(10,3) DEFAULT 0.000,
  `booking_type` varchar(50) DEFAULT NULL,
  `narration` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `received_weight` decimal(10,3) DEFAULT NULL,
  `fine_weight` decimal(10,3) DEFAULT NULL,
  `delivered_weight` decimal(10,3) DEFAULT NULL,
  `difference_weight` decimal(10,3) DEFAULT NULL,
  `amount` decimal(15,2) DEFAULT 0.00,
  `taxable_amount` decimal(15,2) DEFAULT NULL,
  `cgst` decimal(15,2) DEFAULT NULL,
  `sgst` decimal(15,2) DEFAULT NULL,
  `igst` decimal(15,2) DEFAULT NULL,
  `total_gst` decimal(15,2) DEFAULT NULL,
  `due_amount` decimal(15,2) DEFAULT 0.00,
  `payment_status` enum('Paid','Partial','Due','Pending') DEFAULT 'Pending',
  `exchange_material` varchar(10) NOT NULL DEFAULT 'Gold',
  `created_by` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`id`, `company_id`, `user_id`, `party_id`, `receipt_id`, `transaction_type`, `date_of_transaction`, `gold_weight`, `purity`, `rate`, `gold_amount`, `payment_amount`, `payment_method`, `payment_type`, `receipt_method`, `mode`, `party_balance_before`, `party_balance_after`, `party_gold_balance_before`, `party_gold_balance_after`, `booking_type`, `narration`, `created_at`, `updated_at`, `received_weight`, `fine_weight`, `delivered_weight`, `difference_weight`, `amount`, `taxable_amount`, `cgst`, `sgst`, `igst`, `total_gst`, `due_amount`, `payment_status`, `created_by`) VALUES
(1, 12, NULL, NULL, 'CSH-RST-69CBCA7C619E8', 'Payment', '2026-03-31 18:52:04', 0.000, NULL, NULL, 0.00, 3410.00, 'Cash', 'Payment_Out', 'Cash', 'Cash', 0.00, 0.00, 0.000, 0.000, NULL, 'Cash Reset (Previous Balance: ₹3410): ', '2026-03-31 13:22:04', '2026-03-31 13:22:04', NULL, NULL, NULL, NULL, 0.00, NULL, NULL, NULL, NULL, NULL, 0.00, 'Pending', 12),
(2, 12, NULL, NULL, 'STK-69CBCA9134D84', '', '2026-03-31 18:52:25', 100.000, 99.90, NULL, 0.00, 0.00, 'Cash', 'Payment_In', 'Cash', 'Cash', 0.00, 0.00, 0.000, 0.000, NULL, '[Gold] Stock Addition (FINE GOLD - Cash): ', '2026-03-31 13:22:25', '2026-03-31 13:22:25', NULL, NULL, NULL, NULL, 0.00, NULL, NULL, NULL, NULL, NULL, 0.00, 'Pending', 12),
(4, 12, 12, 1, 'S12001', 'Sale', '2026-03-31 20:05:00', 10.640, 99.90, 13640.00, 145130.00, 0.00, 'Cash', 'Payment_In', 'Cash', 'Cash', 0.00, -145130.00, 0.000, 0.000, NULL, '', '2026-03-31 14:35:58', '2026-03-31 14:35:58', NULL, NULL, NULL, NULL, 145130.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 'Due', 12),
(5, 12, 12, 1, 'S12002', 'Sale', '2026-03-31 20:05:00', 10.650, 99.90, 13640.00, 149624.00, 0.00, 'Cash', 'Payment_In', 'Cash', 'Bank', 145130.00, -4494.00, 0.000, 0.000, NULL, '', '2026-03-31 14:36:44', '2026-03-31 14:36:44', NULL, NULL, NULL, NULL, 149624.00, 145266.00, 2178.99, 2178.99, 0.00, 4357.98, 0.00, 'Due', 12),
(6, 12, 12, 1, 'S12003', 'Sale', '2026-03-31 20:21:00', 130.640, 99.90, 13260.00, 1732286.40, 0.00, NULL, NULL, NULL, 'Cash', 294754.00, -1437532.00, 0.000, 0.000, NULL, '', '2026-03-31 14:53:58', '2026-03-31 14:53:58', NULL, NULL, NULL, NULL, 1732286.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 'Due', 12);

-- --------------------------------------------------------

--
-- Table structure for table `transaction_summary`
--

CREATE TABLE `transaction_summary` (
  `id` int(11) DEFAULT NULL,
  `company_id` int(11) DEFAULT NULL,
  `party_id` int(11) DEFAULT NULL,
  `party_name` varchar(255) DEFAULT NULL,
  `receipt_id` varchar(50) DEFAULT NULL,
  `transaction_type` enum('Booking','Sale','Purchase','Received','Payment','Gold_Received') DEFAULT NULL,
  `date_of_transaction` datetime DEFAULT NULL,
  `gold_weight` decimal(10,3) DEFAULT NULL,
  `rate` decimal(10,2) DEFAULT NULL,
  `gold_amount` decimal(15,2) DEFAULT NULL,
  `payment_amount` decimal(15,2) DEFAULT NULL,
  `payment_method` enum('Cash','Bank','UPI','Cheque') DEFAULT NULL,
  `payment_type` enum('Payment_In','Payment_Out') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('Admin','Manager','Operator') DEFAULT 'Operator',
  `remember_token` varchar(255) DEFAULT NULL,
  `remember_expires` timestamp NULL DEFAULT NULL,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `status` enum('Active','Inactive') DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `company_id`, `username`, `email`, `password`, `full_name`, `role`, `remember_token`, `remember_expires`, `last_login`, `created_at`, `status`) VALUES
(12, 12, '9810359441', NULL, '$2y$10$wI6RlVVJCxI6moMEiNvQEuVqjuoXHLF6zg5rgn2efidNJQGXFhY2W', 'prosenjit halder', 'Admin', 'a01562a81120b2079bca4d2457ca115bf88ac9ef61325b52c28250e5b55bfce7', '2026-04-29 10:08:07', '2026-03-30 13:38:24', '2026-03-30 13:35:26', 'Active');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `account_balances`
--
ALTER TABLE `account_balances`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_company_account` (`company_id`,`account_type`);

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_audit_company` (`company_id`),
  ADD KEY `fk_audit_user` (`user_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_table_name` (`table_name`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_company_name` (`company_name`);

--
-- Indexes for table `company_banks`
--
ALTER TABLE `company_banks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `company_id` (`company_id`);

--
-- Indexes for table `exchange_items`
--
ALTER TABLE `exchange_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `transaction_id` (`transaction_id`),
  ADD KEY `company_id` (`company_id`);

--
-- Indexes for table `gold_purchase`
--
ALTER TABLE `gold_purchase`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_purchase_receipt` (`receipt_id`),
  ADD KEY `fk_gold_purchase_company` (`company_id`),
  ADD KEY `fk_gold_purchase_party` (`party_id`);

--
-- Indexes for table `gold_sale_items`
--
ALTER TABLE `gold_sale_items`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `gold_stock`
--
ALTER TABLE `gold_stock`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_stock_purity_mode` (`company_id`,`category`,`purity`,`mode`),
  ADD KEY `fk_gold_stock_company` (`company_id`);

--
-- Indexes for table `parties`
--
ALTER TABLE `parties`
  ADD PRIMARY KEY (`id`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `party_name` (`party_name`);

--
-- Indexes for table `payment_transactions`
--
ALTER TABLE `payment_transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_payment_receipt` (`receipt_id`),
  ADD KEY `fk_payment_company` (`company_id`),
  ADD KEY `fk_payment_party` (`party_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_setting_company` (`setting_key`,`company_id`),
  ADD KEY `fk_settings_company` (`company_id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_receipt_id` (`receipt_id`),
  ADD KEY `fk_transactions_company` (`company_id`),
  ADD KEY `fk_transactions_user` (`user_id`),
  ADD KEY `fk_transactions_party` (`party_id`),
  ADD KEY `idx_transaction_type` (`transaction_type`),
  ADD KEY `idx_date_of_transaction` (`date_of_transaction`),
  ADD KEY `idx_payment_method` (`payment_method`),
  ADD KEY `idx_payment_type` (`payment_type`),
  ADD KEY `idx_transactions_date_type` (`date_of_transaction`,`transaction_type`),
  ADD KEY `idx_transactions_party_date` (`party_id`,`date_of_transaction`),
  ADD KEY `idx_transactions_amount` (`payment_amount`),
  ADD KEY `idx_transactions_gold_weight` (`gold_weight`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_username` (`username`),
  ADD UNIQUE KEY `unique_email` (`email`),
  ADD KEY `fk_users_company` (`company_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `account_balances`
--
ALTER TABLE `account_balances`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `companies`
--
ALTER TABLE `companies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `company_banks`
--
ALTER TABLE `company_banks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `exchange_items`
--
ALTER TABLE `exchange_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gold_purchase`
--
ALTER TABLE `gold_purchase`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gold_sale_items`
--
ALTER TABLE `gold_sale_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `gold_stock`
--
ALTER TABLE `gold_stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `parties`
--
ALTER TABLE `parties`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `payment_transactions`
--
ALTER TABLE `payment_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `account_balances`
--
ALTER TABLE `account_balances`
  ADD CONSTRAINT `account_balances_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `company_banks`
--
ALTER TABLE `company_banks`
  ADD CONSTRAINT `company_banks_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `exchange_items`
--
ALTER TABLE `exchange_items`
  ADD CONSTRAINT `exchange_items_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `exchange_items_ibfk_2` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
