-- Database Backup
-- Created: 2026-03-31 14:06:39



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
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `account_balances` (`id`, `company_id`, `account_type`, `opening_balance`, `current_balance`, `last_updated`, `created_at`) VALUES ('1', '1', 'Cash', '0.00', '0.00', '2025-12-21 02:42:04', '2025-12-21 02:42:04');
INSERT INTO `account_balances` (`id`, `company_id`, `account_type`, `opening_balance`, `current_balance`, `last_updated`, `created_at`) VALUES ('2', '1', 'Bank', '0.00', '0.00', '2025-12-21 02:42:04', '2025-12-21 02:42:04');
INSERT INTO `account_balances` (`id`, `company_id`, `account_type`, `opening_balance`, `current_balance`, `last_updated`, `created_at`) VALUES ('3', '3', 'Cash', '0.00', '0.00', '2025-12-21 02:42:04', '2025-12-21 02:42:04');
INSERT INTO `account_balances` (`id`, `company_id`, `account_type`, `opening_balance`, `current_balance`, `last_updated`, `created_at`) VALUES ('4', '3', 'Bank', '0.00', '0.00', '2025-12-21 02:42:04', '2025-12-21 02:42:04');
INSERT INTO `account_balances` (`id`, `company_id`, `account_type`, `opening_balance`, `current_balance`, `last_updated`, `created_at`) VALUES ('5', '9', 'Cash', '0.00', '-1213448.00', '2025-12-22 20:52:07', '2025-12-21 02:42:04');
INSERT INTO `account_balances` (`id`, `company_id`, `account_type`, `opening_balance`, `current_balance`, `last_updated`, `created_at`) VALUES ('6', '9', 'Bank', '0.00', '0.00', '2025-12-21 02:42:04', '2025-12-21 02:42:04');
INSERT INTO `account_balances` (`id`, `company_id`, `account_type`, `opening_balance`, `current_balance`, `last_updated`, `created_at`) VALUES ('7', '5', 'Cash', '0.00', '0.00', '2025-12-21 02:42:04', '2025-12-21 02:42:04');
INSERT INTO `account_balances` (`id`, `company_id`, `account_type`, `opening_balance`, `current_balance`, `last_updated`, `created_at`) VALUES ('8', '5', 'Bank', '0.00', '0.00', '2025-12-21 02:42:04', '2025-12-21 02:42:04');
INSERT INTO `account_balances` (`id`, `company_id`, `account_type`, `opening_balance`, `current_balance`, `last_updated`, `created_at`) VALUES ('9', '7', 'Cash', '0.00', '0.00', '2025-12-21 02:42:04', '2025-12-21 02:42:04');
INSERT INTO `account_balances` (`id`, `company_id`, `account_type`, `opening_balance`, `current_balance`, `last_updated`, `created_at`) VALUES ('10', '7', 'Bank', '0.00', '0.00', '2025-12-21 02:42:04', '2025-12-21 02:42:04');
INSERT INTO `account_balances` (`id`, `company_id`, `account_type`, `opening_balance`, `current_balance`, `last_updated`, `created_at`) VALUES ('22', '12', 'Cash', '0.00', '3410.00', '2026-03-30 20:48:29', '2026-03-30 20:32:50');


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
  `pin` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_company_name` (`company_name`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `companies` (`id`, `company_name`, `company_address`, `company_contact`, `company_email`, `company_logo`, `created_at`, `status`, `pin`) VALUES ('1', 'ABC PVT LTD', 'Default Address', '1234567890', 'info@abc.com', NULL, '2025-10-13 18:04:30', 'Active', NULL);
INSERT INTO `companies` (`id`, `company_name`, `company_address`, `company_contact`, `company_email`, `company_logo`, `created_at`, `status`, `pin`) VALUES ('3', 'dasd', '', '9810359332', '', NULL, '2025-10-13 18:06:50', 'Active', NULL);
INSERT INTO `companies` (`id`, `company_name`, `company_address`, `company_contact`, `company_email`, `company_logo`, `created_at`, `status`, `pin`) VALUES ('5', 'sds', '', '89898989', '', NULL, '2025-11-08 09:46:27', 'Active', NULL);
INSERT INTO `companies` (`id`, `company_name`, `company_address`, `company_contact`, `company_email`, `company_logo`, `created_at`, `status`, `pin`) VALUES ('7', 'xyz demo', '', '9810359114', '', NULL, '2025-11-18 13:17:05', 'Active', NULL);
INSERT INTO `companies` (`id`, `company_name`, `company_address`, `company_contact`, `company_email`, `company_logo`, `created_at`, `status`, `pin`) VALUES ('9', 'sasgfsg', '', '9810741236', '', NULL, '2025-11-23 15:18:39', 'Active', NULL);
INSERT INTO `companies` (`id`, `company_name`, `company_address`, `company_contact`, `company_email`, `company_logo`, `created_at`, `status`, `pin`) VALUES ('10', 'PROSENJIT TOUNCH', '', '9810359331', '', NULL, '2025-12-28 21:55:21', 'Active', NULL);
INSERT INTO `companies` (`id`, `company_name`, `company_address`, `company_contact`, `company_email`, `company_logo`, `created_at`, `status`, `pin`) VALUES ('12', 'ABC JEWELLERS PVT LTD', '', '9810359441', '', NULL, '2026-03-30 19:05:26', 'Active', NULL);


-- Table: exchange_items
CREATE TABLE `exchange_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `item_type` enum('received','issued') NOT NULL COMMENT 'received = old gold items, issued = fine gold given',
  `weight` decimal(10,3) DEFAULT NULL COMMENT 'Weight in grams',
  `purity` decimal(5,2) DEFAULT NULL COMMENT 'Purity percentage',
  `fine_weight` decimal(10,3) DEFAULT NULL COMMENT 'Calculated fine gold weight',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `transaction_id` (`transaction_id`),
  KEY `company_id` (`company_id`),
  CONSTRAINT `exchange_items_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exchange_items_ibfk_2` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stores individual items for multi-item gold exchange transactions';

INSERT INTO `exchange_items` (`id`, `transaction_id`, `company_id`, `item_type`, `weight`, `purity`, `fine_weight`, `created_at`) VALUES ('1', '2', '12', 'received', '10.640', '75.50', '8.030', '2026-03-30 20:48:29');
INSERT INTO `exchange_items` (`id`, `transaction_id`, `company_id`, `item_type`, `weight`, `purity`, `fine_weight`, `created_at`) VALUES ('2', '4', '12', 'received', '20.640', '75.60', '15.600', '2026-03-31 11:40:43');


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
  `category` enum('Gold','Silver') DEFAULT 'Gold',
  `mode` enum('Cash','Bank') DEFAULT 'Cash' COMMENT 'Cash=Kachha, Bank=Pakka',
  `purity` decimal(5,2) NOT NULL,
  `stock_name` varchar(50) NOT NULL,
  `current_stock` decimal(10,3) NOT NULL DEFAULT 0.000,
  `last_updated` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_stock_purity_mode` (`company_id`,`category`,`purity`,`mode`),
  KEY `fk_gold_stock_company` (`company_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `gold_stock` (`id`, `company_id`, `category`, `mode`, `purity`, `stock_name`, `current_stock`, `last_updated`, `created_at`) VALUES ('1', '12', 'Gold', 'Bank', '99.90', 'FINE GOLD', '100.000', '2026-03-30 20:20:31', '2026-03-30 20:20:31');
INSERT INTO `gold_stock` (`id`, `company_id`, `category`, `mode`, `purity`, `stock_name`, `current_stock`, `last_updated`, `created_at`) VALUES ('3', '12', 'Gold', 'Cash', '99.90', 'SILVER', '166.620', '2026-03-31 15:25:39', '2026-03-30 20:26:08');
INSERT INTO `gold_stock` (`id`, `company_id`, `category`, `mode`, `purity`, `stock_name`, `current_stock`, `last_updated`, `created_at`) VALUES ('5', '12', 'Gold', 'Cash', '0.00', 'MIX Stock', '31.280', '2026-03-31 11:40:43', '2026-03-30 20:32:50');


-- Table: parties
CREATE TABLE `parties` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `company_id` (`company_id`),
  KEY `party_name` (`party_name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `parties` (`id`, `company_id`, `party_name`, `contact_no`, `address`, `city`, `state`, `gstin`, `bank_name`, `account_no`, `ifsc_code`, `cash_balance`, `bank_balance`, `gold_balance`, `silver_balance`, `created_at`, `updated_at`) VALUES ('1', '12', 'prosenjit halder', '', '', '', '', 'N/A', '', '', '', '0.00', '0.00', '0.020', '0.000', '2026-03-30 20:48:12', '2026-03-30 20:48:29');
INSERT INTO `parties` (`id`, `company_id`, `party_name`, `contact_no`, `address`, `city`, `state`, `gstin`, `bank_name`, `account_no`, `ifsc_code`, `cash_balance`, `bank_balance`, `gold_balance`, `silver_balance`, `created_at`, `updated_at`) VALUES ('2', '12', 'TUBAI', '', '', '', '', 'N/A', '', '', '', '2597.00', '0.00', '-0.190', '0.000', '2026-03-31 11:40:21', '2026-03-31 11:40:43');
INSERT INTO `parties` (`id`, `company_id`, `party_name`, `contact_no`, `address`, `city`, `state`, `gstin`, `bank_name`, `account_no`, `ifsc_code`, `cash_balance`, `bank_balance`, `gold_balance`, `silver_balance`, `created_at`, `updated_at`) VALUES ('3', '12', 'kishan', '', '', '', '', 'N/A', '', '', '', '0.00', '0.00', '0.000', '0.000', '2026-03-31 16:16:57', '2026-03-31 16:16:57');


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

INSERT INTO `system_settings` (`id`, `company_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('1', '1', 'default_currency', 'INR', 'string', 'Default currency for the system', '2025-10-13 18:04:31', '2025-10-13 18:04:31');
INSERT INTO `system_settings` (`id`, `company_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('2', '1', 'gold_purity_options', '[\"99.50\", \"99.90\", \"91.60\"]', 'json', 'Available gold purity options', '2025-10-13 18:04:31', '2025-10-13 18:04:31');
INSERT INTO `system_settings` (`id`, `company_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('3', '1', 'payment_methods', '[\"Cash\", \"Bank\", \"UPI\", \"Cheque\"]', 'json', 'Available payment methods', '2025-10-13 18:04:31', '2025-10-13 18:04:31');
INSERT INTO `system_settings` (`id`, `company_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('4', '1', 'receipt_prefix_booking', 'BK', 'string', 'Receipt prefix for booking transactions', '2025-10-13 18:04:31', '2025-10-13 18:04:31');
INSERT INTO `system_settings` (`id`, `company_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('5', '1', 'receipt_prefix_sale', 'SL', 'string', 'Receipt prefix for sale transactions', '2025-10-13 18:04:31', '2025-10-13 18:04:31');
INSERT INTO `system_settings` (`id`, `company_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('6', '1', 'receipt_prefix_purchase', 'PU', 'string', 'Receipt prefix for purchase transactions', '2025-10-13 18:04:31', '2025-10-13 18:04:31');
INSERT INTO `system_settings` (`id`, `company_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('7', '1', 'receipt_prefix_payment', 'PAY', 'string', 'Receipt prefix for payment transactions', '2025-10-13 18:04:31', '2025-10-13 18:04:31');
INSERT INTO `system_settings` (`id`, `company_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `created_at`, `updated_at`) VALUES ('8', '1', 'receipt_prefix_gold_receipt', 'GR', 'string', 'Receipt prefix for gold receipt transactions', '2025-10-13 18:04:31', '2025-10-13 18:04:31');


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
  `received_weight` decimal(10,3) DEFAULT NULL,
  `fine_weight` decimal(10,3) DEFAULT NULL,
  `delivered_weight` decimal(10,3) DEFAULT NULL,
  `difference_weight` decimal(10,3) DEFAULT NULL,
  `amount` decimal(15,2) DEFAULT 0.00,
  `due_amount` decimal(15,2) DEFAULT 0.00,
  `payment_status` enum('Paid','Partial','Due','Pending') DEFAULT 'Pending',
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
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `transactions` (`id`, `company_id`, `user_id`, `party_id`, `receipt_id`, `transaction_type`, `date_of_transaction`, `gold_weight`, `purity`, `rate`, `gold_amount`, `payment_amount`, `payment_method`, `payment_type`, `receipt_method`, `party_balance_before`, `party_balance_after`, `party_gold_balance_before`, `party_gold_balance_after`, `booking_type`, `narration`, `created_at`, `updated_at`, `received_weight`, `fine_weight`, `delivered_weight`, `difference_weight`, `amount`, `due_amount`, `payment_status`, `created_by`) VALUES ('2', '12', '12', '1', 'EX1', 'Exchange', '2026-03-30 20:48:00', '8.050', '75.47', '13640.00', '273.00', '273.00', '', 'Payment_In', 'Cash', '0.00', '0.00', '0.000', '0.000', NULL, '', '2026-03-30 20:48:29', '2026-03-30 20:48:29', '10.640', '8.030', '8.050', '0.020', '273.00', '0.00', 'Paid', '0');
INSERT INTO `transactions` (`id`, `company_id`, `user_id`, `party_id`, `receipt_id`, `transaction_type`, `date_of_transaction`, `gold_weight`, `purity`, `rate`, `gold_amount`, `payment_amount`, `payment_method`, `payment_type`, `receipt_method`, `party_balance_before`, `party_balance_after`, `party_gold_balance_before`, `party_gold_balance_after`, `booking_type`, `narration`, `created_at`, `updated_at`, `received_weight`, `fine_weight`, `delivered_weight`, `difference_weight`, `amount`, `due_amount`, `payment_status`, `created_by`) VALUES ('3', '12', '12', '1', 'PAY-EX1-6131', 'Received', '2026-03-30 20:48:00', '0.000', NULL, NULL, '0.00', '273.00', 'Cash', 'Payment_In', 'Cash', '0.00', '0.00', '0.000', '0.000', NULL, 'Payment for Exchange EX1', '2026-03-30 20:48:29', '2026-03-30 20:48:29', NULL, NULL, NULL, NULL, '273.00', '0.00', 'Paid', '0');
INSERT INTO `transactions` (`id`, `company_id`, `user_id`, `party_id`, `receipt_id`, `transaction_type`, `date_of_transaction`, `gold_weight`, `purity`, `rate`, `gold_amount`, `payment_amount`, `payment_method`, `payment_type`, `receipt_method`, `party_balance_before`, `party_balance_after`, `party_gold_balance_before`, `party_gold_balance_after`, `booking_type`, `narration`, `created_at`, `updated_at`, `received_weight`, `fine_weight`, `delivered_weight`, `difference_weight`, `amount`, `due_amount`, `payment_status`, `created_by`) VALUES ('4', '12', '12', '2', 'EX2', 'Exchange', '2026-03-31 11:40:00', '15.410', '75.58', '13670.00', '2597.00', '0.00', '', 'Payment_Out', 'Cash', '0.00', '0.00', '0.000', '0.000', NULL, '', '2026-03-31 11:40:43', '2026-03-31 11:40:43', '20.640', '15.600', '15.410', '-0.190', '2597.00', '2597.00', 'Due', '0');
INSERT INTO `transactions` (`id`, `company_id`, `user_id`, `party_id`, `receipt_id`, `transaction_type`, `date_of_transaction`, `gold_weight`, `purity`, `rate`, `gold_amount`, `payment_amount`, `payment_method`, `payment_type`, `receipt_method`, `party_balance_before`, `party_balance_after`, `party_gold_balance_before`, `party_gold_balance_after`, `booking_type`, `narration`, `created_at`, `updated_at`, `received_weight`, `fine_weight`, `delivered_weight`, `difference_weight`, `amount`, `due_amount`, `payment_status`, `created_by`) VALUES ('5', '12', NULL, NULL, 'STK-69CB9A1B39384', '', '2026-03-31 15:25:39', '100.000', '99.90', NULL, '0.00', '0.00', 'Cash', 'Payment_In', 'Cash', '0.00', '0.00', '0.000', '0.000', NULL, '[Gold] Stock Addition (SILVER - Cash): ', '2026-03-31 15:25:39', '2026-03-31 15:25:39', NULL, NULL, NULL, NULL, '0.00', '0.00', 'Pending', '12');


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
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`, `company_id`, `username`, `email`, `password`, `full_name`, `role`, `remember_token`, `remember_expires`, `last_login`, `created_at`, `status`) VALUES ('12', '12', '9810359441', NULL, '$2y$10$wI6RlVVJCxI6moMEiNvQEuVqjuoXHLF6zg5rgn2efidNJQGXFhY2W', 'prosenjit halder', 'Admin', 'a01562a81120b2079bca4d2457ca115bf88ac9ef61325b52c28250e5b55bfce7', '2026-04-29 15:38:07', '2026-03-30 19:08:24', '2026-03-30 19:05:26', 'Active');
