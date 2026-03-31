<?php
require_once 'config/database.php';
$res = $conn->query("DESCRIBE gold_sale_items");
if (!$res) {
    echo "Error: " . $conn->error;
    exit;
}
$cols = [];
while($row = $res->fetch_assoc()) {
    $cols[] = $row['Field'];
}
echo implode(", ", $cols);
?>
