$content = @"
<?php
/**
 * Database Configuration (Patched)
 */
// Environment Detection
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
    if ($e->getCode() === 1049) { // Unknown database
        try {
            $conn = new mysqli($db_host, $db_user, $db_pass, null, $db_port);
            $conn->query("CREATE DATABASE IF NOT EXISTS \`$db_name\`");
            $conn->select_db($db_name);
        } catch (Exception $ex) {
            die("DB Init Failed: " . $ex->getMessage());
        }
    } else {
        die("Connection Failed: " . $e->getMessage());
    }
}
if (!$conn->set_charset("utf8mb4")) { error_log("Error setting charset: " . $conn->error); }
?>
"@

$path = "C:\Users\ACER\AppData\Local\Programs\Gold Exchange\resources\server\www\config\database.php"
if (Test-Path $path) {
    Set-Content -Path $path -Value $content
    Write-Host "Patched installed app successfully."
} else {
    Write-Host "Could not find installed app file at $path"
}
