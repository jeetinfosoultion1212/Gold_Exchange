-- Gold Exchange - Database Schema (Offline / Desktop)
-- Creates database and all application tables with no sample data.
-- Compatible with MariaDB 10.4+ / MySQL 8+

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `gold_exchange`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `gold_exchange`;

DROP TABLE IF EXISTS `logo_marking_items`;
DROP TABLE IF EXISTS `jeweller_product_rates`;
DROP TABLE IF EXISTS `master_item_categories`;
DROP TABLE IF EXISTS `exchange_items`;
DROP TABLE IF EXISTS `gold_purchase_items`;
DROP TABLE IF EXISTS `gold_sale_items`;
DROP TABLE IF EXISTS `gold_purchase`;
DROP TABLE IF EXISTS `payment_transactions`;
DROP TABLE IF EXISTS `transactions`;
DROP TABLE IF EXISTS `transaction_summary`;
DROP TABLE IF EXISTS `audit_log`;
DROP TABLE IF EXISTS `system_settings`;
DROP TABLE IF EXISTS `account_balances`;
DROP TABLE IF EXISTS `company_banks`;
DROP TABLE IF EXISTS `gold_stock`;
DROP TABLE IF EXISTS `parties`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `companies`;

CREATE TABLE `account_balances` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `account_type` enum('Cash','Bank','UPI') NOT NULL,
  `opening_balance` decimal(15,2) DEFAULT 0.00,
  `current_balance` decimal(15,2) DEFAULT 0.00,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `payment_status` enum('Paid','Partial','Due','Pending') DEFAULT 'Paid',
  `narration` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `gold_purchase_items` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `receipt_id` varchar(50) NOT NULL,
  `stock_name` varchar(100) NOT NULL,
  `gold_weight` decimal(15,3) NOT NULL,
  `purity` decimal(10,2) NOT NULL,
  `stock_ref_id` int(11) DEFAULT NULL,
  `fine_weight` decimal(15,3) NOT NULL,
  `rate` decimal(15,2) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `gold_sale_items` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `receipt_id` varchar(50) NOT NULL,
  `stock_name` varchar(100) NOT NULL,
  `gold_weight` decimal(15,3) NOT NULL,
  `purity` decimal(10,2) NOT NULL,
  `stock_ref_id` int(11) DEFAULT NULL,
  `fine_weight` decimal(15,3) NOT NULL,
  `rate` decimal(15,2) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

CREATE TABLE `jeweller_product_rates` (
  `id` int(11) NOT NULL,
  `jeweller_id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `firm_id` int(11) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `logo_marking_items` (
  `id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `item_category` varchar(255) DEFAULT NULL,
  `pieces` int(11) NOT NULL DEFAULT 1,
  `weight` decimal(10,3) DEFAULT NULL,
  `purity` varchar(20) DEFAULT NULL,
  `rate_per_piece` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` enum('Pending','Done','Cancelled') NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `master_item_categories` (
  `id` int(11) NOT NULL,
  `firm_id` int(11) NOT NULL,
  `category_name` varchar(255) NOT NULL,
  `default_rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `rate_basis` enum('per_piece','per_gram') NOT NULL DEFAULT 'per_piece',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `parties` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `party_name` varchar(255) NOT NULL,
  `contact_no` varchar(20) DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
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

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `party_id` int(11) DEFAULT NULL,
  `receipt_id` varchar(50) NOT NULL,
  `transaction_type` enum('Booking','Sale','Purchase','Received','Payment','Gold_Received','Exchange','Personal_Expense','Stock_Addition','Stock_Reset','Logo_Marking') NOT NULL,
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
  `created_by` int(11) NOT NULL DEFAULT 0,
  `fine_transferred` decimal(10,3) NOT NULL DEFAULT 0.000,
  `logo` varchar(255) DEFAULT NULL,
  `box_no` varchar(100) DEFAULT NULL,
  `contact_mobile` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `transaction_summary` (
  `id` int(11) DEFAULT NULL,
  `company_id` int(11) DEFAULT NULL,
  `party_id` int(11) DEFAULT NULL,
  `party_name` varchar(255) DEFAULT NULL,
  `receipt_id` varchar(50) DEFAULT NULL,
  `transaction_type` enum('Booking','Sale','Purchase','Received','Payment','Gold_Received','Exchange','Personal_Expense','Stock_Addition','Stock_Reset','Logo_Marking') DEFAULT NULL,
  `date_of_transaction` datetime DEFAULT NULL,
  `gold_weight` decimal(10,3) DEFAULT NULL,
  `rate` decimal(10,2) DEFAULT NULL,
  `gold_amount` decimal(15,2) DEFAULT NULL,
  `payment_amount` decimal(15,2) DEFAULT NULL,
  `payment_method` enum('Cash','Bank','UPI','Cheque') DEFAULT NULL,
  `payment_type` enum('Payment_In','Payment_Out') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Indexes
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
-- Indexes for table `gold_purchase_items`
--
ALTER TABLE `gold_purchase_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_gp_tx` (`transaction_id`),
  ADD KEY `idx_gp_co` (`company_id`);

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
-- Indexes for table `jeweller_product_rates`
--
ALTER TABLE `jeweller_product_rates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_jpr_jeweller_product` (`firm_id`,`jeweller_id`,`product_name`),
  ADD KEY `idx_jpr_firm_jeweller` (`firm_id`,`jeweller_id`),
  ADD KEY `idx_jpr_category` (`category_id`);

--
-- Indexes for table `logo_marking_items`
--
ALTER TABLE `logo_marking_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lmi_tx` (`transaction_id`),
  ADD KEY `idx_lmi_company` (`company_id`);

--
-- Indexes for table `master_item_categories`
--
ALTER TABLE `master_item_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_mic_firm_name` (`firm_id`,`category_name`),
  ADD KEY `idx_mic_firm` (`firm_id`);

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

-- AUTO_INCREMENT
--

--
-- AUTO_INCREMENT for table `account_balances`
--
ALTER TABLE `account_balances`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `companies`
--
ALTER TABLE `companies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `company_banks`
--
ALTER TABLE `company_banks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `exchange_items`
--
ALTER TABLE `exchange_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `gold_purchase`
--
ALTER TABLE `gold_purchase`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gold_purchase_items`
--
ALTER TABLE `gold_purchase_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `gold_sale_items`
--
ALTER TABLE `gold_sale_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `gold_stock`
--
ALTER TABLE `gold_stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `jeweller_product_rates`
--
ALTER TABLE `jeweller_product_rates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `logo_marking_items`
--
ALTER TABLE `logo_marking_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `master_item_categories`
--
ALTER TABLE `master_item_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `parties`
--
ALTER TABLE `parties`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `payment_transactions`
--
ALTER TABLE `payment_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--

-- Foreign key constraints
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

SET FOREIGN_KEY_CHECKS = 1;
