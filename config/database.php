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

// Create connection with auto-initialization for Desktop App
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
} catch (mysqli_sql_exception $e) {
    // Check if error is "Unknown database" (1049) and we are in Desktop mode
    if ($is_desktop_app && $e->getCode() === 1049) {
        // Connect without database selected
        $conn = new mysqli($db_host, $db_user, $db_pass, null, $db_port);
        
        // Create database
        if ($conn->query("CREATE DATABASE IF NOT EXISTS `$db_name`")) {
            $conn->select_db($db_name);
            
            // Import SQL schema
            $sql_file = __DIR__ . '/mormukut.sql';
            if (file_exists($sql_file)) {
                $sql_content = file_get_contents($sql_file);
                // Remove comments to avoid issues with some parsers, though multi_query handles most
                $sql_content = preg_replace('/^--.*$/m', '', $sql_content);
                
                // Execute multi-query
                if ($conn->multi_query($sql_content)) {
                    do {
                        // consume all results
                        if ($result = $conn->store_result()) {
                            $result->free();
                        }
                    } while ($conn->more_results() && $conn->next_result());
                } else {
                    die("Error importing database schema: " . $conn->error);
                }
            } else {
                die("Database schema file not found at: $sql_file");
            }
        } else {
            die("Error creating database: " . $conn->error);
        }
    } else {
        // Re-throw if it's not the unknown database error or not desktop app
        throw $e;
    }
}

// Check connection
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Connection failed: " . $conn->connect_error);
}

// Set charset
if (!$conn->set_charset("utf8mb4")) {
    error_log("Error setting charset: " . $conn->error);
}
?>