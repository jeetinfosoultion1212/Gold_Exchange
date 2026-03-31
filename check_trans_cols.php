<?php
require_once 'config/database.php';
$res = $conn->query("DESCRIBE transactions");
$cols = [];
while($row = $res->fetch_assoc()) {
    $cols[] = $row['Field'];
}
echo implode(", ", $cols);
?>
