<?php
require_once __DIR__ . '/../config/database.php';
ob_start();
echo "COLUMNS: ";
$res = $conn->query("DESCRIBE companies");
while($row = $res->fetch_assoc()) echo $row['Field'] . " ";
echo "\nDATA: ";
$res = $conn->query("SELECT * FROM companies LIMIT 1");
print_r($res->fetch_assoc());
$out = ob_get_clean();
file_put_contents(__DIR__ . '/db_check.txt', $out);
echo "Check completed. See db_check.txt";
?>
