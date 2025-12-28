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
                        
                        -- Cash booking amounts and payments
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' AND t.booking_type = 'Cash' THEN t.gold_amount ELSE 0 END), 0) as cash_booked_amount,
                        COALESCE(SUM(CASE WHEN (t.transaction_type = 'Payment' OR t.transaction_type = 'Received') AND t.payment_type = 'Payment_In' AND t.payment_method = 'Cash' THEN t.payment_amount ELSE 0 END), 0) as cash_received,
                        
                        -- Bank booking amounts and payments
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' AND t.booking_type = 'Bank' THEN t.gold_amount ELSE 0 END), 0) as bank_booked_amount,
                        COALESCE(SUM(CASE WHEN (t.transaction_type = 'Payment' OR t.transaction_type = 'Received') AND t.payment_type = 'Payment_In' AND t.payment_method = 'Bank' THEN t.payment_amount ELSE 0 END), 0) as bank_received
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
                    $available_weight = max(0, $booked_weight - $row['sold_weight']);
                    
                    // Calculate cash due and bank due separately
                    $cash_due = max(0, $row['cash_booked_amount'] - $row['cash_received']);
                    $bank_due = max(0, $row['bank_booked_amount'] - $row['bank_received']);
                    $total_due = $cash_due + $bank_due;
                    
                    $parties[] = [
                        'id' => $row['id'],
                        'party_name' => $row['party_name'],
                        'address' => $row['address'],
                        'contact_no' => $row['contact_no'],
                        'booked_weight' => number_format($booked_weight, 2),
                        'sold_weight' => number_format($row['sold_weight'], 2),
                        'available_weight' => number_format($available_weight, 2),
                        'booked_amount' => number_format($booked_amount, 2),
                        'total_due' => number_format($total_due, 2),
                        'remaining_amount' => $row['current_balance'], // Use actual current_balance from parties table (no formatting)
                        'cash_due' => number_format($cash_due, 2),
                        'bank_due' => number_format($bank_due, 2),
                        'cash_received' => number_format($row['cash_received'], 2),
                        'bank_received' => number_format($row['bank_received'], 2),
                        'total_received' => number_format($row['cash_received'] + $row['bank_received'], 2),
                        // Add actual balance information from parties table
                        'current_balance' => floatval($row['current_balance']),
                        'cash_balance' => floatval($row['cash_balance']),
                        'bank_balance' => floatval($row['bank_balance'])
                    ];
                }
                echo json_encode($parties);
                exit;
                
            case 'get_party_balance':
                $party_id = intval($_POST['party_id']);
                
                // Get balance information from parties table
                $party_sql = "SELECT current_balance, cash_balance, bank_balance FROM parties WHERE id = $party_id AND company_id = $company_id";
                $party_result = $conn->query($party_sql);
                $party_data = $party_result->fetch_assoc();
                
                $sql = "SELECT 
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' THEN t.gold_weight ELSE 0 END), 0) as booked_weight,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Sale' THEN t.gold_weight ELSE 0 END), 0) as sold_weight,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' THEN t.gold_amount ELSE 0 END), 0) as booked_amount,
                        
                        -- Cash booking amounts and payments
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' AND t.booking_type = 'Cash' THEN t.gold_amount ELSE 0 END), 0) as cash_booked_amount,
                        COALESCE(SUM(CASE WHEN (t.transaction_type = 'Payment' OR t.transaction_type = 'Received') AND t.payment_type = 'Payment_In' AND t.payment_method = 'Cash' THEN t.payment_amount ELSE 0 END), 0) as cash_received,
                        
                        -- Bank booking amounts and payments
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' AND t.booking_type = 'Bank' THEN t.gold_amount ELSE 0 END), 0) as bank_booked_amount,
                        COALESCE(SUM(CASE WHEN (t.transaction_type = 'Payment' OR t.transaction_type = 'Received') AND t.payment_type = 'Payment_In' AND t.payment_method = 'Bank' THEN t.payment_amount ELSE 0 END), 0) as bank_received,
                        
                        -- Total received
                        COALESCE(SUM(CASE WHEN (t.transaction_type = 'Payment' OR t.transaction_type = 'Received') AND t.payment_type = 'Payment_In' THEN t.payment_amount ELSE 0 END), 0) as total_received
                        FROM transactions t 
                        WHERE t.party_id = $party_id AND t.company_id = $company_id";
                $result = $conn->query($sql);
                if ($result && $result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    
                    // Calculate cash due and bank due separately
                    $cash_due = max(0, $row['cash_booked_amount'] - $row['cash_received']);
                    $bank_due = max(0, $row['bank_booked_amount'] - $row['bank_received']);
                    $total_due = $cash_due + $bank_due;
                    
                    echo json_encode([
                        'booked_weight' => floatval($row['booked_weight'] ?? 0),
                        'sold_weight' => floatval($row['sold_weight'] ?? 0),
                        'available_weight' => floatval($row['booked_weight'] - $row['sold_weight']),
                        'booked_amount' => floatval($row['booked_amount'] ?? 0),
                        'total_received' => floatval($row['total_received']),
                        'remaining_amount' => floatval($total_due),
                        'cash_received' => floatval($row['cash_received'] ?? 0),
                        'bank_received' => floatval($row['bank_received'] ?? 0),
                        'has_balance' => $total_due > 0,
                        'cash_due' => floatval($cash_due),
                        'bank_due' => floatval($bank_due),
                        'total_due' => floatval($total_due),
                        // Add balance information from parties table
                        'current_balance' => floatval($party_data['current_balance'] ?? 0),
                        'cash_balance' => floatval($party_data['cash_balance'] ?? 0),
                        'bank_balance' => floatval($party_data['bank_balance'] ?? 0),
                        'balance_breakdown' => [
                            'cash_booked' => floatval($row['cash_booked_amount'] ?? 0),
                            'cash_received' => floatval($row['cash_received'] ?? 0),
                            'cash_due' => floatval($cash_due),
                            'bank_booked' => floatval($row['bank_booked_amount'] ?? 0),
                            'bank_received' => floatval($row['bank_received'] ?? 0),
                            'bank_due' => floatval($bank_due),
                            'total_due' => floatval($total_due)
                        ]
                    ]);
                } else {
                    echo json_encode([
                        'booked_weight' => 0,
                        'sold_weight' => 0,
                        'available_weight' => 0,
                        'booked_amount' => 0,
                        'total_received' => 0,
                        'remaining_amount' => 0,
                        'cash_received' => 0,
                        'bank_received' => 0,
                        'has_balance' => false,
                        'cash_due' => 0,
                        'bank_due' => 0,
                        'total_due' => 0,
                        'balance_breakdown' => [
                            'cash_booked' => 0,
                            'cash_received' => 0,
                            'cash_due' => 0,
                            'bank_booked' => 0,
                            'bank_received' => 0,
                            'bank_due' => 0,
                            'total_due' => 0
                        ]
                    ]);
                }
                exit;
                
            case 'get_payment_details':
                $transaction_id = intval($_POST['transaction_id'] ?? 0);
                
                if ($transaction_id <= 0) {
                    echo json_encode(['status' => 'error', 'message' => 'Invalid transaction ID']);
                    exit;
                }
                
                $sql = "SELECT t.*, p.party_name, p.address, p.contact_no 
                       FROM transactions t 
                       LEFT JOIN parties p ON t.party_id = p.id
                       WHERE t.id = $transaction_id 
                       AND t.company_id = $company_id 
                       AND (t.transaction_type = 'Payment' OR t.transaction_type = 'Received')
                       AND t.payment_type = 'Payment_In'";
                $result = $conn->query($sql);
                
                if ($result && $result->num_rows > 0) {
                    $transaction = $result->fetch_assoc();
                    echo json_encode(['status' => 'success', 'data' => $transaction]);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Transaction not found']);
                }
                exit;
                
            case 'update_payment':
                $conn->begin_transaction();
                try {
                    $transaction_id = intval($_POST['transaction_id'] ?? 0);
                    $receipt_id = $conn->real_escape_string($_POST['receipt_id']);
                    $date_of_transaction = $conn->real_escape_string($_POST['date_of_transaction']);
                    $party_id = intval($_POST['party_id']);
                    $payment_amount = floatval($_POST['payment_amount']);
                    $payment_method = $conn->real_escape_string($_POST['payment_method']);
                    $narration = $conn->real_escape_string($_POST['narration'] ?? '');
                    
                    if ($transaction_id <= 0 || empty($receipt_id) || $party_id <= 0 || $payment_amount <= 0) {
                        throw new Exception("Please fill all required fields with valid values");
                    }
                    
                    // Get original transaction
                    $original_sql = "SELECT * FROM transactions WHERE id = $transaction_id AND company_id = $company_id";
                    $original_result = $conn->query($original_sql);
                    $original_trans = $original_result->fetch_assoc();
                    
                    if (!$original_trans) {
                        throw new Exception('Transaction not found');
                    }
                    
                    $original_amount = floatval($original_trans['payment_amount']);
                    $original_method = $original_trans['payment_method'];
                    $original_party_id = $original_trans['party_id'];
                    
                    // Reverse original payment (add back to balance)
                    if ($original_method == 'Cash') {
                        $reverse_sql = "UPDATE parties SET cash_balance = cash_balance + $original_amount WHERE id = $original_party_id";
                    } else {
                        $reverse_sql = "UPDATE parties SET bank_balance = bank_balance + $original_amount WHERE id = $original_party_id";
                    }
                    $conn->query($reverse_sql);
                    
                    // Update current_balance
                    $conn->query("UPDATE parties SET current_balance = cash_balance + bank_balance WHERE id = $original_party_id");
                    
                    // Determine booking_type
                    $booking_type = (strtolower($payment_method) === 'cash') ? 'Cash' : 'Bank';
                    
                    // Update transaction
                    $update_sql = "UPDATE transactions SET 
                                  receipt_id = '$receipt_id',
                                  date_of_transaction = '$date_of_transaction',
                                  party_id = $party_id,
                                  payment_amount = $payment_amount,
                                  payment_method = '$payment_method',
                                  booking_type = '$booking_type',
                                  narration = '$narration',
                                  updated_at = NOW()
                                  WHERE id = $transaction_id AND company_id = $company_id";
                    
                    if (!$conn->query($update_sql)) {
                        throw new Exception('Error updating transaction: ' . $conn->error);
                    }
                    
                    // Apply new payment (deduct from balance)
                    if ($payment_method == 'Cash') {
                        $apply_sql = "UPDATE parties SET cash_balance = cash_balance - $payment_amount WHERE id = $party_id";
                    } else {
                        $apply_sql = "UPDATE parties SET bank_balance = bank_balance - $payment_amount WHERE id = $party_id";
                    }
                    $conn->query($apply_sql);
                    
                    // Update current_balance
                    $conn->query("UPDATE parties SET current_balance = cash_balance + bank_balance WHERE id = $party_id");
                    
                    $conn->commit();
                    
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Payment receipt updated successfully'
                    ]);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Error updating payment: ' . $e->getMessage()
                    ]);
                }
                exit;
                
            case 'save_payment':
                $conn->begin_transaction();
                try {
                    $receipt_id = $conn->real_escape_string($_POST['receipt_id']);
                    $date_of_transaction = $conn->real_escape_string($_POST['date_of_transaction']);
                $party_id = intval($_POST['party_id']);
                $payment_amount = floatval($_POST['payment_amount']);
                $payment_method = $conn->real_escape_string($_POST['payment_method']);
                $payment_type = 'Payment_In'; // Always Payment_In for receiving payments
                $narration = $conn->real_escape_string($_POST['narration'] ?? '');
                
                // Gold payments removed - handled separately in gold receipt form
                    
                    // Determine booking_type based on payment method
                    if (strtolower($payment_method) === 'cash') {
                        $booking_type = 'Cash';
                    } else {
                        $booking_type = 'Bank';
                    }
                    
                    // Validate required fields
                    if (empty($receipt_id) || empty($party_id) || $payment_amount <= 0) {
                        throw new Exception("Please fill all required fields with valid values");
                    }
                    
                    // Get party's current balance
                    $balance_sql = "SELECT 
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' THEN t.gold_amount ELSE 0 END), 0) as booked_amount,
                        COALESCE(SUM(CASE WHEN (t.transaction_type = 'Payment' OR t.transaction_type = 'Received') AND t.payment_type = 'Payment_In' THEN t.payment_amount ELSE 0 END), 0) as total_received
                        FROM transactions t 
                        WHERE t.party_id = $party_id AND t.company_id = $company_id";
                    $balance_result = $conn->query($balance_sql);
                    $balance_data = $balance_result->fetch_assoc();
                    $current_balance = $balance_data['booked_amount'] - $balance_data['total_received'];
                    
                    // Insert payment transaction - use 'Received' when receiving payment from party
                    $payment_sql = "INSERT INTO transactions (
                        company_id, party_id, receipt_id, transaction_type, date_of_transaction,
                        gold_weight, purity, rate, gold_amount, payment_amount, payment_method, payment_type, booking_type,
                        party_balance_before, party_balance_after, party_gold_balance_before, party_gold_balance_after,
                        narration
                    ) VALUES (
                        $company_id, $party_id, '$receipt_id', 'Received', '$date_of_transaction',
                        0.000, 0.00, 0.00, 0.00, $payment_amount, '$payment_method', '$payment_type', '$booking_type',
                        -$current_balance, -" . ($current_balance - $payment_amount) . ", 0, 0,
                        'Payment received - $receipt_id" . (!empty($narration) ? " - $narration" : "") . "'
                    )";
                    
                    if (!$conn->query($payment_sql)) {
                        throw new Exception("Error creating payment transaction: " . $conn->error);
                    }
                    
                    // Debug: Check balances before update
                    $debug_before_sql = "SELECT current_balance, cash_balance, bank_balance FROM parties WHERE id = $party_id";
                    $debug_before_result = $conn->query($debug_before_sql);
                    $debug_before_data = $debug_before_result->fetch_assoc();
                    error_log("Before payment update - Party ID: $party_id, Current: {$debug_before_data['current_balance']}, Cash: {$debug_before_data['cash_balance']}, Bank: {$debug_before_data['bank_balance']}, Payment Amount: $payment_amount");
                    
                    // Update party balances - separate cash and bank
                    // Payment received reduces the party's debt to us (subtract from positive balance)
                    if ($payment_method == 'Cash') {
                        $update_balance_sql = "UPDATE parties SET 
                            cash_balance = cash_balance - $payment_amount
                            WHERE id = $party_id";
                    } elseif ($payment_method == 'Bank') {
                        $update_balance_sql = "UPDATE parties SET 
                            bank_balance = bank_balance - $payment_amount
                            WHERE id = $party_id";
                    }
                    
                    if (!$conn->query($update_balance_sql)) {
                        throw new Exception("Error updating party balance: " . $conn->error);
                    }
                    
                    // Update current_balance as sum of cash + bank balances
                    $update_current_balance_sql = "UPDATE parties SET 
                        current_balance = cash_balance + bank_balance
                        WHERE id = $party_id";
                    
                    if (!$conn->query($update_current_balance_sql)) {
                        throw new Exception("Error updating current balance: " . $conn->error);
                    }
                    
                    // Debug: Check the updated balances
                    $debug_sql = "SELECT current_balance, cash_balance, bank_balance FROM parties WHERE id = $party_id";
                    $debug_result = $conn->query($debug_sql);
                    $debug_data = $debug_result->fetch_assoc();
                    error_log("After payment update - Party ID: $party_id, Current: {$debug_data['current_balance']}, Cash: {$debug_data['cash_balance']}, Bank: {$debug_data['bank_balance']}");
                    
                    // Get party details for response
                    $party_sql = "SELECT party_name, contact_no FROM parties WHERE id = $party_id";
                    $party_result = $conn->query($party_sql);
                    $party_data = $party_result->fetch_assoc();
                    
                    $conn->commit();
                    
                    // Return success response
                    header('Content-Type: application/json');
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Payment recorded successfully',
                        'data' => [
                            'receipt_id' => $receipt_id,
                            'party_name' => $party_data['party_name'],
                            'party_contact' => $party_data['contact_no'],
                            'payment_amount' => $payment_amount,
                            'payment_method' => $payment_method,
                            'payment_type' => $payment_type,
                            'remaining_balance' => $current_balance - $payment_amount
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
                
            case 'generate_payment_id':
                // Generate unique payment ID: PAY + company_id + serial
                $prefix = "PAY{$company_id}";
                
                // Get the last payment ID for this company
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
                    // First payment for this company
                    $serial = 1;
                }
                
                $paymentId = $prefix . str_pad($serial, 3, '0', STR_PAD_LEFT);
                
                echo json_encode([
                    'status' => 'success',
                    'payment_id' => $paymentId
                ]);
                exit;
                
            case 'get_receipt_list':
                // Fetch recent payment receipts for dropdown
                $list_sql = "SELECT t.id, t.receipt_id, t.date_of_transaction, t.payment_amount, t.payment_method, p.party_name
                            FROM transactions t
                            LEFT JOIN parties p ON t.party_id = p.id
                            WHERE (t.transaction_type = 'Payment' OR t.transaction_type = 'Received') 
                            AND t.payment_type = 'Payment_In'
                            AND t.company_id = $company_id
                            ORDER BY t.date_of_transaction DESC, t.id DESC
                            LIMIT 20";
                
                $list_result = $conn->query($list_sql);
                
                if ($list_result) {
                    $receipts = [];
                    while ($row = $list_result->fetch_assoc()) {
                        $receipts[] = $row;
                    }
                    
                    echo json_encode([
                        'status' => 'success',
                        'data' => $receipts
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Failed to fetch receipt list'
                    ]);
                }
                exit;
        }
    }
}

// Enhanced statistics SQL query for payment page
// Get today's payment statistics from transactions
$payment_stats_sql = "
SELECT 
    SUM(CASE WHEN payment_method = 'Cash' THEN payment_amount ELSE 0 END) AS total_cash_received,
    SUM(CASE WHEN payment_method = 'Bank' THEN payment_amount ELSE 0 END) AS total_bank_received,
    SUM(payment_amount) AS total_payments_received,
    COUNT(*) AS total_payment_transactions
FROM transactions
WHERE (transaction_type = 'Payment' OR transaction_type = 'Received')
AND payment_type = 'Payment_In'
AND DATE(date_of_transaction) = CURRENT_DATE 
AND company_id = $company_id";

$payment_stats_result = $conn->query($payment_stats_sql);
$payment_stats = $payment_stats_result ? $payment_stats_result->fetch_assoc() : [];

// Get outstanding amounts from parties table
$outstanding_stats_sql = "
SELECT 
    SUM(CASE WHEN current_balance > 0 THEN current_balance ELSE 0 END) AS total_outstanding,
    SUM(CASE WHEN cash_balance > 0 THEN cash_balance ELSE 0 END) AS total_cash_outstanding,
    SUM(CASE WHEN bank_balance > 0 THEN bank_balance ELSE 0 END) AS total_bank_outstanding
FROM parties
WHERE company_id = $company_id";

$outstanding_stats_result = $conn->query($outstanding_stats_sql);
$outstanding_stats = $outstanding_stats_result ? $outstanding_stats_result->fetch_assoc() : [];

// Combine the results
$stats = array_merge($payment_stats, $outstanding_stats);

// Set default values if no data
if (empty($stats)) {
    $stats = [
        'total_cash_received' => 0,
        'total_bank_received' => 0,
        'total_payments_received' => 0,
        'total_payment_transactions' => 0,
        'total_outstanding' => 0,
        'total_cash_outstanding' => 0,
        'total_bank_outstanding' => 0
    ];
}

// Get recent payment transactions
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$where_clause = $search ? "AND (p.party_name LIKE '%$search%' OR t.receipt_id LIKE '%$search%')" : '';

$transactions_sql = "SELECT t.*, p.party_name, p.contact_no as party_contact 
                    FROM transactions t 
                    LEFT JOIN parties p ON t.party_id = p.id
                    WHERE (t.transaction_type = 'Payment' OR t.transaction_type = 'Received')
                    AND t.payment_type = 'Payment_In'
                    AND t.company_id = $company_id
                    $where_clause 
                    ORDER BY t.date_of_transaction DESC, t.id DESC 
                    LIMIT $offset, $limit";

$transactions = $conn->query($transactions_sql);

// Count the total number of Payment transactions
$total_sql = "SELECT COUNT(*) as count 
              FROM transactions t 
              LEFT JOIN parties p ON t.party_id = p.id
              WHERE t.transaction_type = 'Payment' 
              AND t.payment_type = 'Payment_In'
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
    <title>Payment Receipt Management</title>
    
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

        .btn-success {
            background-color: var(--success);
            border: none;
            color: white;
        }

        .btn-success:hover {
            background-color: #00A085;
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
    /* Validation error styles */
    .validation-error {
        display: block;
        min-height: 1.25rem;
        line-height: 1.25rem;
    }

    .validation-error.hidden {
        display: none;
    }

    input.border-red-500,
    select.border-red-500,
    textarea.border-red-500 {
        border-color: #ef4444 !important;
        box-shadow: 0 0 0 1px #ef4444;
    }

    input.border-red-500:focus,
    select.border-red-500:focus,
    textarea.border-red-500:focus {
        border-color: #ef4444 !important;
        box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.2);
    }

    /* Ensure readonly fields are still focusable for keyboard navigation */
    input[readonly]:focus {
        outline: 2px solid #3b82f6;
        outline-offset: 2px;
    }

    /* Smooth scrolling for focus */
    html {
        scroll-behavior: smooth;
    }
    </style>
</head>
<body>
    <!-- Main Content Container -->
    <div class="w-full">
        <!-- Colorful Statistics with Icons -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4 mb-6">
            <!-- Cash Received -->
            <div class="soft-gradient-green rounded-xl p-4 shadow-sm h-full">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-green-700 mb-1">Cash Received</p>
                        <p class="text-lg font-bold text-green-800 mb-0">₹<?= number_format($stats['total_cash_received'] ?? 0, 0) ?></p>
                        <p class="text-xs text-green-600 mb-0">Cash Payments</p>
                    </div>
                    <div class="w-10 h-10 bg-green-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-money-bill-wave text-white text-sm"></i>
                    </div>
                </div>
            </div>
            
            <!-- Bank Received -->
            <div class="soft-gradient-blue rounded-xl p-4 shadow-sm h-full">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-blue-700 mb-1">Bank Received</p>
                        <p class="text-lg font-bold text-blue-800 mb-0">₹<?= number_format($stats['total_bank_received'] ?? 0, 0) ?></p>
                        <p class="text-xs text-blue-600 mb-0">Bank/UPI/Cheque</p>
                    </div>
                    <div class="w-10 h-10 bg-blue-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-university text-white text-sm"></i>
                    </div>
                </div>
            </div>
            
            <!-- Total Received -->
            <div class="soft-gradient-purple rounded-xl p-4 shadow-sm h-full">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-purple-700 mb-1">Total Received</p>
                        <p class="text-lg font-bold text-purple-800 mb-0">₹<?= number_format($stats['total_payments_received'] ?? 0, 0) ?></p>
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
            
            <!-- Outstanding Amount -->
            <div class="soft-gradient-orange rounded-xl p-4 shadow-sm h-full">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-orange-700 mb-1">Outstanding</p>
                        <p class="text-lg font-bold text-orange-800 mb-0">₹<?= number_format($stats['total_outstanding'] ?? 0, 0) ?></p>
                        <p class="text-xs text-orange-600 mb-0">Due Amount</p>
                    </div>
                    <div class="w-10 h-10 bg-orange-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-exclamation-triangle text-white text-sm"></i>
                    </div>
                </div>
            </div>
            
            <!-- Collection Rate -->
            <div class="soft-gradient-red rounded-xl p-4 shadow-sm h-full">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-red-700 mb-1">Collection Rate</p>
                        <p class="text-lg font-bold text-red-800 mb-0"><?= $stats['total_booked_amount'] > 0 ? number_format((($stats['total_received_amount'] ?? 0) / $stats['total_booked_amount']) * 100, 1) : 0 ?>%</p>
                        <p class="text-xs text-red-600 mb-0">Collection %</p>
                    </div>
                    <div class="w-10 h-10 bg-red-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-percentage text-white text-sm"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Form and List Layout -->
        <div class="flex flex-col lg:flex-row gap-6">
            <!-- Left Side - Payment Receipt Form -->
            <div class="bg-white rounded-lg shadow-md border border-gray-200" style="flex: 0 0 55%;">
                <div class="bg-gradient-to-r from-green-500 to-green-600 px-4 py-2 rounded-t-lg">
                    <h2 class="text-base font-semibold text-white flex items-center">
                        <i class="fas fa-money-bill-wave mr-2"></i>
                        Payment Receipt
                    </h2>
                </div>
                <div class="p-3">
                    <form id="paymentForm" method="POST" class="space-y-3">
                        <input type="hidden" name="action" value="save_payment">
                        <input type="hidden" name="party_id" id="partyId">
                        
                        <!-- Row 1: Receipt ID & Date -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Receipt ID <span id="editModeIndicator" class="text-xs text-orange-600 font-semibold hidden">(Editing)</span></label>
                                <div class="relative">
                                    <input type="text" class="block w-full px-3 py-2 pr-10 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 cursor-pointer" name="receipt_id" readonly id="receiptIdInput" tabindex="0">
                                    <button type="button" class="absolute right-2 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600" id="showReceiptListBtn" title="Show previous receipts">
                                        <i class="fas fa-history"></i>
                                    </button>
                                </div>
                                <div id="receiptList" class="absolute z-10 mt-1 bg-white border border-gray-200 rounded-lg shadow-xl max-h-64 overflow-y-auto hidden" style="width: 400px; max-width: 90vw;"></div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                                <input type="datetime-local" class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500" name="date_of_transaction" required>
                            </div>
                        </div>

                        <!-- Row 2: Party Name -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Customer Name</label>
                            <div class="relative">
                                <input type="text" class="block w-full pl-3 pr-20 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500" name="party_name" id="partyNameInput" required autocomplete="off" placeholder="Enter customer name...">
                                <div class="absolute inset-y-0 right-0 flex items-center pr-2">
                                    <button type="button" class="px-3 py-1 text-sm bg-green-500 text-white rounded-lg hover:bg-green-600" id="addNewPartyBtn">
                                        <i class="fas fa-plus mr-1"></i>New
                                    </button>
                                </div>
                                <div id="partyList" class="absolute top-full left-0 right-0 bg-white border border-gray-200 rounded-lg shadow-xl max-h-64 overflow-y-auto z-50 hidden" style="width: calc(100% - 0px);"></div>
                            </div>
                        </div>

                        <!-- Customer Balance Info -->
                        <div id="balanceInfoSection" class="hidden">
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-3" id="balanceAlert">
                                <!-- Will be populated by JavaScript -->
                            </div>
                        </div>
                        
                        <!-- Row 3: Payment Amount & Method -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Payment Amount (₹)</label>
                                <input type="number" step="0.01" class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500" name="payment_amount" id="paymentAmount" required placeholder="0.00">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                                <select class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500" name="payment_method" required>
                                    <option value="">Select Method</option>
                                    <option value="Cash">💵 Cash</option>
                                    <option value="Bank">🏦 Bank</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Row 4: Narration -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Narration (Optional)</label>
                            <textarea class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500" name="narration" rows="2" placeholder="Enter any additional notes..."></textarea>
                        </div>

                        <!-- Row 6: Submit and Reset Buttons -->
                        <div class="grid grid-cols-2 gap-3">
                            <button type="submit" id="savePaymentBtn" class="px-4 py-2 bg-green-600 text-white text-sm font-medium rounded-lg hover:bg-green-700 focus:ring-2 focus:ring-green-500 focus:ring-offset-2 flex items-center justify-center">
                                <i class="fas fa-money-bill-wave mr-2"></i>Record Payment
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
                <div class="bg-gradient-to-r from-green-500 to-green-600 px-4 py-2 rounded-t-lg">
                    <h2 class="text-base font-semibold text-white flex items-center">
                        <i class="fas fa-list mr-2"></i>
                        Recent Payments
                    </h2>
                </div>
                <div class="p-3 max-w-full">
                    <div class="overflow-x-auto max-w-full">
                        <table class="w-full text-sm responsive-table" style="table-layout: fixed; width: 100%; max-width: 100%;">
                            <thead>
                                <tr class="bg-gray-50 border-b">
                                    <th class="text-left py-2 px-1 font-medium text-gray-700 text-sm" style="width: 25%;">Receipt & Date</th>
                                    <th class="text-left py-2 px-1 font-medium text-gray-700 text-sm" style="width: 25%;">Customer</th>
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
                                                <span class="bg-green-500 text-white text-xs px-1.5 py-0.5 rounded-full mr-1 font-bold">PAY</span>
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
                                            <div class="text-sm font-bold text-green-600">₹<?= number_format($t['payment_amount'], 2) ?></div>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars($t['payment_method']) ?></div>
                                        </td>
                                        <td class="py-2 px-1">
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                <?= htmlspecialchars($t['payment_type']) ?>
                                            </span>
                                        </td>
                                        <td class="py-2 px-1">
                                            <div class="flex items-center space-x-1">
                                                <button type="button" class="edit-payment-btn text-blue-600 hover:text-blue-800" title="Edit" data-id="<?= $t['id'] ?>">
                                                    <i class="fas fa-edit text-xs"></i>
                                                </button>
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
                                            No payments found
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
                                   class="px-3 py-2 text-sm border border-gray-300 rounded-lg <?= $i == $page ? 'bg-green-500 text-white' : 'text-gray-700 hover:bg-gray-50' ?>">
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
            // Generate payment ID
            function generatePaymentId() {
                return new Promise((resolve, reject) => {
                    fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=generate_payment_id'
                    })
                    .then(response => response.json())
                    .then(result => {
                        if (result.status === 'success') {
                            resolve(result.payment_id);
                        } else {
                            // Fallback to client-side generation
                            const companyId = <?= $company_id ?>;
                            const serial = Math.floor(Math.random() * 999) + 1;
                            resolve(`PAY${companyId}${serial.toString().padStart(3, '0')}`);
                        }
                    })
                    .catch(error => {
                        // Fallback to client-side generation
                        const companyId = <?= $company_id ?>;
                        const serial = Math.floor(Math.random() * 999) + 1;
                        resolve(`PAY${companyId}${serial.toString().padStart(3, '0')}`);
                    });
                });
            }
            
            // Set initial values
            async function initializeForm() {
                try {
                    const paymentId = await generatePaymentId();
                    $('#receiptIdInput').val(paymentId);
                } catch (error) {
                    console.error('Error generating payment ID:', error);
                    // Fallback to client-side generation
                    const companyId = <?= $company_id ?>;
                    const serial = Math.floor(Math.random() * 999) + 1;
                    $('#receiptIdInput').val(`PAY${companyId}${serial.toString().padStart(3, '0')}`);
                }
                
                const now = new Date();
                now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
                $('[name="date_of_transaction"]').val(now.toISOString().slice(0, 16));
            }
            
            initializeForm();

            // Initialize keyboard navigation for payment receipt form
            if (typeof KeyboardNavigationGeneric !== 'undefined') {
                KeyboardNavigationGeneric.init({
                    formId: 'paymentForm',
                    fieldOrder: [
                        'receiptIdInput',        // 1. Receipt ID (readonly)
                        'date_of_transaction',   // 2. Date
                        'party_name',            // 3. Customer Name
                        'paymentAmount',         // 4. Payment Amount
                        'payment_method',        // 5. Payment Method
                        'narration'              // 6. Narration
                    ],
                    skipFields: [],
                    submitButtonId: 'savePaymentBtn',
                    formName: 'payment'
                });
                window.KeyboardNavigation = KeyboardNavigationGeneric; // Make globally available
            }
            
            // Receipt History Dropdown
            $('#showReceiptListBtn, #receiptIdInput').on('click', function(e) {
                e.preventDefault();
                showReceiptList();
            });

            function showReceiptList() {
                const receiptList = $('#receiptList');
                
                // Show loading
                receiptList.html('<div class="p-3 text-center text-gray-500"><i class="fas fa-spinner fa-spin"></i> Loading...</div>');
                receiptList.removeClass('hidden');
                
                // Fetch receipt list
                $.post('', {
                    action: 'get_receipt_list'
                }, function(response) {
                    if (response.status === 'success' && response.data && response.data.length > 0) {
                        receiptList.html('');
                        response.data.forEach(function(receipt) {
                            const receiptItem = $('<div>')
                                .addClass('receipt-item p-2 border-b hover:bg-green-100 cursor-pointer')
                                .html(`
                                    <div class="flex justify-between items-center">
                                        <div>
                                            <b class="text-green-600">${receipt.receipt_id}</b>
                                            <span class="text-xs text-gray-500 ml-2">${receipt.party_name || ''}</span>
                                        </div>
                                        <span class="text-xs text-gray-400">${receipt.date_of_transaction ? receipt.date_of_transaction.split(' ')[0] : ''}</span>
                                    </div>
                                    <div class="text-xs text-gray-600 mt-1">
                                        ₹${parseFloat(receipt.payment_amount).toLocaleString('en-IN')} - ${receipt.payment_method}
                                    </div>
                                `)
                                .on('click', function() {
                                    loadReceiptForEdit(receipt.id);
                                    receiptList.addClass('hidden');
                                });
                            receiptList.append(receiptItem);
                        });
                    } else {
                        receiptList.html('<div class="p-3 text-center text-gray-500">No previous receipts found</div>');
                    }
                }, 'json').fail(function() {
                    receiptList.html('<div class="p-3 text-center text-red-500">Error loading receipts</div>');
                });
            }

            function loadReceiptForEdit(transactionId) {
                $.post('', {
                    action: 'get_payment_details',
                    transaction_id: transactionId
                }, function(response) {
                    if (response.status === 'success' && response.data) {
                        const data = response.data;
                        
                        // Set edit mode on form
                        $('#paymentForm').data('edit-id', data.id);
                        
                        // Populate form
                        $('#receiptIdInput').val(data.receipt_id);
                        $('#editModeIndicator').removeClass('hidden');
                        
                        // Format date
                        let dateValue = '';
                        if (data.date_of_transaction) {
                            const date = new Date(data.date_of_transaction);
                            if (!isNaN(date.getTime())) {
                                dateValue = date.toISOString().slice(0, 16);
                            } else {
                                dateValue = data.date_of_transaction.replace(' ', 'T').substring(0, 16);
                            }
                        }
                        $('[name="date_of_transaction"]').val(dateValue);
                        
                        // Set party
                        $('#partyId').val(data.party_id);
                        $('#partyNameInput').val(data.party_name);
                        selectedPartyName = data.party_name;
                        
                        // Set payment details
                        $('#paymentAmount').val(data.payment_amount);
                        $('[name="payment_method"]').val(data.payment_method);
                        $('[name="narration"]').val(data.narration || '');
                        
                        // Change button to Update mode
                        $('#savePaymentBtn').text('Update Receipt').removeClass('bg-green-600 hover:bg-green-700').addClass('bg-orange-600 hover:bg-orange-700');
                        
                        // Highlight form
                        $('#paymentForm').closest('.bg-white').css('border', '2px solid #f97316');
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to load receipt details'
                        });
                    }
                }, 'json').fail(function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to load receipt details'
                    });
                });
            }

            // Hide receipt list when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('#receiptList, #showReceiptListBtn, #receiptIdInput').length) {
                    $('#receiptList').addClass('hidden');
                }
            });
            
            // Payment method handler removed - no gold payments in payment receipt

            // Party search functionality
            let partyListVisible = false;
            let currentIndex = -1;
            let selectedPartyName = '';
            
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
                        console.log('Parties response:', parties); // Debug log
                        
                        const partyList = $('#partyList');
                        partyList.empty();
                        currentIndex = -1;
                        parties.forEach((party, index) => {
                            console.log('Processing party:', party); // Debug log
                            
                            const hasBooking = parseFloat(party.booked_amount || 0) > 0;
                            // Handle remaining_amount - it could be a number or formatted string
                            let remainingAmount = 0;
                            if (typeof party.remaining_amount === 'number') {
                                remainingAmount = party.remaining_amount;
                            } else {
                                // Remove commas and parse
                                remainingAmount = parseFloat((party.remaining_amount || '0').toString().replace(/,/g, ''));
                            }
                            const safeRemainingAmount = isNaN(remainingAmount) ? 0 : remainingAmount;
                            
                            const statusBadge = hasBooking 
                                ? `<div class="flex items-center space-x-1">
                                    <span class="bg-blue-500 text-white text-xs px-1.5 py-0.5 rounded-full font-bold">B</span>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${safeRemainingAmount > 0 ? 'bg-orange-100 text-orange-800' : 'bg-green-100 text-green-800'}">₹${safeRemainingAmount.toLocaleString('en-IN')}</span>
                                   </div>` 
                                : `<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">No Booking</span>`;
                            
                            const partyItem = document.createElement('div');
                            partyItem.className = 'px-2 py-1.5 hover:bg-green-50 cursor-pointer border-b border-gray-100 last:border-b-0 transition-colors duration-150 party-item';
                            partyItem.setAttribute('data-index', index);
                            partyItem.setAttribute('data-id', party.id || '');
                            partyItem.setAttribute('data-name', party.party_name || '');
                            partyItem.setAttribute('data-address', party.address || '');
                            partyItem.setAttribute('data-booked', party.booked_amount || '0');
                            partyItem.setAttribute('data-received', party.total_received || '0');
                            partyItem.setAttribute('data-remaining', party.remaining_amount || '0');
                            
                            partyItem.innerHTML = `
                                <div class="flex items-center">
                                    <div class="w-6 h-6 bg-gradient-to-br from-green-500 to-green-600 rounded-full flex items-center justify-center text-white text-xs font-semibold mr-2 shadow-sm">
                                        ${(party.party_name || 'U').charAt(0).toUpperCase()}
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-medium text-gray-900 truncate">${party.party_name || 'Unknown Party'}</div>
                                        <div class="text-xs text-gray-500 truncate">${party.address || 'No address provided'}</div>
                                    </div>
                                    <div class="flex items-center space-x-1 ml-2">
                                        <div class="text-right">
                                            ${statusBadge}
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
                                    booked_amount: partyItem.getAttribute('data-booked'),
                                    total_received: partyItem.getAttribute('data-received'),
                                    remaining_amount: partyItem.getAttribute('data-remaining')
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
                    $('#balanceInfoSection').addClass('hidden');
                }
            });
            
            // Keyboard navigation for party list
            $('#partyNameInput').on('keydown', function(e) {
                const partyItems = document.querySelectorAll('#partyList .party-item');
                
                // If dropdown is visible, handle arrow keys and Enter
                if (partyListVisible && partyItems.length > 0) {
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        e.stopPropagation();
                        if (currentIndex < 0) {
                            currentIndex = 0;
                        } else {
                            currentIndex = Math.min(currentIndex + 1, partyItems.length - 1);
                        }
                        updatePartyHighlight();
                        return;
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        e.stopPropagation();
                        if (currentIndex <= 0) {
                            currentIndex = -1;
                        } else {
                            currentIndex = Math.max(currentIndex - 1, 0);
                        }
                        updatePartyHighlight();
                        return;
                    } else if (e.key === 'Enter' && currentIndex >= 0) {
                        e.preventDefault();
                        e.stopPropagation();
                        const selectedItem = partyItems[currentIndex];
                        if (selectedItem) {
                            const partyData = {
                                id: selectedItem.getAttribute('data-id'),
                                party_name: selectedItem.getAttribute('data-name'),
                                address: selectedItem.getAttribute('data-address'),
                                booked_amount: selectedItem.getAttribute('data-booked'),
                                total_received: selectedItem.getAttribute('data-received'),
                                remaining_amount: selectedItem.getAttribute('data-remaining')
                            };
                            selectParty(partyData);
                            // Move to next field after selection
                            setTimeout(() => {
                                const paymentAmount = document.getElementById('paymentAmount');
                                if (paymentAmount) {
                                    paymentAmount.focus();
                                    paymentAmount.select();
                                }
                            }, 100);
                        }
                        return;
                    } else if (e.key === 'Escape') {
                        e.preventDefault();
                        e.stopPropagation();
                        $('#partyList').addClass('hidden');
                        partyListVisible = false;
                        currentIndex = -1;
                        return;
                    }
                }
                // If dropdown is not visible, let keyboard navigation handle Enter
            });
            
            // Function to update party selection highlighting
            function updatePartyHighlight() {
                const partyItems = document.querySelectorAll('#partyList .party-item');
                
                partyItems.forEach((item, index) => {
                    if (index === currentIndex && currentIndex >= 0) {
                        item.classList.add('bg-green-100', 'border-l-4', 'border-green-500');
                        item.classList.remove('hover:bg-green-50');
                    } else {
                        item.classList.remove('bg-green-100', 'border-l-4', 'border-green-500');
                        item.classList.add('hover:bg-green-50');
                    }
                });
                
                // Scroll into view
                if (currentIndex >= 0 && currentIndex < partyItems.length) {
                    const currentItem = partyItems[currentIndex];
                    currentItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            }
            
            // Close party list when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('#partyNameInput, #partyList, #addNewPartyBtn').length && partyListVisible) {
                    $('#partyList').addClass('hidden');
                    partyListVisible = false;
                    currentIndex = -1;
                }
            });
            
            // Party selection function
            function selectParty(party) {
                selectedPartyName = party.party_name;
                $('#partyId').val(party.id);
                $('#partyNameInput').val(party.party_name);
                $('#partyList').addClass('hidden');
                partyListVisible = false;
                currentIndex = -1;
                
                // Clear any validation errors
                if (window.KeyboardNavigation) {
                    window.KeyboardNavigation.clearValidationError('party_name');
                }
                
                // Get party balance details
                $.post('', {
                    action: 'get_party_balance',
                    party_id: party.id
                }, function(response) {
                    console.log('Balance response for party:', party.party_name, response); // Debug log
                    
                    // Safe parsing with proper defaults
                    let cashBalance = 0;
                    let bankBalance = 0;
                    let currentBalance = 0;
                    
                    try {
                        cashBalance = parseFloat(response.cash_balance) || 0;
                        bankBalance = parseFloat(response.bank_balance) || 0;
                        currentBalance = parseFloat(response.current_balance) || 0;
                        
                        console.log('Parsed balances:', {cashBalance, bankBalance, currentBalance}); // Debug log
                    } catch(e) {
                        console.log('Balance parsing error:', e);
                    }
                    
                    // Compact inline balance display
                    let balanceHTML = `
                        <div class="bg-gray-50 border border-gray-200 rounded p-2 text-xs">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-4">
                                    <div class="flex items-center space-x-1">
                                        <i class="fas fa-wallet text-blue-500"></i>
                                        <span class="text-gray-600">Total:</span>
                                        <span class="font-semibold ${currentBalance > 0 ? 'text-red-600' : currentBalance < 0 ? 'text-green-600' : 'text-gray-600'}">
                                            ₹${currentBalance.toLocaleString('en-IN')}
                                        </span>
                                    </div>
                                    <div class="flex items-center space-x-1">
                                        <span class="text-gray-500">Cash:</span>
                                        <span class="font-medium ${cashBalance > 0 ? 'text-red-500' : cashBalance < 0 ? 'text-green-500' : 'text-gray-500'}">
                                            ₹${cashBalance.toLocaleString('en-IN')}
                                        </span>
                                    </div>
                                    <div class="flex items-center space-x-1">
                                        <span class="text-gray-500">Bank:</span>
                                        <span class="font-medium ${bankBalance > 0 ? 'text-red-500' : bankBalance < 0 ? 'text-green-500' : 'text-gray-500'}">
                                            ₹${bankBalance.toLocaleString('en-IN')}
                                        </span>
                                    </div>
                                </div>
                                <div class="text-xs text-gray-500">
                                    ${currentBalance > 0 ? 'Party owes us' : currentBalance < 0 ? 'We owe party' : 'Balanced'}
                                </div>
                            </div>
                        </div>
                    `;
                        
                    $('#balanceAlert').html(balanceHTML);
                    $('#balanceInfoSection').removeClass('hidden');
                }, 'json');
            }

            // Unified form submission handler for both create and update
            $('#paymentForm').on('submit', function(e) {
                e.preventDefault();
                
                const editId = $(this).data('edit-id');
                
                // If editing, use update handler
                if (editId) {
                    const formData = {
                        action: 'update_payment',
                        transaction_id: editId,
                        party_id: $('#partyId').val(),
                        receipt_id: $('#receiptIdInput').val(),
                        date_of_transaction: $('[name="date_of_transaction"]').val(),
                        payment_amount: $('[name="payment_amount"]').val(),
                        payment_method: $('[name="payment_method"]').val(),
                        narration: $('[name="narration"]').val()
                    };
                    
                    $.post('', formData, function(response) {
                        if (response.status === 'success') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Payment Updated!',
                                text: response.message || 'Payment has been updated successfully!',
                                timer: 2000,
                                showConfirmButton: false
                            });
                            
                            resetPaymentForm();
                            
                            setTimeout(() => {
                                location.reload();
                            }, 2000);
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: response.message || 'An error occurred'
                            });
                        }
                    }, 'json').fail(function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'An error occurred while processing your request'
                        });
                    });
                    return;
                }
                
                // CREATE NEW PAYMENT - Validate using keyboard navigation if available
                if (window.KeyboardNavigation && window.KeyboardNavigation.validateAllFields) {
                    if (!window.KeyboardNavigation.validateAllFields()) {
                        const firstInvalid = window.KeyboardNavigation.getFirstInvalidField();
                        if (firstInvalid) {
                            firstInvalid.focus();
                            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                        }
                        return false;
                    }
                }
                
                const form = this;
                const partyId = $('#partyId').val();
                
                if (!partyId) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Customer Not Selected',
                        text: 'Please select a customer from the dropdown list first.'
                    });
                    $('#partyNameInput').focus();
                    return false;
                }
                
                const partyName = $('[name="party_name"]').val();
                const paymentAmount = $('[name="payment_amount"]').val();
                const paymentMethod = $('[name="payment_method"]').val();
                const paymentType = 'Payment_In'; // Always Payment_In for receiving payments
                
                // Show confirmation dialog
                Swal.fire({
                    title: '<div style="font-size: 20px; font-weight: 700; color: #1f2937; font-family: \'Poppins\', sans-serif;">Confirm Payment Receipt</div>',
                    html: `
                        <div style="font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; padding: 4px;">
                            <!-- Customer Section -->
                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 8px;">
                                <div style="display: flex; align-items: center; margin-bottom: 6px;">
                                    <div style="width: 20px; height: 20px; background: #3b82f6; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 8px;">
                                        <i class="fas fa-user" style="color: white; font-size: 10px;"></i>
                                    </div>
                                    <span style="font-size: 13px; font-weight: 600; color: #374151;">Customer</span>
                                </div>
                                <div style="font-size: 14px; color: #1f2937; font-weight: 500;">${partyName}</div>
                            </div>
                            
                            <!-- Payment Details -->
                            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px; margin-bottom: 8px;">
                                <div style="display: flex; align-items: center; margin-bottom: 6px;">
                                    <div style="width: 20px; height: 20px; background: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 8px;">
                                        <i class="fas fa-money-bill-wave" style="color: white; font-size: 10px;"></i>
                                    </div>
                                    <span style="font-size: 13px; font-weight: 600; color: #374151;">Payment Details</span>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 13px;">
                                    <div><span style="color: #6b7280;">Amount:</span> <span style="color: #059669; font-weight: 600;">₹${parseFloat(paymentAmount).toLocaleString('en-IN')}</span></div>
                                    <div><span style="color: #6b7280;">Method:</span> <span style="color: #1f2937; font-weight: 500;">${paymentMethod}</span></div>
                                    <div><span style="color: #6b7280;">Type:</span> <span style="color: #1f2937; font-weight: 500;">${paymentType}</span></div>
                                </div>
                            </div>
                            
                            <!-- Balance Impact Section -->
                            <div style="background: #fef3c7; border: 1px solid #fcd34d; border-radius: 8px; padding: 12px;">
                                <div style="display: flex; align-items: center; margin-bottom: 6px;">
                                    <div style="width: 20px; height: 20px; background: #f59e0b; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 8px;">
                                        <i class="fas fa-calculator" style="color: white; font-size: 10px;"></i>
                                    </div>
                                    <span style="font-size: 13px; font-weight: 600; color: #374151;">Balance Impact</span>
                                </div>
                                <div style="font-size: 13px; color: #4b5563;">
                                    <div style="margin-bottom: 4px;"><strong>Payment Method:</strong> ${paymentMethod}</div>
                                    <div style="color: #059669; font-weight: 600; margin-bottom: 4px;">
                                        ✓ This ${paymentMethod.toLowerCase()} payment will be deducted from party's balance
                                    </div>
                                    <div style="color: #dc2626; font-size: 12px;">
                                        ${paymentMethod === 'Cash' ? '💵 Cash payment will be added to cash received' : '🏦 Bank payment will be added to bank received'}
                                    </div>
                                </div>
                            </div>
                        </div>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Record Payment',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#10b981',
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
                                    text: 'Please wait while we record your payment',
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
                                        title: 'Payment Recorded!',
                                        text: 'Payment has been recorded successfully!',
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
                                        text: response.message || 'Failed to record payment'
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
                    cancelButtonColor: '#10b981'
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


            // Auto-focus customer name field on wide screens
            if ($(window).width() >= 992) {
                setTimeout(function() {
                    $('#partyNameInput').focus();
                }, 500);
            }
            
            // Edit payment button handler
            $(document).on('click', '.edit-payment-btn', function() {
                const transactionId = $(this).data('id');
                
                $.post('', {
                    action: 'get_payment_details',
                    transaction_id: transactionId
                }, function(response) {
                    if (response.status === 'success' && response.data) {
                        const data = response.data;
                        
                        // Populate form fields
                        $('#receiptIdInput').val(data.receipt_id);
                        $('[name="date_of_transaction"]').val(data.date_of_transaction);
                        $('#partyId').val(data.party_id);
                        $('#partyNameInput').val(data.party_name);
                        $('[name="payment_amount"]').val(data.payment_amount);
                        $('[name="payment_method"]').val(data.payment_method);
                        $('[name="narration"]').val(data.narration || '');
                        
                        // Store transaction ID for update
                        $('#paymentForm').data('edit-id', transactionId);
                        
                        // Change form title/submit button
                        const submitBtn = $('#paymentForm button[type="submit"]');
                        submitBtn.html('<i class="fas fa-save mr-2"></i>Update Payment');
                        submitBtn.removeClass('bg-green-600 hover:bg-green-700').addClass('bg-blue-600 hover:bg-blue-700');
                        
                        // Scroll to form
                        $('html, body').animate({
                            scrollTop: $('#paymentForm').offset().top - 100
                        }, 500);
                        
                        // Focus on first field
                        $('#receiptIdInput').focus().select();
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to load payment details'
                        });
                    }
                }, 'json').fail(function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to load payment details'
                    });
                });
            });
            
            // Reset form function
            function resetPaymentForm() {
                // Clear customer selection
                $('#partyNameInput').val('').removeClass('border-green-500');
                $('#partyId').val('');
                $('#balanceInfoSection').addClass('hidden');
                selectedPartyName = '';
                
                // Reset form fields
                $('[name="payment_amount"]').val('');
                $('[name="payment_method"]').val('');
                $('[name="narration"]').val('');
                
                // Remove edit mode
                $('#paymentForm').removeData('edit-id');
                
                // Reset submit button
                const submitBtn = $('#paymentForm button[type="submit"]');
                submitBtn.html('<i class="fas fa-money-bill-wave mr-2"></i>Record Payment');
                submitBtn.removeClass('bg-blue-600 hover:bg-blue-700').addClass('bg-green-600 hover:bg-green-700');
                
                // Regenerate payment ID and reset date
                initializeForm();
                
                // Focus on customer name field
                setTimeout(() => {
                    $('#partyNameInput').focus();
                }, 100);
            }
        });
    </script>
</body>
</html>

<?php
// Capture the content
$content = ob_get_clean();

// Set page title and include layout
$page_title = "Payment Receipt";
include 'components/layout.php';
?>
