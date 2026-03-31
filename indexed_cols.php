<?php
require_once 'config/database.php';
$res = $conn->query("DESCRIBE transactions");
$i = 1;
while($row = $res->fetch_assoc()) {
    echo $i . ": " . $row['Field'] . "\n";
    $i++;
}
?>
