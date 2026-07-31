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

if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error . '. Please run setup_database.php first.');
}

// Get user and company info
$company_id = $_SESSION['company_id'];
$user_id = $_SESSION['user_id'];
$company_name = $_SESSION['company_name'];
$user_name = $_SESSION['full_name'];

/** List label: PAYOUT* as stored; else OUT# + id */
function payment_send_list_display_id(array $t): string {
    $rid = trim((string)($t['receipt_id'] ?? ''));
    if ($rid !== '' && preg_match('/^PAYOUT/i', $rid)) {
        return $rid;
    }
    return 'OUT#' . (int)($t['id'] ?? 0);
}

function payment_send_party_id_int(array $t): int {
    if (!array_key_exists('party_id', $t) || $t['party_id'] === null || $t['party_id'] === '') {
        return 0;
    }
    return (int) $t['party_id'];
}

/** @return array{name: string, sub: string} */
function payment_send_party_display_lines(array $t): array {
    $pid = payment_send_party_id_int($t);
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
    $name = $n !== '' ? (strlen($n) > 36 ? substr($n, 0, 34) . '…' : $n) : '—';
    return ['name' => $name, 'sub' => 'No party'];
}

/**
 * payment_type column is always Payment_Out here; this stores the user-facing category in booking_type.
 */
function payment_send_booking_type_from_post(string $raw): string {
    $raw = trim($raw);
    $allowed = ['Payment_Sent', 'Supplier_Payment', 'Vendor_Payment', 'Refund_Payment', 'Advance_Payment', 'Commission_Payment'];
    if (in_array($raw, $allowed, true)) {
        return $raw;
    }
    if ($raw === 'Payment_Out' || $raw === '') {
        return 'Payment_Sent';
    }
    return 'Payment_Sent';
}

function payment_send_category_label(?string $booking_type): string {
    $bt = trim((string) $booking_type);
    $labels = [
        'Payment_Sent' => 'Payment sent',
        'Supplier_Payment' => 'Supplier payment',
        'Vendor_Payment' => 'Vendor payment',
        'Refund_Payment' => 'Refund',
        'Advance_Payment' => 'Advance',
        'Commission_Payment' => 'Commission',
    ];
    if ($bt !== '' && isset($labels[$bt])) {
        return $labels[$bt];
    }
    return $bt !== '' ? $bt : 'Payment sent';
}

/** Indian-style number grouping for stat/list amounts (matches payment_receipt.php). */
function ps_format_inr($amount, int $decimals = 0): string
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

/** Cash leg vs bank (UPI/Cheque/Card/Bank share bank_balance on party) */
function payment_send_party_ledger_is_cash_leg(string $method): bool {
    return strcasecmp(trim($method), 'Cash') === 0;
}

/** Company book account row: Cash vs Bank (same mapping as payment_receipt / purchase). */
function payment_send_company_account_type(string $payment_method): string {
    return payment_send_party_ledger_is_cash_leg($payment_method) ? 'Cash' : 'Bank';
}

/**
 * Adjust party cash_balance or bank_balance when recording vendor payment (+$delta) or reversing (-$delta).
 */
function payment_send_adjust_party_leg_for_payment_out(
    mysqli $conn,
    int $company_id,
    int $party_id,
    string $payment_method,
    float $delta
): void {
    if ($party_id <= 0 || abs($delta) < 0.000001) {
        return;
    }
    $d = round($delta, 2);
    if (payment_send_party_ledger_is_cash_leg($payment_method)) {
        $sql = "UPDATE parties SET cash_balance = cash_balance + ($d) WHERE id = $party_id AND company_id = $company_id";
    } else {
        $sql = "UPDATE parties SET bank_balance = bank_balance + ($d) WHERE id = $party_id AND company_id = $company_id";
    }
    if (!$conn->query($sql)) {
        throw new Exception('Error updating party cash/bank balance: ' . $conn->error);
    }
}

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
                        p.cash_balance, p.bank_balance,
                        (p.cash_balance + p.bank_balance) AS current_balance,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' THEN t.gold_weight ELSE 0 END), 0) as booked_weight,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Sale' THEN t.gold_weight ELSE 0 END), 0) as sold_weight,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' THEN t.gold_amount ELSE 0 END), 0) as booked_amount,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Payment' AND t.payment_type = 'Payment_Out' AND t.payment_method = 'Cash' THEN t.payment_amount ELSE 0 END), 0) as cash_paid,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Payment' AND t.payment_type = 'Payment_Out' AND t.payment_method IN ('Bank', 'UPI', 'Cheque') THEN t.payment_amount ELSE 0 END), 0) as bank_paid
                        FROM parties p 
                        LEFT JOIN transactions t ON p.id = t.party_id AND t.company_id = $company_id
                        WHERE p.company_id = $company_id AND p.party_name LIKE '%$search%' 
                        GROUP BY p.id, p.party_name, p.address, p.contact_no, p.cash_balance, p.bank_balance
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
                if ($party_id <= 0) {
                    echo json_encode([
                        'booked_weight' => 0,
                        'sold_weight' => 0,
                        'available_weight' => 0,
                        'booked_amount' => 0,
                        'total_paid' => 0,
                        'cash_paid' => 0,
                        'bank_paid' => 0,
                        'cash_balance' => 0,
                        'bank_balance' => 0,
                        'ledger_total' => 0,
                        'payable_to_party' => 0,
                        'receivable_from_party' => 0
                    ]);
                    exit;
                }

                $cash_bal = 0.0;
                $bank_bal = 0.0;
                $psum = $conn->prepare('SELECT cash_balance, bank_balance FROM parties WHERE id = ? AND company_id = ?');
                if ($psum) {
                    $psum->bind_param('ii', $party_id, $company_id);
                    $psum->execute();
                    $pr = $psum->get_result();
                    if ($pr && $prow = $pr->fetch_assoc()) {
                        $cash_bal = floatval($prow['cash_balance'] ?? 0);
                        $bank_bal = floatval($prow['bank_balance'] ?? 0);
                    }
                }

                $ledger_total = $cash_bal + $bank_bal;
                $payable_to_party = $ledger_total < 0 ? abs($ledger_total) : 0.0;
                $receivable_from_party = $ledger_total > 0 ? $ledger_total : 0.0;

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
                        'bank_paid' => floatval($row['bank_paid'] ?? 0),
                        'cash_balance' => $cash_bal,
                        'bank_balance' => $bank_bal,
                        'ledger_total' => $ledger_total,
                        'payable_to_party' => $payable_to_party,
                        'receivable_from_party' => $receivable_from_party
                    ]);
                } else {
                    echo json_encode([
                        'booked_weight' => 0,
                        'sold_weight' => 0,
                        'available_weight' => 0,
                        'booked_amount' => 0,
                        'total_paid' => 0,
                        'cash_paid' => 0,
                        'bank_paid' => 0,
                        'cash_balance' => $cash_bal,
                        'bank_balance' => $bank_bal,
                        'ledger_total' => $ledger_total,
                        'payable_to_party' => $payable_to_party,
                        'receivable_from_party' => $receivable_from_party
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
                    $narration = $conn->real_escape_string((string)($_POST['narration'] ?? ''));
                    $booking_type = $conn->real_escape_string(payment_send_booking_type_from_post((string)($_POST['payment_type'] ?? '')));
                    $payment_type = 'Payment_Out';
                    
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
                        gold_weight, purity, rate, gold_amount, payment_amount, payment_method, payment_type, booking_type,
                        party_balance_before, party_balance_after, party_gold_balance_before, party_gold_balance_after,
                        narration
                    ) VALUES (
                        $company_id, $party_id, '$receipt_id', 'Payment', '$date_of_transaction',
                        0.000, 0.00, 0.00, 0.00, $payment_amount, '$payment_method', '$payment_type', '$booking_type',
                        $current_paid, " . ($current_paid + $payment_amount) . ", 0, 0,
                        'Payment sent - $receipt_id" . ($narration !== '' ? " - $narration" : '') . "'
                    )";
                    
                    if (!$conn->query($payment_sql)) {
                        throw new Exception("Error creating payment transaction: " . $conn->error);
                    }

                    payment_send_adjust_party_leg_for_payment_out($conn, $company_id, $party_id, $payment_method, $payment_amount);

                    $shop_acct = payment_send_company_account_type((string)($_POST['payment_method'] ?? ''));
                    if (!updateAccountBalance($conn, $company_id, $shop_acct, -$payment_amount)) {
                        throw new Exception('Error updating company cash/bank balance');
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
                            'booking_type' => payment_send_booking_type_from_post((string)($_POST['payment_type'] ?? '')),
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
                $list_sql = "SELECT t.id, t.party_id, t.narration, t.receipt_id, t.date_of_transaction, t.payment_amount, t.payment_method, t.payment_type, t.booking_type,
                            p.party_name, p.contact_no AS party_contact
                            FROM transactions t
                            LEFT JOIN parties p ON t.party_id = p.id AND p.company_id = t.company_id
                            WHERE t.transaction_type = 'Payment' 
                            AND t.payment_type = 'Payment_Out'
                            AND t.company_id = $company_id
                            ORDER BY t.date_of_transaction DESC, t.id DESC
                            LIMIT 20";
                
                $list_result = $conn->query($list_sql);
                
                if ($list_result) {
                    $payments = [];
                    while ($row = $list_result->fetch_assoc()) {
                        $row['display_receipt_id'] = payment_send_list_display_id($row);
                        $pl = payment_send_party_display_lines($row);
                        $row['party_list_name'] = $pl['name'];
                        $row['party_list_sub'] = $pl['sub'];
                        $row['type_label'] = payment_send_category_label($row['booking_type'] ?? null);
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

            case 'get_payment_out_details':
                $transaction_id = intval($_POST['transaction_id'] ?? 0);
                if ($transaction_id <= 0) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['status' => 'error', 'message' => 'Invalid transaction ID']);
                    exit;
                }
                $sql = "SELECT t.*, p.party_name, p.contact_no, p.address
                        FROM transactions t
                        LEFT JOIN parties p ON t.party_id = p.id AND p.company_id = t.company_id
                        WHERE t.id = $transaction_id AND t.company_id = $company_id
                        AND t.transaction_type = 'Payment' AND t.payment_type = 'Payment_Out'";
                $result = $conn->query($sql);
                if ($result && $result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $pl = payment_send_party_display_lines($row);
                    $row['party_label'] = $pl['name'];
                    $row['party_sub'] = $pl['sub'];
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['status' => 'success', 'data' => $row]);
                } else {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['status' => 'error', 'message' => 'Transaction not found']);
                }
                exit;

            case 'update_payment_out':
                $conn->begin_transaction();
                try {
                    $transaction_id = intval($_POST['transaction_id'] ?? 0);
                    $receipt_id = $conn->real_escape_string($_POST['receipt_id'] ?? '');
                    $date_of_transaction = $conn->real_escape_string($_POST['date_of_transaction'] ?? '');
                    $party_id = intval($_POST['party_id'] ?? 0);
                    $payment_amount = floatval($_POST['payment_amount'] ?? 0);
                    $payment_method = $conn->real_escape_string($_POST['payment_method'] ?? '');
                    $narration = $conn->real_escape_string((string)($_POST['narration'] ?? ''));
                    $booking_type = $conn->real_escape_string(payment_send_booking_type_from_post((string)($_POST['payment_type'] ?? '')));
                    $payment_type = 'Payment_Out';

                    if ($transaction_id <= 0 || $party_id <= 0 || $payment_amount <= 0 || $receipt_id === '' || $date_of_transaction === '') {
                        throw new Exception('Please fill all required fields with valid values');
                    }

                    $bal_sql = "SELECT 
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Payment' AND t.payment_type = 'Payment_Out' THEN t.payment_amount ELSE 0 END), 0) as total_paid
                        FROM transactions t WHERE t.party_id = $party_id AND t.company_id = $company_id AND t.id != $transaction_id";
                    $bal_row = $conn->query($bal_sql)->fetch_assoc();
                    $paid_before_row = floatval($bal_row['total_paid'] ?? 0);

                    $orig = $conn->query(
                        "SELECT payment_amount, party_id, payment_method FROM transactions WHERE id = $transaction_id AND company_id = $company_id"
                    )->fetch_assoc();
                    if (!$orig) {
                        throw new Exception('Transaction not found');
                    }

                    $old_party_id = (int) $orig['party_id'];
                    $old_amt = floatval($orig['payment_amount']);
                    $old_method = (string) ($orig['payment_method'] ?? 'Cash');

                    payment_send_adjust_party_leg_for_payment_out($conn, $company_id, $old_party_id, $old_method, -$old_amt);
                    payment_send_adjust_party_leg_for_payment_out($conn, $company_id, $party_id, $payment_method, $payment_amount);

                    $pb_before = $paid_before_row;
                    $pb_after = $paid_before_row + $payment_amount;

                    $upd = "UPDATE transactions SET receipt_id = '$receipt_id', date_of_transaction = '$date_of_transaction',
                            party_id = $party_id, payment_amount = $payment_amount, payment_method = '$payment_method',
                            payment_type = '$payment_type', booking_type = '$booking_type',
                            party_balance_before = $pb_before,
                            party_balance_after = $pb_after,
                            narration = 'Payment sent - $receipt_id" . ($narration !== '' ? " - $narration" : '') . "',
                            updated_at = NOW()
                            WHERE id = $transaction_id AND company_id = $company_id";
                    if (!$conn->query($upd)) {
                        throw new Exception('Error updating transaction: ' . $conn->error);
                    }

                    $conn->commit();
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['status' => 'success', 'message' => 'Payment updated successfully']);
                } catch (Exception $e) {
                    $conn->rollback();
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
                exit;

            case 'delete_payment_out':
                $transaction_id = intval($_POST['transaction_id'] ?? 0);
                if ($transaction_id <= 0) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['status' => 'error', 'message' => 'Invalid transaction ID']);
                    exit;
                }
                $conn->begin_transaction();
                try {
                    $row = $conn->query(
                        "SELECT party_id, payment_amount, payment_method FROM transactions WHERE id = $transaction_id AND company_id = $company_id AND transaction_type = 'Payment' AND payment_type = 'Payment_Out'"
                    )->fetch_assoc();
                    if (!$row) {
                        throw new Exception('Transaction not found');
                    }
                    $pid = (int) $row['party_id'];
                    $amt = floatval($row['payment_amount']);
                    $meth = (string) ($row['payment_method'] ?? 'Cash');
                    payment_send_adjust_party_leg_for_payment_out($conn, $company_id, $pid, $meth, -$amt);
                    if (!updateAccountBalance($conn, $company_id, payment_send_company_account_type($meth), $amt)) {
                        throw new Exception('Error updating company cash/bank balance');
                    }
                    if (!$conn->query("DELETE FROM transactions WHERE id = $transaction_id AND company_id = $company_id")) {
                        throw new Exception($conn->error ?: 'Delete failed');
                    }
                    $conn->commit();
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['status' => 'success', 'message' => 'Payment deleted']);
                } catch (Exception $e) {
                    $conn->rollback();
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
                exit;
        }
    }
}

// Stats: company cash/bank (account_balances) + today's outgoing payments by method
$stats = [
    'cash_in_hand' => 0.0,
    'bank_balance' => 0.0,
    'total_cash_paid' => 0.0,
    'total_bank_paid' => 0.0,
];

$stats_sql = "SELECT 
    COALESCE(SUM(CASE WHEN transaction_type = 'Payment' AND payment_type = 'Payment_Out' AND payment_method = 'Cash' THEN payment_amount ELSE 0 END), 0) AS total_cash_paid,
    COALESCE(SUM(CASE WHEN transaction_type = 'Payment' AND payment_type = 'Payment_Out' AND payment_method IN ('Bank', 'UPI', 'Cheque', 'Card') THEN payment_amount ELSE 0 END), 0) AS total_bank_paid
FROM transactions
WHERE DATE(date_of_transaction) = CURRENT_DATE AND company_id = $company_id";

$stats_result = $conn->query($stats_sql);
if ($stats_result && ($sr = $stats_result->fetch_assoc())) {
    $stats['total_cash_paid'] = (float) ($sr['total_cash_paid'] ?? 0);
    $stats['total_bank_paid'] = (float) ($sr['total_bank_paid'] ?? 0);
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

// Get recent payment out transactions (date range + scroll list, same pattern as payment_receipt.php)
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
                    WHERE t.transaction_type = 'Payment' 
                    AND t.payment_type = 'Payment_Out'
                    AND t.company_id = $company_id
                    $where_clause 
                    ORDER BY t.date_of_transaction DESC, t.id DESC 
                    LIMIT $payment_list_limit";

$transactions = $conn->query($transactions_sql);
$transactions_list = ($transactions && $transactions->num_rows > 0) ? $transactions->fetch_all(MYSQLI_ASSOC) : [];

$total_sql = "SELECT COUNT(*) as count 
              FROM transactions t 
              LEFT JOIN parties p ON t.party_id = p.id
              WHERE t.transaction_type = 'Payment' 
              AND t.payment_type = 'Payment_Out'
              AND t.company_id = $company_id
              $where_clause";
$total_result = $conn->query($total_sql);
$total_transactions = ($total_result) ? (int) $total_result->fetch_assoc()['count'] : 0;
$payment_list_has_more = $total_transactions > count($transactions_list);
?>

<!-- Page-specific styles (body shell from components/layout.php) -->
<style>
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
        .validation-error.hidden { display: none; }
        input.border-red-500, select.border-red-500, textarea.border-red-500 {
            border-color: #ef4444 !important;
            box-shadow: 0 0 0 1px #ef4444;
        }
    </style>

<div class="w-full min-w-0 px-1 pb-4">
    <div class="overflow-x-auto pb-1 -mx-0.5 px-0.5">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mb-3 min-w-0 w-full">
            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase">Cash in hand</p>
                        <p class="stats-card-value leading-tight">₹<?= ps_format_inr($stats['cash_in_hand'] ?? 0) ?></p>
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
                        <p class="stats-card-value leading-tight">₹<?= ps_format_inr($stats['bank_balance'] ?? 0) ?></p>
                    </div>
                    <div class="stats-icon-wrap bg-blue-100 shrink-0">
                        <i class="fas fa-university text-blue-600 text-xs"></i>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase">Cash paid</p>
                        <p class="stats-card-value leading-tight">₹<?= ps_format_inr($stats['total_cash_paid'] ?? 0) ?></p>
                    </div>
                    <div class="stats-icon-wrap bg-rose-100 shrink-0">
                        <i class="fas fa-money-bill-wave text-rose-600 text-xs"></i>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase">Bank paid</p>
                        <p class="stats-card-value leading-tight">₹<?= ps_format_inr($stats['total_bank_paid'] ?? 0) ?></p>
                    </div>
                    <div class="stats-icon-wrap bg-orange-100 shrink-0">
                        <i class="fas fa-building-columns text-orange-600 text-xs"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="flex flex-col lg:flex-row gap-4 min-w-0 w-full lg:items-start">
        <div id="paymentOutFormCard" class="bg-white rounded-lg shadow-md border border-gray-200 min-w-0 lg:flex-[1_1_55%] overflow-hidden self-start w-full">
            <form id="paymentOutForm" method="POST" class="overflow-hidden" onsubmit="return false;">
                <input type="hidden" name="action" value="save_payment_out">
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
                        <label class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Payment ID</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-gray-500"><i class="fas fa-hashtag text-xs"></i></span>
                            <input type="text" name="receipt_id" readonly id="paymentIdInput" tabindex="0"
                                class="block w-full pl-7 pr-8 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-blue-400 focus:border-blue-400 compact-input cursor-pointer"
                                placeholder="Auto..." title="Click for recent payments to load">
                            <button type="button" class="absolute right-1 top-1/2 -translate-y-1/2 text-gray-400 hover:text-blue-600 p-0.5" id="showPaymentListBtn" title="Recent payments"><i class="fas fa-history text-xs"></i></button>
                        </div>
                        <div id="paymentList" class="hidden absolute z-[60] mt-1 bg-white border border-gray-200 rounded-md shadow-lg max-h-72 overflow-y-auto w-[min(100%,20rem)] left-0 text-[9px] leading-tight"></div>
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

                <div id="partyInfoSection" class="hidden px-2 pb-1">
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-2 text-xs" id="partyInfoAlert"></div>
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
                                <option value="UPI">UPI</option>
                                <option value="Cheque">Cheque</option>
                                <option value="Card">Card</option>
                            </select>
                        </div>
                    </div>
                    <div class="relative col-span-12 sm:col-span-4">
                        <label class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Type</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-gray-600"><i class="fas fa-tag text-xs"></i></span>
                            <select name="payment_type" id="paymentCategorySelect" required
                                class="block w-full pl-7 pr-1 py-1.5 text-[11px] font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-gray-400 focus:border-gray-400 compact-input"
                                title="Category for reports. All types update party balance the same way for the Method you choose.">
                                <option value="">Select</option>
                                <option value="Payment_Sent">Payment sent</option>
                                <option value="Supplier_Payment">Supplier payment</option>
                                <option value="Vendor_Payment">Vendor payment</option>
                                <option value="Refund_Payment">Refund</option>
                                <option value="Advance_Payment">Advance</option>
                                <option value="Commission_Payment">Commission</option>
                            </select>
                        </div>
                    </div>
                    <div class="relative col-span-12">
                        <label class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Narration</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-purple-500"><i class="fas fa-comment-alt text-xs"></i></span>
                            <input type="text" name="narration" placeholder="Optional notes..."
                                class="block w-full pl-7 pr-2 py-1.5 text-xs font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-purple-400 focus:border-purple-400 compact-input">
                        </div>
                    </div>
                </div>

                <div class="pr-form-footer">
                    <button type="submit" id="sendPaymentBtn"
                        class="min-w-[7rem] bg-gradient-to-r from-blue-600 to-blue-700 text-white text-[10px] font-bold uppercase py-1.5 px-4 rounded shadow hover:from-blue-700 hover:to-blue-800 transition tracking-tighter">
                        <i class="fas fa-paper-plane mr-1"></i><span id="sendPaymentBtnText">Send payment</span>
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

        <div class="bg-white rounded-lg shadow-md border border-gray-200 min-w-0 lg:flex-[1_1_45%] self-start w-full">
            <div class="bg-blue-50 px-3 py-1.5 border-b border-blue-100 rounded-t-lg">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-xs font-bold text-blue-800 flex items-center">
                        <i class="fas fa-list mr-1.5 text-xs"></i> Recent Payments Sent
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
                <div class="ge-txn-scroll" id="psTxnScroll">
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
                        <tbody class="divide-y divide-gray-100">
                            <?php if (count($transactions_list) > 0):
                            foreach ($transactions_list as $index => $t):
                                $serial = $index + 1;
                                $out_disp = payment_send_list_display_id($t);
                                $pl = payment_send_party_display_lines($t);
                                $is_bank = strcasecmp(trim((string)($t['payment_method'] ?? 'Cash')), 'Cash') !== 0;
                                $cat_label = payment_send_category_label($t['booking_type'] ?? null);
                            ?>
                            <tr class="ge-txn-row hover:bg-slate-50 transition-colors border-b border-slate-100 last:border-b-0">
                                <td class="py-1.5 px-1 align-top text-center ge-serial-col">
                                    <span class="text-[9px] font-bold text-slate-400 tabular-nums"><?= $serial ?></span>
                                </td>
                                <td class="py-1.5 px-2 align-top group">
                                    <div class="text-[10px] font-bold text-blue-600 group-hover:underline truncate flex items-center gap-0.5">
                                        <span class="truncate">#<?= htmlspecialchars($out_disp) ?></span>
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
                                    <div class="text-[10px] font-semibold text-slate-800 truncate uppercase" title="<?= htmlspecialchars($pl['name']) ?>">
                                        <?= htmlspecialchars($pl['name']) ?>
                                    </div>
                                    <div class="text-[8px] font-medium text-slate-400 truncate"><?= htmlspecialchars($pl['sub']) ?></div>
                                </td>
                                <td class="py-1.5 px-2 align-top text-right">
                                    <div class="text-[10px] font-bold text-slate-800 leading-none">₹<?= ps_format_inr($t['payment_amount']) ?></div>
                                    <div class="mt-1">
                                        <span class="text-[7.5px] px-1 py-0.5 rounded bg-rose-100 text-rose-700 font-bold uppercase tracking-tighter"><?= htmlspecialchars($cat_label) ?></span>
                                    </div>
                                </td>
                                <td class="py-1.5 px-2 align-top text-right">
                                    <div class="text-[10px] font-semibold text-slate-700 leading-none"><?= htmlspecialchars($t['payment_method'] ?? '—') ?></div>
                                </td>
                                <td class="py-1.5 px-2 align-top ge-action-col whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-0.5">
                                        <button type="button" class="ge-action-btn edit-send-btn text-blue-500 hover:text-blue-700" title="Edit" data-id="<?= (int)$t['id'] ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="ge-action-btn print-send-btn text-emerald-600 hover:text-emerald-800" title="Print" data-id="<?= (int)$t['id'] ?>">
                                            <i class="fas fa-print"></i>
                                        </button>
                                        <button type="button" class="ge-action-btn delete-send-btn text-red-500 hover:text-red-700" title="Delete"
                                            data-id="<?= (int)$t['id'] ?>"
                                            data-receipt-id="<?= htmlspecialchars($t['receipt_id']) ?>"
                                            data-display-id="<?= htmlspecialchars($out_disp) ?>"
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
                                    No payments sent yet
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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="js/keyboard-navigation-generic.js"></script>
<script>
(function () {
    $(document).ready(function () {
        var psSaveBtnClass = 'min-w-[7rem] bg-gradient-to-r from-blue-600 to-blue-700 text-white text-[10px] font-bold uppercase py-1.5 px-4 rounded shadow hover:from-blue-700 hover:to-blue-800 transition tracking-tighter';

        function refreshPaymentTypeHint() {
            var v = ($('#paymentCategorySelect').val() || $('[name="payment_type"]').val() || '');
            var hints = {
                'Payment_Sent': 'General payment to the party.',
                'Supplier_Payment': 'Tag payments tied to goods from a supplier.',
                'Vendor_Payment': 'Tag payments for services or other vendors.',
                'Refund_Payment': 'Money you return to the party.',
                'Advance_Payment': 'Pay before purchase/service.',
                'Commission_Payment': 'Brokerage or commission paid to the party.'
            };
            var hint = hints[v] || '';
            $('#paymentCategorySelect').attr('title', hint || 'Category for reports. All types update party balance the same way for the Method you choose.');
        }

        $('#paymentCategorySelect').on('change', refreshPaymentTypeHint);
        refreshPaymentTypeHint();

        function generatePaymentOutId() {
            return new Promise(function (resolve) {
                fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=generate_payment_out_id' })
                    .then(function (r) { return r.json(); })
                    .then(function (result) {
                        if (result.status === 'success') { resolve(result.payment_id); }
                        else {
                            var companyId = <?= (int)$company_id ?>;
                            resolve('PAYOUT' + companyId + String(Math.floor(Math.random() * 999) + 1).padStart(3, '0'));
                        }
                    })
                    .catch(function () {
                        var companyId = <?= (int)$company_id ?>;
                        resolve('PAYOUT' + companyId + String(Math.floor(Math.random() * 999) + 1).padStart(3, '0'));
                    });
            });
        }

        function initializeForm() {
            return generatePaymentOutId().then(function (pid) {
                $('#paymentIdInput').val(pid);
                var now = new Date();
                now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
                $('[name="date_of_transaction"]').val(now.toISOString().slice(0, 16));
            });
        }

        initializeForm();

        if (typeof KeyboardNavigationGeneric !== 'undefined') {
            KeyboardNavigationGeneric.init({
                formId: 'paymentOutForm',
                fieldOrder: ['paymentIdInput', 'date_of_transaction', 'partyNameInput', 'paymentAmount', 'payment_method', 'payment_type', 'narration'],
                skipFields: [],
                submitButtonId: 'sendPaymentBtn',
                formName: 'payment_out'
            });
            window.KeyboardNavigation = KeyboardNavigationGeneric;
        }

        $('#showPaymentListBtn, #paymentIdInput').on('click', function (e) {
            e.preventDefault();
            showPaymentList();
        });

        function showPaymentList() {
            var el = $('#paymentList');
            el.html('<div class="p-1.5 text-center text-gray-500 text-[10px]"><i class="fas fa-spinner fa-spin"></i> Loading…</div>');
            el.removeClass('hidden');
            $.post('', { action: 'get_payment_list' }, function (response) {
                if (response.status === 'success' && response.data && response.data.length > 0) {
                    var rows = '';
                    response.data.forEach(function (p) {
                        var disp = p.display_receipt_id || p.receipt_id;
                        var d = (p.date_of_transaction || '').split(' ')[0];
                        var pnm = String(p.party_list_name || p.party_name || '—').replace(/</g, '&lt;');
                        var psub = String(p.party_list_sub || '').replace(/</g, '&lt;');
                        var rawRid = String(p.receipt_id || '').replace(/"/g, '&quot;');
                        var amt = parseFloat(p.payment_amount || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        var meth = String(p.payment_method || '').replace(/</g, '&lt;');
                        rows += '<tr class="payment-pick-row border-b border-gray-100 hover:bg-rose-50/90 cursor-pointer align-top" data-tid="' + p.id + '">' +
                            '<td class="py-0.5 px-1.5 w-[32%]"><div class="flex items-start gap-0.5">' +
                            '<span class="shrink-0 bg-rose-600 text-white text-[8px] px-0.5 py-px rounded font-bold leading-none mt-0.5">OUT</span>' +
                            '<div class="min-w-0"><div class="font-mono font-bold text-[11px] text-gray-900 leading-tight truncate max-w-[9rem]" title="' + rawRid + '">' +
                            String(disp).replace(/</g, '&lt;') + '</div><div class="text-[9px] text-gray-500 leading-none">' + d + '</div></div></div></td>' +
                            '<td class="py-0.5 px-1.5 text-[10px]"><div class="font-semibold text-gray-900 leading-tight line-clamp-2">' + pnm + '</div>' +
                            '<div class="text-[9px] text-gray-500 leading-tight">' + psub + '</div></td>' +
                            '<td class="py-0.5 px-1.5 text-right whitespace-nowrap"><div class="font-bold text-rose-700 text-[11px]">₹' + amt + '</div>' +
                            '<div class="text-[9px] text-gray-500">' + meth + '</div></td></tr>';
                    });
                    el.html('<table class="w-full border-collapse text-[10px]"><thead><tr class="bg-gray-100 text-gray-600 border-b border-gray-200">' +
                        '<th class="text-left font-semibold px-1.5 py-0.5">Payment</th><th class="text-left font-semibold px-1.5 py-0.5">Party</th>' +
                        '<th class="text-right font-semibold px-1.5 py-0.5">Amt</th></tr></thead><tbody>' + rows + '</tbody></table>');
                    el.find('tr.payment-pick-row').on('click', function () {
                        loadPaymentForEdit($(this).data('tid'));
                        el.addClass('hidden');
                    });
                } else {
                    el.html('<div class="p-2 text-center text-gray-500 text-[10px]">No previous payments</div>');
                }
            }, 'json').fail(function () {
                el.html('<div class="p-2 text-center text-red-500 text-[10px]">Error loading list</div>');
            });
        }

        function loadPaymentForEdit(tid) {
            $.post('', { action: 'get_payment_out_details', transaction_id: tid }, function (res) {
                if (res.status !== 'success' || !res.data) {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to load' });
                    return;
                }
                var data = res.data;
                $('#editTransactionId').val(String(data.id));
                $('#paymentIdInput').val(data.receipt_id);
                $('#editModeIndicator').removeClass('hidden');
                var dateValue = '';
                if (data.date_of_transaction) {
                    var raw = String(data.date_of_transaction).replace(' ', 'T');
                    var dt = new Date(raw);
                    dateValue = !isNaN(dt.getTime()) ? dt.toISOString().slice(0, 16) : raw.substring(0, 16);
                }
                $('[name="date_of_transaction"]').val(dateValue);
                var pid = (data.party_id != null && String(data.party_id) !== '') ? parseInt(data.party_id, 10) : 0;
                if (pid > 0) {
                    $('#partyId').val(String(pid));
                    $('#partyNameInput').val((data.party_name || data.party_label || '').trim());
                    selectedPartyName = (data.party_name || data.party_label || '').trim();
                } else {
                    $('#partyId').val('');
                    $('#partyNameInput').val((data.party_label || '').trim());
                    selectedPartyName = '';
                }
                if (window.KeyboardNavigation && typeof window.KeyboardNavigation.clearValidationError === 'function') {
                    window.KeyboardNavigation.clearValidationError('partyNameInput');
                }
                $('#paymentAmount').val(data.payment_amount);
                $('[name="payment_method"]').val(data.payment_method);
                var narrOnly = (data.narration || '').replace(/^Payment sent - \S+\s*-?\s*/i, '').trim();
                $('[name="narration"]').val(narrOnly);
                var bt = String(data.booking_type || '').trim();
                if (!bt || bt === 'Payment_Out') {
                    bt = 'Payment_Sent';
                }
                var $pt = $('[name="payment_type"]');
                $pt.val(bt);
                if (!$pt.val()) {
                    $pt.val('Payment_Sent');
                }
                refreshPaymentTypeHint();
                $('#sendPaymentBtn').html('<i class="fas fa-paper-plane mr-1"></i><span>Update payment</span>').attr('class', psSaveBtnClass);
                $('#paymentOutFormCard').css('border', '2px solid #f97316');
                $('#deleteEditedPaymentBtn').removeClass('hidden');
                if (pid > 0) {
                    $.post('', { action: 'get_party_info', party_id: pid }, function (response) {
                        renderPartyInfoAlert(response);
                    }, 'json').fail(function () {
                        $('#partyInfoSection').addClass('hidden');
                    });
                } else {
                    $('#partyInfoSection').addClass('hidden');
                }
            }, 'json').fail(function () {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to load payment' });
            });
        }

        $(document).on('click', function (e) {
            if (!$(e.target).closest('#paymentList, #showPaymentListBtn, #paymentIdInput').length) {
                $('#paymentList').addClass('hidden');
            }
            if (!$(e.target).closest('#partyNameInput, #partyList, #addNewPartyBtn').length && partyListVisible) {
                $('#partyList').addClass('hidden');
                partyListVisible = false;
                currentIndex = -1;
            }
        });

        var partyListVisible = false;
        var currentIndex = -1;
        var selectedPartyName = '';

        function showAddPartyModal(partyName, form) {
            Swal.fire({
                title: 'Add new party',
                html: '<input id="swalPartyName" class="swal2-input" placeholder="Name" value="' + (partyName || '').replace(/"/g, '&quot;') + '">' +
                    '<input id="swalPartyAddr" class="swal2-input" placeholder="Address (optional)">' +
                    '<input id="swalPartyContact" class="swal2-input" placeholder="Contact (optional)">',
                showCancelButton: true,
                confirmButtonText: 'Create',
                confirmButtonColor: '#e11d48',
                preConfirm: function () {
                    var n = document.getElementById('swalPartyName').value.trim();
                    if (!n) { Swal.showValidationMessage('Name required'); return false; }
                    return {
                        name: n,
                        address: document.getElementById('swalPartyAddr').value.trim(),
                        contact: document.getElementById('swalPartyContact').value.trim()
                    };
                }
            }).then(function (r) {
                if (!r.isConfirmed) return;
                var v = r.value;
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=save_party&party_name=' + encodeURIComponent(v.name) + '&address=' + encodeURIComponent(v.address) + '&contact_no=' + encodeURIComponent(v.contact)
                }).then(function (x) { return x.json(); }).then(function (result) {
                    if (result.status === 'success') {
                        $('#partyId').val(result.party_id);
                        $('#partyNameInput').val(v.name);
                        selectedPartyName = v.name;
                        if (window.KeyboardNavigation && typeof window.KeyboardNavigation.clearValidationError === 'function') {
                            window.KeyboardNavigation.clearValidationError('partyNameInput');
                        }
                        Swal.fire({ icon: 'success', title: 'Created', timer: 1200, showConfirmButton: false });
                        if (form) { setTimeout(function () { $(form).trigger('submit'); }, 500); }
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: result.message || 'Failed' });
                    }
                });
            });
        }

        $('#addNewPartyBtn').on('click', function (e) {
            e.preventDefault();
            showAddPartyModal(($('#partyNameInput').val() || '').trim(), null);
        });

        function escPartyHtml(t) {
            return String(t ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
        }

        function appendCreatePartyRow(partyListEl, searchTerm) {
            var term = (searchTerm || '').trim();
            if (!term) return;
            var row = document.createElement('div');
            row.className = 'px-3 py-2 hover:bg-green-50 cursor-pointer transition-colors party-item bg-green-50 border-t-2 border-green-200';
            row.setAttribute('data-create-new', '1');
            row.innerHTML = '<div class="flex items-center gap-2"><i class="fas fa-plus-circle text-green-600"></i>' +
                '<div class="font-semibold text-[11px] text-green-700">Create new party &quot;' + escPartyHtml(term) + '&quot;</div></div>';
            row.addEventListener('mousedown', function (e) { e.preventDefault(); });
            row.addEventListener('click', function (e) {
                e.stopPropagation();
                $('#partyList').addClass('hidden');
                partyListVisible = false;
                currentIndex = -1;
                showAddPartyModal(term, null);
            });
            partyListEl.appendChild(row);
        }

        function updatePartyHighlight() {
            var partyItems = document.querySelectorAll('#partyList .party-item');
            partyItems.forEach(function (item, index) {
                if (index === currentIndex && currentIndex >= 0) {
                    item.classList.add('bg-yellow-100', 'border-l-4', 'border-amber-400');
                    item.classList.remove('hover:bg-yellow-50', 'hover:bg-green-50', 'bg-green-50');
                } else {
                    item.classList.remove('bg-yellow-100', 'border-l-4', 'border-amber-400');
                    if (item.getAttribute('data-create-new')) {
                        item.classList.add('hover:bg-green-50', 'bg-green-50');
                    } else {
                        item.classList.add('hover:bg-yellow-50');
                    }
                }
            });
            if (currentIndex >= 0 && currentIndex < partyItems.length) {
                partyItems[currentIndex].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }

        $('#partyNameInput').on('keydown', function (e) {
            var partyItems = document.querySelectorAll('#partyList .party-item');
            if (partyListVisible && partyItems.length > 0) {
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    e.stopPropagation();
                    currentIndex = currentIndex < 0 ? 0 : Math.min(currentIndex + 1, partyItems.length - 1);
                    updatePartyHighlight();
                    return;
                }
                if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    e.stopPropagation();
                    currentIndex = currentIndex <= 0 ? -1 : Math.max(currentIndex - 1, 0);
                    updatePartyHighlight();
                    return;
                }
                if (e.key === 'Enter') {
                    e.preventDefault();
                    e.stopPropagation();
                    var idx = currentIndex >= 0 ? currentIndex : 0;
                    var sel = partyItems[idx];
                    if (sel) {
                        if (sel.getAttribute('data-create-new')) {
                            sel.click();
                            return;
                        }
                        selectParty({
                            id: sel.getAttribute('data-id'),
                            party_name: sel.getAttribute('data-name')
                        });
                        var amt = document.getElementById('paymentAmount');
                        if (amt) { setTimeout(function () { amt.focus(); if (amt.select) amt.select(); }, 80); }
                    }
                    return;
                }
                if (e.key === 'Escape') {
                    e.preventDefault();
                    e.stopPropagation();
                    $('#partyList').addClass('hidden');
                    partyListVisible = false;
                    currentIndex = -1;
                    return;
                }
            }
        });

        $('#partyNameInput').on('input', function () {
            var term = $(this).val();
            if (term !== selectedPartyName) {
                selectedPartyName = '';
                $('#partyId').val('');
            }
            if (term.length < 1) {
                $('#partyList').addClass('hidden');
                partyListVisible = false;
                currentIndex = -1;
                $('#partyInfoSection').addClass('hidden');
                return;
            }
            $.post('', { action: 'search_parties', term: term }, function (parties) {
                var partyList = $('#partyList');
                partyList.empty();
                currentIndex = -1;
                if (!parties || parties.length === 0) {
                    appendCreatePartyRow(partyList[0], term.trim());
                    partyList.removeClass('hidden');
                    partyListVisible = true;
                    return;
                }
                parties.forEach(function (party, index) {
                    var cb = parseFloat(party.cash_balance) || 0;
                    var bb = parseFloat(party.bank_balance) || 0;
                    var totalRaw = parseFloat(party.total_due_amount);
                    var ledger = !isNaN(totalRaw) ? totalRaw : (cb + bb);
                    var pname = escPartyHtml(party.party_name || '');
                    var addr = escPartyHtml((party.address || '').trim() || 'No address');
                    var partyItem = document.createElement('div');
                    partyItem.className = 'px-3 py-2 hover:bg-yellow-50 cursor-pointer border-b border-gray-100 last:border-b-0 transition-colors party-item';
                    partyItem.setAttribute('data-index', String(index));
                    partyItem.setAttribute('data-id', party.id || '');
                    partyItem.setAttribute('data-name', party.party_name || '');
                    partyItem.innerHTML =
                        '<div class="flex justify-between items-start gap-2">' +
                        '<div class="font-bold text-[11px] text-slate-800 uppercase tracking-tight leading-tight">' + pname + '</div>' +
                        '<div class="text-[10px] text-slate-400 font-medium truncate max-w-[130px] text-right">' + addr + '</div></div>' +
                        '<div class="flex flex-wrap items-center gap-x-3 gap-y-0.5 mt-1">' +
                        '<div class="text-[10px] text-rose-600 font-bold tracking-tight"><i class="fas fa-wallet mr-1 opacity-70"></i>₹' + ledger.toLocaleString('en-IN') + '</div>' +
                        '<div class="text-[9px] text-slate-500 font-semibold">C ₹' + cb.toLocaleString('en-IN') + ' · B ₹' + bb.toLocaleString('en-IN') + '</div></div>';
                    partyItem.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
                    partyItem.addEventListener('click', function (ev) {
                        ev.stopPropagation();
                        selectParty({
                            id: partyItem.getAttribute('data-id'),
                            party_name: partyItem.getAttribute('data-name')
                        });
                    });
                    partyList[0].appendChild(partyItem);
                });
                appendCreatePartyRow(partyList[0], term.trim());
                partyList.removeClass('hidden');
                partyListVisible = true;
            }, 'json');
        });

        function inr(n) {
            var x = parseFloat(n);
            if (isNaN(x)) x = 0;
            return '₹' + x.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function renderPartyInfoAlert(r) {
            if (!r) {
                $('#partyInfoSection').addClass('hidden');
                return;
            }
            var cash = parseFloat(r.cash_balance) || 0;
            var bank = parseFloat(r.bank_balance) || 0;
            var cashPay = cash < 0 ? Math.abs(cash) : 0;
            var bankPay = bank < 0 ? Math.abs(bank) : 0;

            var box = '<div class="grid grid-cols-2 gap-2">' +
                '<div class="min-w-0">' +
                '<label class="block text-[8px] font-bold text-slate-500 uppercase tracking-tight mb-0.5">Cash payable</label>' +
                '<div class="font-mono text-[11px] font-bold text-slate-900 bg-white border border-slate-200 rounded px-1.5 py-1 leading-none truncate" title="Owed on cash leg; paying with Method Cash reduces this">' + inr(cashPay) + '</div></div>' +
                '<div class="min-w-0">' +
                '<label class="block text-[8px] font-bold text-slate-500 uppercase tracking-tight mb-0.5">Bank payable</label>' +
                '<div class="font-mono text-[11px] font-bold text-slate-900 bg-white border border-slate-200 rounded px-1.5 py-1 leading-none truncate" title="Owed on bank leg; paying with Method Bank/UPI/Cheque reduces this">' + inr(bankPay) + '</div></div>' +
                '</div>' +
                '<p class="text-[8px] text-slate-500 mt-1 leading-tight">Choose <b>Method</b> below — cash payment lowers cash payable; bank lowers bank payable.</p>';

            $('#partyInfoAlert').html(box);
            $('#partyInfoSection').removeClass('hidden');
        }

        function selectParty(party) {
            selectedPartyName = party.party_name || '';
            $('#partyId').val(party.id != null && party.id !== '' ? String(party.id) : '');
            $('#partyNameInput').val(party.party_name || '');
            $('#partyList').addClass('hidden');
            partyListVisible = false;
            currentIndex = -1;
            if (window.KeyboardNavigation && typeof window.KeyboardNavigation.clearValidationError === 'function') {
                window.KeyboardNavigation.clearValidationError('partyNameInput');
            }
            var pid = parseInt(party.id, 10) || 0;
            if (pid <= 0) {
                $('#partyInfoSection').addClass('hidden');
                return;
            }
            $.post('', { action: 'get_party_info', party_id: pid }, function (response) {
                renderPartyInfoAlert(response);
            }, 'json').fail(function () {
                $('#partyInfoSection').addClass('hidden');
            });
        }

        function resetPaymentForm() {
            $('#partyNameInput').val('');
            $('#partyId').val('');
            $('#partyInfoSection').addClass('hidden');
            selectedPartyName = '';
            $('[name="payment_amount"]').val('');
            $('[name="payment_method"]').val('');
            $('[name="payment_type"]').val('');
            refreshPaymentTypeHint();
            $('[name="narration"]').val('');
            $('#editTransactionId').val('');
            $('#editModeIndicator').addClass('hidden');
            $('#deleteEditedPaymentBtn').addClass('hidden');
            $('#paymentOutFormCard').css('border', '');
            $('#sendPaymentBtn').html('<i class="fas fa-paper-plane mr-1"></i><span>Send payment</span>').attr('class', psSaveBtnClass);
            initializeForm().then(function () { $('#partyNameInput').focus(); });
        }

        $('#resetFormBtn').on('click', function () {
            Swal.fire({ title: 'Reset form?', icon: 'question', showCancelButton: true, confirmButtonText: 'Yes' }).then(function (r) {
                if (r.isConfirmed) { resetPaymentForm(); }
            });
        });

        function confirmDeleteSend(tid, label, amt) {
            Swal.fire({
                title: 'Delete payment?',
                html: 'Delete <b>' + label + '</b> — ₹' + parseFloat(amt).toLocaleString('en-IN') + '?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                confirmButtonText: 'Delete'
            }).then(function (r) {
                if (!r.isConfirmed) return;
                $.post('', { action: 'delete_payment_out', transaction_id: tid }, function (res) {
                    if (res.status === 'success') {
                        Swal.fire({ icon: 'success', title: 'Deleted', timer: 1200, showConfirmButton: false });
                        setTimeout(function () { location.reload(); }, 800);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed' });
                    }
                }, 'json');
            });
        }

        $('#deleteEditedPaymentBtn').on('click', function () {
            var tid = parseInt($('#editTransactionId').val(), 10) || 0;
            if (tid <= 0) return;
            var label = ($('#paymentIdInput').val() || '').trim() || ('OUT#' + tid);
            confirmDeleteSend(tid, label, parseFloat($('[name="payment_amount"]').val()) || 0);
        });

        $(document).on('click', '.delete-send-btn', function () {
            confirmDeleteSend($(this).data('id'), $(this).data('display-id') || $(this).data('receipt-id'), $(this).data('amount'));
        });

        $(document).on('click', '.edit-send-btn', function () {
            loadPaymentForEdit($(this).data('id'));
            $('html, body').animate({ scrollTop: $('#paymentOutFormCard').offset().top - 80 }, 400);
        });

        $(document).on('click', '.print-send-btn', function () {
            window.open('print_payment_receipt.php?id=' + $(this).data('id'), '_blank');
        });

        $('#paymentOutForm').on('submit', function (e) {
            e.preventDefault();
            var form = this;
            var editId = parseInt($('#editTransactionId').val(), 10) || 0;

            if (editId > 0) {
                var udata = {
                    action: 'update_payment_out',
                    transaction_id: editId,
                    receipt_id: $('#paymentIdInput').val(),
                    date_of_transaction: $('[name="date_of_transaction"]').val(),
                    party_id: $('#partyId').val(),
                    payment_amount: $('[name="payment_amount"]').val(),
                    payment_method: $('[name="payment_method"]').val(),
                    payment_type: $('[name="payment_type"]').val(),
                    narration: $('[name="narration"]').val()
                };
                if (!udata.party_id || parseFloat(udata.payment_amount) <= 0) {
                    Swal.fire({ icon: 'error', title: 'Check party and amount' });
                    return;
                }
                $.post('', udata, function (res) {
                    if (res.status === 'success') {
                        Swal.fire({ icon: 'success', title: 'Updated', timer: 1500, showConfirmButton: false });
                        resetPaymentForm();
                        setTimeout(function () { location.reload(); }, 1500);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Update failed' });
                    }
                }, 'json').fail(function () {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Request failed' });
                });
                return;
            }

            var partyId = $('#partyId').val();
            if (!partyId) {
                var pn = $('#partyNameInput').val().trim();
                if (pn) { showAddPartyModal(pn, form); }
                else { Swal.fire({ icon: 'error', title: 'Select party' }); $('#partyNameInput').focus(); }
                return;
            }

            Swal.fire({
                title: 'Confirm send',
                html: '<div class="text-left text-sm">Paying <b>' + ($('[name="party_name"]').val() || '') + '</b><br>₹' + parseFloat($('[name="payment_amount"]').val()).toLocaleString('en-IN') + '</div>',
                showCancelButton: true,
                confirmButtonText: 'Send',
                confirmButtonColor: '#e11d48'
            }).then(function (r) {
                if (!r.isConfirmed) return;
                var fd = new FormData(form);
                $.ajax({
                    url: '', method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
                    success: function (response) {
                        if (response.status === 'success') {
                            Swal.fire({ icon: 'success', title: 'Sent', timer: 1800, showConfirmButton: false });
                            resetPaymentForm();
                            setTimeout(function () { location.reload(); }, 1800);
                        } else {
                            Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed' });
                        }
                    },
                    error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Request failed' }); }
                });
            });
        });

        if ($(window).width() >= 992) {
            setTimeout(function () { $('#partyNameInput').focus(); }, 400);
        }

        $('#startDate, #endDate').on('change', function () {
            var startDate = new Date($('#startDate').val());
            var endDate = new Date($('#endDate').val());
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
})();
</script>

<?php
// Capture the content
$content = ob_get_clean();

// Set page title and include layout
$page_title = "Send Payment";
include 'components/layout.php';
?>
