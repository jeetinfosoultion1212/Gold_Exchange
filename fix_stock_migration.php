<?php
require_once 'config/database.php';
session_start();
$company_id = $_SESSION['company_id'] ?? 1;

echo "<h2>Migrating Exchange Received Stock for Company ID: $company_id</h2>";

// 1. Calculate total received weight per purity from transactions
$sql = "SELECT purity, SUM(received_weight) as total_weight 
        FROM transactions 
        WHERE company_id = ? 
        AND transaction_type = 'Exchange' 
        AND received_weight > 0 
        GROUP BY purity";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $company_id);
$stmt->execute();
$result = $stmt->get_result();

echo "<ul>";
while ($row = $result->fetch_assoc()) {
    $purity = floatval($row['purity']);
    $weight = floatval($row['total_weight']);
    $stock_name = "Exchange Received - " . $purity . "%";

    echo "<li>Found $weight g of $purity% purity (Target Stock Name: '$stock_name')";

    // 2. Check if this stock exists
    $check_stock = $conn->query("SELECT id, current_stock FROM gold_stock WHERE company_id = $company_id AND stock_name = '$stock_name' AND purity = $purity");
    
    if ($check_stock->num_rows > 0) {
        $stock_row = $check_stock->fetch_assoc();
        $current = $stock_row['current_stock'];
        echo " - Stock exists (Current: $current g). ";
        
        // Update if significantly different (assuming we want to sync perfectly with history + any manual edits? 
        // Actually, since this is a new feature, we should probably OVERWRITE or ADD?
        // Let's assume the user hasn't manually adjusted this NEW stock type yet. 
        // So we can set it to the calculated total.
        
        $update = $conn->query("UPDATE gold_stock SET current_stock = $weight, last_updated = NOW() WHERE id = {$stock_row['id']}");
        echo $update ? "Updated to $weight g." : "Update Failed.";
        
    } else {
        // Create new stock
        $insert = $conn->query("INSERT INTO gold_stock (company_id, stock_name, purity, current_stock, last_updated) VALUES ($company_id, '$stock_name', $purity, $weight, NOW())");
        echo " - Created new stock entry with $weight g.";
    }
    echo "</li>";
}
echo "</ul>";
echo "<p>Done. Please go back to Report page.</p>";
?>
