<?php
require_once 'config/database.php';

$sql1 = "DROP TABLE IF EXISTS gold_sales";
$sql2 = "CREATE TABLE gold_sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    transaction_id INT NOT NULL,
    receipt_id VARCHAR(50) NOT NULL,
    stock_name VARCHAR(100) NOT NULL,
    gold_weight DECIMAL(15,3) NOT NULL,
    purity DECIMAL(10,2) NOT NULL,
    fine_weight DECIMAL(15,3) NOT NULL,
    rate DECIMAL(15,2) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if ($conn->query($sql1) && $conn->query($sql2)) {
    echo "Table gold_sales dropped and gold_sale_items created successfully\n";
} else {
    echo "Error: " . $conn->error . "\n";
}
?>
