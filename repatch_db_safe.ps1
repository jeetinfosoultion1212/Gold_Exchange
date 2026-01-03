$content = @'
<?php
// Database Configuration (Patched + Enum Fix)
$is_desktop_app = true;
$db_host = '127.0.0.1';
$db_name = 'gold_exchange';
$db_user = 'root';
$db_pass = '';
$db_port = 3307;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
} catch (mysqli_sql_exception $e) {
    if ($e->getCode() === 1049) {
        try {
            $conn = new mysqli($db_host, $db_user, $db_pass, null, $db_port);
            $conn->query('CREATE DATABASE IF NOT EXISTS ' . $db_name);
            $conn->select_db($db_name);
            $schema_path = dirname(__DIR__) . '/auto_fix_schema.php';
            if (file_exists($schema_path)) {
                ob_start();
                require $schema_path;
                ob_end_clean();
            }
        } catch (Exception $ex) {
            die('DB Init Failed: ' . $ex->getMessage());
        }
    } else {
        die('Connection Failed: ' . $e->getMessage());
    }
}

if (!$conn->set_charset('utf8mb4')) { error_log('Error setting charset: ' . $conn->error); }

// AUTO-FIX ENUM TYPES
try {
    $enum_check = $conn->query("SHOW COLUMNS FROM transactions LIKE 'transaction_type'");
    if ($enum_check) {
        $row = $enum_check->fetch_assoc();
        if (stripos($row['Type'], 'Stock_Addition') === false) {
            // Add missing types: Stock_Addition, Stock_Reset, Transaction_Deleted
            $conn->query("ALTER TABLE transactions MODIFY COLUMN transaction_type ENUM('Booking','Sale','Purchase','Received','Payment','Gold_Received','Exchange','Stock_Addition','Stock_Reset','Transaction_Deleted') NOT NULL");
        }
    }
} catch (Exception $e) { /* Ignore */ }
?>
'@

$path = "C:\Users\ACER\AppData\Local\Programs\Gold Exchange\resources\server\www\config\database.php"
[System.IO.File]::WriteAllText($path, $content)
Write-Host "Repatched database.php with Enum fix (Safe Syntax)."
