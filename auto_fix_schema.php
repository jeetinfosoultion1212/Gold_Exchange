<?php
// auto_fix_schema.php
// Automatically fixes the database schema by adding missing columns and tables.

$db_config = include __DIR__ . '/config/database.php';

// Detect environment and choose DB connection parameters
if (getenv('PHPDESKTOP_VERSION')) {
    // Desktop App - use hardcoded settings for reliability
    $fix_host = '127.0.0.1';
    $fix_user = 'root';
    $fix_pass = '';
    $fix_db   = 'gold_exchange';
    $fix_port = 3307;
} else {
    // Normal Web Environment
    $fix_host = 'localhost';
    $fix_user = 'root';
    $fix_pass = '';
    $fix_db   = 'gold_exchange';
    $fix_port = 3306;
}

$fix_conn = new mysqli($fix_host, $fix_user, $fix_pass, $fix_db, $fix_port);

if ($fix_conn->connect_error) {
    error_log("Schema Fix: Connection failed: " . $fix_conn->connect_error);
    return; // Silent fail if DB not reachable yet
}

function checkAndAddColumn($conn, $table, $column, $definition) {
    $check_sql = "SHOW COLUMNS FROM `$table` LIKE '$column'";
    $result = $conn->query($check_sql);
    
    if ($result && $result->num_rows == 0) {
        $alter_sql = "ALTER TABLE `$table` ADD COLUMN $column $definition";
        if ($conn->query($alter_sql)) {
            error_log("Schema Fix: Added column $column to $table");
        } else {
            error_log("Schema Fix Error: Failed to add $column to $table - " . $conn->error);
        }
    }
}

function checkAndCreateTable($conn, $table, $createSql) {
    $check_sql = "SHOW TABLES LIKE '$table'";
    $result = $conn->query($check_sql);
    
    if ($result && $result->num_rows == 0) {
        if ($conn->query($createSql)) {
            error_log("Schema Fix: Created table $table");
            return true;
        } else {
            error_log("Schema Fix Error: Failed to create table $table - " . $conn->error);
            return false;
        }
    }
    return false;
}

// 1. Create missing tables (Full Schema from mormukut.sql)

// Companies
$companies_sql = "CREATE TABLE IF NOT EXISTS `companies` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
checkAndCreateTable($fix_conn, 'companies', $companies_sql);

// Users
$users_sql = "CREATE TABLE IF NOT EXISTS `users` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
checkAndCreateTable($fix_conn, 'users', $users_sql);

// Parties
$parties_sql = "CREATE TABLE IF NOT EXISTS `parties` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
checkAndCreateTable($fix_conn, 'parties', $parties_sql);

// Gold Stock
$gold_stock_sql = "CREATE TABLE IF NOT EXISTS `gold_stock` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
checkAndCreateTable($fix_conn, 'gold_stock', $gold_stock_sql);

// Account Balances
$account_balances_sql = "CREATE TABLE IF NOT EXISTS `account_balances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `account_type` enum('Cash','Bank','UPI') NOT NULL,
  `opening_balance` decimal(15,2) DEFAULT 0.00,
  `current_balance` decimal(15,2) DEFAULT 0.00,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_company_account` (`company_id`,`account_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
checkAndCreateTable($fix_conn, 'account_balances', $account_balances_sql);

// Transactions (Base Table)
$transactions_sql = "CREATE TABLE IF NOT EXISTS `transactions` (
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
  `created_by` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_receipt_id` (`receipt_id`),
  KEY `fk_transactions_company` (`company_id`),
  KEY `fk_transactions_user` (`user_id`),
  KEY `fk_transactions_party` (`party_id`),
  KEY `idx_transaction_type` (`transaction_type`),
  KEY `idx_date_of_transaction` (`date_of_transaction`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
checkAndCreateTable($fix_conn, 'transactions', $transactions_sql);

// System Settings
$system_settings_sql = "CREATE TABLE IF NOT EXISTS `system_settings` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
checkAndCreateTable($fix_conn, 'system_settings', $system_settings_sql);

// Audit Log
$audit_log_sql = "CREATE TABLE IF NOT EXISTS `audit_log` (
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
  KEY `fk_audit_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
checkAndCreateTable($fix_conn, 'audit_log', $audit_log_sql);

// Fix 'transactions' table columns
// Fix 'transactions' table columns
checkAndAddColumn($fix_conn, 'transactions', 'received_weight', "decimal(10,3) DEFAULT NULL");
checkAndAddColumn($fix_conn, 'transactions', 'fine_weight', "decimal(10,3) DEFAULT NULL");
checkAndAddColumn($fix_conn, 'transactions', 'delivered_weight', "decimal(10,3) DEFAULT NULL");
checkAndAddColumn($fix_conn, 'transactions', 'difference_weight', "decimal(10,3) DEFAULT NULL");
checkAndAddColumn($fix_conn, 'transactions', 'amount', "decimal(15,2) DEFAULT 0.00");
checkAndAddColumn($fix_conn, 'transactions', 'due_amount', "decimal(15,2) DEFAULT 0.00");
checkAndAddColumn($fix_conn, 'transactions', 'payment_status', "enum('Paid','Partial','Due','Pending') DEFAULT 'Pending'");
checkAndAddColumn($fix_conn, 'transactions', 'created_by', "int(11) NOT NULL DEFAULT 0");

// FORCE AUTO_INCREMENT on ID columns (Fix for "Field 'id' doesn't have a default value")
$tables_to_fix_id = ['gold_stock', 'transactions', 'parties', 'users', 'companies', 'account_balances', 'audit_log', 'system_settings'];
foreach ($tables_to_fix_id as $t) {
    // Check if table exists first
    $check_t = $fix_conn->query("SHOW TABLES LIKE '$t'");
    if ($check_t && $check_t->num_rows > 0) {
        // Modify column to ensure AUTO_INCREMENT
        // Note: We assume 'id' corresponds to the first integer primary key
        $sql = "ALTER TABLE `$t` MODIFY COLUMN `id` int(11) NOT NULL AUTO_INCREMENT";
        if (!$fix_conn->query($sql)) {
             error_log("Schema Fix: Failed to set AUTO_INCREMENT for $t.id: " . $fix_conn->error);
        } else {
             error_log("Schema Fix: Enforced AUTO_INCREMENT on $t.id");
        }
    }
}

$fix_conn->close();
?>
