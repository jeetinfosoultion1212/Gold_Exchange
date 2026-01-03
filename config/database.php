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

// Create connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);

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