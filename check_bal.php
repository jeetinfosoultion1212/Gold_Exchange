<?php
require_once 'config/database.php';
session_start();
$company_id = $_SESSION['company_id'] ?? 1; // Default to 1 if no session

echo "Checking balances for Company ID: $company_id\n";
$sql = "SELECT * FROM account_balances WHERE company_id = $company_id";
$result = $conn->query($sql);
while($row = $result->fetch_assoc()) {
    print_r($row);
}
?>
