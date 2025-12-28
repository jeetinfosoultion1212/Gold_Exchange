<?php
require_once 'config/database.php';
session_start();
$company_id = $_SESSION['company_id'] ?? 1;

echo "<h2>Migrating to MIX Stock (Purity 0) for Company ID: $company_id</h2>";

// 1. Calculate TOTAL received weight from ALL Exchange transactions
$sql = "SELECT SUM(received_weight) as total_weight 
        FROM transactions 
        WHERE company_id = ? 
        AND transaction_type = 'Exchange' 
        AND received_weight > 0";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $company_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$total_weight = floatval($row['total_weight']);

echo "<p>Total Exchange Received Weight Calculated: <strong>$total_weight g</strong></p>";

// 2. Update or Create 'MIX Stock' (Purity 0)
$check_mix = $conn->query("SELECT id, current_stock FROM gold_stock WHERE company_id = $company_id AND stock_name = 'MIX Stock' AND purity = 0");

if ($check_mix->num_rows > 0) {
    $mix_row = $check_mix->fetch_assoc();
    $conn->query("UPDATE gold_stock SET current_stock = $total_weight, last_updated = NOW() WHERE id = {$mix_row['id']}");
    echo "<p>Updated existing 'MIX Stock' to $total_weight g.</p>";
} else {
    $conn->query("INSERT INTO gold_stock (company_id, stock_name, purity, current_stock, last_updated) VALUES ($company_id, 'MIX Stock', 0, $total_weight, NOW())");
    echo "<p>Created new 'MIX Stock' with $total_weight g.</p>";
}

// 3. Delete old 'Exchange Received - %' stocks to avoid duplication/confusion
// Only delete stocks that exactly match our auto-generated naming convention
$cleanup_sql = "DELETE FROM gold_stock WHERE company_id = $company_id AND stock_name LIKE 'Exchange Received - %'";
if ($conn->query($cleanup_sql)) {
    echo "<p>Cleaned up old 'Exchange Received - %' stock entries.</p>";
} else {
    echo "<p>Error cleaning up old entries: " . $conn->error . "</p>";
}

echo "<p><strong>Migration Complete.</strong></p>";
?>
