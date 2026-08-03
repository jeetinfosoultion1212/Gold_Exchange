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
require_once __DIR__ . '/handlers/account_balance_helper.php';

/** Map payment_method to account_balances.account_type ('Cash' | 'Bank') */
function payment_receipt_account_type(string $payment_method): string {
    return (strcasecmp(trim($payment_method), 'Cash') === 0) ? 'Cash' : 'Bank';
}

/**
 * Label shown in payment lists: keep real PAY* receipt_id; legacy rows (e.g. CSH-*) use PAY# + transaction id.
 */
function payment_receipt_list_display_id(array $t): string {
    $rid = trim((string)($t['receipt_id'] ?? ''));
    if ($rid !== '' && preg_match('/^PAY/i', $rid)) {
        return $rid;
    }
    return 'PAY#' . (int)($t['id'] ?? 0);
}

function payment_receipt_party_id_int(array $t): int {
    if (!array_key_exists('party_id', $t) || $t['party_id'] === null || $t['party_id'] === '') {
        return 0;
    }
    return (int) $t['party_id'];
}

/**
 * Customer column + form hint: real party shows name + "Party #id"; internal cash rows (NULL party) use narration.
 *
 * @return array{name: string, sub: string}
 */
function payment_receipt_party_display_lines(array $t): array {
    $pid = payment_receipt_party_id_int($t);
    $pname = trim((string)($t['party_name'] ?? ''));
    if ($pid > 0) {
        $name = $pname !== '' ? $pname : ('Party #' . $pid);
        $sub = 'Party #' . $pid;
        $c = trim((string)($t['party_contact'] ?? ''));
        if ($c !== '') {
            $sub .= ' · ' . $c;
        }
        return ['name' => $name, 'sub' => $sub];
    }
    $n = trim((string)($t['narration'] ?? ''));
    $name = $n !== '' ? (strlen($n) > 36 ? substr($n, 0, 34) . '…' : $n) : 'Internal (no party)';
    return ['name' => $name, 'sub' => 'No party linked'];
}

/** Indian-style number grouping for stat/list amounts (matches exchange.php). */
function pr_format_inr($amount, int $decimals = 0): string
{
    $amount = (float) $amount;
    $negative = $amount < 0;
    $amount = abs(round($amount, $decimals));
    $parts = explode('.', number_format($amount, $decimals, '.', ''));
    $integer = $parts[0];
    $decimalPart = $decimals > 0 ? ('.' . $parts[1]) : '';
    $lastThree = substr($integer, -3);
    $rest = substr($integer, 0, -3);
    if ($rest !== '') {
        $lastThree = ',' . $lastThree;
        $rest = (string) preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
    }
    return ($negative ? '-' : '') . $rest . $lastThree . $decimalPart;
}

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
                        p.cash_balance, p.bank_balance, p.gold_balance, p.silver_balance,
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
                        GROUP BY p.id, p.party_name, p.address, p.contact_no, p.cash_balance, p.bank_balance, p.gold_balance, p.silver_balance
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
                        'remaining_amount' => floatval($row['cash_balance']) + floatval($row['bank_balance']),
                        'cash_due' => number_format($cash_due, 2),
                        'bank_due' => number_format($bank_due, 2),
                        'cash_received' => number_format($row['cash_received'], 2),
                        'bank_received' => number_format($row['bank_received'], 2),
                        'total_received' => number_format($row['cash_received'] + $row['bank_received'], 2),
                        // Add actual balance information from parties table
                        'current_balance' => floatval($row['cash_balance']) + floatval($row['bank_balance']),
                        'cash_balance' => floatval($row['cash_balance']),
                        'bank_balance' => floatval($row['bank_balance']),
                        'gold_balance' => floatval($row['gold_balance'] ?? 0),
                        'silver_balance' => floatval($row['silver_balance'] ?? 0),
                        'total_due_amount' => floatval($row['cash_balance']) + floatval($row['bank_balance']),
                        'total_due_gold' => floatval($row['gold_balance'] ?? 0),
                        'total_due_silver' => floatval($row['silver_balance'] ?? 0)
                    ];
                }
                echo json_encode($parties);
                exit;
                
            case 'get_party_balance':
                $party_id = intval($_POST['party_id']);
                
                // Get balance information from parties table
                $party_sql = "SELECT cash_balance, bank_balance FROM parties WHERE id = $party_id AND company_id = $company_id";
                $party_result = $conn->query($party_sql);
                $party_data = $party_result ? ($party_result->fetch_assoc() ?: []) : [];
                $party_ledger_total = floatval($party_data['cash_balance'] ?? 0) + floatval($party_data['bank_balance'] ?? 0);
                
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
                        'current_balance' => $party_ledger_total,
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
                        'current_balance' => $party_ledger_total,
                        'cash_balance' => floatval($party_data['cash_balance'] ?? 0),
                        'bank_balance' => floatval($party_data['bank_balance'] ?? 0),
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
                    $pl = payment_receipt_party_display_lines($transaction);
                    $transaction['party_label'] = $pl['name'];
                    $transaction['party_sub'] = $pl['sub'];
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
                    
                    // Reverse original payment (add back to party balance + reduce company cash/bank)
                    if ($original_method == 'Cash') {
                        $reverse_sql = "UPDATE parties SET cash_balance = cash_balance + $original_amount WHERE id = $original_party_id";
                    } else {
                        $reverse_sql = "UPDATE parties SET bank_balance = bank_balance + $original_amount WHERE id = $original_party_id";
                    }
                    $conn->query($reverse_sql);
                    if (!updateAccountBalance($conn, $company_id, payment_receipt_account_type((string) $original_method), -$original_amount)) {
                        throw new Exception('Error reversing company account balance');
                    }
                    
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
                    
                    // Apply new payment (deduct from party balance + increase company cash/bank)
                    if ($payment_method == 'Cash') {
                        $apply_sql = "UPDATE parties SET cash_balance = cash_balance - $payment_amount WHERE id = $party_id";
                    } else {
                        $apply_sql = "UPDATE parties SET bank_balance = bank_balance - $payment_amount WHERE id = $party_id";
                    }
                    $conn->query($apply_sql);
                    if (!updateAccountBalance($conn, $company_id, payment_receipt_account_type($payment_method), $payment_amount)) {
                        throw new Exception('Error updating company account balance');
                    }
                    
                    $conn->commit();
                    
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Payment receipt updated successfully'
                    ]);
                } catch (Exception $e) {
                    $conn->rollback();
                    header('Content-Type: application/json; charset=utf-8');
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
                    $debug_before_sql = "SELECT cash_balance, bank_balance FROM parties WHERE id = $party_id";
                    $debug_before_result = $conn->query($debug_before_sql);
                    $debug_before_data = $debug_before_result ? $debug_before_result->fetch_assoc() : [];
                    $dbg_tot = floatval($debug_before_data['cash_balance'] ?? 0) + floatval($debug_before_data['bank_balance'] ?? 0);
                    error_log("Before payment update - Party ID: $party_id, Ledger (cash+bank): $dbg_tot, Cash: {$debug_before_data['cash_balance']}, Bank: {$debug_before_data['bank_balance']}, Payment Amount: $payment_amount");
                    
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
                    
                    // Company cash in hand / bank (account_balances) — money received increases shop balance
                    $acct_type = payment_receipt_account_type($payment_method);
                    if (!updateAccountBalance($conn, $company_id, $acct_type, $payment_amount)) {
                        throw new Exception('Error updating company cash/bank balance');
                    }
                    
                    // Debug: Check the updated balances
                    $debug_sql = "SELECT cash_balance, bank_balance FROM parties WHERE id = $party_id";
                    $debug_result = $conn->query($debug_sql);
                    $debug_data = $debug_result ? $debug_result->fetch_assoc() : [];
                    $dbg_a = floatval($debug_data['cash_balance'] ?? 0) + floatval($debug_data['bank_balance'] ?? 0);
                    error_log("After payment update - Party ID: $party_id, Ledger (cash+bank): $dbg_a, Cash: {$debug_data['cash_balance']}, Bank: {$debug_data['bank_balance']}");
                    
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

            case 'save_party':
                $party_name = trim($_POST['party_name'] ?? '');
                $address = trim($_POST['address'] ?? '');
                $contact_no = trim($_POST['contact_no'] ?? '');
                $gstin = trim($_POST['gstin'] ?? '') ?: 'N/A';
                $state = trim($_POST['state'] ?? '');
                $city = trim($_POST['city'] ?? '');
                $bank_name = trim($_POST['bank_name'] ?? '');
                $account_no = trim($_POST['account_no'] ?? '');
                $ifsc_code = trim($_POST['ifsc_code'] ?? '');
                $cash_balance = floatval($_POST['cash_balance'] ?? 0);
                $bank_balance = floatval($_POST['bank_balance'] ?? 0);
                $gold_balance = floatval($_POST['gold_balance'] ?? $_POST['cash_gold_balance'] ?? 0);
                $silver_balance = floatval($_POST['silver_balance'] ?? $_POST['cash_silver_balance'] ?? 0);
                if ($party_name === '') {
                    echo json_encode(['status' => 'error', 'message' => 'Party name is required']);
                    exit;
                }
                $sql = "INSERT INTO parties (company_id, party_name, address, contact_no, gstin, state, city, bank_name, account_no, ifsc_code, cash_balance, bank_balance, gold_balance, silver_balance) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("isssssssssdddd", $company_id, $party_name, $address, $contact_no, $gstin, $state, $city, $bank_name, $account_no, $ifsc_code, $cash_balance, $bank_balance, $gold_balance, $silver_balance);
                if ($stmt->execute()) {
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Party added successfully',
                        'party_id' => $stmt->insert_id
                    ]);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Error adding party: ' . $stmt->error]);
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
                $list_sql = "SELECT t.id, t.party_id, t.narration, t.receipt_id, t.date_of_transaction, t.payment_amount, t.payment_method,
                            p.party_name, p.contact_no AS party_contact
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
                        $row['display_receipt_id'] = payment_receipt_list_display_id($row);
                        $pl = payment_receipt_party_display_lines($row);
                        $row['party_list_name'] = $pl['name'];
                        $row['party_list_sub'] = $pl['sub'];
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

            case 'delete_payment':
                $conn->begin_transaction();
                try {
                    $transaction_id = intval($_POST['transaction_id'] ?? 0);
                    
                    if ($transaction_id <= 0) {
                        throw new Exception("Invalid transaction ID");
                    }
                    
                    // Get transaction details
                    $sql = "SELECT * FROM transactions WHERE id = $transaction_id AND company_id = $company_id";
                    $result = $conn->query($sql);
                    $transaction = $result->fetch_assoc();
                    
                    if (!$transaction) {
                        throw new Exception("Transaction not found");
                    }
                    
                    $party_id = $transaction['party_id'];
                    $amount = floatval($transaction['payment_amount']);
                    $method = $transaction['payment_method'];
                    
                    // Reverse payment effect: Payment Received (Payment_In) REDUCED the balance (party owes us less).
                    // To delete it, we must ADD the amount back to the balance (party owes us more).
                    
                    // Update specific balance (Cash/Bank)
                    if ($method == 'Cash') {
                        $update_sql = "UPDATE parties SET cash_balance = cash_balance + $amount WHERE id = $party_id";
                    } else {
                        $update_sql = "UPDATE parties SET bank_balance = bank_balance + $amount WHERE id = $party_id";
                    }
                    
                    if (!$conn->query($update_sql)) {
                        throw new Exception("Error updating party balance");
                    }
                    
                    // Reverse company balance (payment had increased cash/bank when recorded)
                    if (!updateAccountBalance($conn, $company_id, payment_receipt_account_type((string) $method), -$amount)) {
                        throw new Exception('Error reversing company account balance');
                    }
                    
                    // Delete transaction
                    $conn->query("DELETE FROM transactions WHERE id = $transaction_id");
                    
                    $conn->commit();
                    
                    echo json_encode(['status' => 'success', 'message' => 'Payment deleted successfully']);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
                exit;
        }
    }
}

// Stats: company cash/bank (account_balances) + today's receipts + receivable from parties
$stats = [
    'cash_in_hand' => 0.0,
    'bank_balance' => 0.0,
    'total_cash_received' => 0.0,
    'total_bank_received' => 0.0,
    'total_outstanding' => 0.0,
];

$payment_stats_sql = "
SELECT 
    COALESCE(SUM(CASE WHEN payment_method = 'Cash' THEN payment_amount ELSE 0 END), 0) AS total_cash_received,
    COALESCE(SUM(CASE WHEN payment_method IN ('Bank', 'UPI', 'Cheque', 'Card') THEN payment_amount ELSE 0 END), 0) AS total_bank_received
FROM transactions
WHERE (transaction_type = 'Payment' OR transaction_type = 'Received')
AND payment_type = 'Payment_In'
AND DATE(date_of_transaction) = CURRENT_DATE 
AND company_id = $company_id";

$payment_stats_result = $conn->query($payment_stats_sql);
if ($payment_stats_result && ($pr = $payment_stats_result->fetch_assoc())) {
    $stats['total_cash_received'] = (float) ($pr['total_cash_received'] ?? 0);
    $stats['total_bank_received'] = (float) ($pr['total_bank_received'] ?? 0);
}

$outstanding_stats_sql = "
SELECT 
    COALESCE(SUM(GREATEST(0, cash_balance + bank_balance)), 0) AS total_outstanding
FROM parties
WHERE company_id = $company_id";

$outstanding_stats_result = $conn->query($outstanding_stats_sql);
if ($outstanding_stats_result && ($os = $outstanding_stats_result->fetch_assoc())) {
    $stats['total_outstanding'] = (float) ($os['total_outstanding'] ?? 0);
}

$balance_sql = "SELECT 
    COALESCE(SUM(CASE WHEN account_type = 'Cash' THEN current_balance ELSE 0 END), 0) AS cash_in_hand,
    COALESCE(SUM(CASE WHEN account_type = 'Bank' THEN current_balance ELSE 0 END), 0) AS bank_balance
FROM account_balances
WHERE company_id = $company_id";
$balance_result = $conn->query($balance_sql);
if ($balance_result && ($br = $balance_result->fetch_assoc())) {
    $stats['cash_in_hand'] = (float) ($br['cash_in_hand'] ?? 0);
    $stats['bank_balance'] = (float) ($br['bank_balance'] ?? 0);
}

// Get recent payment transactions (date range + scroll list, same pattern as exchange.php)
$start_date = isset($_GET['start_date']) && !empty($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
$end_date = isset($_GET['end_date']) && !empty($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$payment_list_limit = 100;

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$where_clause = "AND DATE(t.date_of_transaction) BETWEEN '$start_date' AND '$end_date'";
if ($search) {
    $where_clause .= " AND (p.party_name LIKE '%$search%' OR t.receipt_id LIKE '%$search%')";
}

$transactions_sql = "SELECT t.*, p.party_name, p.contact_no AS party_contact 
                    FROM transactions t 
                    LEFT JOIN parties p ON t.party_id = p.id AND p.company_id = t.company_id
                    WHERE (t.transaction_type = 'Payment' OR t.transaction_type = 'Received')
                    AND t.payment_type = 'Payment_In'
                    AND t.company_id = $company_id
                    $where_clause 
                    ORDER BY t.date_of_transaction DESC, t.id DESC 
                    LIMIT $payment_list_limit";

$transactions = $conn->query($transactions_sql);
$transactions_list = ($transactions && $transactions->num_rows > 0) ? $transactions->fetch_all(MYSQLI_ASSOC) : [];

$total_sql = "SELECT COUNT(*) as count 
              FROM transactions t 
              LEFT JOIN parties p ON t.party_id = p.id
              WHERE (t.transaction_type = 'Payment' OR t.transaction_type = 'Received')
              AND t.payment_type = 'Payment_In'
              AND t.company_id = $company_id
              $where_clause";
$total_result = $conn->query($total_sql);
$total_transactions = ($total_result) ? (int) $total_result->fetch_assoc()['count'] : 0;
$payment_list_has_more = $total_transactions > count($transactions_list);
?>

<!-- Page-specific styles (page body comes from components/layout.php) -->
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
    /* Validation error — overlay below field, no layout shift */
    .validation-error {
        position: absolute;
        left: 0;
        right: 0;
        top: 100%;
        z-index: 40;
        font-size: 9px;
        line-height: 1.15;
        color: #dc2626;
        margin-top: 1px;
        pointer-events: none;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
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
        .stats-card-label {
            font-size: 10px;
            font-weight: 500;
            letter-spacing: 0.02em;
            color: rgb(100 116 139);
        }
        .stats-card-value {
            font-size: 1rem;
            font-weight: 600;
            color: rgb(51 65 85);
            font-variant-numeric: tabular-nums;
        }
        .stats-icon-wrap {
            width: 2rem;
            height: 2rem;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .compact-input { padding-top: 0.375rem !important; padding-bottom: 0.375rem !important; font-size: 0.75rem !important; }
        .compact-label { font-size: 0.65rem !important; margin-bottom: 0.1rem !important; }

        #partyList {
            position: absolute !important;
            top: 100% !important;
            left: 0 !important;
            right: 0 !important;
            width: 100% !important;
            z-index: 1000 !important;
        }

        .ge-action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.4rem;
            height: 1.4rem;
            border-radius: 0.25rem;
            flex-shrink: 0;
            border: none;
            background: transparent;
            cursor: pointer;
            padding: 0;
        }
        .ge-action-btn i { font-size: 10px; line-height: 1; pointer-events: none; }
        .ge-action-btn:hover { background: rgba(148, 163, 184, 0.18); }

        .ge-txn-table .ge-action-col {
            width: 3.75rem;
            min-width: 3.75rem;
            padding-left: 0.2rem !important;
            padding-right: 0.3rem !important;
        }
        .ge-txn-scroll {
            max-height: calc(100vh - 300px);
            min-height: 220px;
            overflow-y: auto;
            overflow-x: auto;
        }
        .ge-txn-scroll thead th {
            position: sticky;
            top: 0;
            z-index: 10;
            background-color: #f8fafc;
            box-shadow: 0 1px 0 #e2e8f0;
        }
        .ge-txn-table thead th { white-space: nowrap; }
        .ge-txn-table .ge-serial-col {
            width: 1.75rem;
            min-width: 1.75rem;
            padding-left: 0.35rem !important;
            padding-right: 0.25rem !important;
            text-align: center;
        }
        .ge-txn-table .ge-party-col {
            width: 5.75rem;
            max-width: 5.75rem;
            padding-left: 0.3rem !important;
            padding-right: 0.3rem !important;
        }
        .ge-txn-table td,
        .ge-txn-table th {
            padding-left: 0.35rem;
            padding-right: 0.35rem;
        }
        .ge-txn-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .ge-txn-scroll::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, 0.5); border-radius: 3px; }
        .ge-txn-scroll::-webkit-scrollbar-track { background: transparent; }

        /* Compact form — tight rows, aligned field baselines */
        .pr-form-section { padding: 0.375rem 0.5rem; }
        .pr-form-grid {
            display: grid;
            grid-template-columns: repeat(12, minmax(0, 1fr));
            gap: 0.375rem;
            align-items: end;
        }
        .pr-form-footer {
            padding: 0.375rem 0.5rem;
            border-top: 1px solid #e5e7eb;
            background: #f9fafb;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.25rem;
        }
    </style>

<div class="w-full min-w-0 px-1 pb-4">
        <!-- Statistics Cards -->
        <div class="overflow-x-auto pb-1 -mx-0.5 px-0.5">
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-2 mb-3 min-w-0 w-full">
            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase">Cash in hand</p>
                        <p class="stats-card-value leading-tight">₹<?= pr_format_inr($stats['cash_in_hand'] ?? 0) ?></p>
                    </div>
                    <div class="stats-icon-wrap bg-emerald-100 shrink-0">
                        <i class="fas fa-wallet text-emerald-600 text-xs"></i>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase">Bank balance</p>
                        <p class="stats-card-value leading-tight">₹<?= pr_format_inr($stats['bank_balance'] ?? 0) ?></p>
                    </div>
                    <div class="stats-icon-wrap bg-blue-100 shrink-0">
                        <i class="fas fa-university text-blue-600 text-xs"></i>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase">Cash received</p>
                        <p class="stats-card-value leading-tight">₹<?= pr_format_inr($stats['total_cash_received'] ?? 0) ?></p>
                    </div>
                    <div class="stats-icon-wrap bg-purple-100 shrink-0">
                        <i class="fas fa-money-bill-wave text-purple-600 text-xs"></i>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase">Bank received</p>
                        <p class="stats-card-value leading-tight">₹<?= pr_format_inr($stats['total_bank_received'] ?? 0) ?></p>
                    </div>
                    <div class="stats-icon-wrap bg-teal-100 shrink-0">
                        <i class="fas fa-building-columns text-teal-600 text-xs"></i>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase">Outstanding</p>
                        <p class="stats-card-value leading-tight">₹<?= pr_format_inr($stats['total_outstanding'] ?? 0) ?></p>
                    </div>
                    <div class="stats-icon-wrap bg-orange-100 shrink-0">
                        <i class="fas fa-file-invoice-dollar text-orange-600 text-xs"></i>
                    </div>
                </div>
            </div>
        </div>
        </div>

        <!-- Main Content -->
        <div class="flex flex-col lg:flex-row gap-4 min-w-0 w-full lg:items-start">
            <!-- Left — Form -->
            <div class="bg-white rounded-lg shadow-md border border-gray-200 min-w-0 lg:flex-[1_1_55%] overflow-hidden self-start w-full">
                <form id="paymentForm" method="POST" class="overflow-hidden" onsubmit="return false;">
                        <input type="hidden" name="action" value="save_payment">
                        <input type="hidden" name="party_id" id="partyId">
                        <input type="hidden" id="editTransactionId" value="">

                        <div class="bg-blue-50 px-3 py-1 border-b border-blue-100">
                            <h3 class="text-xs font-bold text-blue-800 flex items-center">
                                <i class="fas fa-file-invoice mr-1.5 text-xs"></i> Transaction Details
                                <span id="editModeIndicator" class="ml-2 text-orange-600 hidden text-[10px]">(Edit)</span>
                            </h3>
                        </div>
                        <div class="pr-form-section pr-form-grid">
                            <div class="relative col-span-12 sm:col-span-4 lg:col-span-3">
                                <label class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Receipt ID</label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-gray-500"><i class="fas fa-hashtag text-xs"></i></span>
                                    <input type="text" name="receipt_id" readonly id="receiptIdInput" tabindex="0"
                                        class="block w-full pl-7 pr-8 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-blue-400 focus:border-blue-400 compact-input cursor-pointer"
                                        placeholder="Auto..." title="Click for recent payments to load">
                                    <button type="button" class="absolute right-1 top-1/2 -translate-y-1/2 text-gray-400 hover:text-blue-600 p-0.5" id="showReceiptListBtn" title="Recent payments / Load to edit" aria-label="Open payment list">
                                        <i class="fas fa-history text-xs"></i>
                                    </button>
                                </div>
                                <div id="receiptList" class="hidden absolute z-[60] mt-1 bg-white border border-gray-200 rounded-md shadow-lg max-h-72 overflow-y-auto w-[min(100%,20rem)] left-0 text-[9px] leading-tight"></div>
                            </div>
                            <div class="relative col-span-12 sm:col-span-4 lg:col-span-3">
                                <label class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Date</label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-purple-500"><i class="fas fa-calendar-alt text-xs"></i></span>
                                    <input type="datetime-local" name="date_of_transaction" required
                                        class="block w-full pl-7 pr-1 py-1.5 text-[11px] font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-purple-400 focus:border-purple-400 compact-input">
                                </div>
                            </div>
                            <div class="relative col-span-12 sm:col-span-4 lg:col-span-6">
                                <label class="block text-[10px] font-bold text-gray-700 mb-0.5 flex items-center justify-between uppercase tracking-tighter compact-label">
                                    <span>Party Name</span>
                                    <button type="button" id="addNewPartyBtn" class="text-blue-600 hover:text-blue-800 font-bold transition-all text-[9px] flex items-center uppercase tracking-tighter">
                                        <i class="fas fa-plus-circle mr-1 text-[10px]"></i> Add New
                                    </button>
                                </label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-blue-500"><i class="fas fa-user text-xs"></i></span>
                                    <input type="text" name="party_name" id="partyNameInput" required autocomplete="off" spellcheck="false" placeholder="Select Party"
                                        class="block w-full pl-7 pr-2 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-blue-400 focus:border-blue-400 compact-input">
                                </div>
                                <div id="partyList" class="hidden absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-48 overflow-y-auto"></div>
                            </div>
                        </div>

                        <div id="balanceInfoSection" class="hidden px-2 pb-1">
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-2 text-xs" id="balanceAlert"></div>
                        </div>

                        <div class="bg-emerald-50 px-3 py-1 border-t border-b border-emerald-100">
                            <h3 class="text-xs font-bold text-emerald-800 flex items-center">
                                <i class="fas fa-money-bill-wave mr-1.5 text-xs"></i> Payment Details
                            </h3>
                        </div>
                        <div class="pr-form-section pr-form-grid">
                            <div class="relative col-span-12 sm:col-span-4">
                                <label class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Amount (₹)</label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-indigo-500"><i class="fas fa-wallet text-xs"></i></span>
                                    <input type="number" step="0.01" name="payment_amount" id="paymentAmount" required placeholder="0.00"
                                        class="block w-full pl-7 pr-2 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-indigo-400 focus:border-indigo-400 compact-input">
                                </div>
                            </div>
                            <div class="relative col-span-12 sm:col-span-4">
                                <label class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Mode</label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-gray-600"><i class="fas fa-credit-card text-xs"></i></span>
                                    <select name="payment_method" required
                                        class="block w-full pl-7 pr-1 py-1.5 text-[11px] font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-gray-400 focus:border-gray-400 compact-input">
                                        <option value="">Select</option>
                                        <option value="Cash">Cash</option>
                                        <option value="Bank">Bank</option>
                                    </select>
                                </div>
                            </div>
                            <div class="relative col-span-12 sm:col-span-4">
                                <label class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Narration</label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-purple-500"><i class="fas fa-comment-alt text-xs"></i></span>
                                    <input type="text" name="narration" placeholder="Optional notes..."
                                        class="block w-full pl-7 pr-2 py-1.5 text-xs font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-purple-400 focus:border-purple-400 compact-input">
                                </div>
                            </div>
                        </div>

                        <div class="pr-form-footer">
                            <button type="submit" id="savePaymentBtn"
                                class="min-w-[7rem] bg-gradient-to-r from-blue-600 to-blue-700 text-white text-[10px] font-bold uppercase py-1.5 px-4 rounded shadow hover:from-blue-700 hover:to-blue-800 transition tracking-tighter">
                                <i class="fas fa-save mr-1"></i><span id="savePaymentBtnText">Save</span>
                            </button>
                            <button type="button" id="deleteEditedPaymentBtn"
                                class="hidden px-2.5 py-1.5 bg-gradient-to-r from-red-600 to-red-700 text-white text-[10px] font-bold rounded hover:from-red-700 hover:to-red-800 shadow-sm"
                                title="Delete"><i class="fas fa-trash-alt"></i></button>
                            <button type="button" id="resetFormBtn"
                                class="px-2.5 py-1.5 bg-white border border-gray-300 text-gray-700 text-[10px] font-bold rounded hover:bg-gray-50 shadow-sm"
                                title="Reset"><i class="fas fa-undo"></i></button>
                        </div>
                    </form>
            </div>

            <!-- Right — Recent payments list -->
            <div class="bg-white rounded-lg shadow-md border border-gray-200 min-w-0 lg:flex-[1_1_45%] self-start w-full">
                <div class="bg-blue-50 px-3 py-1.5 border-b border-blue-100 rounded-t-lg">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-xs font-bold text-blue-800 flex items-center">
                            <i class="fas fa-list mr-1.5 text-xs"></i> Recent Payments
                        </h2>
                        <form method="GET" action="" id="dateRangeForm" class="flex items-center gap-1.5">
                            <input type="date" name="start_date" id="startDate"
                                value="<?= htmlspecialchars($start_date) ?>"
                                class="px-1.5 py-0.5 border border-gray-200 rounded text-[10px] w-24 focus:ring-1 focus:ring-blue-400 focus:border-blue-400 bg-white font-medium"
                                max="<?= date('Y-m-d') ?>" title="From Date">
                            <span class="text-gray-400 text-[10px] font-bold">to</span>
                            <input type="date" name="end_date" id="endDate" value="<?= htmlspecialchars($end_date) ?>"
                                class="px-1.5 py-0.5 border border-gray-200 rounded text-[10px] w-24 focus:ring-1 focus:ring-blue-400 focus:border-blue-400 bg-white font-medium"
                                max="<?= date('Y-m-d') ?>" title="To Date">
                            <button type="submit"
                                class="px-1.5 py-0.5 bg-blue-600 text-white text-[10px] font-bold rounded hover:bg-blue-700 transition shadow-sm"
                                title="Apply Date Filter">
                                <i class="fas fa-filter text-[10px]"></i>
                            </button>
                        </form>
                    </div>
                </div>
                <div class="p-2">
                    <div class="ge-txn-scroll" id="prTxnScroll">
                        <table class="w-full text-sm text-left text-gray-500 ge-txn-table">
                            <thead class="bg-slate-50 border-b border-slate-100">
                                <tr>
                                    <th class="py-2 px-1 text-center text-[9px] font-bold text-slate-500 ge-serial-col">#</th>
                                    <th class="py-2 px-2 text-left text-[9px] font-bold text-slate-500 w-16">Id</th>
                                    <th class="py-2 px-2 text-left text-[9px] font-bold text-slate-500 ge-party-col">Party</th>
                                    <th class="py-2 px-2 text-right text-[9px] font-bold text-slate-500">Amount</th>
                                    <th class="py-2 px-2 text-right text-[9px] font-bold text-slate-500">Method</th>
                                    <th class="py-2 px-2 text-right text-[9px] font-bold text-slate-500 ge-action-col">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100" id="recentPaymentList">
                                <?php if (count($transactions_list) > 0):
                                foreach ($transactions_list as $index => $t):
                                $serial = $index + 1;
                                $pay_disp = payment_receipt_list_display_id($t);
                                $partyLines = payment_receipt_party_display_lines($t);
                                $is_bank = strcasecmp(trim((string)($t['payment_method'] ?? 'Cash')), 'Cash') !== 0;
                                ?>
                                    <tr class="ge-txn-row hover:bg-slate-50 transition-colors border-b border-slate-100 last:border-b-0">
                                        <td class="py-1.5 px-1 align-top text-center ge-serial-col">
                                            <span class="text-[9px] font-bold text-slate-400 tabular-nums"><?= $serial ?></span>
                                        </td>
                                        <td class="py-1.5 px-2 align-top group">
                                            <div class="text-[10px] font-bold text-blue-600 truncate flex items-center gap-0.5">
                                                <span class="truncate">#<?= htmlspecialchars($pay_disp) ?></span>
                                                <?php if ($is_bank): ?>
                                                    <i class="fas fa-university text-indigo-600 text-[9px] shrink-0" title="Bank"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-wallet text-emerald-600 text-[9px] shrink-0" title="Cash"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-[8px] font-semibold text-slate-400 leading-tight tabular-nums whitespace-nowrap">
                                                <?= date('d M', strtotime($t['date_of_transaction'])) ?> · <?= date('h:i A', strtotime($t['date_of_transaction'])) ?>
                                            </div>
                                        </td>
                                        <td class="py-1.5 px-2 align-top ge-party-col">
                                            <div class="text-[10px] font-semibold text-slate-800 truncate uppercase" title="<?= htmlspecialchars($partyLines['name']) ?>">
                                                <?= htmlspecialchars($partyLines['name']) ?>
                                            </div>
                                            <div class="text-[8px] font-medium text-slate-400 truncate"><?= htmlspecialchars($partyLines['sub']) ?></div>
                                        </td>
                                        <td class="py-1.5 px-2 align-top text-right">
                                            <div class="text-[10px] font-bold text-slate-800 leading-none">₹<?= pr_format_inr($t['payment_amount']) ?></div>
                                            <div class="mt-1">
                                                <span class="text-[7.5px] px-1 py-0.5 rounded bg-green-100 text-green-700 font-bold uppercase tracking-tighter">Received</span>
                                            </div>
                                        </td>
                                        <td class="py-1.5 px-2 align-top text-right">
                                            <div class="text-[10px] font-semibold text-slate-700 leading-none"><?= htmlspecialchars($t['payment_method'] ?? '—') ?></div>
                                        </td>
                                        <td class="py-1.5 px-2 align-top ge-action-col whitespace-nowrap">
                                            <div class="flex items-center justify-end gap-0.5">
                                                <button type="button" class="ge-action-btn edit-payment-btn text-blue-500 hover:text-blue-700" title="Edit" data-id="<?= (int)$t['id'] ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="ge-action-btn print-payment-btn text-emerald-600 hover:text-emerald-800" title="Print" data-id="<?= (int)$t['id'] ?>">
                                                    <i class="fas fa-print"></i>
                                                </button>
                                                <button type="button" class="ge-action-btn delete-payment-btn text-red-500 hover:text-red-700" title="Delete"
                                                    data-id="<?= (int)$t['id'] ?>"
                                                    data-receipt-id="<?= htmlspecialchars($t['receipt_id']) ?>"
                                                    data-display-id="<?= htmlspecialchars($pay_disp) ?>"
                                                    data-amount="<?= $t['payment_amount'] ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach;
                                else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-8 text-gray-500">
                                            <i class="fas fa-inbox text-2xl mb-2"></i><br>
                                            No payments found
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($payment_list_has_more): ?>
                    <p class="text-[9px] text-slate-400 text-center mt-1">Showing first <?= count($transactions_list) ?> of <?= $total_transactions ?> — narrow the date range to see more</p>
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
            const prSaveBtnClass = 'min-w-[7rem] bg-gradient-to-r from-blue-600 to-blue-700 text-white text-[10px] font-bold uppercase py-1.5 px-4 rounded shadow hover:from-blue-700 hover:to-blue-800 transition tracking-tighter';

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
                
                receiptList.html(
                    '<div class="p-1.5 text-center text-gray-500 text-[10px]"><i class="fas fa-spinner fa-spin"></i> Loading…</div>'
                );
                receiptList.removeClass('hidden');
                
                $.post('', { action: 'get_receipt_list' }, function(response) {
                    if (response.status === 'success' && response.data && response.data.length > 0) {
                        let rows = '';
                        response.data.forEach(function(receipt) {
                            const disp = receipt.display_receipt_id || receipt.receipt_id;
                            const d = (receipt.date_of_transaction || '').split(' ')[0];
                            const pnm = String(receipt.party_list_name || receipt.party_name || '—').replace(/</g, '&lt;');
                            const psub = String(receipt.party_list_sub || '').replace(/</g, '&lt;');
                            const rawRid = String(receipt.receipt_id || '').replace(/"/g, '&quot;');
                            const amt = parseFloat(receipt.payment_amount || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                            const meth = String(receipt.payment_method || '').replace(/</g, '&lt;');
                            rows +=
                                '<tr class="receipt-item border-b border-gray-100 hover:bg-blue-50/90 cursor-pointer align-top" data-tid="' + receipt.id + '">' +
                                '<td class="py-0.5 px-1.5 w-[32%]">' +
                                '<div class="flex items-start gap-0.5">' +
                                '<span class="shrink-0 bg-blue-600 text-white text-[8px] px-0.5 py-px rounded font-bold leading-none mt-0.5">PAY</span>' +
                                '<div class="min-w-0">' +
                                '<div class="font-mono font-bold text-[11px] text-gray-900 leading-tight truncate max-w-[9rem]" title="' + rawRid + '">' + String(disp).replace(/</g, '&lt;') + '</div>' +
                                '<div class="text-[9px] text-gray-500 leading-none">' + d + '</div></div></div></td>' +
                                '<td class="py-0.5 px-1.5 text-[10px]">' +
                                '<div class="font-semibold text-gray-900 leading-tight line-clamp-2">' + pnm + '</div>' +
                                '<div class="text-[9px] text-gray-500 leading-tight">' + psub + '</div></td>' +
                                '<td class="py-0.5 px-1.5 text-right whitespace-nowrap">' +
                                '<div class="font-bold text-blue-700 text-[11px]">₹' + amt + '</div>' +
                                '<div class="text-[9px] text-gray-500">' + meth + '</div></td>' +
                                '</tr>';
                        });
                        receiptList.html(
                            '<table class="w-full border-collapse text-[10px]">' +
                            '<thead><tr class="bg-gray-100 text-gray-600 border-b border-gray-200">' +
                            '<th class="text-left font-semibold px-1.5 py-0.5">Receipt</th>' +
                            '<th class="text-left font-semibold px-1.5 py-0.5">Party</th>' +
                            '<th class="text-right font-semibold px-1.5 py-0.5">Amt</th>' +
                            '</tr></thead><tbody>' + rows + '</tbody></table>'
                        );
                        receiptList.find('tr.receipt-item').on('click', function() {
                            loadReceiptForEdit($(this).data('tid'));
                            receiptList.addClass('hidden');
                        });
                    } else {
                        receiptList.html('<div class="p-2 text-center text-gray-500 text-[10px]">No previous receipts found</div>');
                    }
                }, 'json').fail(function() {
                    receiptList.html('<div class="p-2 text-center text-red-500 text-[10px]">Error loading receipts</div>');
                });
            }

            function loadReceiptForEdit(transactionId) {
                $.post('', {
                    action: 'get_payment_details',
                    transaction_id: transactionId
                }, function(response) {
                    if (response.status === 'success' && response.data) {
                        const data = response.data;
                        
                        $('#editTransactionId').val(String(data.id));
                        
                        // Populate form (keep stored receipt_id in DB; user may legacy CSH-*)
                        $('#receiptIdInput').val(data.receipt_id);
                        $('#editModeIndicator').removeClass('hidden');
                        
                        let dateValue = '';
                        if (data.date_of_transaction) {
                            const raw = String(data.date_of_transaction).replace(' ', 'T');
                            const date = new Date(raw);
                            if (!isNaN(date.getTime())) {
                                dateValue = date.toISOString().slice(0, 16);
                            } else {
                                dateValue = raw.substring(0, 16);
                            }
                        }
                        $('[name="date_of_transaction"]').val(dateValue);
                        
                        const pid = (data.party_id != null && String(data.party_id) !== '')
                            ? parseInt(data.party_id, 10) : 0;
                        if (pid > 0) {
                            $('#partyId').val(String(pid));
                            $('#partyNameInput').val((data.party_name || data.party_label || '').trim());
                            selectedPartyName = (data.party_name || data.party_label || '').trim();
                        } else {
                            $('#partyId').val('');
                            $('#partyNameInput').val((data.party_label || '').trim());
                            selectedPartyName = '';
                        }
                        
                        // Set payment details
                        $('#paymentAmount').val(data.payment_amount);
                        $('[name="payment_method"]').val(data.payment_method);
                        $('[name="narration"]').val(data.narration || '');
                        
                        // Change button to Update mode
                        $('#savePaymentBtn').html('<i class="fas fa-save mr-1"></i><span id="savePaymentBtnText">Update</span>').attr('class', prSaveBtnClass);
                        
                        $('#paymentForm').closest('.bg-white').css('border', '2px solid #f97316');
                        $('#deleteEditedPaymentBtn').removeClass('hidden');
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

            // Party search — dropdown layout matches gold exchange (wallet + cash/bank + gold)
            let partyListVisible = false;
            let currentIndex = -1;
            let selectedPartyName = '';

            function escPartyHtml(t) {
                return String(t ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
            }

            function appendCreatePartyRow(partyListEl, searchTerm) {
                const row = document.createElement('div');
                row.className = 'px-3 py-2 hover:bg-green-50 cursor-pointer transition-colors party-item bg-green-50 border-t-2 border-green-200';
                row.setAttribute('data-create-new', '1');
                row.innerHTML = `
                    <div class="flex items-center gap-2">
                        <i class="fas fa-plus-circle text-green-600"></i>
                        <div class="font-semibold text-[11px] text-green-700">Create new party &quot;${escPartyHtml(searchTerm)}&quot;</div>
                    </div>`;
                row.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (typeof SharedPartyHandler !== 'undefined') {
                        SharedPartyHandler.showAddPartyModal({
                            prefillName: searchTerm,
                            onSuccess: function (response, partyData) {
                                const pid = response.party_id || response.id;
                                $('#partyId').val(pid || '');
                                $('#partyNameInput').val(partyData.party_name || '');
                                selectedPartyName = partyData.party_name || '';
                                $('#partyList').addClass('hidden');
                                partyListVisible = false;
                                if (pid) {
                                    selectParty({ id: pid, party_name: partyData.party_name || '' });
                                }
                            }
                        });
                    }
                });
                partyListEl.append(row);
            }

            $('#partyNameInput').on('input', function () {
                const term = $(this).val();

                if (term !== selectedPartyName) {
                    selectedPartyName = '';
                    $('#partyId').val('');
                }

                if (term.length < 1) {
                    $('#partyList').addClass('hidden');
                    partyListVisible = false;
                    selectedPartyName = '';
                    $('#partyId').val('');
                    $('#balanceInfoSection').addClass('hidden');
                    return;
                }

                $.post('', { action: 'search_parties', term: term }, function (parties) {
                    const partyList = $('#partyList');
                    partyList.empty();
                    currentIndex = -1;

                    if (!parties || parties.length === 0) {
                        appendCreatePartyRow(partyList, term.trim());
                        partyList.removeClass('hidden');
                        partyListVisible = true;
                        return;
                    }

                    parties.forEach((party, index) => {
                        const cb = parseFloat(party.cash_balance) || 0;
                        const bb = parseFloat(party.bank_balance) || 0;
                        const totalRaw = parseFloat(party.total_due_amount);
                        const ledger = !isNaN(totalRaw) ? totalRaw : (cb + bb);
                        const gb = parseFloat(party.gold_balance != null ? party.gold_balance : party.total_due_gold) || 0;
                        const sb = parseFloat(party.silver_balance != null ? party.silver_balance : party.total_due_silver) || 0;
                        const pname = escPartyHtml(party.party_name || '');
                        const addr = escPartyHtml((party.address || '').trim() || 'No address');

                        const partyItem = document.createElement('div');
                        partyItem.className = 'px-3 py-2 hover:bg-yellow-50 cursor-pointer border-b border-gray-100 last:border-b-0 transition-colors party-item';
                        partyItem.setAttribute('data-index', String(index));
                        partyItem.setAttribute('data-id', party.id || '');
                        partyItem.setAttribute('data-name', party.party_name || '');
                        partyItem.setAttribute('data-address', party.address || '');

                        const silverLine = Math.abs(sb) >= 0.0001
                            ? `<div class="text-[10px] text-slate-500 font-bold tracking-tight"><i class="fas fa-compact-disc mr-1 opacity-70"></i>${sb.toFixed(3)}g Ag</div>`
                            : '';

                        partyItem.innerHTML = `
                            <div class="flex justify-between items-start gap-2">
                                <div class="font-bold text-[11px] text-slate-800 uppercase tracking-tight leading-tight">${pname}</div>
                                <div class="text-[10px] text-slate-400 font-medium truncate max-w-[130px] text-right">${addr}</div>
                            </div>
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5 mt-1">
                                <div class="text-[10px] text-rose-600 font-bold tracking-tight"><i class="fas fa-wallet mr-1 opacity-70"></i>₹${ledger.toLocaleString('en-IN')}</div>
                                <div class="text-[9px] text-slate-500 font-semibold">C ₹${cb.toLocaleString('en-IN')} · B ₹${bb.toLocaleString('en-IN')}</div>
                                <div class="text-[10px] text-amber-600 font-bold tracking-tight"><i class="fas fa-coins mr-1 opacity-70"></i>${gb.toFixed(3)}g</div>
                                ${silverLine}
                            </div>`;

                        partyItem.addEventListener('click', (e) => {
                            e.stopPropagation();
                            selectParty({
                                id: partyItem.getAttribute('data-id'),
                                party_name: partyItem.getAttribute('data-name'),
                                address: partyItem.getAttribute('data-address')
                            });
                        });
                        partyList.append(partyItem);
                    });

                    appendCreatePartyRow(partyList, term.trim());
                    partyList.removeClass('hidden');
                    partyListVisible = true;
                }, 'json');
            });

            $('#addNewPartyBtn').on('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                const t = ($('#partyNameInput').val() || '').trim();
                if (typeof SharedPartyHandler !== 'undefined') {
                    SharedPartyHandler.showAddPartyModal({
                        prefillName: t,
                        onSuccess: function (response, partyData) {
                            const pid = response.party_id || response.id;
                            $('#partyId').val(pid || '');
                            $('#partyNameInput').val(partyData.party_name || '');
                            selectedPartyName = partyData.party_name || '';
                            if (pid) {
                                selectParty({ id: pid, party_name: partyData.party_name || '' });
                            }
                        }
                    });
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
                            if (selectedItem.getAttribute('data-create-new')) {
                                selectedItem.click();
                                return;
                            }
                            const partyData = {
                                id: selectedItem.getAttribute('data-id'),
                                party_name: selectedItem.getAttribute('data-name'),
                                address: selectedItem.getAttribute('data-address')
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
                        item.classList.add('bg-yellow-100', 'border-l-4', 'border-amber-400');
                        item.classList.remove('hover:bg-yellow-50', 'hover:bg-green-50', 'bg-green-50');
                    } else {
                        item.classList.remove('bg-yellow-100', 'border-l-4', 'border-amber-400');
                        if (item.getAttribute('data-create-new')) {
                            item.classList.add('hover:bg-green-50');
                        } else {
                            item.classList.add('hover:bg-yellow-50');
                        }
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
                
                const editId = parseInt($('#editTransactionId').val(), 10) || 0;
                
                // If editing, use update handler
                if (editId > 0) {
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
                    }, 'json').fail(function(xhr) {
                        let msg = 'An error occurred while processing your request';
                        try {
                            const j = JSON.parse(xhr.responseText || '{}');
                            if (j.message) msg = j.message;
                        } catch (err) { /* ignore */ }
                        Swal.fire({ icon: 'error', title: 'Error', text: msg });
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
                        
                        $('#receiptIdInput').val(data.receipt_id);
                        
                        let dateValue = '';
                        if (data.date_of_transaction) {
                            const date = new Date(data.date_of_transaction.replace(' ', 'T'));
                            if (!isNaN(date.getTime())) {
                                dateValue = date.toISOString().slice(0, 16);
                            } else {
                                dateValue = String(data.date_of_transaction).replace(' ', 'T').substring(0, 16);
                            }
                        }
                        $('[name="date_of_transaction"]').val(dateValue);
                        
                        const pidTable = (data.party_id != null && String(data.party_id) !== '')
                            ? parseInt(data.party_id, 10) : 0;
                        if (pidTable > 0) {
                            $('#partyId').val(String(pidTable));
                            $('#partyNameInput').val((data.party_name || data.party_label || '').trim());
                            selectedPartyName = (data.party_name || data.party_label || '').trim();
                        } else {
                            $('#partyId').val('');
                            $('#partyNameInput').val((data.party_label || '').trim());
                            selectedPartyName = '';
                        }
                        $('[name="payment_amount"]').val(data.payment_amount);
                        $('[name="payment_method"]').val(data.payment_method);
                        $('[name="narration"]').val(data.narration || '');
                        
                        $('#editTransactionId').val(String(transactionId));
                        $('#editModeIndicator').removeClass('hidden');
                        
                        const submitBtn = $('#paymentForm button[type="submit"]');
                        submitBtn.html('<i class="fas fa-save mr-1"></i><span id="savePaymentBtnText">Update</span>');
                        submitBtn.attr('class', prSaveBtnClass);
                        $('#paymentForm').closest('.bg-white').css('border', '2px solid #f97316');
                        $('#deleteEditedPaymentBtn').removeClass('hidden');
                        
                        $('html, body').animate({
                            scrollTop: $('#paymentForm').offset().top - 100
                        }, 500);
                        
                        $('#receiptIdInput').focus();
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
            
            // Print payment button handler
            $(document).on('click', '.print-payment-btn', function() {
                const transactionId = $(this).data('id');
                const url = 'print_payment_receipt.php?id=' + encodeURIComponent(transactionId);
                if (window.GePrint && typeof window.GePrint.printReceipt === 'function') {
                    window.GePrint.printReceipt(url);
                } else {
                    window.open(url, '_blank');
                }
            });

            function confirmAndDeletePayment(transactionId, receiptLabel, amount) {
                Swal.fire({
                    title: 'Delete Payment?',
                    html: `Are you sure you want to delete payment <b>${receiptLabel}</b> for <b>₹${parseFloat(amount).toLocaleString('en-IN')}</b>?<br><br><span class="text-red-600 text-sm">This will reverse the balance adjustment for the customer.</span>`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, Delete',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#dc2626',
                    cancelButtonColor: '#6b7280'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $.post('', {
                            action: 'delete_payment',
                            transaction_id: transactionId
                        }, function(response) {
                            if (response.status === 'success') {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Deleted!',
                                    text: 'Payment has been deleted successfully.',
                                    timer: 1500,
                                    showConfirmButton: false
                                });
                                setTimeout(() => {
                                    location.reload();
                                }, 1500);
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: response.message || 'Failed to delete payment'
                                });
                            }
                        }, 'json').fail(function() {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'Failed to process request'
                            });
                        });
                    }
                });
            }

            $('#deleteEditedPaymentBtn').on('click', function() {
                const tid = parseInt($('#editTransactionId').val(), 10) || 0;
                if (tid <= 0) return;
                const rid = ($('#receiptIdInput').val() || '').trim() || ('PAY#' + tid);
                const amt = parseFloat($('[name="payment_amount"]').val()) || 0;
                confirmAndDeletePayment(tid, rid, amt);
            });

            // Delete payment button handler
            $(document).on('click', '.delete-payment-btn', function() {
                const transactionId = $(this).data('id');
                const receiptId = $(this).data('display-id') || $(this).data('receipt-id');
                const amount = $(this).data('amount');
                confirmAndDeletePayment(transactionId, receiptId, amount);
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
                
                $('#editTransactionId').val('');
                $('#editModeIndicator').addClass('hidden');
                $('#paymentForm').closest('.bg-white').css('border', '');
                
                // Reset submit button
                const submitBtn = $('#paymentForm button[type="submit"]');
                submitBtn.html('<i class="fas fa-save mr-1"></i><span id="savePaymentBtnText">Save</span>');
                submitBtn.attr('class', prSaveBtnClass);
                $('#deleteEditedPaymentBtn').addClass('hidden');
                
                // Regenerate payment ID and reset date
                initializeForm();
                
                // Focus on customer name field
                setTimeout(() => {
                    $('#partyNameInput').focus();
                }, 100);
            }

            /* Date range filter validation (same as exchange.php) */
            $('#startDate, #endDate').on('change', function () {
                const startDate = new Date($('#startDate').val());
                const endDate = new Date($('#endDate').val());
                if (startDate > endDate) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Invalid Date Range',
                        text: 'End date must be greater than or equal to start date',
                        confirmButtonColor: '#3085d6',
                        timer: 2000,
                        showConfirmButton: false
                    });
                    if ($(this).attr('id') === 'startDate') {
                        $('#endDate').val($('#startDate').val());
                    } else {
                        $('#startDate').val($('#endDate').val());
                    }
                }
            });
        });
    </script>

<?php
// Capture the content
$content = ob_get_clean();

// Set page title and include layout
$page_title = "Payment Receipt";
include 'components/layout.php';
?>
