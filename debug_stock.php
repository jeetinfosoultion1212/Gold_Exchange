<?php
session_start();
require_once 'config/database.php';

$company_id = $_SESSION['company_id'] ?? 1;

echo "<h2>Gold Stock Debug</h2>";
echo "<p>Current company_id from session: $company_id</p>";

// Check all gold stock entries
$sql = "SELECT id, company_id, stock_name, purity, current_stock FROM gold_stock ORDER BY purity DESC";
$result = $conn->query($sql);

echo "<h3>All Gold Stock Entries:</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Company ID</th><th>Stock Name</th><th>Purity</th><th>Current Stock</th></tr>";

while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>{$row['id']}</td>";
    echo "<td>{$row['company_id']}</td>";
    echo "<td>{$row['stock_name']}</td>";
    echo "<td>{$row['purity']}</td>";
    echo "<td>{$row['current_stock']}</td>";
    echo "</tr>";
}
echo "</table>";

// Try the exact query from gold_exchange.php
echo "<h3>Query Result (purity = 100.00, company_id = $company_id):</h3>";
$test_sql = "SELECT id, current_stock, stock_name FROM gold_stock WHERE company_id = $company_id AND purity = 100.00 ORDER BY id ASC LIMIT 1";
echo "<p>SQL: $test_sql</p>";
$test_result = $conn->query($test_sql);

if ($test_result->num_rows > 0) {
    $row = $test_result->fetch_assoc();
    echo "<p style='color: green;'>✓ Found: ID={$row['id']}, Name={$row['stock_name']}, Stock={$row['current_stock']}</p>";
} else {
    echo "<p style='color: red;'>✗ No matching stock found!</p>";
}
?>
