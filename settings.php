<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Load database configuration
require_once __DIR__ . '/config/database.php';

if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error);
}

// Get user and company info
$company_id = $_SESSION['company_id'];
$user_id = $_SESSION['user_id'];
$company_name = $_SESSION['company_name'];
$user_name = $_SESSION['full_name'];

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'get_tables':
                // Only show actual database tables from schema
                $actual_tables = ['users', 'parties', 'transactions', 'gold_stock'];
                
                // Protected tables that should not be edited
                $protected_tables = ['companies', 'transaction_logs', 'party_summary', 'daily_summary'];
                
                // Return only actual tables that are not protected
                $editable_tables = array_diff($actual_tables, $protected_tables);
                
                echo json_encode(['status' => 'success', 'tables' => array_values($editable_tables)]);
                exit;
                
            case 'get_table_data':
                $table_name = $conn->real_escape_string($_POST['table_name']);
                
                // Protected tables that should not be edited
                $protected_tables = ['companies', 'transaction_logs', 'party_summary', 'daily_summary'];
                
                // Validate table name to prevent SQL injection
                $valid_tables_query = "SHOW TABLES";
                $valid_tables_result = $conn->query($valid_tables_query);
                $valid_tables = [];
                while ($row = $valid_tables_result->fetch_array()) {
                    $valid_tables[] = $row[0];
                }
                
                if (!in_array($table_name, $valid_tables) || in_array($table_name, $protected_tables)) {
                    echo json_encode(['status' => 'error', 'message' => 'Invalid or protected table name']);
                    exit;
                }
                
                // Get table structure
                $structure_query = "DESCRIBE `$table_name`";
                $structure_result = $conn->query($structure_query);
                $columns = [];
                
                while ($row = $structure_result->fetch_assoc()) {
                    $columns[] = [
                        'Field' => $row['Field'],
                        'Type' => $row['Type'],
                        'Null' => $row['Null'],
                        'Key' => $row['Key'],
                        'Default' => $row['Default'],
                        'Extra' => $row['Extra']
                    ];
                }
                
                // Get table data
                $data_query = "SELECT * FROM `$table_name` ORDER BY id DESC LIMIT 1000";
                $data_result = $conn->query($data_query);
                $data = [];
                
                while ($row = $data_result->fetch_assoc()) {
                    $data[] = $row;
                }
                
                echo json_encode([
                    'status' => 'success',
                    'table_name' => $table_name,
                    'columns' => $columns,
                    'data' => $data
                ]);
                exit;
                
            case 'update_record':
                $table_name = $conn->real_escape_string($_POST['table_name']);
                $record_id = intval($_POST['record_id']);
                $field_name = $conn->real_escape_string($_POST['field_name']);
                $field_value = $conn->real_escape_string($_POST['field_value']);
                
                // Protected tables
                $protected_tables = ['companies', 'transaction_logs', 'party_summary', 'daily_summary'];
                
                // Validate table name
                if (in_array($table_name, $protected_tables)) {
                    echo json_encode(['status' => 'error', 'message' => 'Cannot edit protected tables']);
                    exit;
                }
                
                $update_query = "UPDATE `$table_name` SET `$field_name` = '$field_value' WHERE id = $record_id";
                
                if ($conn->query($update_query)) {
                    echo json_encode(['status' => 'success', 'message' => 'Record updated successfully']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Update failed: ' . $conn->error]);
                }
                exit;
                
            case 'delete_record':
                $table_name = $conn->real_escape_string($_POST['table_name']);
                $record_id = intval($_POST['record_id']);
                
                // Protected tables
                $protected_tables = ['companies', 'transaction_logs', 'party_summary', 'daily_summary'];
                
                // Validate table name
                if (in_array($table_name, $protected_tables)) {
                    echo json_encode(['status' => 'error', 'message' => 'Cannot delete from protected tables']);
                    exit;
                }
                
                $delete_query = "DELETE FROM `$table_name` WHERE id = $record_id";
                
                if ($conn->query($delete_query)) {
                    echo json_encode(['status' => 'success', 'message' => 'Record deleted successfully']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Delete failed: ' . $conn->error]);
                }
                exit;
                
            case 'backup_database':
                // Create backup directory if not exists
                $backup_dir = __DIR__ . '/backups';
                if (!file_exists($backup_dir)) {
                    mkdir($backup_dir, 0777, true);
                }
                
                $backup_file = $backup_dir . '/backup_' . date('Y-m-d_H-i-s') . '.sql';
                
                // Get database name from connection
                $db_name = $conn->query("SELECT DATABASE()")->fetch_row()[0];
                
                // Get all tables
                $tables_result = $conn->query("SHOW TABLES");
                $backup_content = "-- Database Backup\n";
                $backup_content .= "-- Created: " . date('Y-m-d H:i:s') . "\n\n";
                
                while ($table = $tables_result->fetch_array()) {
                    $table_name = $table[0];
                    $backup_content .= "\n\n-- Table: $table_name\n";
                    
                    // Get CREATE TABLE statement
                    $create_table = $conn->query("SHOW CREATE TABLE `$table_name`")->fetch_row();
                    $backup_content .= $create_table[1] . ";\n\n";
                    
                    // Get table data
                    $data_result = $conn->query("SELECT * FROM `$table_name`");
                    
                    while ($row = $data_result->fetch_assoc()) {
                        $columns = array_keys($row);
                        $values = array_map(function($val) use ($conn) {
                            return $val === null ? 'NULL' : "'" . $conn->real_escape_string($val) . "'";
                        }, array_values($row));
                        
                        $backup_content .= "INSERT INTO `$table_name` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $values) . ");\n";
                    }
                }
                
                if (file_put_contents($backup_file, $backup_content)) {
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Backup created successfully',
                        'filename' => basename($backup_file)
                    ]);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Failed to create backup file']);
                }
                exit;
                
            case 'get_backups':
                $backup_dir = __DIR__ . '/backups';
                $backups = [];
                
                if (file_exists($backup_dir)) {
                    $files = scandir($backup_dir);
                    foreach ($files as $file) {
                        if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
                            $backups[] = [
                                'filename' => $file,
                                'size' => filesize($backup_dir . '/' . $file),
                                'date' => date('Y-m-d H:i:s', filemtime($backup_dir . '/' . $file))
                            ];
                        }
                    }
                }
                
                echo json_encode(['status' => 'success', 'backups' => $backups]);
                exit;
                
            case 'restore_database':
                $filename = $_POST['filename'];
                $backup_file = __DIR__ . '/backups/' . basename($filename);
                
                if (!file_exists($backup_file)) {
                    echo json_encode(['status' => 'error', 'message' => 'Backup file not found']);
                    exit;
                }
                
                $sql_content = file_get_contents($backup_file);
                
                // Execute SQL statements
                $conn->multi_query($sql_content);
                
                // Wait for all queries to finish
                do {
                    if ($result = $conn->store_result()) {
                        $result->free();
                    }
                } while ($conn->more_results() && $conn->next_result());
                
                echo json_encode(['status' => 'success', 'message' => 'Database restored successfully']);
                exit;
                
            case 'upload_restore_backup':
                if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
                    echo json_encode(['status' => 'error', 'message' => 'No file uploaded or upload error']);
                    exit;
                }
                
                $file = $_FILES['backup_file'];
                $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                
                // Validate file extension
                if ($file_ext !== 'sql') {
                    echo json_encode(['status' => 'error', 'message' => 'Only .sql files are allowed']);
                    exit;
                }
                
                // Read file content
                $sql_content = file_get_contents($file['tmp_name']);
                
                if (empty($sql_content)) {
                    echo json_encode(['status' => 'error', 'message' => 'Backup file is empty']);
                    exit;
                }
                
                // Execute SQL statements
                $conn->multi_query($sql_content);
                
                // Wait for all queries to finish
                do {
                    if ($result = $conn->store_result()) {
                        $result->free();
                    }
                } while ($conn->more_results() && $conn->next_result());
                
                // Optionally save the uploaded file to backups folder
                $backup_dir = __DIR__ . '/backups';
                if (!file_exists($backup_dir)) {
                    mkdir($backup_dir, 0777, true);
                }
                $backup_filename = 'uploaded_' . date('Y-m-d_H-i-s') . '.sql';
                move_uploaded_file($file['tmp_name'], $backup_dir . '/' . $backup_filename);
                
                echo json_encode(['status' => 'success', 'message' => 'Backup uploaded and restored successfully']);
                exit;
                
            case 'reset_table':
                $table_name = $conn->real_escape_string($_POST['table_name']);
                
                // Protected tables - cannot be reset (only companies and users are protected)
                $protected_reset_tables = ['companies', 'users', 'transaction_logs', 'party_summary', 'daily_summary'];
                
                // Validate table name
                if (in_array($table_name, $protected_reset_tables)) {
                    echo json_encode(['status' => 'error', 'message' => 'Cannot reset this critical table']);
                    exit;
                }
                
                // Disable foreign key checks temporarily
                $conn->query("SET FOREIGN_KEY_CHECKS = 0");
                
                $truncate_query = "TRUNCATE TABLE `$table_name`";
                
                if ($conn->query($truncate_query)) {
                    // Re-enable foreign key checks
                    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                    echo json_encode(['status' => 'success', 'message' => 'Table reset successfully']);
                } else {
                    // Re-enable foreign key checks
                    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                    echo json_encode(['status' => 'error', 'message' => 'Reset failed: ' . $conn->error]);
                }
                exit;
                
            case 'add_record':
                $table_name = $conn->real_escape_string($_POST['table_name']);
                $data = json_decode($_POST['data'], true);
                
                // Protected tables
                $protected_tables = ['companies', 'transaction_logs', 'party_summary', 'daily_summary'];
                
                if (in_array($table_name, $protected_tables)) {
                    echo json_encode(['status' => 'error', 'message' => 'Cannot add to protected tables']);
                    exit;
                }
                
                $columns = array_keys($data);
                $values = array_map(function($val) use ($conn) {
                    return $val === '' || $val === null ? 'NULL' : "'" . $conn->real_escape_string($val) . "'";
                }, array_values($data));
                
                $insert_query = "INSERT INTO `$table_name` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $values) . ")";
                
                if ($conn->query($insert_query)) {
                    echo json_encode(['status' => 'success', 'message' => 'Record added successfully', 'id' => $conn->insert_id]);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Insert failed: ' . $conn->error]);
                }
                exit;
                
            case 'reset_all_company_data':
                // Reset all data for the current company
                $conn->begin_transaction();
                
                try {
                    // Disable foreign key checks temporarily
                    $conn->query("SET FOREIGN_KEY_CHECKS = 0");
                    
                    // Reset tables in order (respecting foreign key dependencies)
                    $reset_queries = [
                        "DELETE FROM transactions WHERE company_id = $company_id",
                        "DELETE FROM gold_stock WHERE company_id = $company_id", 
                        "DELETE FROM parties WHERE company_id = $company_id"
                    ];
                    
                    foreach ($reset_queries as $query) {
                        if (!$conn->query($query)) {
                            throw new Exception("Error resetting data: " . $conn->error);
                        }
                    }
                    
                    // Re-enable foreign key checks
                    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                    
                    $conn->commit();
                    
                    echo json_encode(['status' => 'success', 'message' => 'All company data reset successfully']);
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
                exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Settings - <?= htmlspecialchars($company_name) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e8ecf1 100%);
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Header -->
    <?php include 'components/header.php'; ?>
    
    <div class="flex">
        <!-- Sidebar -->
        <?php include 'components/sidebar.php'; ?>
        
        <!-- Main Content -->
        <div class="flex-1 p-6 ml-64">
            <div class="max-w-7xl mx-auto">
                <!-- Page Header -->
                <div class="mb-6">
                    <h1 class="text-3xl font-bold text-gray-800" style="font-family: 'Poppins', sans-serif;">
                        <i class="fas fa-cog mr-2 text-slate-600"></i>Database Settings
                    </h1>
                    <p class="text-gray-600 mt-1" style="font-family: 'Poppins', sans-serif; font-weight: 400;">Manage your database, backup, restore, and edit data</p>
                </div>
                
                <!-- Settings Tabs -->
                <div class="bg-white rounded-lg shadow-sm mb-6 border border-gray-200">
                    <div class="border-b border-gray-200">
                        <nav class="flex space-x-4 px-6" aria-label="Tabs">
                            <button onclick="showTab('backup')" id="tab-backup" class="tab-btn active px-4 py-3 text-sm font-medium border-b-2 border-slate-500 text-slate-700" style="font-family: 'Poppins', sans-serif;">
                                <i class="fas fa-download mr-2"></i>Backup & Restore
                            </button>
                            <button onclick="showTab('tables')" id="tab-tables" class="tab-btn px-4 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700" style="font-family: 'Poppins', sans-serif;">
                                <i class="fas fa-table mr-2"></i>Manage Tables
                            </button>
                            <button onclick="showTab('reset')" id="tab-reset" class="tab-btn px-4 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700" style="font-family: 'Poppins', sans-serif;">
                                <i class="fas fa-trash-restore mr-2"></i>Reset Data
                            </button>
                        </nav>
                    </div>
                </div>
                
                <!-- Backup & Restore Tab -->
                <div id="content-backup" class="tab-content">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Create Backup -->
                        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                            <h2 class="text-xl font-semibold text-gray-800 mb-4" style="font-family: 'Poppins', sans-serif;">
                                <i class="fas fa-save text-slate-600 mr-2"></i>Create Backup
                            </h2>
                            <p class="text-gray-600 mb-4" style="font-family: 'Poppins', sans-serif; font-weight: 400;">Create a complete backup of your database</p>
                            <button onclick="createBackup()" class="w-full bg-slate-600 hover:bg-slate-700 text-white font-medium py-3 px-4 rounded-lg transition shadow-sm" style="font-family: 'Poppins', sans-serif;">
                                <i class="fas fa-download mr-2"></i>Create New Backup
                            </button>
                        </div>
                        
                        <!-- Restore Backup -->
                        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                            <h2 class="text-xl font-semibold text-gray-800 mb-4" style="font-family: 'Poppins', sans-serif;">
                                <i class="fas fa-upload text-emerald-600 mr-2"></i>Restore Backup
                            </h2>
                            <p class="text-gray-600 mb-4" style="font-family: 'Poppins', sans-serif; font-weight: 400;">Restore database from a previous backup</p>
                            
                            <!-- Upload Backup File -->
                            <div class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                                <label class="block text-sm font-medium text-gray-700 mb-2" style="font-family: 'Poppins', sans-serif;">
                                    <i class="fas fa-file-upload mr-1"></i>Upload Backup File (.sql)
                                </label>
                                <input type="file" id="backupFileInput" accept=".sql" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-600 file:text-white hover:file:bg-blue-700 cursor-pointer" style="font-family: 'Poppins', sans-serif;">
                                <button onclick="uploadAndRestoreBackup()" class="mt-3 w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition shadow-sm" style="font-family: 'Poppins', sans-serif;">
                                    <i class="fas fa-cloud-upload-alt mr-2"></i>Upload & Restore
                                </button>
                            </div>
                            
                            <!-- Existing Backups List -->
                            <div class="border-t pt-4">
                                <p class="text-sm font-medium text-gray-700 mb-2" style="font-family: 'Poppins', sans-serif;">
                                    <i class="fas fa-history mr-1"></i>Existing Backups
                                </p>
                                <div id="backupsList" class="space-y-2">
                                    <p class="text-gray-400 text-sm" style="font-family: 'Poppins', sans-serif;">Loading backups...</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Manage Tables Tab -->
                <div id="content-tables" class="tab-content hidden">
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-xl font-semibold text-gray-800" style="font-family: 'Poppins', sans-serif;">
                                <i class="fas fa-database text-slate-600 mr-2"></i>Database Tables
                            </h2>
                            <button onclick="loadTables()" class="bg-slate-500 hover:bg-slate-600 text-white px-4 py-2 rounded-lg font-medium shadow-sm" style="font-family: 'Poppins', sans-serif;">
                                <i class="fas fa-sync mr-2"></i>Refresh
                            </button>
                        </div>
                        
                        <!-- Tables List -->
                        <div id="tablesList" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 mb-6">
                            <p class="text-gray-400">Loading tables...</p>
                        </div>
                        
                        <!-- Table Data View -->
                        <div id="tableDataView" class="hidden">
                            <div class="flex justify-between items-center mb-4 border-t pt-4">
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-800" style="font-family: 'Poppins', sans-serif;">Table: <span id="currentTableName" class="text-slate-600"></span></h3>
                                    <p class="text-sm text-gray-600" style="font-family: 'Poppins', sans-serif; font-weight: 400;">Click on any cell to edit</p>
                                </div>
                                <div class="space-x-2">
                                    <button onclick="addNewRecord()" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg font-medium shadow-sm" style="font-family: 'Poppins', sans-serif;">
                                        <i class="fas fa-plus mr-2"></i>Add Record
                                    </button>
                                    <button onclick="closeTableView()" class="bg-slate-500 hover:bg-slate-600 text-white px-4 py-2 rounded-lg font-medium shadow-sm" style="font-family: 'Poppins', sans-serif;">
                                        <i class="fas fa-times mr-2"></i>Close
                                    </button>
                                </div>
                            </div>
                            
                            <div class="overflow-x-auto">
                                <table id="tableDataTable" class="w-full text-sm">
                                    <!-- Table will be populated by JavaScript -->
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Reset Data Tab -->
                <div id="content-reset" class="tab-content hidden">
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <div class="bg-rose-50 border-l-4 border-rose-400 p-4 mb-6 rounded">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-exclamation-triangle text-rose-500"></i>
                                </div>
                                <div class="ml-3">
                                    <h3 class="text-sm font-semibold text-rose-800" style="font-family: 'Poppins', sans-serif;">⚠️ Critical Warning</h3>
                                    <p class="mt-2 text-sm text-rose-700" style="font-family: 'Poppins', sans-serif; font-weight: 400;">
                                        Resetting tables will permanently delete all data. This action cannot be undone. Please create a backup before proceeding.
                                    </p>
                                    <p class="mt-2 text-sm text-rose-600 font-medium" style="font-family: 'Poppins', sans-serif;">
                                        🔒 Protected: companies, users, transaction_logs (cannot be reset)
                                    </p>
                                    <p class="mt-2 text-sm text-rose-600 font-medium" style="font-family: 'Poppins', sans-serif;">
                                        💥 Reset All Company Data: Deletes all parties, transactions, and gold stock for this company only.
                                    </p>
                                </div>
                            </div>
                        </div>
                        
                        <h2 class="text-xl font-semibold text-gray-800 mb-4" style="font-family: 'Poppins', sans-serif;">
                            <i class="fas fa-trash-restore text-rose-500 mr-2"></i>Reset Tables
                        </h2>
                        
                        <div id="resetTablesList" class="grid grid-cols-2 md:grid-cols-3 gap-4">
                            <p class="text-gray-400">Loading tables...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let currentTable = null;
        let currentTableData = null;
        
        // Tab switching
        function showTab(tab) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('.tab-btn').forEach(el => {
                el.classList.remove('active', 'border-slate-500', 'text-slate-700');
                el.classList.add('border-transparent', 'text-gray-500');
            });
            
            // Show selected tab
            document.getElementById('content-' + tab).classList.remove('hidden');
            document.getElementById('tab-' + tab).classList.add('active', 'border-slate-500', 'text-slate-700');
            document.getElementById('tab-' + tab).classList.remove('border-transparent', 'text-gray-500');
            
            // Load data for specific tabs
            if (tab === 'backup') {
                loadBackups();
            } else if (tab === 'tables') {
                loadTables();
            } else if (tab === 'reset') {
                loadResetTables();
            }
        }
        
        // Create backup
        function createBackup() {
            Swal.fire({
                title: 'Creating Backup...',
                text: 'Please wait while we backup your database',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            $.post('', {
                action: 'backup_database'
            }, function(response) {
                if (response.status === 'success') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Backup Created',
                        text: response.message,
                        confirmButtonColor: '#3b82f6'
                    });
                    loadBackups();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Backup Failed',
                        text: response.message
                    });
                }
            }, 'json');
        }
        
        // Load backups list
        function loadBackups() {
            $.post('', {
                action: 'get_backups'
            }, function(response) {
                if (response.status === 'success') {
                    const backupsList = $('#backupsList');
                    backupsList.empty();
                    
                    if (response.backups.length === 0) {
                        backupsList.html('<p class="text-gray-400 text-sm">No backups found</p>');
                    } else {
                        response.backups.forEach(backup => {
                            const sizeInMB = (backup.size / (1024 * 1024)).toFixed(2);
                            backupsList.append(`
                                <div class="flex items-center justify-between p-3 bg-slate-50 rounded-lg border border-slate-200">
                                    <div>
                                        <p class="font-medium text-gray-900" style="font-family: 'Poppins', sans-serif;">${backup.filename}</p>
                                        <p class="text-xs text-gray-500" style="font-family: 'Poppins', sans-serif;">${backup.date} • ${sizeInMB} MB</p>
                                    </div>
                                    <div class="flex gap-2">
                                        <a href="backups/${backup.filename}" download class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm font-medium shadow-sm inline-flex items-center" style="font-family: 'Poppins', sans-serif;">
                                            <i class="fas fa-download mr-1"></i>Download
                                        </a>
                                        <button onclick="restoreBackup('${backup.filename}')" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1 rounded text-sm font-medium shadow-sm" style="font-family: 'Poppins', sans-serif;">
                                            <i class="fas fa-upload mr-1"></i>Restore
                                        </button>
                                    </div>
                                </div>
                            `);
                        });
                    }
                }
            }, 'json');
        }
        
        // Restore backup
        function restoreBackup(filename) {
            Swal.fire({
                title: 'Are you sure?',
                text: 'This will replace all current data with the backup. This action cannot be undone!',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#22c55e',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, restore it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Restoring...',
                        text: 'Please wait while we restore your database',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    $.post('', {
                        action: 'restore_database',
                        filename: filename
                    }, function(response) {
                        if (response.status === 'success') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Restored!',
                                text: response.message,
                                confirmButtonColor: '#3b82f6'
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Restore Failed',
                                text: response.message
                            });
                        }
                    }, 'json');
                }
            });
        }
        
        // Upload and restore backup
        function uploadAndRestoreBackup() {
            const fileInput = document.getElementById('backupFileInput');
            const file = fileInput.files[0];
            
            if (!file) {
                Swal.fire({
                    icon: 'warning',
                    title: 'No File Selected',
                    text: 'Please select a backup file (.sql) to upload',
                    confirmButtonColor: '#3b82f6'
                });
                return;
            }
            
            // Validate file extension
            if (!file.name.toLowerCase().endsWith('.sql')) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid File',
                    text: 'Please select a valid .sql backup file',
                    confirmButtonColor: '#ef4444'
                });
                return;
            }
            
            Swal.fire({
                title: 'Upload & Restore?',
                html: `
                    <div style="font-family: 'Poppins', sans-serif; text-align: left;">
                        <p style="margin-bottom: 10px;"><strong>File:</strong> ${file.name}</p>
                        <p style="margin-bottom: 10px;"><strong>Size:</strong> ${(file.size / 1024).toFixed(2)} KB</p>
                        <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px; margin-top: 12px;">
                            <p style="color: #dc2626; font-weight: 600;">⚠️ Warning:</p>
                            <p style="color: #7f1d1d; font-size: 14px; margin-top: 4px;">This will replace all current data with the uploaded backup. This action cannot be undone!</p>
                        </div>
                    </div>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#22c55e',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, upload & restore!'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('action', 'upload_restore_backup');
                    formData.append('backup_file', file);
                    
                    Swal.fire({
                        title: 'Uploading & Restoring...',
                        text: 'Please wait, this may take a moment',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    $.ajax({
                        url: '',
                        type: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        dataType: 'json',
                        success: function(response) {
                            if (response.status === 'success') {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Success!',
                                    text: response.message,
                                    confirmButtonColor: '#3b82f6'
                                }).then(() => {
                                    fileInput.value = ''; // Clear file input
                                    loadBackups(); // Reload backups list
                                    location.reload(); // Reload page
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Upload Failed',
                                    text: response.message,
                                    confirmButtonColor: '#ef4444'
                                });
                            }
                        },
                        error: function() {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'An error occurred during upload',
                                confirmButtonColor: '#ef4444'
                            });
                        }
                    });
                }
            });
        }
        
        // Load tables
        function loadTables() {
            $.post('', {
                action: 'get_tables'
            }, function(response) {
                if (response.status === 'success') {
                    const tablesList = $('#tablesList');
                    tablesList.empty();
                    
                    response.tables.forEach(table => {
                        tablesList.append(`
                            <button onclick="viewTable('${table}')" class="bg-gradient-to-br from-slate-500 to-slate-600 hover:from-slate-600 hover:to-slate-700 text-white p-4 rounded-lg shadow-sm transition transform hover:scale-105" style="font-family: 'Poppins', sans-serif;">
                                <i class="fas fa-table text-2xl mb-2"></i>
                                <p class="font-semibold">${table}</p>
                            </button>
                        `);
                    });
                }
            }, 'json');
        }
        
        // Load tables for reset
        function loadResetTables() {
            $.post('', {
                action: 'get_tables'
            }, function(response) {
                if (response.status === 'success') {
                    const resetTablesList = $('#resetTablesList');
                    resetTablesList.empty();
                    
                    // Protected tables that cannot be reset (only users table is protected)
                    const protectedResetTables = ['users'];
                    
                    response.tables.forEach(table => {
                        // Don't show users table in reset (critical data)
                        if (!protectedResetTables.includes(table)) {
                            resetTablesList.append(`
                                <button onclick="resetTable('${table}')" class="bg-rose-50 hover:bg-rose-100 border-2 border-rose-200 text-rose-700 p-4 rounded-lg transition" style="font-family: 'Poppins', sans-serif;">
                                    <i class="fas fa-trash text-xl mb-2"></i>
                                    <p class="font-semibold">${table}</p>
                                </button>
                            `);
                        }
                    });
                    
                    // Add "Reset All Company Data" button
                    resetTablesList.append(`
                        <button onclick="resetAllCompanyData()" class="bg-red-50 hover:bg-red-100 border-2 border-red-300 text-red-700 p-4 rounded-lg transition col-span-2" style="font-family: 'Poppins', sans-serif;">
                            <i class="fas fa-bomb text-xl mb-2"></i>
                            <p class="font-semibold">Reset All Company Data</p>
                            <p class="text-xs text-red-600 mt-1">All parties, transactions & stock</p>
                        </button>
                    `);
                }
            }, 'json');
        }
        
        // Reset all company data
        function resetAllCompanyData() {
            Swal.fire({
                title: 'Reset All Company Data?',
                html: `
                    <div style="font-family: 'Poppins', sans-serif; text-align: left;">
                        <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                            <div style="color: #dc2626; font-weight: 600; margin-bottom: 8px;">
                                ⚠️ This will permanently delete:
                            </div>
                            <ul style="color: #7f1d1d; font-size: 14px; margin: 0; padding-left: 20px;">
                                <li>All parties</li>
                                <li>All transactions (bookings, sales, purchases, payments)</li>
                                <li>All gold stock records</li>
                            </ul>
                        </div>
                        <div style="background: #f0f9ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 12px;">
                            <div style="color: #1e40af; font-weight: 600; margin-bottom: 4px;">
                                ℹ️ What will be preserved:
                            </div>
                            <div style="color: #1e3a8a; font-size: 14px;">
                                • Company information<br>
                                • User accounts<br>
                                • System settings
                            </div>
                        </div>
                    </div>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, reset everything!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('', {
                        action: 'reset_all_company_data'
                    }, function(response) {
                        if (response.status === 'success') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Reset Complete!',
                                text: response.message,
                                confirmButtonColor: '#3b82f6'
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Reset Failed',
                                text: response.message,
                                confirmButtonColor: '#dc2626'
                            });
                        }
                    }, 'json');
                }
            });
        }
        
        // View table data
        function viewTable(tableName) {
            currentTable = tableName;
            
            Swal.fire({
                title: 'Loading...',
                text: 'Please wait',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            $.post('', {
                action: 'get_table_data',
                table_name: tableName
            }, function(response) {
                Swal.close();
                
                if (response.status === 'success') {
                    currentTableData = response;
                    $('#currentTableName').text(tableName);
                    renderTableData(response);
                    $('#tableDataView').removeClass('hidden');
                    $('#tablesList').addClass('hidden');
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: response.message
                    });
                }
            }, 'json');
        }
        
        // Render table data
        function renderTableData(data) {
            const table = $('#tableDataTable');
            table.empty();
            
            // Create header
            let headerHtml = '<thead class="bg-gray-100"><tr>';
            headerHtml += '<th class="px-4 py-2 text-left text-xs font-medium text-gray-700">Actions</th>';
            data.columns.forEach(col => {
                headerHtml += `<th class="px-4 py-2 text-left text-xs font-medium text-gray-700">${col.Field}<br><span class="text-gray-500 font-normal">${col.Type}</span></th>`;
            });
            headerHtml += '</tr></thead>';
            table.append(headerHtml);
            
            // Create body
            let bodyHtml = '<tbody class="divide-y divide-gray-200">';
            data.data.forEach(row => {
                bodyHtml += '<tr class="hover:bg-gray-50">';
                bodyHtml += `<td class="px-4 py-2 whitespace-nowrap">
                    <button onclick="deleteRecord(${row.id})" class="text-red-600 hover:text-red-800">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>`;
                
                data.columns.forEach(col => {
                    const value = row[col.Field] !== null ? row[col.Field] : '';
                    const isAutoIncrement = col.Extra === 'auto_increment';
                    const isEditable = !isAutoIncrement;
                    
                    bodyHtml += `<td class="px-4 py-2 whitespace-nowrap text-sm ${isEditable ? 'editable-cell cursor-pointer hover:bg-blue-50' : 'bg-gray-100'}" 
                                    ${isEditable ? `onclick="editCell(this, ${row.id}, '${col.Field}')"` : ''}>
                        ${value}
                    </td>`;
                });
                
                bodyHtml += '</tr>';
            });
            bodyHtml += '</tbody>';
            table.append(bodyHtml);
        }
        
        // Edit cell
        function editCell(cell, recordId, fieldName) {
            const currentValue = $(cell).text().trim();
            
            Swal.fire({
                title: 'Edit Field',
                input: 'text',
                inputLabel: fieldName,
                inputValue: currentValue,
                showCancelButton: true,
                confirmButtonText: 'Save',
                confirmButtonColor: '#3b82f6'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('', {
                        action: 'update_record',
                        table_name: currentTable,
                        record_id: recordId,
                        field_name: fieldName,
                        field_value: result.value
                    }, function(response) {
                        if (response.status === 'success') {
                            $(cell).text(result.value);
                            Swal.fire({
                                icon: 'success',
                                title: 'Updated!',
                                text: response.message,
                                timer: 1500,
                                showConfirmButton: false
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: response.message
                            });
                        }
                    }, 'json');
                }
            });
        }
        
        // Delete record
        function deleteRecord(recordId) {
            Swal.fire({
                title: 'Are you sure?',
                text: 'This record will be permanently deleted!',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('', {
                        action: 'delete_record',
                        table_name: currentTable,
                        record_id: recordId
                    }, function(response) {
                        if (response.status === 'success') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Deleted!',
                                text: response.message,
                                timer: 1500,
                                showConfirmButton: false
                            });
                            viewTable(currentTable); // Reload table
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: response.message
                            });
                        }
                    }, 'json');
                }
            });
        }
        
        // Add new record
        function addNewRecord() {
            if (!currentTableData) return;
            
            let fieldsHtml = '';
            currentTableData.columns.forEach(col => {
                if (col.Extra !== 'auto_increment') {
                    fieldsHtml += `
                        <div class="mb-3">
                            <label class="block text-sm font-medium text-gray-700 mb-1">${col.Field}</label>
                            <input type="text" id="field_${col.Field}" class="w-full px-3 py-2 border border-gray-300 rounded-lg" placeholder="${col.Type}">
                            <p class="text-xs text-gray-500 mt-1">${col.Type} ${col.Null === 'YES' ? '(Optional)' : '(Required)'}</p>
                        </div>
                    `;
                }
            });
            
            Swal.fire({
                title: 'Add New Record',
                html: `<div class="text-left max-h-96 overflow-y-auto">${fieldsHtml}</div>`,
                width: '600px',
                showCancelButton: true,
                confirmButtonText: 'Add Record',
                confirmButtonColor: '#22c55e',
                preConfirm: () => {
                    const data = {};
                    currentTableData.columns.forEach(col => {
                        if (col.Extra !== 'auto_increment') {
                            const value = document.getElementById('field_' + col.Field).value;
                            data[col.Field] = value || null;
                        }
                    });
                    return data;
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('', {
                        action: 'add_record',
                        table_name: currentTable,
                        data: JSON.stringify(result.value)
                    }, function(response) {
                        if (response.status === 'success') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Added!',
                                text: response.message,
                                confirmButtonColor: '#3b82f6'
                            });
                            viewTable(currentTable); // Reload table
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: response.message
                            });
                        }
                    }, 'json');
                }
            });
        }
        
        // Close table view
        function closeTableView() {
            $('#tableDataView').addClass('hidden');
            $('#tablesList').removeClass('hidden');
            currentTable = null;
            currentTableData = null;
        }
        
        // Reset table
        function resetTable(tableName) {
            Swal.fire({
                title: 'Are you sure?',
                text: `All data in "${tableName}" table will be permanently deleted!`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, reset it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('', {
                        action: 'reset_table',
                        table_name: tableName
                    }, function(response) {
                        if (response.status === 'success') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Reset!',
                                text: response.message,
                                confirmButtonColor: '#3b82f6'
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: response.message
                            });
                        }
                    }, 'json');
                }
            });
        }
        
        // Initialize
        $(document).ready(function() {
            loadBackups();
        });
    </script>
</body>
</html>

