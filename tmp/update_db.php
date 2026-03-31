<?php
require_once __DIR__ . '/../config/database.php';

echo "Starting Database Migration...\n";

// 1. Alter companies table
$sql1 = "ALTER TABLE companies 
        ADD COLUMN IF NOT EXISTS state VARCHAR(100) DEFAULT NULL AFTER company_address,
        ADD COLUMN IF NOT EXISTS city VARCHAR(100) DEFAULT NULL AFTER state,
        ADD COLUMN IF NOT EXISTS gstin VARCHAR(50) DEFAULT NULL AFTER city";

if ($conn->query($sql1)) {
    echo "✔ Companies table updated with tax fields.\n";
} else {
    echo "✘ Error updating companies table: " . $conn->error . "\n";
}

// 2. Create company_banks table
$sql2 = "CREATE TABLE IF NOT EXISTS company_banks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    account_holder_name VARCHAR(255) DEFAULT NULL,
    bank_name VARCHAR(255) DEFAULT NULL,
    account_no VARCHAR(100) DEFAULT NULL,
    ifsc_code VARCHAR(50) DEFAULT NULL,
    branch_name VARCHAR(255) DEFAULT NULL,
    is_primary TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql2)) {
    echo "✔ Company Banks table created successfully.\n";
} else {
    echo "✘ Error creating company_banks table: " . $conn->error . "\n";
}

// 3. Update existing parties with default state if empty
$sql3 = "UPDATE parties SET state = 'West Bengal' WHERE (state IS NULL OR state = '')";
if ($conn->query($sql3)) {
    echo "✔ Existing parties updated with default state.\n";
}

// 4. Set a sample state for current companies to test GST logic
$sql4 = "UPDATE companies SET state = 'West Bengal' WHERE (state IS NULL OR state = '')";
if ($conn->query($sql4)) {
    echo "✔ Current companies updated with default state.\n";
}

echo "Migration Completed Successfully.\n";
echo "<br><br><a href='../settings.php' style='padding:10px 20px; background:#2563eb; color:white; text-decoration:none; border-radius:5px; font-family:sans-serif;'>✔ Return to Settings</a>";
?>
