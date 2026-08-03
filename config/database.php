<?php
/**
 * Database Configuration
 * Multi-Environment Support: Desktop App, Local Development, Production Hosting
 */

// Environment Detection
$is_desktop_app = (getenv('PHPDESKTOP_VERSION') !== false && getenv('PHPDESKTOP_VERSION') !== '')
    || (isset($_SERVER['PHPDESKTOP_VERSION']) && $_SERVER['PHPDESKTOP_VERSION'] !== '')
    || (getenv('GOLD_EXCHANGE_DESKTOP') !== false && getenv('GOLD_EXCHANGE_DESKTOP') !== '');
$is_production = (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') === false && strpos($_SERVER['HTTP_HOST'], '127.0.0.1') === false);

if ($is_desktop_app) {
    // ============================================
    // DESKTOP APPLICATION (Standalone)
    // Bundled MariaDB uses 3307; XAMPP local test uses 3306 (override via GOLD_EXCHANGE_DB_PORT).
    // ============================================
    $db_host = '127.0.0.1';
    $db_name = 'gold_exchange';
    $db_user = 'root';
    $db_pass = '';
    $db_port = (int) (getenv('GOLD_EXCHANGE_DB_PORT') ?: 3307);
    
} elseif ($is_production) {
    // ============================================
    // PRODUCTION - HOSTINGER
    // ============================================
    $db_host = 'localhost';
    $db_name = 'u176143338_gold_exhange';
    $db_user = 'u176143338_gold_exhange';
    $db_pass = 'Suniprosern25@#';
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
$init_desktop_schema = static function (mysqli $connection, string $database): void {
    if (!$connection->query("CREATE DATABASE IF NOT EXISTS `$database`")) {
        die("Error creating database: " . $connection->error);
    }
    $connection->select_db($database);

    $sql_file = __DIR__ . '/database_schamas.sql';
    if (!file_exists($sql_file)) {
        die("Database schema file not found at: $sql_file");
    }

    $sql_content = file_get_contents($sql_file);
    $sql_content = preg_replace('/^--.*$/m', '', $sql_content);

    if ($connection->multi_query($sql_content)) {
        do {
            if ($result = $connection->store_result()) {
                $result->free();
            }
        } while ($connection->more_results() && $connection->next_result());
    } else {
        die("Error importing database schema: " . $connection->error);
    }

    $fix_file = __DIR__ . '/desktop_fix_schema.sql';
    if (file_exists($fix_file)) {
        $fix_sql = preg_replace('/^--.*$/m', '', file_get_contents($fix_file));
        if ($connection->multi_query($fix_sql)) {
            do {
                if ($result = $connection->store_result()) {
                    $result->free();
                }
            } while ($connection->more_results() && $connection->next_result());
        }
    }
};

$apply_desktop_schema_fix = static function (mysqli $connection): void {
    static $applied = false;
    if ($applied) {
        return;
    }
    $applied = true;

    $fix_file = __DIR__ . '/desktop_fix_schema.sql';
    if (!file_exists($fix_file)) {
        return;
    }

    try {
        $connection->query(
            "CREATE TABLE IF NOT EXISTS `system_settings` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `company_id` int(11) NOT NULL,
                `setting_key` varchar(100) NOT NULL,
                `setting_value` text DEFAULT NULL,
                `setting_type` enum('string','number','boolean','json') DEFAULT 'string',
                `description` text DEFAULT NULL,
                `created_at` timestamp NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `unique_company_setting` (`company_id`,`setting_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $check = $connection->prepare(
            "SELECT id FROM system_settings WHERE company_id = 0 AND setting_key = 'desktop_schema_fix_v2' LIMIT 1"
        );
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $check->close();
            return;
        }
        $check->close();

        $fix_sql = preg_replace('/^--.*$/m', '', file_get_contents($fix_file));
        if ($connection->multi_query($fix_sql)) {
            do {
                if ($result = $connection->store_result()) {
                    $result->free();
                }
            } while ($connection->more_results() && $connection->next_result());
        }

        $mark = $connection->prepare(
            "INSERT INTO system_settings (company_id, setting_key, setting_value) VALUES (0, 'desktop_schema_fix_v2', '1')"
        );
        $mark->execute();
        $mark->close();
    } catch (Throwable $e) {
        error_log('Desktop schema fix skipped: ' . $e->getMessage());
    }
};

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
} catch (mysqli_sql_exception $e) {
    if (!$is_desktop_app) {
        throw $e;
    }

    // Local desktop test: bundled DB on 3307 may be absent; try XAMPP on 3306 once
    if ($db_port === 3307 && (int) $e->getCode() === 2002) {
        $db_port = 3306;
        try {
            $conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
        } catch (mysqli_sql_exception $fallbackError) {
            if ((int) $fallbackError->getCode() !== 1049) {
                throw $fallbackError;
            }
            $conn = new mysqli($db_host, $db_user, $db_pass, null, $db_port);
            $init_desktop_schema($conn, $db_name);
        }
    } elseif ((int) $e->getCode() === 1049) {
        $conn = new mysqli($db_host, $db_user, $db_pass, null, $db_port);
        $init_desktop_schema($conn, $db_name);
    } else {
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

// One-time ALTER on existing desktop DB (not a per-request migration)
if ($is_desktop_app) {
    $apply_desktop_schema_fix($conn);
}
?>