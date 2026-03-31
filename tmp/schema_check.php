<?php
require_once 'config/database.php';
$res = $conn->query("DESCRIBE transactions");
echo "=== transactions ===\n";
while($row = $res->fetch_assoc()) print_r($row);

$res = $conn->query("DESCRIBE gold_stock");
echo "=== gold_stock ===\n";
while($row = $res->fetch_assoc()) print_r($row);
?>
