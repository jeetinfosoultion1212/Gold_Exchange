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
    // ============================================
    // DESKTOP APPLICATION (Standalone)
    // ============================================
    $db_host = '127.0.0.1';
    $db_name = 'gold_exchange';
    $db_user = 'root';
    $db_pass = '';
    $db_port = 3307;
    
} elseif ($is_production) {
    // ============================================
    // PRODUCTION - HOSTINGER
    // ============================================
    $db_host = 'localhost';
    $db_name = 'u176143338_mormukut';
    $db_user = 'u176143338_mormukut';
    $db_pass = 'Mahalaxmi1234@#';
    $db_port = 3306;
    
} else {
    // ============================================
    // LOCAL DEVELOPMENT - XAMPP/WAMP
    // ============================================
    $db_host = 'localhost';
    $db_name = 'mormukut';
    $db_user = 'root';
    $db_pass = '';
    $db_port = 3306;
}

// Enable error reporting mode for mysqli to throw exceptions
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // Attempt connection
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
    
} catch (mysqli_sql_exception $e) {
    // Check for "Unknown database" error (Error Code 1049)
    if ($e->getCode() === 1049 && $is_desktop_app) {
        try {
            // Connect without database selected
            $conn = new mysqli($db_host, $db_user, $db_pass, null, $db_port);
            
            // Create the database
            if ($conn->query("CREATE DATABASE IF NOT EXISTS \`$db_name\`")) {
                $conn->select_db($db_name);
                
                // Initialize Schema if needed
                // If you have a schema file, you could check and load it here
                // For now, we at least ensure the DB exists so the app doesn't crash on connect
            } else {
                die("Failed to create database: " . $conn->error);
            }
        } catch (Exception $ex) {
            die("Database initialization failed: " . $ex->getMessage());
        }
    } else {
        // Log real error and die
        error_log("Database connection failed: " . $e->getMessage());
        die("Connection failed: " . $e->getMessage());
    }
}

// Check connection (legacy check, though try-catch handles most)
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Connection failed: " . $conn->connect_error);
}

// Set charset
if (!$conn->set_charset("utf8mb4")) {
    error_log("Error setting charset: " . $conn->error);
}
?>
"@

$path = "d:\Project\Gold_Exchange\electron-desktop\server\www\config\database.php"
Set-Content -Path $path -Value $content
Write-Host "Updated database.php successfully via PowerShell"
