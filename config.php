<?php
// Prevent direct access to this file
if (!defined('ALLOW_ACCESS') && basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    exit('Direct access not permitted');
}

// Database configuration
// Environment Detection: Check for PHP Desktop-specific environment variable
$is_desktop_app = (getenv('PHPDESKTOP_VERSION') !== false);

if ($is_desktop_app) {
    // Desktop / Offline Settings (MariaDB Portable)
    define('DB_HOST', '127.0.0.1');
    define('DB_NAME', 'gold_exchange');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_PORT', 3307); // Custom port for desktop app
} else {
    // Web / Server Settings (Default)
    if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
    if (!defined('DB_NAME')) define('DB_NAME', 'u176143338_jewellery_baza');
    if (!defined('DB_USER')) define('DB_USER', 'u176143338_jewellery_baza');
    if (!defined('DB_PASS')) define('DB_PASS', 'Suniprosen2511@#');
}
// Create connection
try {
    $port = defined('DB_PORT') ? DB_PORT : 3306;
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, $port);
    
    // Check connection
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Set charset to utf8mb4
    $conn->set_charset("utf8mb4");
    
    // Set timezone
    date_default_timezone_set('Asia/Kolkata');
    
    // Enable error reporting for development
    // Comment these out in production
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
} catch (Exception $e) {
    // Log the error (in production, you'd want to log this to a file)
    error_log($e->getMessage());
    
    // Show a user-friendly message
    die("Sorry, there was a problem connecting to the database. Please try again later.");
}

// Common functions
function sanitize_input($data) {
    global $conn;
    return $conn->real_escape_string(trim($data));
}

function format_number($number, $decimals = 2) {
    return number_format((float)$number, $decimals, '.', '');
}

// Error handling function
function handle_error($message, $sql_error = '') {
    $error = [
        'status' => 'error',
        'message' => $message
    ];
    
    if ($sql_error && defined('DEBUG_MODE') && DEBUG_MODE) {
        $error['sql_error'] = $sql_error;
    }
    
    return json_encode($error);
}

// Success response function
function handle_success($data = [], $message = 'Operation successful') {
    return json_encode([
        'status' => 'success',
        'message' => $message,
        'data' => $data
    ]);
}

// Constants
define('DEBUG_MODE', true); // Set to false in production
define('DATE_FORMAT', 'Y-m-d H:i:s');
define('CURRENCY_SYMBOL', '₹');

// Session configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
?>