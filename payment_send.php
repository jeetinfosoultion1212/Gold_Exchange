<?php
// Start output buffering
ob_start();

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Set headers before any output
header("Cache-Control: no-cache, must-revalidate"); // HTTP 1.1
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
header("Pragma: no-cache"); // HTTP 1.0
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_error.log');

// Load database configuration
require_once __DIR__ . '/config/database.php';

if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error . '. Please run setup_database.php first.');
}

// Get user and company info
$company_id = $_SESSION['company_id'];
$user_id = $_SESSION['user_id'];
$company_name = $_SESSION['company_name'];
$user_name = $_SESSION['full_name'];

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'search_parties':
                $search = $conn->real_escape_string($_POST['term']);
                $sql = "SELECT DISTINCT p.id, p.party_name, p.address, p.contact_no,
                        p.current_balance, p.cash_balance, p.bank_balance,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' THEN t.gold_weight ELSE 0 END), 0) as booked_weight,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Sale' THEN t.gold_weight ELSE 0 END), 0) as sold_weight,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' THEN t.gold_amount ELSE 0 END), 0) as booked_amount,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Payment' AND t.payment_type = 'Payment_Out' AND t.payment_method = 'Cash' THEN t.payment_amount ELSE 0 END), 0) as cash_paid,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Payment' AND t.payment_type = 'Payment_Out' AND t.payment_method IN ('Bank', 'UPI', 'Cheque') THEN t.payment_amount ELSE 0 END), 0) as bank_paid
                        FROM parties p 
                        LEFT JOIN transactions t ON p.id = t.party_id AND t.company_id = $company_id
                        WHERE p.company_id = $company_id AND p.party_name LIKE '%$search%' 
                        GROUP BY p.id, p.party_name, p.address, p.contact_no, p.current_balance, p.cash_balance, p.bank_balance
                        ORDER BY p.party_name
                        LIMIT 10";
                $result = $conn->query($sql);
                $parties = [];
                while ($row = $result->fetch_assoc()) {
                    $booked_weight = $row['booked_weight'];
                    $booked_amount = $row['booked_amount'];
                    $available_weight = $booked_weight - $row['sold_weight'];
                    $total_paid = $row['cash_paid'] + $row['bank_paid'];
                    
                    $parties[] = [
                        'id' => $row['id'],
                        'party_name' => $row['party_name'],
                        'address' => $row['address'],
                        'contact_no' => $row['contact_no'],
                        'booked_weight' => number_format($booked_weight, 2),
                        'sold_weight' => number_format($row['sold_weight'], 2),
                        'available_weight' => number_format($available_weight, 2),
                        'booked_amount' => number_format($booked_amount, 2),
                        'total_paid' => number_format($total_paid, 2),
                        'cash_paid' => number_format($row['cash_paid'], 2),
                        'bank_paid' => number_format($row['bank_paid'], 2),
                        'current_balance' => floatval($row['current_balance']),
                        'cash_balance' => floatval($row['cash_balance']),
                        'bank_balance' => floatval($row['bank_balance'])
                    ];
                }
                echo json_encode($parties);
                exit;
                
            case 'get_party_info':
                $party_id = intval($_POST['party_id']);
                
                $sql = "SELECT 
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' THEN t.gold_weight ELSE 0 END), 0) as booked_weight,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Sale' THEN t.gold_weight ELSE 0 END), 0) as sold_weight,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' THEN t.gold_amount ELSE 0 END), 0) as booked_amount,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Payment' AND t.payment_type = 'Payment_Out' AND t.payment_method = 'Cash' THEN t.payment_amount ELSE 0 END), 0) as cash_paid,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Payment' AND t.payment_type = 'Payment_Out' AND t.payment_method IN ('Bank', 'UPI', 'Cheque') THEN t.payment_amount ELSE 0 END), 0) as bank_paid
                        FROM transactions t 
                        WHERE t.party_id = $party_id AND t.company_id = $company_id";
                $result = $conn->query($sql);
                if ($result && $result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $total_paid = $row['cash_paid'] + $row['bank_paid'];
                    
                    echo json_encode([
                        'booked_weight' => floatval($row['booked_weight'] ?? 0),
                        'sold_weight' => floatval($row['sold_weight'] ?? 0),
                        'available_weight' => floatval($row['booked_weight'] - $row['sold_weight']),
                        'booked_amount' => floatval($row['booked_amount'] ?? 0),
                        'total_paid' => floatval($total_paid),
                        'cash_paid' => floatval($row['cash_paid'] ?? 0),
                        'bank_paid' => floatval($row['bank_paid'] ?? 0)
                    ]);
                } else {
                    echo json_encode([
                        'booked_weight' => 0,
                        'sold_weight' => 0,
                        'available_weight' => 0,
                        'booked_amount' => 0,
                        'total_paid' => 0,
                        'cash_paid' => 0,
                        'bank_paid' => 0
                    ]);
                }
                exit;
                
            case 'save_payment_out':
                $conn->begin_transaction();
                try {
                    $receipt_id = $conn->real_escape_string($_POST['receipt_id']);
                    $date_of_transaction = $conn->real_escape_string($_POST['date_of_transaction']);
                    $party_id = intval($_POST['party_id']);
                    $payment_amount = floatval($_POST['payment_amount']);
                    $payment_method = $conn->real_escape_string($_POST['payment_method']);
                    $payment_type = $conn->real_escape_string($_POST['payment_type']);
                    $narration = $conn->real_escape_string($_POST['narration'] ?? '');
                    
                    // Validate required fields
                    if (empty($receipt_id) || empty($party_id) || $payment_amount <= 0) {
                        throw new Exception("Please fill all required fields with valid values");
                    }
                    
                    // Get party's current paid amount
                    $balance_sql = "SELECT 
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Payment' AND t.payment_type = 'Payment_Out' THEN t.payment_amount ELSE 0 END), 0) as total_paid
                        FROM transactions t 
                        WHERE t.party_id = $party_id AND t.company_id = $company_id";
                    $balance_result = $conn->query($balance_sql);
                    $balance_data = $balance_result->fetch_assoc();
                    $current_paid = $balance_data['total_paid'];
                    
                    // Insert payment transaction
                    $payment_sql = "INSERT INTO transactions (
                        company_id, party_id, receipt_id, transaction_type, date_of_transaction,
                        gold_weight, purity, rate, gold_amount, payment_amount, payment_method, payment_type,
                        party_balance_before, party_balance_after, party_gold_balance_before, party_gold_balance_after,
                        narration
                    ) VALUES (
                        $company_id, $party_id, '$receipt_id', 'Payment', '$date_of_transaction',
                        0.000, 0.00, 0.00, 0.00, $payment_amount, '$payment_method', '$payment_type',
                        $current_paid, " . ($current_paid + $payment_amount) . ", 0, 0,
                        'Payment sent - $receipt_id" . (!empty($narration) ? " - $narration" : "") . "'
                    )";
                    
                    if (!$conn->query($payment_sql)) {
                        throw new Exception("Error creating payment transaction: " . $conn->error);
                    }
                    
                    // Get party details for response
                    $party_sql = "SELECT party_name, contact_no FROM parties WHERE id = $party_id";
                    $party_result = $conn->query($party_sql);
                    $party_data = $party_result->fetch_assoc();
                    
                    $conn->commit();
                    
                    // Return success response
                    header('Content-Type: application/json');
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Payment sent successfully',
                        'data' => [
                            'receipt_id' => $receipt_id,
                            'party_name' => $party_data['party_name'],
                            'party_contact' => $party_data['contact_no'],
                            'payment_amount' => $payment_amount,
                            'payment_method' => $payment_method,
                            'payment_type' => $payment_type,
                            'total_paid' => $current_paid + $payment_amount
                        ]
                    ]);
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    header('Content-Type: application/json');
                    echo json_encode([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }
                exit;
                
            case 'generate_payment_out_id':
                // Generate unique payment out ID: PAYOUT + company_id + serial
                $prefix = "PAYOUT{$company_id}";
                
                // Get the last payment out ID for this company
                $lastIdSql = "SELECT receipt_id FROM transactions 
                             WHERE company_id = ? 
                             AND receipt_id LIKE '{$prefix}%' 
                             ORDER BY receipt_id DESC 
                             LIMIT 1";
                $lastIdStmt = $conn->prepare($lastIdSql);
                $lastIdStmt->bind_param("i", $company_id);
                $lastIdStmt->execute();
                $lastIdResult = $lastIdStmt->get_result();
                
                if ($lastIdResult->num_rows > 0) {
                    $lastId = $lastIdResult->fetch_assoc()['receipt_id'];
                    // Extract serial number and increment
                    $serial = (int)substr($lastId, strlen($prefix)) + 1;
                } else {
                    // First payment out for this company
                    $serial = 1;
                }
                
                $paymentId = $prefix . str_pad($serial, 3, '0', STR_PAD_LEFT);
                
                echo json_encode([
                    'status' => 'success',
                    'payment_id' => $paymentId
                ]);
                exit;
                
            case 'save_party':
                $party_name = $conn->real_escape_string($_POST['party_name']);
                $address = $conn->real_escape_string($_POST['address']);
                $contact_no = $conn->real_escape_string($_POST['contact_no']);
                
                $sql = "INSERT INTO parties (company_id, party_name, address, contact_no) VALUES (?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("isss", $company_id, $party_name, $address, $contact_no);
                
                if ($stmt->execute()) {
                    $new_party_id = $stmt->insert_id;
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Party added successfully',
                        'party_id' => $new_party_id
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Error adding party: ' . $stmt->error
                    ]);
                }
                exit;
                
            case 'get_payment_list':
                // Fetch recent payment out transactions for dropdown
                $list_sql = "SELECT t.id, t.receipt_id, t.date_of_transaction, t.payment_amount, t.payment_method, t.payment_type, p.party_name
                            FROM transactions t
                            LEFT JOIN parties p ON t.party_id = p.id
                            WHERE t.transaction_type = 'Payment' 
                            AND t.payment_type = 'Payment_Out'
                            AND t.company_id = $company_id
                            ORDER BY t.date_of_transaction DESC, t.id DESC
                            LIMIT 20";
                
                $list_result = $conn->query($list_sql);
                
                if ($list_result) {
                    $payments = [];
                    while ($row = $list_result->fetch_assoc()) {
                        $payments[] = $row;
                    }
                    
                    echo json_encode([
                        'status' => 'success',
                        'data' => $payments
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Failed to fetch payment list'
                    ]);
                }
                exit;
        }
    }
}

// Enhanced statistics SQL query for payment out page
$stats_sql = "
SELECT 
    SUM(CASE WHEN transaction_type = 'Payment' AND payment_type = 'Payment_Out' AND payment_method = 'Cash' THEN payment_amount ELSE 0 END) AS total_cash_paid,
    SUM(CASE WHEN transaction_type = 'Payment' AND payment_type = 'Payment_Out' AND payment_method IN ('Bank', 'UPI', 'Cheque') THEN payment_amount ELSE 0 END) AS total_bank_paid,
    SUM(CASE WHEN transaction_type = 'Payment' AND payment_type = 'Payment_Out' THEN payment_amount ELSE 0 END) AS total_payments_sent,
    COUNT(CASE WHEN transaction_type = 'Payment' AND payment_type = 'Payment_Out' THEN 1 END) AS total_payment_transactions,
    
    -- Purchase related
    SUM(CASE WHEN transaction_type = 'Purchase' THEN gold_amount ELSE 0 END) AS total_purchase_amount,
    SUM(CASE WHEN transaction_type = 'Payment' AND payment_type = 'Payment_Out' THEN payment_amount ELSE 0 END) AS total_paid_amount,
    (SUM(CASE WHEN transaction_type = 'Purchase' THEN gold_amount ELSE 0 END) - SUM(CASE WHEN transaction_type = 'Payment' AND payment_type = 'Payment_Out' THEN payment_amount ELSE 0 END)) AS total_pending_payment
FROM transactions
WHERE DATE(date_of_transaction) = CURRENT_DATE AND company_id = $company_id";

$stats_result = $conn->query($stats_sql);
if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
} else {
    $stats = [
        'total_cash_paid' => 0,
        'total_bank_paid' => 0,
        'total_payments_sent' => 0,
        'total_payment_transactions' => 0,
        'total_purchase_amount' => 0,
        'total_paid_amount' => 0,
        'total_pending_payment' => 0
    ];
}

// Get recent payment out transactions
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$where_clause = $search ? "AND (p.party_name LIKE '%$search%' OR t.receipt_id LIKE '%$search%')" : '';

$transactions_sql = "SELECT t.*, p.party_name, p.contact_no as party_contact 
                    FROM transactions t 
                    LEFT JOIN parties p ON t.party_id = p.id
                    WHERE t.transaction_type = 'Payment' 
                    AND t.payment_type = 'Payment_Out'
                    AND t.company_id = $company_id
                    $where_clause 
                    ORDER BY t.date_of_transaction DESC, t.id DESC 
                    LIMIT $offset, $limit";

$transactions = $conn->query($transactions_sql);

// Count the total number of Payment Out transactions
$total_sql = "SELECT COUNT(*) as count 
              FROM transactions t 
              LEFT JOIN parties p ON t.party_id = p.id
              WHERE t.transaction_type = 'Payment' 
              AND t.payment_type = 'Payment_Out'
              AND t.company_id = $company_id
              $where_clause";
$total_result = $conn->query($total_sql);

if ($total_result && $transactions) {
    $total_transactions = $total_result->fetch_assoc()['count'];
    $total_pages = ceil($total_transactions / $limit);
} else {
    $total_transactions = 0;
    $total_pages = 0;
    $transactions = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Payment</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&family=Open+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.0.18/dist/sweetalert2.min.css">
    <style>
        :root {
            --primary: #FFD700;
            --primary-dark: #DAA520;
            --secondary: #2D3436;
            --success: #00B894;
            --danger: #FF7675;
            --warning: #FFEAA7;
            --info: #74B9FF;
            --light: #DFE6E9;
            --dark: #2D3436;
        }

        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background-color: #F8F9FA;
            color: var(--secondary);
            font-weight: 400;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .app-container {
            max-width: 100%;
            margin: 0;
            padding: 0;
        }

        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.04);
        }

        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #DFE6E9;
            padding: 0.375rem 0.5rem;
            font-size: 0.8rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(255, 215, 0, 0.25);
        }

        .btn {
            border-radius: 8px;
            padding: 0.375rem 0.75rem;
            font-weight: 500;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }

        .btn-danger {
            background-color: var(--danger);
            border: none;
            color: white;
        }

        .btn-danger:hover {
            background-color: #ff6b6b;
        }

        .table {
            margin-bottom: 0;
            font-size: 0.875rem;
        }

        .table th {
            background-color: #F8F9FA;
            font-weight: 600;
            padding: 0.5rem;
            white-space: nowrap;
        }

        .table td {
            padding: 0.5rem;
            vertical-align: middle;
        }

        /* Soft gradient backgrounds */
        .soft-gradient-blue { background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.05)); }
        .soft-gradient-green { background: linear-gradient(135deg, rgba(34, 197, 94, 0.1), rgba(34, 197, 94, 0.05)); }
        .soft-gradient-orange { background: linear-gradient(135deg, rgba(249, 115, 22, 0.1), rgba(249, 115, 22, 0.05)); }
        .soft-gradient-purple { background: linear-gradient(135deg, rgba(168, 85, 247, 0.1), rgba(168, 85, 247, 0.05)); }
        .soft-gradient-teal { background: linear-gradient(135deg, rgba(20, 184, 166, 0.1), rgba(20, 184, 166, 0.05)); }
        .soft-gradient-red { background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(239, 68, 68, 0.05)); }

        .text-primary { color: #0284c7; }
        .text-warning { color: #d97706; }
        .text-success { color: #16a34a; }
        .text-danger { color: #dc2626; }
        .text-info { color: #0284c7; }

        /* Professional input styling */
        input, select, textarea {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-weight: 400;
            letter-spacing: -0.01em;
        }

        /* Professional button styling */
        button {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-weight: 500;
            letter-spacing: -0.01em;
        }

        /* Responsive table styles */
        .responsive-table {
            font-size: 0.75rem;
        }
        
        .responsive-table th,
        .responsive-table td {
            padding: 0.25rem 0.125rem;
        }

        /* Prevent horizontal overflow */
        .w-full {
            max-width: 100%;
            overflow-x: hidden;
        }

        /* Fix table overflow issues */
        .responsive-table {
            width: 100% !important;
            table-layout: fixed !important;
        }

        .responsive-table th,
        .responsive-table td {
            word-wrap: break-word;
            overflow-wrap: break-word;
            max-width: 0;
        }

        /* Party list styling */
        .party-item {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-weight: 400;
            letter-spacing: -0.01em;
        }

        /* Fix dropdown width */
        #partyList {
            position: absolute !important;
            top: 100% !important;
            left: 0 !important;
            right: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
            z-index: 1000 !important;
        }

        /* Statistics grid responsive */
        @media (max-width: 768px) {
            .grid.grid-cols-1.md\\:grid-cols-2.lg\\:grid-cols-6 {
                grid-template-columns: repeat(1, minmax(0, 1fr)) !important;
            }
            
            .responsive-table th,
            .responsive-table td {
                font-size: 0.7rem !important;
                padding: 0.25rem 0.125rem !important;
            }
        }

        @media (min-width: 769px) and (max-width: 1024px) {
            .grid.grid-cols-1.md\\:grid-cols-2.lg\\:grid-cols-6 {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }
            
            .responsive-table th,
            .responsive-table td {
                font-size: 0.75rem !important;
                padding: 0.375rem 0.25rem !important;
            }
        }
    </style>
</head>
<body>
    <!-- Main Content Container -->
    <div class="w-full">
        <!-- Colorful Statistics with Icons -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4 mb-6">
            <!-- Cash Paid -->
            <div class="soft-gradient-red rounded-xl p-4 shadow-sm h-full">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-red-700 mb-1">Cash Paid</p>
                        <p class="text-lg font-bold text-red-800 mb-0">₹<?= number_format($stats['total_cash_paid'] ?? 0, 0) ?></p>
                        <p class="text-xs text-red-600 mb-0">Cash Payments</p>
                    </div>
                    <div class="w-10 h-10 bg-red-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-money-bill-wave text-white text-sm"></i>
                    </div>
                </div>
            </div>
            
            <!-- Bank Paid -->
            <div class="soft-gradient-orange rounded-xl p-4 shadow-sm h-full">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-orange-700 mb-1">Bank Paid</p>
                        <p class="text-lg font-bold text-orange-800 mb-0">₹<?= number_format($stats['total_bank_paid'] ?? 0, 0) ?></p>
                        <p class="text-xs text-orange-600 mb-0">Bank/UPI/Cheque</p>
                    </div>
                    <div class="w-10 h-10 bg-orange-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-university text-white text-sm"></i>
                    </div>
                </div>
            </div>
            
            <!-- Total Paid -->
            <div class="soft-gradient-purple rounded-xl p-4 shadow-sm h-full">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-purple-700 mb-1">Total Paid</p>
                        <p class="text-lg font-bold text-purple-800 mb-0">₹<?= number_format($stats['total_payments_sent'] ?? 0, 0) ?></p>
                        <p class="text-xs text-purple-600 mb-0">All Payments</p>
                    </div>
                    <div class="w-10 h-10 bg-purple-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-coins text-white text-sm"></i>
                    </div>
                </div>
            </div>
            
            <!-- Payment Transactions -->
            <div class="soft-gradient-teal rounded-xl p-4 shadow-sm h-full">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-teal-700 mb-1">Transactions</p>
                        <p class="text-lg font-bold text-teal-800 mb-0"><?= number_format($stats['total_payment_transactions'] ?? 0, 0) ?></p>
                        <p class="text-xs text-teal-600 mb-0">Payment Count</p>
                    </div>
                    <div class="w-10 h-10 bg-teal-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-receipt text-white text-sm"></i>
                    </div>
                </div>
            </div>
            
            <!-- Pending Payments -->
            <div class="soft-gradient-blue rounded-xl p-4 shadow-sm h-full">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-blue-700 mb-1">Pending</p>
                        <p class="text-lg font-bold text-blue-800 mb-0">₹<?= number_format($stats['total_pending_payment'] ?? 0, 0) ?></p>
                        <p class="text-xs text-blue-600 mb-0">To Pay</p>
                    </div>
                    <div class="w-10 h-10 bg-blue-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-exclamation-circle text-white text-sm"></i>
                    </div>
                </div>
            </div>
            
            <!-- Payment Rate -->
            <div class="soft-gradient-green rounded-xl p-4 shadow-sm h-full">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-green-700 mb-1">Payment Rate</p>
                        <p class="text-lg font-bold text-green-800 mb-0"><?= $stats['total_purchase_amount'] > 0 ? number_format((($stats['total_paid_amount'] ?? 0) / $stats['total_purchase_amount']) * 100, 1) : 0 ?>%</p>
                        <p class="text-xs text-green-600 mb-0">Paid %</p>
                    </div>
                    <div class="w-10 h-10 bg-green-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-percentage text-white text-sm"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Form and List Layout -->
        <div class="flex flex-col lg:flex-row gap-6">
            <!-- Left Side - Send Payment Form -->
            <div class="bg-white rounded-lg shadow-md border border-gray-200" style="flex: 0 0 55%;">
                <div class="bg-gradient-to-r from-red-500 to-red-600 px-4 py-2 rounded-t-lg">
                    <h2 class="text-base font-semibold text-white flex items-center">
                        <i class="fas fa-paper-plane mr-2"></i>
                        Send Payment
                    </h2>
                </div>
                <div class="p-3">
                    <form id="paymentOutForm" method="POST" class="space-y-3">
                        <input type="hidden" name="action" value="save_payment_out">
                        <input type="hidden" name="party_id" id="partyId">
                        
                        <!-- Row 1: Payment ID & Date -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Payment ID <span id="editModeIndicator" class="text-xs text-orange-600 font-semibold hidden">(Editing)</span></label>
                                <div class="relative">
                                    <input type="text" class="block w-full px-3 py-2 pr-10 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500 cursor-pointer" name="receipt_id" readonly id="paymentIdInput" tabindex="0">
                                    <button type="button" class="absolute right-2 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600" id="showPaymentListBtn" title="Show previous payments">
                                        <i class="fas fa-history"></i>
                                    </button>
                                </div>
                                <div id="paymentList" class="absolute z-10 mt-1 bg-white border border-gray-200 rounded-lg shadow-xl max-h-64 overflow-y-auto hidden" style="width: 400px; max-width: 90vw;"></div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                                <input type="datetime-local" class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500" name="date_of_transaction" required>
                            </div>
                        </div>

                        <!-- Row 2: Party Name -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Party Name (Supplier/Vendor)</label>
                            <div class="relative">
                                <input type="text" class="block w-full pl-3 pr-20 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500" name="party_name" id="partyNameInput" required autocomplete="off" placeholder="Enter party name...">
                                <div class="absolute inset-y-0 right-0 flex items-center pr-2">
                                    <button type="button" class="px-3 py-1 text-sm bg-red-500 text-white rounded-lg hover:bg-red-600" id="addNewPartyBtn">
                                        <i class="fas fa-plus mr-1"></i>New
                                    </button>
                                </div>
                                <div id="partyList" class="absolute top-full left-0 right-0 bg-white border border-gray-200 rounded-lg shadow-xl max-h-64 overflow-y-auto z-50 hidden" style="width: calc(100% - 0px);"></div>
                            </div>
                        </div>

                        <!-- Party Info -->
                        <div id="partyInfoSection" class="hidden">
                            <div class="bg-orange-50 border border-orange-200 rounded-lg p-3" id="partyInfoAlert">
                                <!-- Will be populated by JavaScript -->
                            </div>
                        </div>
                        
                        <!-- Row 3: Payment Amount & Method -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Payment Amount (₹)</label>
                                <input type="number" step="0.01" class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500" name="payment_amount" id="paymentAmount" required placeholder="0.00">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                                <select class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500" name="payment_method" required>
                                    <option value="">Select Method</option>
                                    <option value="Cash">Cash</option>
                                    <option value="Bank">Bank Transfer</option>
                                    <option value="UPI">UPI</option>
                                    <option value="Cheque">Cheque</option>
                                    <option value="Card">Card Payment</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Row 4: Payment Type -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Payment Type</label>
                            <select class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500" name="payment_type" required>
                                <option value="">Select Type</option>
                                <option value="Payment_Out">Payment Sent</option>
                                <option value="Supplier_Payment">Supplier Payment</option>
                                <option value="Vendor_Payment">Vendor Payment</option>
                                <option value="Refund_Payment">Refund Payment</option>
                                <option value="Advance_Payment">Advance Payment</option>
                            </select>
                        </div>

                        <!-- Row 5: Narration -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Narration (Optional)</label>
                            <textarea class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500" name="narration" rows="2" placeholder="Enter any additional notes..."></textarea>
                        </div>

                        <!-- Row 6: Submit and Reset Buttons -->
                        <div class="grid grid-cols-2 gap-3">
                            <button type="submit" id="sendPaymentBtn" class="px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-lg hover:bg-red-700 focus:ring-2 focus:ring-red-500 focus:ring-offset-2 flex items-center justify-center">
                                <i class="fas fa-paper-plane mr-2"></i>Send Payment
                            </button>
                            <button type="button" id="resetFormBtn" class="px-4 py-2 bg-gray-500 text-white text-sm font-medium rounded-lg hover:bg-gray-600 focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 flex items-center justify-center">
                                <i class="fas fa-undo mr-2"></i>Reset
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Right Side - Recent Payments List -->
            <div class="bg-white rounded-lg shadow-md border border-gray-200" style="flex: 0 0 45%;">
                <div class="bg-gradient-to-r from-red-500 to-red-600 px-4 py-2 rounded-t-lg">
                    <h2 class="text-base font-semibold text-white flex items-center">
                        <i class="fas fa-list mr-2"></i>
                        Recent Payments Sent
                    </h2>
                </div>
                <div class="p-3 max-w-full">
                    <div class="overflow-x-auto max-w-full">
                        <table class="w-full text-sm responsive-table" style="table-layout: fixed; width: 100%; max-width: 100%;">
                            <thead>
                                <tr class="bg-gray-50 border-b">
                                    <th class="text-left py-2 px-1 font-medium text-gray-700 text-sm" style="width: 25%;">Payment & Date</th>
                                    <th class="text-left py-2 px-1 font-medium text-gray-700 text-sm" style="width: 25%;">Party</th>
                                    <th class="text-left py-2 px-1 font-medium text-gray-700 text-sm" style="width: 25%;">Amount & Method</th>
                                    <th class="text-left py-2 px-1 font-medium text-gray-700 text-sm" style="width: 15%;">Type</th>
                                    <th class="text-left py-2 px-1 font-medium text-gray-700 text-sm" style="width: 10%;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($transactions && $transactions->num_rows > 0): 
                                foreach ($transactions as $t): ?>
                                    <tr class="border-b hover:bg-gray-50">
                                        <td class="py-2 px-1">
                                            <div class="flex items-center">
                                                <span class="bg-red-500 text-white text-xs px-1.5 py-0.5 rounded-full mr-1 font-bold">OUT</span>
                                                <div>
                                                    <div class="font-mono text-sm font-bold text-gray-900"><?= htmlspecialchars($t['receipt_id']) ?></div>
                                                    <div class="text-xs text-gray-500 border-b border-gray-300 pb-0.5"><?= date('d M Y', strtotime($t['date_of_transaction'])) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-2 px-1">
                                            <div class="font-semibold text-gray-900 text-sm"><?= htmlspecialchars($t['party_name']) ?></div>
                                            <?php if ($t['party_contact']): ?>
                                                <div class="text-xs text-gray-500"><?= htmlspecialchars($t['party_contact']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-2 px-1">
                                            <div class="text-sm font-bold text-red-600">₹<?= number_format($t['payment_amount'], 2) ?></div>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars($t['payment_method']) ?></div>
                                        </td>
                                        <td class="py-2 px-1">
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                <?= htmlspecialchars($t['payment_type']) ?>
                                            </span>
                                        </td>
                                        <td class="py-2 px-1">
                                            <div class="flex items-center space-x-1">
                                                <button type="button" class="text-blue-600 hover:text-blue-800" title="Print" data-id="<?= $t['id'] ?>">
                                                    <i class="fas fa-print text-xs"></i>
                                                </button>
                                                <button type="button" class="text-red-600 hover:text-red-800" title="Delete" data-id="<?= $t['id'] ?>" data-receipt-id="<?= htmlspecialchars($t['receipt_id']) ?>" data-amount="<?= $t['payment_amount'] ?>">
                                                    <i class="fas fa-trash text-xs"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; 
                                else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-8 text-gray-500">
                                            <i class="fas fa-inbox text-2xl mb-2"></i><br>
                                            No payments sent yet
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="mt-4 flex justify-center">
                        <nav class="flex space-x-1">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="?page=<?= $i ?><?= isset($_GET['search']) ? '&search=' . htmlspecialchars($_GET['search']) : '' ?>" 
                                   class="px-3 py-2 text-sm border border-gray-300 rounded-lg <?= $i == $page ? 'bg-red-500 text-white' : 'text-gray-700 hover:bg-gray-50' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="js/keyboard-navigation-generic.js"></script>
    <script>
        $(document).ready(function() {
            // Generate payment out ID
            function generatePaymentOutId() {
                return new Promise((resolve, reject) => {
                    fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=generate_payment_out_id'
                    })
                    .then(response => response.json())
                    .then(result => {
                        if (result.status === 'success') {
                            resolve(result.payment_id);
                        } else {
                            // Fallback to client-side generation
                            const companyId = <?= $company_id ?>;
                            const serial = Math.floor(Math.random() * 999) + 1;
                            resolve(`PAYOUT${companyId}${serial.toString().padStart(3, '0')}`);
                        }
                    })
                    .catch(error => {
                        // Fallback to client-side generation
                        const companyId = <?= $company_id ?>;
                        const serial = Math.floor(Math.random() * 999) + 1;
                        resolve(`PAYOUT${companyId}${serial.toString().padStart(3, '0')}`);
                    });
                });
            }
            
            // Set initial values
            async function initializeForm() {
                try {
                    const paymentId = await generatePaymentOutId();
                    $('#paymentIdInput').val(paymentId);
                } catch (error) {
                    console.error('Error generating payment ID:', error);
                    // Fallback to client-side generation
                    const companyId = <?= $company_id ?>;
                    const serial = Math.floor(Math.random() * 999) + 1;
                    $('#paymentIdInput').val(`PAYOUT${companyId}${serial.toString().padStart(3, '0')}`);
                }
                
                const now = new Date();
                now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
                $('[name="date_of_transaction"]').val(now.toISOString().slice(0, 16));
            }
            
            initializeForm();

            // Initialize keyboard navigation
            if (typeof KeyboardNavigationGeneric !== 'undefined') {
                KeyboardNavigationGeneric.init({
                    formId: 'paymentOutForm',
                    fieldOrder: [
                        'paymentIdInput',        // 1. Payment ID (readonly)
                        'date_of_transaction',   // 2. Date
                        'partyNameInput',        // 3. Party Name
                        'paymentAmount',         // 4. Payment Amount
                        'payment_method',        // 5. Payment Method
                        'payment_type',          // 6. Payment Type
                        'narration'              // 7. Narration
                    ],
                    skipFields: [],
                    submitButtonId: 'sendPaymentBtn',
                    formName: 'payment_out'
                });
                window.KeyboardNavigation = KeyboardNavigationGeneric;
            }

            // Payment ID History Dropdown
            $('#showPaymentListBtn, #paymentIdInput').on('click', function(e) {
                e.preventDefault();
                showPaymentList();
            });

            function showPaymentList() {
                const paymentList = $('#paymentList');
                
                // Show loading
                paymentList.html('<div class="p-3 text-center text-gray-500"><i class="fas fa-spinner fa-spin"></i> Loading...</div>');
                paymentList.removeClass('hidden');
                
                // Fetch payment list
                $.post('', {
                    action: 'get_payment_list'
                }, function(response) {
                    if (response.status === 'success' && response.data && response.data.length > 0) {
                        paymentList.html('');
                        response.data.forEach(function(payment) {
                            const paymentItem = $('<div>')
                                .addClass('payment-item p-2 border-b hover:bg-red-100 cursor-pointer')
                                .html(`
                                    <div class="flex justify-between items-center">
                                        <div>
                                            <b class="text-red-600">${payment.receipt_id}</b>
                                            <span class="text-xs text-gray-500 ml-2">${payment.party_name || ''}</span>
                                        </div>
                                        <span class="text-xs text-gray-400">${payment.date_of_transaction ? payment.date_of_transaction.split(' ')[0] : ''}</span>
                                    </div>
                                    <div class="text-xs text-gray-600 mt-1">
                                        ₹${parseFloat(payment.payment_amount).toLocaleString('en-IN')} - ${payment.payment_method} (${payment.payment_type})
                                    </div>
                                `)
                                .on('click', function() {
                                    // For now, just populate the Payment ID (edit functionality can be added later)
                                    $('#paymentIdInput').val(payment.receipt_id);
                                    paymentList.addClass('hidden');
                                    Swal.fire({
                                        icon: 'info',
                                        title: 'Payment Selected',
                                        text: 'Payment ID: ' + payment.receipt_id,
                                        timer: 1500,
                                        showConfirmButton: false
                                    });
                                });
                            paymentList.append(paymentItem);
                        });
                    } else {
                        paymentList.html('<div class="p-3 text-center text-gray-500">No previous payments found</div>');
                    }
                }, 'json').fail(function() {
                    paymentList.html('<div class="p-3 text-center text-red-500">Error loading payments</div>');
                });
            }

            // Hide payment list when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('#paymentList, #showPaymentListBtn, #paymentIdInput').length) {
                    $('#paymentList').addClass('hidden');
                }
            });

            // Party search functionality
            let partyListVisible = false;
            let currentIndex = -1;
            let selectedPartyName = '';
            
            // Function to show add party modal
            function showAddPartyModal(partyName, form) {
                Swal.fire({
                    title: '<div style="font-size: 18px; font-weight: 600; color: #1f2937; font-family: \'Poppins\', sans-serif;">Add New Party</div>',
                    html: `
                        <div style="padding: 8px;">
                            <div style="margin-bottom: 12px;">
                                <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 4px;">Party Name *</label>
                                <input type="text" id="newPartyName" value="${partyName}" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px; outline: none; transition: border-color 0.2s;" placeholder="Enter party name">
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 4px;">Address</label>
                                <textarea id="newPartyAddress" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px; outline: none; transition: border-color 0.2s; resize: vertical; min-height: 50px;" placeholder="Enter party address (optional)"></textarea>
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 4px;">Contact Number</label>
                                <input type="tel" id="newPartyContact" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px; outline: none; transition: border-color 0.2s;" placeholder="Enter contact number (optional)">
                            </div>
                            ${partyName ? `
                            <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 4px; padding: 8px; margin-bottom: 12px;">
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <div style="width: 16px; height: 16px; background: #3b82f6; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 10px;">ℹ</div>
                                    <div style="font-size: 12px; color: #1e40af;">Party "${partyName}" not found. Please fill in the details to create a new party.</div>
                                </div>
                            </div>
                            ` : ''}
                        </div>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Create Party',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#dc2626',
                    cancelButtonColor: '#6b7280',
                    width: '400px',
                    preConfirm: () => {
                        const name = document.getElementById('newPartyName').value.trim();
                        const address = document.getElementById('newPartyAddress').value.trim();
                        const contact = document.getElementById('newPartyContact').value.trim();
                        
                        if (!name) {
                            Swal.showValidationMessage('Party name is required');
                            return false;
                        }
                        
                        return { name, address, contact };
                    },
                    didOpen: () => {
                        // Focus on party name field
                        document.getElementById('newPartyName').focus();
                        
                        // Add input event listeners for better UX
                        const nameInput = document.getElementById('newPartyName');
                        const addressInput = document.getElementById('newPartyAddress');
                        const contactInput = document.getElementById('newPartyContact');
                        
                        // Add focus/blur effects
                        [nameInput, addressInput, contactInput].forEach(input => {
                            input.addEventListener('focus', function() {
                                this.style.borderColor = '#3b82f6';
                            });
                            input.addEventListener('blur', function() {
                                this.style.borderColor = '#d1d5db';
                            });
                        });
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        const { name, address, contact } = result.value;
                        
                        // Show loading
                        Swal.fire({
                            title: 'Creating Party...',
                            text: 'Please wait while we create the new party',
                            allowOutsideClick: false,
                            showConfirmButton: false,
                            didOpen: () => {
                                Swal.showLoading();
                            }
                        });
                        
                        // Create new party
                        fetch('', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `action=save_party&party_name=${encodeURIComponent(name)}&address=${encodeURIComponent(address)}&contact_no=${encodeURIComponent(contact)}`
                        })
                        .then(response => response.json())
                        .then(result => {
                            if (result.status === 'success') {
                                // Update form fields
                                $('#partyId').val(result.party_id);
                                $('#partyNameInput').val(name);
                                selectedPartyName = name;
                                
                                // Show success message
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Party Created!',
                                    text: `Party "${name}" has been created successfully.`,
                                    confirmButtonColor: '#dc2626',
                                    timer: 2000,
                                    showConfirmButton: false
                                });
                                
                                // If form is provided, retry form submission
                                if (form) {
                                    setTimeout(() => {
                                        $('#paymentOutForm').submit();
                                    }, 1000);
                                }
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: result.message || 'Failed to create party',
                                    confirmButtonColor: '#dc2626'
                                });
                            }
                        })
                        .catch(error => {
                            console.error('Error creating party:', error);
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'Failed to create party. Please try again.',
                                confirmButtonColor: '#dc2626'
                            });
                        });
                    }
                });
            }
            
            $('#partyNameInput').on('input', function () {
                const term = $(this).val();
                
                // Reset selection if user clears or modifies the selected party name
                if (term !== selectedPartyName) {
                    selectedPartyName = '';
                    $('#partyId').val('');
                }
                
                if (term.length >= 1) {
                    $.post('', {
                        action: 'search_parties',
                        term: term
                    }, function (parties) {
                        const partyList = $('#partyList');
                        partyList.empty();
                        currentIndex = -1;
                        parties.forEach((party, index) => {
                            const currentBalance = parseFloat(party.current_balance || 0);
                            let balanceBadge;
                            
                            if (currentBalance > 0) {
                                // Positive balance - They owe you
                                balanceBadge = `<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">To Receive ₹${Math.abs(currentBalance).toLocaleString('en-IN')}</span>`;
                            } else if (currentBalance < 0) {
                                // Negative balance - You owe them
                                balanceBadge = `<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">To Pay ₹${Math.abs(currentBalance).toLocaleString('en-IN')}</span>`;
                            } else {
                                // Zero balance
                                balanceBadge = `<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">Clear</span>`;
                            }
                            
                            const partyItem = document.createElement('div');
                            partyItem.className = 'px-2 py-1.5 hover:bg-red-50 cursor-pointer border-b border-gray-100 last:border-b-0 transition-colors duration-150 party-option';
                            partyItem.setAttribute('data-index', index);
                            partyItem.setAttribute('data-id', party.id || '');
                            partyItem.setAttribute('data-name', party.party_name || '');
                            partyItem.setAttribute('data-address', party.address || '');
                            partyItem.setAttribute('data-balance', currentBalance);
                            
                            partyItem.innerHTML = `
                                <div class="flex items-center">
                                    <div class="w-6 h-6 bg-gradient-to-br from-red-500 to-red-600 rounded-full flex items-center justify-center text-white text-xs font-semibold mr-2 shadow-sm">
                                        ${(party.party_name || 'U').charAt(0).toUpperCase()}
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-medium text-gray-900 truncate">${party.party_name || 'Unknown Party'}</div>
                                        <div class="text-xs text-gray-500 truncate">${party.address || 'No address provided'}</div>
                                    </div>
                                    <div class="flex items-center space-x-1 ml-2">
                                        <div class="text-right">
                                            ${balanceBadge}
                                        </div>
                                        <div class="text-xs text-gray-400">
                                            <i class="fas fa-chevron-right"></i>
                                        </div>
                                    </div>
                                </div>
                            `;
                            
                            // Add click handler
                            partyItem.addEventListener('click', (e) => {
                                e.stopPropagation();
                                const partyData = {
                                    id: partyItem.getAttribute('data-id'),
                                    party_name: partyItem.getAttribute('data-name'),
                                    address: partyItem.getAttribute('data-address'),
                                    current_balance: partyItem.getAttribute('data-balance')
                                };
                                selectParty(partyData);
                            });
                            
                            partyList[0].appendChild(partyItem);
                        });
                        if (parties.length > 0) {
                            partyList.removeClass('hidden');
                            partyListVisible = true;
                            currentIndex = -1;
                        } else {
                            partyList.addClass('hidden');
                            partyListVisible = false;
                        }
                    }, 'json');
                } else {
                    $('#partyList').addClass('hidden');
                    partyListVisible = false;
                    selectedPartyName = '';
                    $('#partyId').val('');
                    $('#partyInfoSection').addClass('hidden');
                }
            });

            // Keyboard navigation for party list
            $('#partyNameInput').on('keydown', function(e) {
                const partyItems = document.querySelectorAll('#partyList .party-option');
                
                if (e.key === 'ArrowDown' && partyListVisible && partyItems.length > 0) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (currentIndex < 0) {
                        currentIndex = 0;
                    } else {
                        currentIndex = Math.min(currentIndex + 1, partyItems.length - 1);
                    }
                    updatePartyHighlight();
                } else if (e.key === 'ArrowUp' && partyListVisible && partyItems.length > 0) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (currentIndex <= 0) {
                        currentIndex = -1;
                    } else {
                        currentIndex = Math.max(currentIndex - 1, 0);
                    }
                    updatePartyHighlight();
                } else if (e.key === 'Enter' && partyListVisible && currentIndex >= 0) {
                    e.preventDefault();
                    e.stopPropagation();
                    const selectedItem = partyItems[currentIndex];
                    if (selectedItem) {
                        selectedItem.click();
                    }
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    e.stopPropagation();
                    $('#partyList').addClass('hidden');
                    partyListVisible = false;
                    currentIndex = -1;
                }
            });
            
            // Function to update party selection highlighting
            function updatePartyHighlight() {
                const partyItems = document.querySelectorAll('#partyList .party-option');
                
                partyItems.forEach((item, index) => {
                    if (index === currentIndex && currentIndex >= 0) {
                        item.classList.add('bg-red-100', 'border-l-4', 'border-red-500');
                        item.classList.remove('hover:bg-red-50');
                    } else {
                        item.classList.remove('bg-red-100', 'border-l-4', 'border-red-500');
                        item.classList.add('hover:bg-red-50');
                    }
                });
                
                // Scroll into view
                if (currentIndex >= 0 && currentIndex < partyItems.length) {
                    const currentItem = partyItems[currentIndex];
                    currentItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            }
            
            // Party selection function
            function selectParty(party) {
                selectedPartyName = party.party_name;
                $('#partyId').val(party.id);
                $('#partyNameInput').val(party.party_name);
                $('#partyList').addClass('hidden');
                partyListVisible = false;
                currentIndex = -1;
                
                // Get party info
                $.post('', {
                    action: 'get_party_info',
                    party_id: party.id
                }, function(response) {
                    if (response.total_paid > 0) {
                        let infoHTML = `
                            <div class="text-center text-xs">
                                <div class="text-gray-600">Total Paid to this Party</div>
                                <div class="font-bold text-red-600 text-lg">₹${response.total_paid.toLocaleString('en-IN')}</div>
                                <div class="text-gray-500 mt-1">Cash: ₹${response.cash_paid.toLocaleString('en-IN')} | Bank: ₹${response.bank_paid.toLocaleString('en-IN')}</div>
                            </div>
                        `;
                        
                        $('#partyInfoAlert').html(infoHTML);
                        $('#partyInfoSection').removeClass('hidden');
                    } else {
                        $('#partyInfoSection').addClass('hidden');
                    }
                }, 'json');
            }

            // Add New Party button click handler
            $('#addNewPartyBtn').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                showAddPartyModal('', null);
            });

            // Form submission
            $('#paymentOutForm').on('submit', function(e) {
                e.preventDefault();
                
                const form = this;
                const partyId = $('#partyId').val();
                
                if (!partyId) {
                    const partyName = $('#partyNameInput').val().trim();
                    if (partyName) {
                        showAddPartyModal(partyName, form);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Party Not Selected',
                            text: 'Please select a party from the dropdown list or add a new party first.'
                        });
                        $('#partyNameInput').focus();
                    }
                    return false;
                }
                
                const partyName = $('[name="party_name"]').val();
                const paymentAmount = $('[name="payment_amount"]').val();
                const paymentMethod = $('[name="payment_method"]').val();
                const paymentType = $('[name="payment_type"]').val();
                
                // Show confirmation dialog
                Swal.fire({
                    title: '<div style="font-size: 20px; font-weight: 700; color: #1f2937; font-family: \'Poppins\', sans-serif;">Confirm Payment Send</div>',
                    html: `
                        <div style="font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; padding: 4px;">
                            <!-- Party Section -->
                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 8px;">
                                <div style="display: flex; align-items: center; margin-bottom: 6px;">
                                    <div style="width: 20px; height: 20px; background: #dc2626; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 8px;">
                                        <i class="fas fa-user" style="color: white; font-size: 10px;"></i>
                                    </div>
                                    <span style="font-size: 13px; font-weight: 600; color: #374151;">Paying To</span>
                                </div>
                                <div style="font-size: 14px; color: #1f2937; font-weight: 500;">${partyName}</div>
                            </div>
                            
                            <!-- Payment Details -->
                            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px;">
                                <div style="display: flex; align-items: center; margin-bottom: 6px;">
                                    <div style="width: 20px; height: 20px; background: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 8px;">
                                        <i class="fas fa-money-bill-wave" style="color: white; font-size: 10px;"></i>
                                    </div>
                                    <span style="font-size: 13px; font-weight: 600; color: #374151;">Payment Details</span>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 13px;">
                                    <div><span style="color: #6b7280;">Amount:</span> <span style="color: #dc2626; font-weight: 600;">₹${parseFloat(paymentAmount).toLocaleString('en-IN')}</span></div>
                                    <div><span style="color: #6b7280;">Method:</span> <span style="color: #1f2937; font-weight: 500;">${paymentMethod}</span></div>
                                    <div><span style="color: #6b7280;">Type:</span> <span style="color: #1f2937; font-weight: 500;">${paymentType}</span></div>
                                </div>
                            </div>
                        </div>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Send Payment',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#dc2626',
                    cancelButtonColor: '#6b7280',
                    width: '420px'
                }).then((result) => {
                    if (result.isConfirmed) {
                        const formData = new FormData(form);
                        
                        $.ajax({
                            url: '',
                            method: 'POST',
                            data: formData,
                            processData: false,
                            contentType: false,
                            dataType: 'json',
                            beforeSend: function() {
                                Swal.fire({
                                    title: 'Processing...',
                                    text: 'Please wait while we process your payment',
                                    allowOutsideClick: false,
                                    allowEscapeKey: false,
                                    didOpen: () => {
                                        Swal.showLoading();
                                    }
                                });
                            },
                            success: function(response) {
                                if(response.status === 'success') {
                                    Swal.fire({
                                        icon: 'success',
                                        title: 'Payment Sent!',
                                        text: 'Payment has been sent successfully!',
                                        timer: 2000,
                                        showConfirmButton: false
                                    });
                                    
                                    // Reset form after successful payment
                                    resetPaymentForm();
                                    
                                    setTimeout(() => {
                                        location.reload();
                                    }, 2000);
                                } else {
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Error',
                                        text: response.message || 'Failed to send payment'
                                    });
                                }
                            },
                            error: function(xhr, status, error) {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: 'An error occurred while processing your request. Please try again.'
                                });
                            }
                        });
                    }
                });
            });

            // Reset form button
            $('#resetFormBtn').on('click', function() {
                Swal.fire({
                    title: 'Reset Form?',
                    text: 'This will clear all form fields. Are you sure?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, Reset',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#6c757d',
                    cancelButtonColor: '#dc2626'
                }).then((result) => {
                    if (result.isConfirmed) {
                        resetPaymentForm();
                        Swal.fire({
                            icon: 'success',
                            title: 'Form Reset',
                            text: 'All fields have been cleared.',
                            timer: 1500,
                            showConfirmButton: false
                        });
                    }
                });
            });

            // Function to reset the payment form
            function resetPaymentForm() {
                // Clear party selection
                $('#partyNameInput').val('');
                $('#partyId').val('');
                $('#partyInfoSection').addClass('hidden');
                selectedPartyName = '';
                
                // Reset form fields
                $('[name="payment_amount"]').val('');
                $('[name="payment_method"]').val('');
                $('[name="payment_type"]').val('');
                $('[name="narration"]').val('');
                
                // Generate new payment ID
                generatePaymentOutId().then(paymentId => {
                    $('#paymentIdInput').val(paymentId);
                }).catch(error => {
                    console.error('Error generating payment ID:', error);
                    const companyId = <?= $company_id ?>;
                    const serial = Math.floor(Math.random() * 999) + 1;
                    $('#paymentIdInput').val(`PAYOUT${companyId}${serial.toString().padStart(3, '0')}`);
                });
                
                // Reset date to current time
                const now = new Date();
                now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
                $('[name="date_of_transaction"]').val(now.toISOString().slice(0, 16));
                
                // Focus on party name field
                $('#partyNameInput').focus();
            }

            // Auto-focus party name field on wide screens
            if ($(window).width() >= 992) {
                setTimeout(function() {
                    $('#partyNameInput').focus();
                }, 500);
            }
        });
    </script>
</body>
</html>

<?php
// Capture the content
$content = ob_get_clean();

// Set page title and include layout
$page_title = "Send Payment";
include 'components/layout.php';
?>
