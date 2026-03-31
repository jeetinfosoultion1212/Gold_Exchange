<?php
require_once 'config/database.php';
$sql = "ALTER TABLE transactions 
ADD COLUMN mode VARCHAR(20) DEFAULT 'Cash' AFTER receipt_method,
ADD COLUMN taxable_amount DECIMAL(15,2) DEFAULT NULL AFTER amount,
ADD COLUMN cgst DECIMAL(15,2) DEFAULT NULL AFTER taxable_amount,
ADD COLUMN sgst DECIMAL(15,2) DEFAULT NULL AFTER cgst,
ADD COLUMN igst DECIMAL(15,2) DEFAULT NULL AFTER sgst,
ADD COLUMN total_gst DECIMAL(15,2) DEFAULT NULL AFTER igst";

if ($conn->query($sql)) echo "Table transactions updated successfully\n";
else echo "Error updating table: " . $conn->error . "\n";
?>
