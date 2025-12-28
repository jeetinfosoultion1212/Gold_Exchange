<?php
// install_account_balances.php
require_once __DIR__ . '/config/database.php';

// 1. Create Table
$sql = "CREATE TABLE IF NOT EXISTS account_balances (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    account_type ENUM('Cash', 'Bank', 'UPI') NOT NULL,
    opening_balance DECIMAL(15,2) DEFAULT 0.00,
    current_balance DECIMAL(15,2) DEFAULT 0.00,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_company_account (company_id, account_type),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

if ($conn->query($sql) === TRUE) {
    echo "Table 'account_balances' created successfully.<br>";
} else {
    die("Error creating table: " . $conn->error);
}

// 2. Migrate Data from old cash_balance table if it exists
$check_old = $conn->query("SHOW TABLES LIKE 'cash_balance'");
if ($check_old->num_rows > 0) {
    echo "Found existing 'cash_balance' table. Migrating data...<br>";
    
    $migrate_sql = "INSERT INTO account_balances (company_id, account_type, opening_balance, current_balance)
                    SELECT company_id, 'Cash', opening_balance, current_balance 
                    FROM cash_balance
                    ON DUPLICATE KEY UPDATE current_balance = VALUES(current_balance)";
                    
    if ($conn->query($migrate_sql) === TRUE) {
        echo "Data migrated successfully.<br>";
        
        // Optional: Rename old table instead of dropping to be safe
        $conn->query("RENAME TABLE cash_balance TO cash_balance_backup");
        echo "Old table renamed to 'cash_balance_backup'.<br>";
    } else {
        echo "Error migrating data: " . $conn->error . "<br>";
    }
} else {
    echo "No existing 'cash_balance' table found. Creating default records...<br>";
    
    // Initialize defaults for all companies
    $companies = $conn->query("SELECT id FROM companies");
    while($row = $companies->fetch_assoc()) {
        $cid = $row['id'];
        $conn->query("INSERT IGNORE INTO account_balances (company_id, account_type, opening_balance, current_balance) VALUES ($cid, 'Cash', 0, 0)");
        $conn->query("INSERT IGNORE INTO account_balances (company_id, account_type, opening_balance, current_balance) VALUES ($cid, 'Bank', 0, 0)");
    }
    echo "Default records initialized.<br>";
}

echo "Installation complete!";
?>
