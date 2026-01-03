$content = @"
<?php
/**
 * Database Configuration
 * Multi-Environment Support: Desktop App, Local Development, Production Hosting
 */

// Environment Detection
$is_desktop_app = (getenv('PHPDESKTOP_VERSION') !== false);
$is_production = (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') === false && strpos($_SERVER['HTTP_HOST'], '127.0.0.1') === false);

if ($is_desktop_app) {
    // DESKTOP APPLICATION (Standalone)
    $db_host = '127.0.0.1';
    $db_name = 'gold_exchange';
    $db_user = 'root';
    $db_pass = '';
    $db_port = 3307;
} elseif ($is_production) {
    // PRODUCTION - HOSTINGER
    $db_host = 'localhost';
    $db_name = 'u176143338_mormukut';
    $db_user = 'u176143338_mormukut';
    $db_pass = 'Mahalaxmi1234@#';
    $db_port = 3306;
} else {
    // LOCAL DEVELOPMENT
    $db_host = 'localhost';
    $db_name = 'mormukut';
    $db_user = 'root';
    $db_pass = '';
    $db_port = 3306;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
} catch (mysqli_sql_exception $e) {
    if ($e->getCode() === 1049 && $is_desktop_app) {
        try {
            // Create Database
            $conn = new mysqli($db_host, $db_user, $db_pass, null, $db_port);
            $conn->query("CREATE DATABASE IF NOT EXISTS \`$db_name\`");
            $conn->select_db($db_name);
            
            // Auto-Run Schema Fix
            $schema_fix_file = dirname(__DIR__) . '/auto_fix_schema.php';
            if (file_exists($schema_fix_file)) {
                ob_start(); 
                require $schema_fix_file;
                ob_end_clean();
            }
        } catch (Exception $ex) {
            die("Database initialization failed: " . $ex->getMessage());
        }
    } else {
        error_log("Database connection failed: " . $e->getMessage());
        die("Connection failed: " . $e->getMessage());
    }
}

if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Connection failed: " . $conn->connect_error);
}

if (!$conn->set_charset("utf8mb4")) {
    error_log("Error setting charset: " . $conn->error);
}
?>
"@

$path = "d:\Project\Gold_Exchange\electron-desktop\server\www\config\database.php"
Set-Content -Path $path -Value $content
Write-Host "Updated source database.php successfully"
