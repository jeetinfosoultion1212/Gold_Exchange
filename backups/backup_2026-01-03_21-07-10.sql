-- Database Backup
-- Created: 2026-01-03 21:07:10



-- Table: account_balances
CREATE TABLE `account_balances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `account_type` enum('Cash','Bank','UPI') NOT NULL,
  `opening_balance` decimal(15,2) DEFAULT 0.00,
  `current_balance` decimal(15,2) DEFAULT 0.00,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_company_account` (`company_id`,`account_type`),
  CONSTRAINT `account_balances_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `account_balances` (`id`, `company_id`, `account_type`, `opening_balance`, `current_balance`, `last_updated`, `created_at`) VALUES ('1', '1', 'Cash', '0.00', '0.00', '2025-12-20 21:12:04', '2025-12-20 21:12:04');
INSERT INTO `account_balances` (`id`, `company_id`, `account_type`, `opening_balance`, `current_balance`, `last_updated`, `created_at`) VALUES ('2', '1', 'Bank', '0.00', '0.00', '2025-12-20 21:12:04', '2025-12-20 21:12:04');
INSERT INTO `account_balances` (`id`, `company_id`, `account_type`, `opening_balance`, `current_balance`, `last_updated`, `created_at`) VALUES ('3', '3', 'Cash', '0.00', '0.00', '2025-12-20 21:12:04', '2025-12-20 21:12:04');
INSERT INTO `account_balances` (`id`, `company_id`, `account_type`, `opening_balance`, `current_balance`, `last_updated`, `created_at`) VALUES ('4', '3', 'Bank', '0.00', '0.00', '2025-12-20 21:12:04', '2025-12-20 21:12:04');
INSERT INTO `account_balances` (`id`, `company_id`, `account_type`, `opening_balance`, `current_balance`, `last_updated`, `created_at`) VALUES ('5', '9', 'Cash', '0.00', '-1213448.00', '2025-12-22 15:22:07', '2025-12-20 21:12:04');
INSERT INTO `account_balances` (`id`, `company_id`, `account_type`, `opening_balance`, `current_balance`, `last_updated`, `created_at`) VALUES ('6', '9', 'Bank', '0.00', '0.00', '2025-12-20 21:12:04', '2025-12-20 21:12:04');
INSERT INTO `account_balances` (`id`, `company_id`, `account_type`, `opening_balance`, `current_balance`, `last_updated`, `created_at`) VALUES ('7', '5', 'Cash', '0.00', '0.00', '2025-12-20 21:12:04', '2025-12-20 21:12:04');
INSERT INTO `account_balances` (`id`, `company_id`, `account_type`, `opening_balance`, `current_balance`, `last_updated`, `created_at`) VALUES ('8', '5', 'Bank', '0.00', '0.00', '2025-12-20 21:12:04', '2025-12-20 21:12:04');
INSERT INTO `account_balances` (`id`, `company_id`, `account_type`, `opening_balance`, `current_balance`, `last_updated`, `created_at`) VALUES ('9', '7', 'Cash', '0.00', '0.00', '2025-12-20 21:12:04', '2025-12-20 21:12:04');
INSERT INTO `account_balances` (`id`, `company_id`, `account_type`, `opening_balance`, `current_balance`, `last_updated`, `created_at`) VALUES ('10', '7', 'Bank', '0.00', '0.00', '2025-12-20 21:12:04', '2025-12-20 21:12:04');


-- Table: audit_log
CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_audit_company` (`company_id`),
  KEY `fk_audit_user` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_table_name` (`table_name`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- Table: companies
CREATE TABLE `companies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_name` varchar(255) NOT NULL,
  `company_address` text DEFAULT NULL,
  `company_contact` varchar(20) DEFAULT NULL,
  `company_email` varchar(100) DEFAULT NULL,
  `company_logo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `status` enum('Active','Inactive') DEFAULT 'Active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_company_name` (`company_name`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `companies` (`id`, `company_name`, `company_address`, `company_contact`, `company_email`, `company_logo`, `created_at`, `status`) VALUES ('1', 'ABC PVT LTD', 'Default Address', '1234567890', 'info@abc.com', NULL, '2025-10-13 12:34:30', 'Active');
INSERT INTO `companies` (`id`, `company_name`, `company_address`, `company_contact`, `company_email`, `company_logo`, `created_at`, `status`) VALUES ('3', 'dasd', '', '9810359332', '', NULL, '2025-10-13 12:36:50', 'Active');
INSERT INTO `companies` (`id`, `company_name`, `company_address`, `company_contact`, `company_email`, `company_logo`, `created_at`, `status`) VALUES ('5', 'sds', '', '89898989', '', NULL, '2025-11-08 04:16:27', 'Active');
INSERT INTO `companies` (`id`, `company_name`, `company_address`, `company_contact`, `company_email`, `company_logo`, `created_at`, `status`) VALUES ('7', 'xyz demo', '', '9810359114', '', NULL, '2025-11-18 07:47:05', 'Active');
INSERT INTO `companies` (`id`, `company_name`, `company_address`, `company_contact`, `company_email`, `company_logo`, `created_at`, `status`) VALUES ('9', 'sasgfsg', '', '9810741236', '', NULL, '2025-11-23 09:48:39', 'Active');
INSERT INTO `companies` (`id`, `company_name`, `company_address`, `company_contact`, `company_email`, `company_logo`, `created_at`, `status`) VALUES ('10', 'PROSENJIT TOUNCH', '', '9810359331', '', NULL, '2025-12-28 16:25:21', 'Active');


-- Table: gold_purchase
CREATE TABLE `gold_purchase` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_purchase_receipt` (`receipt_id`),
  KEY `fk_gold_purchase_company` (`company_id`),
  KEY `fk_gold_purchase_party` (`party_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- Table: gold_sales
CREATE TABLE `gold_sales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `party_id` int(11) NOT NULL,
  `receipt_id` varchar(50) NOT NULL,
  `sale_date` datetime NOT NULL,
  `gold_weight` decimal(10,3) NOT NULL,
  `purity` decimal(5,2) NOT NULL,
  `rate` decimal(10,2) NOT NULL,
  `total_amount` decimal(15,2) NOT NULL,
  `payment_method` enum('Cash','Bank','UPI','Cheque') DEFAULT 'Cash',
  `payment_status` enum('Paid','Pending','Partial') DEFAULT 'Pending',
  `narration` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_sale_receipt` (`receipt_id`),
  KEY `fk_gold_sales_company` (`company_id`),
  KEY `fk_gold_sales_party` (`party_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- Table: gold_stock
CREATE TABLE `gold_stock` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `purity` decimal(5,2) NOT NULL,
  `stock_name` varchar(50) NOT NULL,
  `current_stock` decimal(10,3) NOT NULL DEFAULT 0.000,
  `last_updated` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_purity_company` (`purity`,`company_id`),
  KEY `fk_gold_stock_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- Table: parties
CREATE TABLE `parties` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `party_name` varchar(255) NOT NULL,
  `contact_no` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `current_balance` decimal(15,2) DEFAULT 0.00,
  `current_gold_balance` decimal(10,3) DEFAULT 0.000,
  `cash_balance` decimal(15,2) DEFAULT 0.00,
  `bank_balance` decimal(15,2) DEFAULT 0.00,
  `cash_gold_balance` decimal(10,3) DEFAULT 0.000,
  `bank_gold_balance` decimal(10,3) DEFAULT 0.000,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_parties_company` (`company_id`),
  KEY `idx_party_name` (`party_name`),
  KEY `idx_parties_balance` (`current_balance`),
  KEY `idx_parties_gold_balance` (`current_gold_balance`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- Table: payment_transactions
CREATE TABLE `payment_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_payment_receipt` (`receipt_id`),
  KEY `fk_payment_company` (`company_id`),
  KEY `fk_payment_party` (`party_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- Table: system_settings
CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` enum('string','number','boolean','json') DEFAULT 'string',
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_setting_company` (`setting_key`,`company_id`),
  KEY `fk_settings_company` (`company_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `system_settings` (`id`, `company_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('1', '1', 'default_currency', 'INR', 'string', 'Default currency for the system', '2025-10-13 12:34:31', '2025-10-13 12:34:31');
INSERT INTO `system_settings` (`id`, `company_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('2', '1', 'gold_purity_options', '[\"99.50\", \"99.90\", \"91.60\"]', 'json', 'Available gold purity options', '2025-10-13 12:34:31', '2025-10-13 12:34:31');
INSERT INTO `system_settings` (`id`, `company_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('3', '1', 'payment_methods', '[\"Cash\", \"Bank\", \"UPI\", \"Cheque\"]', 'json', 'Available payment methods', '2025-10-13 12:34:31', '2025-10-13 12:34:31');
INSERT INTO `system_settings` (`id`, `company_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('4', '1', 'receipt_prefix_booking', 'BK', 'string', 'Receipt prefix for booking transactions', '2025-10-13 12:34:31', '2025-10-13 12:34:31');
INSERT INTO `system_settings` (`id`, `company_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('5', '1', 'receipt_prefix_sale', 'SL', 'string', 'Receipt prefix for sale transactions', '2025-10-13 12:34:31', '2025-10-13 12:34:31');
INSERT INTO `system_settings` (`id`, `company_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('6', '1', 'receipt_prefix_purchase', 'PU', 'string', 'Receipt prefix for purchase transactions', '2025-10-13 12:34:31', '2025-10-13 12:34:31');
INSERT INTO `system_settings` (`id`, `company_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('7', '1', 'receipt_prefix_payment', 'PAY', 'string', 'Receipt prefix for payment transactions', '2025-10-13 12:34:31', '2025-10-13 12:34:31');
INSERT INTO `system_settings` (`id`, `company_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('8', '1', 'receipt_prefix_gold_receipt', 'GR', 'string', 'Receipt prefix for gold receipt transactions', '2025-10-13 12:34:31', '2025-10-13 12:34:31');


-- Table: transaction_summary
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



-- Table: transactions
CREATE TABLE `transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `party_id` int(11) DEFAULT NULL,
  `receipt_id` varchar(50) NOT NULL,
  `transaction_type` enum('Booking','Sale','Purchase','Received','Payment','Gold_Received','Exchange') NOT NULL,
  `date_of_transaction` datetime NOT NULL,
  `gold_weight` decimal(10,3) NOT NULL DEFAULT 0.000,
  `purity` decimal(5,2) DEFAULT NULL,
  `rate` decimal(10,2) DEFAULT NULL,
  `gold_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payment_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('Cash','Bank','UPI','Cheque') DEFAULT 'Cash',
  `payment_type` enum('Payment_In','Payment_Out') DEFAULT 'Payment_In',
  `receipt_method` enum('Cash','Bank') DEFAULT 'Cash',
  `party_balance_before` decimal(15,2) DEFAULT 0.00,
  `party_balance_after` decimal(15,2) DEFAULT 0.00,
  `party_gold_balance_before` decimal(10,3) DEFAULT 0.000,
  `party_gold_balance_after` decimal(10,3) DEFAULT 0.000,
  `booking_type` varchar(50) DEFAULT NULL,
  `narration` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `received_weight` decimal(10,3) DEFAULT NULL COMMENT 'Weight of old gold received',
  `fine_weight` decimal(10,3) DEFAULT NULL COMMENT 'Calculated fine gold weight',
  `delivered_weight` decimal(10,3) DEFAULT NULL COMMENT 'Weight of fine gold issued',
  `difference_weight` decimal(10,3) DEFAULT NULL COMMENT 'Difference between fine and delivered',
  `amount` decimal(15,2) DEFAULT 0.00 COMMENT 'Transaction amount',
  `due_amount` decimal(15,2) DEFAULT 0.00 COMMENT 'Remaining due amount',
  `payment_status` enum('Paid','Partial','Due','Pending') DEFAULT 'Pending' COMMENT 'Payment status',
  `created_by` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_receipt_id` (`receipt_id`),
  KEY `fk_transactions_company` (`company_id`),
  KEY `fk_transactions_user` (`user_id`),
  KEY `fk_transactions_party` (`party_id`),
  KEY `idx_transaction_type` (`transaction_type`),
  KEY `idx_date_of_transaction` (`date_of_transaction`),
  KEY `idx_payment_method` (`payment_method`),
  KEY `idx_payment_type` (`payment_type`),
  KEY `idx_transactions_date_type` (`date_of_transaction`,`transaction_type`),
  KEY `idx_transactions_party_date` (`party_id`,`date_of_transaction`),
  KEY `idx_transactions_amount` (`payment_amount`),
  KEY `idx_transactions_gold_weight` (`gold_weight`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- Table: users
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `status` enum('Active','Inactive') DEFAULT 'Active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_username` (`username`),
  UNIQUE KEY `unique_email` (`email`),
  KEY `fk_users_company` (`company_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`, `company_id`, `username`, `email`, `password`, `full_name`, `role`, `remember_token`, `remember_expires`, `last_login`, `created_at`, `status`) VALUES ('1', '1', 'admin', 'admin@abc.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'Admin', NULL, NULL, NULL, '2025-10-13 12:34:30', 'Active');
INSERT INTO `users` (`id`, `company_id`, `username`, `email`, `password`, `full_name`, `role`, `remember_token`, `remember_expires`, `last_login`, `created_at`, `status`) VALUES ('3', '3', 'admin21', '', '$2y$10$k3r3QOya58HKdQdvTXq.iePp5OAbVGqxtvyiLQh7Y90iMEjLvwa0q', 'prose', 'Admin', '3e5e3d5953d68388f598b0b2bdfd06800c17eca1d4c1b7e02169e19a12be2567', '2025-11-12 12:50:06', '2025-11-23 08:13:22', '2025-10-13 12:36:50', 'Active');
INSERT INTO `users` (`id`, `company_id`, `username`, `email`, `password`, `full_name`, `role`, `remember_token`, `remember_expires`, `last_login`, `created_at`, `status`) VALUES ('5', '5', 'demoad', 'demo@gmail.com', '$2y$10$UZI4nkxO6j2WwhiimCgRiOSVyzOZ.M5aJOhIt6D15JhiS0yhFuFIy', 'prosen', 'Admin', NULL, NULL, '2025-11-11 07:10:50', '2025-11-08 04:16:27', 'Active');
INSERT INTO `users` (`id`, `company_id`, `username`, `email`, `password`, `full_name`, `role`, `remember_token`, `remember_expires`, `last_login`, `created_at`, `status`) VALUES ('7', '7', 'goldadmin', 'dedmo1234@gmail.com', '$2y$10$GCjZO7Ctri7cSuYvnvnrBena722i8.k.qfa0MnbDfg19HeSElkXmS', 'prosenjit', 'Admin', NULL, NULL, '2025-11-24 06:08:30', '2025-11-18 07:47:05', 'Active');
INSERT INTO `users` (`id`, `company_id`, `username`, `email`, `password`, `full_name`, `role`, `remember_token`, `remember_expires`, `last_login`, `created_at`, `status`) VALUES ('9', '9', 'prosen21', 'xyx@gmail.com', '$2y$10$x03XfBfzvb1VXTRe7Lk60.1RIYcxZpBAtL5TNw9QdtQ9Tk1zKo9Mu', 'demo', 'Admin', 'bb9bc8d7144b8a208f144e83a3f4ec22726bc6e85403cd2d4707f658fe4d0636', '2026-01-19 12:17:45', '2025-12-28 16:30:07', '2025-11-23 09:48:39', 'Active');
