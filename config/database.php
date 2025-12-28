<?php
/**
 * Database Configuration
 * Simple database connection settings
 * Localhost Development Database
 */

// Database settings
// Environment Detection: Check for PHP Desktop-specific environment variable
// This is more robust than checking file paths
$is_desktop_app = (getenv('PHPDESKTOP_VERSION') !== false);

if ($is_desktop_app) {
    // Desktop / Offline Settings (MariaDB Portable)
    $db_host = '127.0.0.1';
    $db_name = 'gold_exchange';
    $db_user = 'root';
    $db_pass = '';
    $db_port = 3307;
} else {
    // Localhost Development Defaults
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