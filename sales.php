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
require_once __DIR__ . '/helpers/gold_rate_helper.php';
require_once __DIR__ . '/helpers/receipt_id_helper.php';

if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error . '. Please run setup_database.php first.');
}

// Get user and company info
$company_id = $_SESSION['company_id'];
$user_id = $_SESSION['user_id'];
$company_name = $_SESSION['company_name'];
$user_name = $_SESSION['full_name'];
$gold_rate_unit = gold_rate_get_unit($conn, $company_id);
$gold_rate_label = gold_rate_label($gold_rate_unit);
$gold_rate_suffix = gold_rate_suffix($gold_rate_unit);

// Get Company State for GST Calculations
$company_state = '';
$state_q = $conn->query("SELECT state FROM companies WHERE id = $company_id");
if ($state_res = $state_q->fetch_assoc()) {
    $company_state = $state_res['state'] ?? '';
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

/** Link sale lines to gold_stock.id so Gold vs Silver / Cash vs Bank deduct the correct row. */
function sales_ensure_sale_item_stock_ref(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $r = $conn->query("SHOW COLUMNS FROM `gold_sale_items` LIKE 'stock_ref_id'");
    if ($r && $r->num_rows > 0) {
        return;
    }
    if (!$conn->query("ALTER TABLE `gold_sale_items` ADD COLUMN `stock_ref_id` INT NULL DEFAULT NULL AFTER `purity`")) {
        error_log('sales: ALTER gold_sale_items.stock_ref_id failed: ' . $conn->error);
    }
}

/**
 * Always resolve to one gold_stock row id (never purity-only), so Silver does not hit Gold.
 */
function sales_resolve_stock_row_id(mysqli $conn, int $company_id, array $item): int
{
    $from_post = (int) ($item['stock_id'] ?? 0);
    if ($from_post > 0) {
        $q = $conn->query('SELECT id FROM gold_stock WHERE id = ' . $from_post . ' AND company_id = ' . (int) $company_id . ' LIMIT 1');
        if ($q && $q->num_rows > 0) {
            return $from_post;
        }
    }
    $sn = $conn->real_escape_string(trim((string) ($item['stock_name'] ?? '')));
    $p = floatval($item['purity'] ?? 0);
    $cat = trim((string) ($item['category'] ?? ''));
    $md = trim((string) ($item['mode'] ?? ''));
    if ($sn !== '' && $p > 0 && $cat !== '' && $md !== '') {
        $ce = $conn->real_escape_string($cat);
        $me = $conn->real_escape_string($md);
        $q = $conn->query("SELECT id FROM gold_stock WHERE company_id = $company_id AND stock_name = '$sn' AND purity = $p AND category = '$ce' AND mode = '$me' LIMIT 1");
        if ($q && $r = $q->fetch_assoc()) {
            return (int) $r['id'];
        }
    }
    if ($sn !== '' && $p > 0) {
        $looks_silver = stripos($sn, 'silver') !== false || strcasecmp($cat, 'Silver') === 0;
        $pref_cat = $looks_silver ? 'Silver' : 'Gold';
        $ce = $conn->real_escape_string($pref_cat);
        $q = $conn->query("SELECT id FROM gold_stock WHERE company_id = $company_id AND stock_name = '$sn' AND purity = $p AND category = '$ce' ORDER BY id ASC LIMIT 1");
        if ($q && $r = $q->fetch_assoc()) {
            return (int) $r['id'];
        }
        $q = $conn->query("SELECT id FROM gold_stock WHERE company_id = $company_id AND stock_name = '$sn' AND purity = $p ORDER BY (category = 'Silver') DESC, id ASC LIMIT 1");
        if ($q && $r = $q->fetch_assoc()) {
            return (int) $r['id'];
        }
    }

    return 0;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'search_parties':
                $search = $conn->real_escape_string($_POST['term']);
                $sql = "SELECT DISTINCT p.id, p.party_name, p.address, p.contact_no, p.state,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' THEN t.gold_weight ELSE 0 END), 0) as booked_weight,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Sale' THEN t.gold_weight ELSE 0 END), 0) as sold_weight,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' THEN t.gold_amount ELSE 0 END), 0) as booked_amount,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Payment' AND t.payment_type = 'Payment_In' AND (t.payment_method = 'Cash' OR t.payment_method IS NULL OR t.payment_method = '') THEN t.payment_amount ELSE 0 END), 0) as cash_received,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Payment' AND t.payment_type = 'Payment_In' AND t.payment_method IN ('Bank', 'UPI', 'Cheque') THEN t.payment_amount ELSE 0 END), 0) as bank_received
                        FROM parties p 
                        LEFT JOIN transactions t ON p.id = t.party_id AND t.company_id = $company_id
                        WHERE p.company_id = $company_id AND p.party_name LIKE '%$search%' 
                        GROUP BY p.id
                        ORDER BY p.party_name
                        LIMIT 10";
                $result = $conn->query($sql);
                $parties = [];
                while ($row = $result->fetch_assoc()) {
                    $booked_weight = $row['booked_weight'];
                    $booked_amount = $row['booked_amount'];
                    $available_weight = max(0, $booked_weight - $row['sold_weight']);
                    $avg_rate = ($booked_weight > 0) ? ($booked_amount / $booked_weight) : 0;

                    $parties[] = [
                        'id' => $row['id'],
                        'party_name' => $row['party_name'],
                        'address' => $row['address'],
                        'contact_no' => $row['contact_no'],
                        'state' => $row['state'],
                        'booked_weight' => number_format($booked_weight, 2),
                        'sold_weight' => number_format($row['sold_weight'], 2),
                        'available_weight' => number_format($available_weight, 2),
                        'avg_rate' => number_format(gold_rate_to_display($avg_rate, $gold_rate_unit), 2),
                        'cash_received' => number_format($row['cash_received'], 2),
                        'bank_received' => number_format($row['bank_received'], 2)
                    ];
                }
                ob_clean();
                echo json_encode($parties);
                exit;

            case 'get_party_stats':
            case 'get_party_gold_balance':
                $party_id = intval($_POST['party_id']);

                // Debug: Get all payment transactions for this party
                $debug_sql = "SELECT id, receipt_id, transaction_type, payment_amount, payment_method, payment_type 
                             FROM transactions 
                             WHERE party_id = $party_id AND company_id = $company_id 
                             AND transaction_type = 'Payment'";
                $debug_result = $conn->query($debug_sql);
                $debug_payments = [];
                while ($debug_row = $debug_result->fetch_assoc()) {
                    $debug_payments[] = $debug_row;
                }

                // Get detailed booking information with different rates
                $detailed_bookings_sql = "SELECT 
                    t.receipt_id, 
                    t.date_of_transaction, 
                    t.gold_weight, 
                    t.rate, 
                    t.gold_amount, 
                    t.booking_type,
                    (t.gold_weight - COALESCE((
                        SELECT SUM(t2.gold_weight) 
                        FROM transactions t2 
                        WHERE t2.party_id = t.party_id 
                        AND t2.company_id = t.company_id 
                        AND t2.transaction_type = 'Sale' 
                        AND t2.booking_type = t.booking_type 
                        AND t2.date_of_transaction >= t.date_of_transaction
                    ), 0)) as remaining_weight
                    FROM transactions t 
                    WHERE t.party_id = $party_id 
                    AND t.company_id = $company_id 
                    AND t.transaction_type = 'Booking' 
                    ORDER BY t.date_of_transaction DESC";

                $detailed_result = $conn->query($detailed_bookings_sql);
                $detailed_bookings = [];
                while ($booking_row = $detailed_result->fetch_assoc()) {
                    $remaining_weight = max(0, floatval($booking_row['remaining_weight']));
                    if ($remaining_weight > 0) {
                        $detailed_bookings[] = [
                            'receipt_id' => $booking_row['receipt_id'],
                            'date' => $booking_row['date_of_transaction'],
                            'weight' => floatval($booking_row['gold_weight']),
                            'rate' => gold_rate_to_display(floatval($booking_row['rate']), $gold_rate_unit),
                            'amount' => floatval($booking_row['gold_amount']),
                            'booking_type' => $booking_row['booking_type'],
                            'remaining_weight' => $remaining_weight
                        ];
                    }
                }

                $sql = "SELECT 
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' THEN t.gold_weight ELSE 0 END), 0) as booked_weight,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Sale' THEN t.gold_weight ELSE 0 END), 0) as sold_weight,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' AND t.booking_type = 'Cash' THEN t.gold_weight ELSE 0 END), 0) as booked_weight_cash,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Sale' AND t.booking_type = 'Cash' THEN t.gold_weight ELSE 0 END), 0) as sold_weight_cash,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' AND t.booking_type = 'Bank' THEN t.gold_weight ELSE 0 END), 0) as booked_weight_bank,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Sale' AND t.booking_type = 'Bank' THEN t.gold_weight ELSE 0 END), 0) as sold_weight_bank,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' THEN t.gold_amount ELSE 0 END), 0) as booked_amount,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Payment' AND t.payment_type = 'Payment_In' THEN t.payment_amount ELSE 0 END), 0) as advance_received,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Payment' AND t.payment_type = 'Payment_In' AND (t.payment_method = 'Cash' OR t.payment_method IS NULL OR t.payment_method = '') THEN t.payment_amount ELSE 0 END), 0) as cash_received,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Payment' AND t.payment_type = 'Payment_In' AND t.payment_method = 'Bank' THEN t.payment_amount ELSE 0 END), 0) as bank_received
                        FROM transactions t 
                        WHERE t.party_id = $party_id AND t.company_id = $company_id";
                $result = $conn->query($sql);
                if ($result && $result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $available_weight = max(0, $row['booked_weight'] - $row['sold_weight']);
                    $available_weight_cash = max(0, $row['booked_weight_cash'] - $row['sold_weight_cash']);
                    $available_weight_bank = max(0, $row['booked_weight_bank'] - $row['sold_weight_bank']);
                    $remaining_amount = $row['booked_amount'] - $row['advance_received'];
                    $avg_rate = ($row['booked_weight'] > 0) ? ($row['booked_amount'] / $row['booked_weight']) : 0;

                    // Get party's current balance from parties table
                    $party_balance_sql = "SELECT (cash_balance + bank_balance) as tot_bal, gold_balance FROM parties WHERE id = ? AND company_id = ?";
                    $party_balance_stmt = $conn->prepare($party_balance_sql);
                    $party_balance_stmt->bind_param("ii", $party_id, $company_id);
                    $party_balance_stmt->execute();
                    $party_balance_result = $party_balance_stmt->get_result();
                    $party_balance = $party_balance_result->fetch_assoc();

                    // Ensure all values are numeric, not null
                    $cash_received = floatval($row['cash_received'] ?? 0);
                    $bank_received = floatval($row['bank_received'] ?? 0);

                    $data = [
                        'booked_weight' => floatval($row['booked_weight'] ?? 0),
                        'sold_weight' => floatval($row['sold_weight'] ?? 0),
                        'available_weight' => floatval($available_weight),
                        'booked_weight_cash' => floatval($row['booked_weight_cash'] ?? 0),
                        'sold_weight_cash' => floatval($row['sold_weight_cash'] ?? 0),
                        'available_weight_cash' => floatval($available_weight_cash),
                        'booked_weight_bank' => floatval($row['booked_weight_bank'] ?? 0),
                        'sold_weight_bank' => floatval($row['sold_weight_bank'] ?? 0),
                        'available_weight_bank' => floatval($available_weight_bank),
                        'booked_amount' => floatval($row['booked_amount'] ?? 0),
                        'advance_received' => floatval($row['advance_received'] ?? 0),
                        'remaining_amount' => floatval($remaining_amount),
                        'avg_rate' => gold_rate_to_display(floatval($avg_rate), $gold_rate_unit),
                        'cash_received' => floatval($cash_received),
                        'bank_received' => floatval($bank_received),
                        'current_balance' => floatval($party_balance['tot_bal'] ?? 0),
                        'current_gold_balance' => floatval($party_balance['gold_balance'] ?? 0),
                        'detailed_bookings' => $detailed_bookings,
                        'debug_payments' => $debug_payments
                    ];
                } else {
                    $data = [
                        'booked_weight' => 0,
                        'sold_weight' => 0,
                        'available_weight' => 0,
                        'booked_weight_cash' => 0,
                        'sold_weight_cash' => 0,
                        'available_weight_cash' => 0,
                        'booked_weight_bank' => 0,
                        'sold_weight_bank' => 0,
                        'available_weight_bank' => 0,
                        'booked_amount' => 0,
                        'advance_received' => 0,
                        'remaining_amount' => 0,
                        'avg_rate' => '0.00',
                        'cash_received' => '0.00',
                    ];
                }
                ob_clean();
                echo json_encode($data);
                exit;
            case 'save_party':
                $party_name = $conn->real_escape_string($_POST['party_name']);
                $address = $conn->real_escape_string($_POST['address']);
                $contact_no = $conn->real_escape_string($_POST['contact_no']);
                $gstin = $conn->real_escape_string($_POST['gstin'] ?? 'N/A');
                $state = $conn->real_escape_string($_POST['state'] ?? '');
                $city = $conn->real_escape_string($_POST['city'] ?? '');
                $bank_name = $conn->real_escape_string($_POST['bank_name'] ?? '');
                $account_no = $conn->real_escape_string($_POST['account_no'] ?? '');
                $ifsc_code = $conn->real_escape_string($_POST['ifsc_code'] ?? '');

                $cash_balance = floatval($_POST['cash_balance'] ?? 0);
                $bank_balance = floatval($_POST['bank_balance'] ?? 0);
                $gold_balance = floatval($_POST['gold_balance'] ?? 0);
                $silver_balance = floatval($_POST['silver_balance'] ?? 0);

                $conn->begin_transaction();
                try {
                    $sql = "INSERT INTO parties (company_id, party_name, address, contact_no, gstin, state, city, bank_name, account_no, ifsc_code, cash_balance, bank_balance, gold_balance, silver_balance) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("isssssssssdddd", $company_id, $party_name, $address, $contact_no, $gstin, $state, $city, $bank_name, $account_no, $ifsc_code, $cash_balance, $bank_balance, $gold_balance, $silver_balance);
                    $stmt->execute();
                    $new_id = $stmt->insert_id;
                    $conn->commit();
                    ob_clean();
                    echo json_encode(['status' => 'success', 'message' => 'Party added successfully', 'party_id' => $new_id]);
                } catch (Exception $e) {
                    $conn->rollback();
                    ob_clean();
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
                exit;

            case 'generate_sale_id':
                $saleId = next_receipt_id($conn, $company_id, 'S', [
                    'transaction_type' => 'Sale',
                    'pad_length' => 3,
                ]);

                echo json_encode([
                    'status' => 'success',
                    'sale_id' => $saleId
                ]);
                exit;

            case 'save_sell':
                sales_ensure_sale_item_stock_ref($conn);
                $conn->begin_transaction();
                try {
                    $receipt_id_input = trim((string)($_POST['receipt_id'] ?? ''));
                    $receipt_id = $conn->real_escape_string($receipt_id_input);
                    $party_id = intval($_POST['party_id']);
                    $date_of_transaction = $conn->real_escape_string($_POST['date_of_transaction']);
                    $sell_weight = floatval($_POST['sell_weight']);
                    $purity = floatval($_POST['purity']);
                    $rate = gold_rate_from_display(floatval($_POST['rate']), $gold_rate_unit);
                    $gold_amount = floatval(str_replace(',', '', $_POST['amount']));
                    
                    // NEW: Field support
                    $mode = $conn->real_escape_string($_POST['mode'] ?? 'Cash');
                    $taxable_amount = floatval($_POST['taxable_amount'] ?? 0);
                    $cgst = floatval($_POST['cgst'] ?? 0);
                    $sgst = floatval($_POST['sgst'] ?? 0);
                    $igst = floatval($_POST['igst'] ?? 0);
                    $total_gst = floatval($_POST['total_gst'] ?? 0);

                    $sell_items_json = $_POST['sell_items'] ?? null;
                    $additional_cash = floatval($_POST['additional_cash'] ?? 0);
                    $additional_bank = floatval($_POST['additional_bank'] ?? 0);
                    $payment_amount = $additional_cash + $additional_bank;
                    $payment_method = $conn->real_escape_string($_POST['payment_method'] ?? 'Cash');
                    $narration = $conn->real_escape_string($_POST['narration'] ?? '');

                    if ($receipt_id_input === '' || empty($party_id) || $sell_weight <= 0) {
                        throw new Exception("Required fields missing or invalid values");
                    }

                    $receipt_id = $conn->real_escape_string(ensure_unique_receipt_id(
                        $conn,
                        $company_id,
                        'S',
                        $receipt_id_input,
                        ['transaction_type' => 'Sale', 'pad_length' => 3]
                    ));
                    $receipt_id_input = $receipt_id;

                    // Fetch Party Data and Balance (calculate total from cash + bank)
                    $party_sql = "SELECT party_name, (cash_balance + bank_balance) as current_balance, cash_balance, bank_balance FROM parties WHERE id = $party_id AND company_id = $company_id";
                    $party_result = $conn->query($party_sql);
                    if (!$party_result || $party_result->num_rows === 0) throw new Exception("Party not found");
                    $party_data = $party_result->fetch_assoc();
                    $bal_before = floatval($party_data['current_balance']);
                    $adv_before = 0; // Calculated if needed, or stick to balance logic

                    // Conditional Payment Info
                    $pay_meth_val = ($payment_amount > 0) ? $payment_method : null;
                    $pay_type_val = ($payment_amount > 0) ? 'Payment_In' : null;
                    $rcpt_meth_val = ($payment_amount > 0) ? $payment_method : null;
                    $gold_val_calc = $sell_weight * $rate;

                    $sql = "INSERT INTO transactions (
                        company_id, user_id, party_id, receipt_id, transaction_type, date_of_transaction,
                        gold_weight, purity, rate, gold_amount, payment_amount, payment_method, payment_type, 
                        receipt_method, mode, amount, taxable_amount, cgst, sgst, igst, total_gst,
                        party_balance_before, party_balance_after, narration, payment_status, created_by
                    ) VALUES (?, ?, ?, ?, 'Sale', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                    $payment_status = ($payment_amount >= $gold_amount) ? 'Paid' : (($payment_amount > 0) ? 'Partial' : 'Due');
                    $bal_after = $bal_before - $gold_amount; 

                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param(
                        "iiissdddddssssddddddddssi",
                        $company_id, $user_id, $party_id, $receipt_id_input, $date_of_transaction,
                        $sell_weight, $purity, $rate, $gold_val_calc, $payment_amount, 
                        $pay_meth_val, $pay_type_val, $rcpt_meth_val, $mode, $gold_amount,
                        $taxable_amount, $cgst, $sgst, $igst, $total_gst,
                        $bal_before, $bal_after, $narration, $payment_status, $user_id
                    );
                    $stmt->execute();
                    $transaction_id = $stmt->insert_id;

                    // Handle Items and Stock
                    if ($sell_items_json) {
                        $items = json_decode($sell_items_json, true);
                        if (is_array($items)) {
                            foreach ($items as $item) {
                                $w = floatval($item['weight']);
                                if ($w <= 0) {
                                    continue;
                                }
                                $f = floatval($item['fine']);
                                $r = gold_rate_from_display(floatval($item['rate'] ?? $rate), $gold_rate_unit);
                                $a = round($w * $r);
                                $stock_id = sales_resolve_stock_row_id($conn, $company_id, $item);
                                if ($stock_id <= 0) {
                                    throw new Exception('Each line with weight needs a stock selection (Gold or Silver row).');
                                }
                                $st_row = $conn->query("SELECT id, stock_name, purity, category, mode FROM gold_stock WHERE id = $stock_id AND company_id = $company_id LIMIT 1");
                                if (!$st_row || !($grow = $st_row->fetch_assoc())) {
                                    throw new Exception('Stock row not found for deduction.');
                                }
                                $sn = $conn->real_escape_string($grow['stock_name']);
                                $p = floatval($grow['purity']);
                                $ref_sql = (string) intval($grow['id']);

                                $conn->query("INSERT INTO gold_sale_items (company_id, transaction_id, receipt_id, stock_name, gold_weight, purity, stock_ref_id, fine_weight, rate, amount) 
                                             VALUES ($company_id, $transaction_id, '$receipt_id', '$sn', $w, $p, $ref_sql, $f, $r, $a)");

                                if (!$conn->query("UPDATE gold_stock SET current_stock = current_stock - $w WHERE id = $stock_id AND company_id = $company_id LIMIT 1")) {
                                    throw new Exception('Stock update failed: ' . $conn->error);
                                }
                            }
                        }
                    }

                    // Update Party Balance and Settlements
                    $adv_use = min($adv_before, $gold_amount);
                    if ($adv_use > 0) {
                        $adv_rcid = 'ADV-' . $receipt_id . '-' . rand(100,999);
                        $conn->query("INSERT INTO transactions (company_id, party_id, receipt_id, transaction_type, date_of_transaction, gold_amount, payment_amount, payment_method, payment_type, narration) 
                                     VALUES ($company_id, $party_id, '$adv_rcid', 'Advance_Settlement', '$date_of_transaction', 0, $adv_use, 'Advance', 'Payment_Out', 'Advance used for $receipt_id')");
                    }
                    if ($additional_cash > 0) {
                        $csh_rcid = 'CSH-' . $receipt_id . '-' . rand(100,999);
                        $conn->query("INSERT INTO transactions (company_id, party_id, receipt_id, transaction_type, date_of_transaction, payment_amount, payment_method, payment_type, narration) 
                                     VALUES ($company_id, $party_id, '$csh_rcid', 'Received', '$date_of_transaction', $additional_cash, 'Cash', 'Payment_In', 'Cash for $receipt_id')");
                        require_once __DIR__ . '/handlers/account_balance_helper.php';
                        updateAccountBalance($conn, $company_id, 'Cash', $additional_cash);
                    }
                    if ($additional_bank > 0) {
                        $bnk_rcid = 'BNK-' . $receipt_id . '-' . rand(100,999);
                        $conn->query("INSERT INTO transactions (company_id, party_id, receipt_id, transaction_type, date_of_transaction, payment_amount, payment_method, payment_type, narration) 
                                     VALUES ($company_id, $party_id, '$bnk_rcid', 'Received', '$date_of_transaction', $additional_bank, 'Bank', 'Payment_In', 'Bank for $receipt_id')");
                        require_once __DIR__ . '/handlers/account_balance_helper.php';
                        updateAccountBalance($conn, $company_id, 'Bank', $additional_bank);
                    }

                    $cb = ($mode == 'Cash' ? $gold_amount : 0) - $adv_use - $additional_cash;
                    $bb = ($mode == 'Bank' ? $gold_amount : 0) - $additional_bank;
                    $conn->query("UPDATE parties SET cash_balance=cash_balance+$cb, bank_balance=bank_balance+$bb WHERE id=$party_id");

                    $conn->commit();
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Sale saved successfully',
                        'transaction_id' => (int) $transaction_id,
                        'receipt_id' => $receipt_id_input,
                    ]);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
                exit;

            case 'get_sell_list':
                try {
                    $sql = "SELECT t.*, p.party_name, p.contact_no as party_contact,
                           (SELECT GROUP_CONCAT(DISTINCT gsi.stock_name ORDER BY gsi.id SEPARATOR ', ')
                            FROM gold_sale_items gsi
                            WHERE gsi.transaction_id = t.id AND gsi.company_id = t.company_id) AS sale_stock_names
                           FROM transactions t 
                           LEFT JOIN parties p ON t.party_id = p.id
                           WHERE t.transaction_type = 'Sale'
                           AND t.company_id = ?
                           ORDER BY t.date_of_transaction DESC, t.id DESC 
                           LIMIT 10";
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        throw new Exception("Failed to prepare statement: " . $conn->error);
                    }
                    $stmt->bind_param("i", $company_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $sells = [];
                    while ($row = $result->fetch_assoc()) {
                        gold_rate_apply_display_to_row($row, $gold_rate_unit);
                        $sells[] = [
                            'id' => $row['id'],
                            'receipt_id' => $row['receipt_id'],
                            'party_name' => $row['party_name'],
                            'party_contact' => $row['party_contact'],
                            'date_of_transaction' => $row['date_of_transaction'],
                            'gold_weight' => $row['gold_weight'],
                            'rate' => $row['rate'],
                            'gold_amount' => $row['gold_amount'],
                            'purity' => $row['purity'],
                            'party_id' => $row['party_id'],
                            'payment_type' => $row['payment_type'] ?? 'Cash',
                            'booking_type' => $row['booking_type'] ?? '',
                            'receipt_method' => $row['receipt_method'] ?? '',
                            'mode' => $row['mode'] ?? '',
                            'narration' => $row['narration'] ?? '',
                            'sale_stock_names' => $row['sale_stock_names'] ?? '',
                        ];
                    }
                    echo json_encode($sells);
                } catch (Exception $e) {
                    error_log("Get sell list error: " . $e->getMessage());
                    echo json_encode(['error' => 'Failed to get sell list']);
                }
                exit;

            case 'get_sell_details':
                try {
                    $receipt_id = $conn->real_escape_string($_POST['receipt_id'] ?? '');
                    $sql = "SELECT t.*, p.party_name, p.state as party_state FROM transactions t LEFT JOIN parties p ON t.party_id = p.id WHERE t.company_id = ? AND t.receipt_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("is", $company_id, $receipt_id);
                    $stmt->execute();
                    $transaction = $stmt->get_result()->fetch_assoc();
                    if (!$transaction) throw new Exception("Sale not found");
                    
                    $items_sql = "SELECT * FROM gold_sale_items WHERE transaction_id = ? ORDER BY id";
                    $items_stmt = $conn->prepare($items_sql);
                    $items_stmt->bind_param("i", $transaction['id']);
                    $items_stmt->execute();
                    $res = $items_stmt->get_result();
                    $items = [];
                    while ($it = $res->fetch_assoc()) {
                        gold_rate_apply_display_to_row($it, $gold_rate_unit);
                        $items[] = $it;
                    }
                    gold_rate_apply_display_to_row($transaction, $gold_rate_unit);
                    $transaction['items'] = $items;
                    echo json_encode(['status' => 'success'] + $transaction);
                } catch (Exception $e) {
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
                exit;

            case 'get_purity_stocks':
                $stocks_sql = "SELECT DISTINCT purity, stock_name, current_stock FROM gold_stock WHERE company_id = $company_id ORDER BY purity DESC";
                $stocks_result = $conn->query($stocks_sql);
                $stocks = [];
                if ($stocks_result) {
                    while ($row = $stocks_result->fetch_assoc()) {
                        $stocks[] = ['purity' => $row['purity'], 'stock_name' => $row['stock_name'], 'current_stock' => $row['current_stock']];
                    }
                }
                echo json_encode(['success' => true, 'stocks' => $stocks]);
                exit;

            case 'update_sale':
            case 'delete_sale':
                sales_ensure_sale_item_stock_ref($conn);
                $conn->begin_transaction();
                try {
                    $receipt_id = $conn->real_escape_string($_POST['original_receipt_id'] ?? $_POST['receipt_id'] ?? '');
                    if (empty($receipt_id)) throw new Exception("Receipt ID required");

                    // 1. Get Main Transaction
                    $sale_q = $conn->query("SELECT id, party_id, gold_weight, gold_amount, purity, booking_type FROM transactions WHERE receipt_id = '$receipt_id' AND transaction_type = 'Sale' AND company_id = $company_id");
                    if (!$sale_q || $sale_q->num_rows == 0) throw new Exception("Sale not found: $receipt_id");
                    $sale = $sale_q->fetch_assoc();
                    $tid = $sale['id'];
                    $pid = $sale['party_id'];

                    // 2. Revert Stock per Item
                    $items_q = $conn->query("SELECT stock_name, gold_weight, purity, stock_ref_id FROM gold_sale_items WHERE transaction_id = $tid");
                    while ($it = $items_q->fetch_assoc()) {
                        $wt = floatval($it['gold_weight']);
                        $ref = intval($it['stock_ref_id'] ?? 0);
                        if ($ref > 0) {
                            $conn->query("UPDATE gold_stock SET current_stock = current_stock + $wt WHERE id = $ref AND company_id = $company_id LIMIT 1");
                        } else {
                            $sn = $conn->real_escape_string($it['stock_name'] ?? '');
                            $pr = floatval($it['purity']);
                            if ($sn !== '') {
                                $conn->query("UPDATE gold_stock SET current_stock = current_stock + $wt WHERE stock_name = '$sn' AND purity = $pr AND company_id = $company_id LIMIT 1");
                            }
                        }
                    }

                    // 3. Revert Linked Account Balances
                    $linked_q = $conn->query("SELECT payment_amount, payment_method, transaction_type FROM transactions WHERE narration LIKE '%$receipt_id%' AND company_id = $company_id");
                    require_once __DIR__ . '/handlers/account_balance_helper.php';
                    while ($link = $linked_q->fetch_assoc()) {
                        if ($link['transaction_type'] == 'Received') {
                            $amt = $link['payment_amount'];
                            $method = ($link['payment_method'] == 'Cash') ? 'Cash' : 'Bank';
                            updateAccountBalance($conn, $company_id, $method, -$amt);
                        }
                    }

                    // 4. Revert Party Balance
                    $orig_amt = $sale['gold_amount'];
                    $orig_wt = $sale['gold_weight'];
                    $btype = $sale['booking_type'] == 'Bank' ? 'bank_balance' : 'cash_balance';
                    // Reverse the save_sell impact
                    $conn->query("UPDATE parties SET $btype = $btype - ($orig_amt) WHERE id = $pid");
                    
                    // 5. Delete everything
                    $conn->query("DELETE FROM gold_sale_items WHERE transaction_id = $tid");
                    $conn->query("DELETE FROM transactions WHERE (receipt_id = '$receipt_id' OR narration LIKE '%$receipt_id%') AND company_id = $company_id");

                    $conn->commit();
                    echo json_encode(['status' => 'success', 'message' => 'Operation successful']);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
                exit;

        }
    }
}

function formatIndianNumber($num)
{
    $num = round($num, 2);
    $decimal = "";

    if (strpos($num, '.') !== false) {
        list($num, $decimal) = explode('.', $num);
        $decimal = "." . substr($decimal, 0, 2);
    }

    $last3 = substr($num, -3);
    $rest = substr($num, 0, -3);

    if ($rest != '') {
        $rest = preg_replace("/\B(?=(\d{2})+(?!\d))/", ",", $rest);
    }

    return ($rest != '') ? $rest . ',' . $last3 . $decimal : $last3 . $decimal;
}

// Get date range from user input (default: today only)
$start_date = (isset($_GET['start_date']) && $_GET['start_date'] !== '') ? $conn->real_escape_string($_GET['start_date']) : date('Y-m-d');
$end_date = (isset($_GET['end_date']) && $_GET['end_date'] !== '') ? $conn->real_escape_string($_GET['end_date']) : date('Y-m-d');

// Enhanced statistics SQL query for sell page (reflects the same date range as the list below)
$stats_sql = "
SELECT 
    SUM(CASE WHEN transaction_type = 'Booking' THEN gold_weight ELSE 0 END) AS total_booking_weight,
    SUM(CASE WHEN transaction_type = 'Sale' THEN gold_weight ELSE 0 END) AS total_sale_weight,
    SUM(CASE WHEN transaction_type = 'Sale' AND UPPER(TRIM(COALESCE(NULLIF(TRIM(mode), ''), receipt_method, 'Cash'))) != 'BANK' THEN gold_weight ELSE 0 END) AS total_sale_weight_cash,
    SUM(CASE WHEN transaction_type = 'Sale' AND UPPER(TRIM(COALESCE(NULLIF(TRIM(mode), ''), receipt_method, 'Cash'))) = 'BANK' THEN gold_weight ELSE 0 END) AS total_sale_weight_bank,
    SUM(CASE WHEN transaction_type = 'Purchase' THEN gold_weight ELSE 0 END) AS total_purchase_weight,
    SUM(gold_amount) AS total_amount,
    COUNT(DISTINCT party_id) AS total_parties,
    COUNT(*) AS total_transactions,
    SUM(CASE WHEN payment_type = 'Payment_In' THEN payment_amount ELSE 0 END) AS total_paid_amount,
    SUM(CASE WHEN payment_type = 'Payment_Out' THEN payment_amount ELSE 0 END) AS total_due_amount,
    SUM(CASE WHEN payment_type = 'Payment_Out' AND payment_method = 'Cash' THEN payment_amount ELSE 0 END) AS total_cash_due_today,
    SUM(CASE WHEN payment_type = 'Payment_Out' AND (payment_method = 'Bank' OR payment_method = 'UPI' OR payment_method = 'Cheque') THEN payment_amount ELSE 0 END) AS total_bank_due_today,
    
    -- Payment method breakdown
    SUM(CASE WHEN transaction_type = 'Received' AND payment_method = 'Cash' THEN payment_amount ELSE 0 END) AS total_cash_received,
    SUM(CASE WHEN transaction_type = 'Received' AND (payment_method = 'Bank' OR payment_method = 'UPI' OR payment_method = 'Cheque') THEN payment_amount ELSE 0 END) AS total_bank_received,
    
    -- Booking type breakdown
    SUM(CASE WHEN transaction_type = 'Booking' AND booking_type = 'Cash' THEN gold_weight ELSE 0 END) AS total_cash_booking_weight,
    SUM(CASE WHEN transaction_type = 'Booking' AND booking_type = 'Bank' THEN gold_weight ELSE 0 END) AS total_bank_booking_weight,
    SUM(CASE WHEN transaction_type = 'Booking' AND booking_type = 'Cash' THEN gold_amount ELSE 0 END) AS total_cash_booking_amount,
    SUM(CASE WHEN transaction_type = 'Booking' AND booking_type = 'Bank' THEN gold_amount ELSE 0 END) AS total_bank_booking_amount
FROM transactions
WHERE DATE(date_of_transaction) BETWEEN '$start_date' AND '$end_date' AND company_id = $company_id";

$stats_result = $conn->query($stats_sql);
if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
} else {
    $stats = [
        'total_booking_weight' => 0,
        'total_sale_weight' => 0,
        'total_sale_weight_cash' => 0,
        'total_sale_weight_bank' => 0,
        'total_purchase_weight' => 0,
        'total_amount' => 0,
        'total_parties' => 0,
        'total_transactions' => 0,
        'total_paid_amount' => 0,
        'total_due_amount' => 0,
        'total_cash_due_today' => 0,
        'total_bank_due_today' => 0,
        'total_cash_received' => 0,
        'total_bank_received' => 0,
        'total_cash_booking_weight' => 0,
        'total_bank_booking_weight' => 0,
        'total_cash_booking_amount' => 0,
        'total_bank_booking_amount' => 0
    ];
}

// Each vault row (Gold / Silver, Cash / Bank) — one dropdown option per row (no purity-only merge)
$stock_sql = "SELECT id, stock_name, purity, current_stock, mode, category FROM gold_stock WHERE company_id = $company_id ORDER BY category ASC, purity DESC, mode ASC, stock_name ASC";
$stock_result = $conn->query($stock_sql);
$sell_stock_rows = [];
$sell_stock_options_inner = '';
if ($stock_result) {
    while ($stock_row = $stock_result->fetch_assoc()) {
        if (stripos((string) $stock_row['stock_name'], 'mix') !== false) {
            continue;
        }
        $sell_stock_rows[] = $stock_row;
        $is_silver = (isset($stock_row['category']) && strcasecmp((string) $stock_row['category'], 'Silver') === 0)
            || stripos((string) $stock_row['stock_name'], 'silver') !== false;
        $pfx = $is_silver ? 'Ag · ' : 'Au · ';
        $mode_lbl = $stock_row['mode'] ?? 'Cash';
        $p_disp = number_format((float) $stock_row['purity'], 2);
        $opt_label = $pfx . $stock_row['stock_name'] . ' (' . $p_disp . '%) · ' . $mode_lbl;
        $sell_stock_options_inner .= sprintf(
            '<option value="%d" data-purity="%s" data-stock-name="%s" data-category="%s" data-mode="%s">%s</option>',
            (int) $stock_row['id'],
            htmlspecialchars((string) $stock_row['purity'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string) $stock_row['stock_name'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string) ($stock_row['category'] ?? 'Gold'), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string) ($stock_row['mode'] ?? 'Cash'), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($opt_label, ENT_QUOTES, 'UTF-8')
        );
    }
}

// Get cash & bank balances from account_balances table
$cash_in_hand = 0;
$bank_balance_shop = 0;
// Cash Account
$cash_sql = "SELECT current_balance FROM account_balances WHERE company_id = $company_id AND account_type = 'Cash'";
$cash_result = $conn->query($cash_sql);
if ($cash_result && $cash_result->num_rows > 0) {
    $cash_in_hand = $cash_result->fetch_assoc()['current_balance'] ?? 0;
}
// Bank Account
$bank_shop_sql = "SELECT SUM(current_balance) as total FROM account_balances WHERE company_id = $company_id AND account_type = 'Bank'";
$bank_shop_result = $conn->query($bank_shop_sql);
if ($bank_shop_result) {
    $bank_balance_shop = $bank_shop_result->fetch_assoc()['total'] ?? 0;
}

// Get recent sell transactions for the selected date range (defaults to today, shows ALL matches - no pagination cap)
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$where_clause = $search ? "AND (p.party_name LIKE '%$search%' OR t.receipt_id LIKE '%$search%')" : '';
$date_clause = " AND DATE(t.date_of_transaction) BETWEEN '$start_date' AND '$end_date' ";

$transactions_sql = "SELECT t.*, p.party_name, p.contact_no as party_contact,
                    (SELECT GROUP_CONCAT(DISTINCT gsi.stock_name ORDER BY gsi.id SEPARATOR ', ')
                     FROM gold_sale_items gsi
                     WHERE gsi.transaction_id = t.id AND gsi.company_id = t.company_id) AS sale_stock_names
                    FROM transactions t 
                    LEFT JOIN parties p ON t.party_id = p.id
                    WHERE t.transaction_type = 'Sale' 
                    AND t.company_id = $company_id
                    $date_clause
                    $where_clause 
                    ORDER BY t.date_of_transaction DESC, t.id DESC";

$transactions = $conn->query($transactions_sql);
$total_transactions = $transactions ? $transactions->num_rows : 0;
?>
<style>
    .soft-gradient-blue {
        background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.05));
    }

    .soft-gradient-green {
        background: linear-gradient(135deg, rgba(34, 197, 94, 0.1), rgba(34, 197, 94, 0.05));
    }

    .soft-gradient-orange {
        background: linear-gradient(135deg, rgba(249, 115, 22, 0.1), rgba(249, 115, 22, 0.05));
    }

    .soft-gradient-purple {
        background: linear-gradient(135deg, rgba(168, 85, 247, 0.1), rgba(168, 85, 247, 0.05));
    }

    .soft-gradient-teal {
        background: linear-gradient(135deg, rgba(20, 184, 166, 0.1), rgba(20, 184, 166, 0.05));
    }

    .soft-gradient-yellow {
        background: linear-gradient(135deg, rgba(234, 179, 8, 0.1), rgba(234, 179, 8, 0.05));
    }

    .soft-gradient-cyan {
        background: linear-gradient(135deg, rgba(6, 182, 212, 0.1), rgba(6, 182, 212, 0.05));
    }

    .readonly-field {
        background-color: #F8F9FA;
        cursor: not-allowed;
    }

    #partyList {
        position: absolute !important;
        top: 100% !important;
        left: 0 !important;
        right: 0 !important;
        width: 100% !important;
        z-index: 1000 !important;
    }

    @media (max-width: 1600px) {
        .compact-label {
            font-size: 0.65rem !important;
            margin-bottom: 0.1rem !important;
        }

        .compact-input {
            padding-top: 0.4rem !important;
            padding-bottom: 0.4rem !important;
            font-size: 0.75rem !important;
        }

        .stats-card {
            padding: 0.6rem !important;
        }

        .stats-icon {
            width: 1.75rem !important;
            height: 1.75rem !important;
        }
    }

    .stats-card-label { font-size: 10px; font-weight: 500; letter-spacing: 0.02em; color: rgb(100 116 139); }
    .stats-card-value { font-size: 1rem; font-weight: 600; color: rgb(51 65 85); font-variant-numeric: tabular-nums; }
    .stats-metal-split { display: flex; flex-wrap: wrap; align-items: center; gap: 0.15rem 0.45rem; font-size: 0.8125rem; font-weight: 600; letter-spacing: 0.02em; line-height: 1.35; margin-top: 0.35rem; font-variant-numeric: tabular-nums; color: rgb(51 65 85); }
    .stats-metal-split .metal-seg { display: inline-flex; align-items: center; gap: 0.2rem; }
    .stats-metal-split .metal-num { font-weight: 700; font-size: 0.8125rem; }
    .stats-metal-split .metal-unit { font-size: 0.6875rem; font-weight: 600; color: rgb(100 116 139); margin-left: 0.02rem; }
    .stats-metal-split .metal-icon-gold { color: #b45309; font-size: 0.625rem; line-height: 1; }
    .stats-metal-split .metal-icon-silver { color: #475569; font-size: 0.625rem; line-height: 1; }
    .stats-icon-wrap { width: 2rem; height: 2rem; border-radius: 0.5rem; display: flex; align-items: center; justify-content: center; }

    /* Keep the Recent Sales list within a fixed viewport height and scroll internally
       instead of growing the whole page when there are many sales for the day. */
    .ge-txn-scroll {
        max-height: calc(100vh - 300px);
        min-height: 220px;
        overflow-y: auto;
        overflow-x: auto;
    }

    .ge-txn-scroll thead th {
        position: sticky;
        top: 0;
        z-index: 5;
    }

    .ge-txn-scroll::-webkit-scrollbar {
        width: 6px;
        height: 6px;
    }

    .ge-txn-scroll::-webkit-scrollbar-thumb {
        background: rgba(148, 163, 184, 0.5);
        border-radius: 3px;
    }

    .ge-txn-scroll::-webkit-scrollbar-track {
        background: transparent;
    }
</style>
<div class="px-1 pb-4">
    <!-- Stats Cards -->
    <div class="overflow-x-auto pb-1">
        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3 mb-4 min-w-[36rem] sm:min-w-0">
            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase">Sold wt</p>
                        <p class="stats-metal-split stats-metal-split--hero">
                            <span class="metal-seg" title="Cash sales weight">
                                <i class="fas fa-wallet metal-icon-gold" aria-hidden="true"></i>
                                <span class="metal-num"><?php echo number_format((float)($stats['total_sale_weight_cash'] ?? 0), 2); ?></span><span class="metal-unit">g</span>
                            </span>
                            <span class="text-slate-300" aria-hidden="true">·</span>
                            <span class="metal-seg" title="Bank sales weight">
                                <i class="fas fa-university metal-icon-silver" aria-hidden="true"></i>
                                <span class="metal-num"><?php echo number_format((float)($stats['total_sale_weight_bank'] ?? 0), 2); ?></span><span class="metal-unit">g</span>
                            </span>
                        </p>
                    </div>
                    <div class="stats-icon-wrap bg-slate-100 stats-icon shrink-0">
                        <i class="fas fa-shopping-cart text-slate-600 text-xs"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase">Received</p>
                        <p class="stats-metal-split stats-metal-split--hero">
                            <span class="metal-seg" title="Cash received">
                                <i class="fas fa-wallet metal-icon-gold" aria-hidden="true"></i>
                                <span class="metal-num">₹<?php echo number_format($stats['total_cash_received'] ?? 0, 0); ?></span>
                            </span>
                            <span class="text-slate-300" aria-hidden="true">·</span>
                            <span class="metal-seg" title="Bank received">
                                <i class="fas fa-university metal-icon-silver" aria-hidden="true"></i>
                                <span class="metal-num">₹<?php echo number_format($stats['total_bank_received'] ?? 0, 0); ?></span>
                            </span>
                        </p>
                    </div>
                    <div class="stats-icon-wrap bg-teal-100 stats-icon shrink-0">
                        <i class="fas fa-arrow-up text-teal-600 text-xs"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="stats-card-label uppercase">Cash in hand</p>
                        <p class="stats-card-value leading-tight">₹<?php echo number_format($cash_in_hand, 0); ?></p>
                    </div>
                    <div class="stats-icon-wrap bg-cyan-100 stats-icon">
                        <i class="fas fa-wallet text-cyan-600 text-xs"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="stats-card-label uppercase">Bank balance</p>
                        <p class="stats-card-value leading-tight">₹<?php echo number_format($bank_balance_shop, 0); ?></p>
                    </div>
                    <div class="stats-icon-wrap bg-violet-100 stats-icon">
                        <i class="fas fa-university text-violet-600 text-xs"></i>
                    </div>
                </div>
            </div>

            <?php foreach ($sell_stock_rows as $stock):
                $is_ag = (isset($stock['category']) && strcasecmp((string) $stock['category'], 'Silver') === 0)
                    || stripos((string) $stock['stock_name'], 'silver') !== false;
                if ($is_ag) {
                    continue;
                }
                $tot = floatval($stock['current_stock']);
                if ($tot <= 0) {
                    continue;
                }
                $cash_part = strtolower((string) ($stock['mode'] ?? 'Cash')) === 'cash' ? $tot : 0;
                $bank_part = strtolower((string) ($stock['mode'] ?? '')) === 'bank' ? $tot : 0;
            ?>
            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase">
                            <span class="text-amber-700">Au</span>
                            <?php echo htmlspecialchars($stock['stock_name']); ?>
                            <span class="text-slate-400 font-normal"><?php echo number_format((float) $stock['purity'], 2); ?>%</span>
                        </p>
                        <p class="stats-metal-split">
                            <span class="metal-seg" title="Cash stock">
                                <i class="fas fa-wallet metal-icon-gold" aria-hidden="true"></i>
                                <span class="metal-num"><?php echo number_format($cash_part, 2); ?></span><span class="metal-unit">g</span>
                            </span>
                            <span class="text-slate-300" aria-hidden="true">·</span>
                            <span class="metal-seg" title="Bank stock">
                                <i class="fas fa-university metal-icon-silver" aria-hidden="true"></i>
                                <span class="metal-num"><?php echo number_format($bank_part, 2); ?></span><span class="metal-unit">g</span>
                            </span>
                        </p>
                    </div>
                    <div class="stats-icon-wrap bg-amber-100 stats-icon shrink-0">
                        <i class="fas fa-box text-amber-700 text-xs"></i>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Main Content -->
    <div class="flex flex-col lg:flex-row gap-3">
        <!-- Left: Sell Form -->
        <div class="bg-white rounded-lg shadow-md border border-gray-200" style="flex: 0 0 55%;">
            <form id="sellForm" onsubmit="return false;" class="overflow-hidden">

                <!-- Section 1: Transaction Details -->
                <div class="bg-blue-50 px-3 py-1 border-b border-blue-100">
                    <h3 class="text-xs font-bold text-blue-800 flex items-center">
                        <i class="fas fa-file-invoice mr-1.5 text-xs"></i> Transaction Details
                    </h3>
                </div>
                <div class="p-2 grid grid-cols-12 gap-1.5">
                    <div class="relative col-span-3">
                        <label
                            class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Sale
                            ID</label>
                        <div class="relative">
                            <span
                                class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-gray-500"><i
                                    class="fas fa-hashtag text-xs"></i></span>
                            <input type="text" name="receipt_id" id="saleIdInput" tabindex="0"
                                class="block w-full pl-7 pr-8 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-blue-400 compact-input cursor-pointer"
                                placeholder="Auto..." readonly title="Click for recent sales to load">
                            <button type="button" class="absolute right-1 top-1/2 -translate-y-1/2 text-gray-400 hover:text-blue-600 p-0.5" id="showSaleListBtn" title="Recent sales / Load to edit" aria-label="Open sales list">
                                <i class="fas fa-history text-xs"></i>
                            </button>
                        </div>
                        <div id="saleList" class="hidden absolute z-[60] mt-1 bg-white border border-gray-200 rounded-md shadow-lg max-h-72 overflow-y-auto w-[min(100%,20rem)] left-0 text-[9px] leading-tight"></div>
                    </div>
                    <div class="relative col-span-3">
                        <label
                            class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Date</label>
                        <div class="relative">
                            <span
                                class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-purple-500"><i
                                    class="fas fa-calendar-alt text-xs"></i></span>
                            <input type="datetime-local" name="date_of_transaction"
                                class="block w-full pl-7 pr-1 py-1.5 text-[11px] font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-purple-400 compact-input"
                                required>
                        </div>
                    </div>
                    <div class="relative col-span-6">
                        <label
                            class="block text-[10px] font-bold text-gray-700 mb-0.5 flex items-center justify-between uppercase tracking-tighter compact-label">
                            <span>Party Name</span><button type="button" id="addNewPartyBtn"
                                class="text-blue-600 hover:text-blue-800 font-bold transition-all hover:scale-105 active:scale-95 flex items-center uppercase tracking-tighter"><i
                                    class="fas fa-plus-circle mr-1 text-[10px]"></i> Add New</button>
                        </label>
                        <div class="relative">
                            <span
                                class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-blue-500"><i
                                    class="fas fa-user text-xs"></i></span>
                            <input type="text" name="party_name" id="partyNameInput"
                                class="block w-full pl-7 pr-2 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-blue-400 compact-input"
                                required placeholder="Select Party" autocomplete="off">
                            <input type="hidden" name="party_id" id="partyId">
                        </div>
                        <div id="partyList"
                            class="hidden absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                        </div>
                        <div id="partyDueInfoInline"
                            class="hidden mt-1 px-2 py-1 bg-amber-50 border border-amber-200 rounded flex items-center justify-between shadow-sm animate-pulse-once">
                            <div class="flex items-center gap-2">
                                <span class="text-[9px] font-bold text-amber-600 uppercase">Dues info:</span>
                                <span id="dueAmountValueInline"
                                    class="text-xs font-black text-amber-800 leading-none tracking-tighter">₹0</span>
                                <span
                                    class="text-[9px] font-bold text-slate-400 border-l border-slate-200 pl-1.5 uppercase">Gold:</span>
                                <span id="dueGoldValueInline"
                                    class="text-xs font-black text-amber-800 leading-none tracking-tighter">0.000g</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Items (Gold & Silver) -->
                <div class="bg-slate-50 px-3 py-1 border-t border-b border-slate-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-xs font-bold text-indigo-800 flex items-center">
                            <i class="fas fa-arrow-up mr-1.5 text-xs"></i> Items (To Sell) — Gold &amp; Silver
                        </h3>

                        <!-- Transaction Mode Toggle (Kachha/Pakka) -->
                        <div class="flex items-center gap-2">
                            <div class="inline-flex bg-white/50 rounded-lg p-0.5 border border-slate-200">
                                <button type="button" id="cashModeBtn"
                                    class="px-3 py-1 rounded text-[9px] font-bold uppercase transition-all flex items-center gap-1.5 bg-white text-indigo-600 shadow-sm border border-indigo-100">
                                    <i class="fas fa-money-bill-wave"></i> Cash
                                </button>
                                <button type="button" id="bankModeBtn"
                                    class="px-3 py-1 rounded text-[9px] font-bold uppercase transition-all flex items-center gap-1.5 text-slate-500 hover:text-blue-600">
                                    <i class="fas fa-university"></i> Bank
                                </button>
                            </div>
                            <input type="hidden" name="receipt_method" id="payModeInternal" value="Cash">
                            <input type="hidden" name="mode" id="modeHidden" value="Cash">
                            <input type="hidden" name="taxable_amount" id="taxableAmountHidden" value="0">
                            <input type="hidden" name="cgst" id="cgstHidden" value="0">
                            <input type="hidden" name="sgst" id="sgstHidden" value="0">
                            <input type="hidden" name="igst" id="igstHidden" value="0">
                            <input type="hidden" name="total_gst" id="totalGstHidden" value="0">

                            <button type="button" onclick="addSellItem()"
                                class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1 rounded text-[10px] font-bold shadow-sm transition-all hover:scale-105 active:scale-95 ml-2">
                                <i class="fas fa-plus mr-1"></i> Add Item
                            </button>
                        </div>
                    </div>
                </div>
                <div class="p-2">
                    <div class="border border-gray-200 rounded overflow-hidden">
                        <table class="w-full text-xs table-fixed">
                            <thead class="bg-slate-100">
                                <tr class="text-[9px] uppercase font-bold text-slate-600 tracking-tighter">
                                    <th class="px-2 py-1.5 text-left border-b" style="width: 5%;">#</th>
                                    <th class="px-2 py-1.5 text-left border-b" style="width: 35%;"><i
                                            class="fas fa-box text-blue-500 mr-1"></i>Stock</th>
                                    <th class="px-2 py-1.5 text-left border-b" style="width: 15%;"><i
                                            class="fas fa-weight text-slate-500 mr-1"></i>Weight</th>
                                    <th class="px-2 py-1.5 text-left border-b" style="width: 15%;"><i
                                            class="fas fa-tag text-orange-400 mr-1"></i>Rate (<?= htmlspecialchars($gold_rate_label) ?>)</th>
                                    <th class="px-2 py-1.5 text-left border-b" style="width: 22%;"><i
                                            class="fas fa-coins text-yellow-500 mr-1"></i>Amount</th>
                                    <th class="px-2 py-1.5 text-center border-b" style="width: 8%;">Act</th>
                                </tr>
                            </thead>
                        </table>
                        <div class="overflow-y-auto border-t border-gray-100" style="max-height: 120px;">
                            <table class="w-full text-xs table-fixed">
                                <tbody id="sellItemsTable">
                                    <tr class="sell-item-row group">
                                        <td class="px-2 py-1 border-b text-gray-500 font-bold item-number"
                                            style="width: 5%;">1</td>
                                        <td class="px-2 py-1 border-b" style="width: 35%;">
                                            <select
                                                class="w-full px-1 py-1 text-xs font-bold text-blue-800 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-blue-400 sell-item-select">
                                                <option value="" data-purity="0" data-stock-name="" data-category="" data-mode="">Select Stock</option>
                                                <?php echo $sell_stock_options_inner; ?>
                                            </select>
                                            <input type="hidden" class="sell-purity" value="0">
                                            <input type="hidden" class="sell-stock-id" value="">
                                            <input type="hidden" class="sell-stock-name" value="">
                                        </td>
                                        <td class="px-2 py-1 border-b" style="width: 15%;"><input type="number"
                                                step="0.001"
                                                class="w-full px-1 py-1 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-indigo-400 sell-weight"
                                                placeholder="0.000"></td>
                                        <td class="px-2 py-1 border-b" style="width: 15%;"><input type="number"
                                                step="0.01"
                                                class="w-full px-1 py-1 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-orange-400 sell-rate"
                                                placeholder="0.00"></td>
                                        <td class="px-2 py-1 border-b" style="width: 22%;"><input type="text"
                                                data-value="0"
                                                class="w-full px-1 py-1 text-[10px] font-bold text-gray-600 bg-gray-50 border border-gray-200 rounded sell-amount cursor-not-allowed"
                                                readonly></td>

                                        <input type="hidden" class="sell-fine" value="0">
                                        <td class="px-2 py-1 border-b text-center" style="width: 8%;">
                                            <button type="button" onclick="removeSellItem(this)"
                                                class="text-red-400 hover:text-red-600 text-xs transition-colors"><i
                                                    class="fas fa-trash-alt"></i></button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Fixed Totals Footer -->
                        <table class="w-full text-xs table-fixed border-t border-slate-200">
                            <tfoot class="bg-slate-50/50 font-bold">
                                <tr>
                                    <td colspan="2"
                                        class="px-2 py-2 text-right text-[10px] uppercase text-slate-500 font-bold tracking-tighter"
                                        style="width: 30%;">Totals:</td>
                                    <td class="px-2 py-2" style="width: 15%;">
                                        <div class="flex flex-col">
                                            <input type="text" id="totalSellWeight"
                                                class="w-full bg-transparent border-none text-[11px] font-semibold text-slate-800 p-0 cursor-not-allowed"
                                                readonly value="0.000">

                                        </div>
                                    </td>
                                    <td class="px-2 py-2" style="width: 15%;"></td>
                                    <td class="px-2 py-2" style="width: 22%;">
                                        <input type="text" id="totalAmountInput"
                                            class="w-full bg-transparent border-none text-xs font-semibold text-amber-700 p-0 cursor-not-allowed"
                                            readonly value="0.00">
                                    </td>
                                    <td colspan="2" style="width: 22%;"></td>
                                </tr>
                                <tr id="gstRow" class="hidden bg-blue-50/50 border-t border-blue-100">
                                    <td colspan="4"
                                        class="px-2 py-1 text-right text-[9px] font-semibold text-blue-600 uppercase tracking-tighter"
                                        id="gstLabel" style="width: 60%;">GST (3%):</td>
                                    <td class="px-2 py-1" style="width: 22%;">
                                        <input type="text" id="gstAmountInput"
                                            class="w-full bg-transparent border-none text-[10px] font-semibold text-blue-700 p-0 cursor-not-allowed"
                                            readonly value="0.00">
                                    </td>
                                    <td colspan="2" style="width: 22%;"></td>
                                </tr>
                                <tr id="roundOffRow" class="hidden bg-gray-50/50 border-t border-gray-100">
                                    <td colspan="4"
                                        class="px-2 py-1 text-right text-[9px] font-semibold text-gray-500 uppercase tracking-tighter"
                                        style="width: 60%;">Round Off:</td>
                                    <td class="px-2 py-1" style="width: 22%;">
                                        <input type="text" id="roundOffInput"
                                            class="w-full bg-transparent border-none text-[10px] font-semibold text-gray-500 p-0 cursor-not-allowed"
                                            readonly value="0.00">
                                    </td>
                                    <td colspan="2" style="width: 22%;"></td>
                                </tr>
                                <tr id="finalTotalRow" class="hidden bg-blue-600 border-t border-blue-700">
                                    <td colspan="4"
                                        class="px-2 py-1.5 text-right text-[10px] font-semibold text-white uppercase tracking-tighter"
                                        style="width: 60%;">FINAL TOTAL (INC. GST):</td>
                                    <td class="px-2 py-1.5" style="width: 22%;">
                                        <input type="text" id="finalTotalDisplay"
                                            class="w-full bg-transparent border-none text-xs font-semibold text-white p-0 cursor-not-allowed"
                                            readonly value="0.00">
                                    </td>
                                    <td colspan="2" style="width: 22%;"><input type="hidden" name="amount" id="finalAmountHidden"
                                            value="0"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <input type="hidden" id="totalSellFine" value="0.000">
                <input type="hidden" id="rateInput" name="rate" value="0">

                <!-- Hidden fields -->
                <input type="hidden" name="action" value="save_sell">
                <input type="hidden" name="sell_weight" id="sellWeightHidden">
                <input type="hidden" name="purity" id="purityHidden">
                <input type="hidden" name="additional_cash" id="additionalCash" value="0">
                <input type="hidden" name="additional_bank" id="additionalBank" value="0">
                <input type="hidden" name="payment_status" value="Due">
                <input type="hidden" name="payment_type" id="paymentType" value="Payment_Out">

                <!-- Section 4: Payment Details -->
                <div class="bg-indigo-50 px-3 py-1 border-t border-b border-indigo-100">
                    <h3 class="text-xs font-bold text-indigo-800 flex items-center">
                        <i class="fas fa-money-bill-wave mr-1.5 text-xs"></i> Payment Details
                    </h3>
                </div>
                <div class="p-2 grid grid-cols-3 gap-3">
                    <div>
                        <label
                            class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Paid
                            Amt (&#8377;)</label>
                        <div class="relative">
                            <span
                                class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-indigo-500"><i
                                    class="fas fa-wallet text-xs"></i></span>
                            <input type="number" step="0.01" name="payment_amount" id="paidAmountInput"
                                class="block w-full pl-7 pr-2 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-indigo-400 compact-input"
                                value="0">
                        </div>
                    </div>

                    <div>
                        <label
                            class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Method</label>
                        <div class="relative">
                            <span
                                class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-gray-500"><i
                                    class="fas fa-credit-card text-xs"></i></span>
                            <select name="payment_method" id="payMethodSelect"
                                class="block w-full pl-7 pr-2 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-gray-400 compact-input">
                                <option value="Cash">Cash</option>
                                <option value="Bank">Bank</option>
                                <option value="UPI">UPI</option>
                                <option value="Cheque">Cheque</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label
                            class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Narration</label>
                        <div class="relative">
                            <span
                                class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-gray-400"><i
                                    class="fas fa-comment-alt text-xs"></i></span>
                            <input type="text" name="narration" id="saleNarrationInput"
                                class="block w-full pl-7 pr-2 py-1.5 text-xs font-bold border border-gray-200 rounded focus:ring-1 focus:ring-gray-400 compact-input"
                                placeholder="Remarks...">
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="bg-gray-50 px-3 py-2 border-t border-gray-200 flex items-center gap-2 justify-end">
                    <button type="button" id="cancelEditBtn"
                        class="hidden px-3 py-1.5 bg-gray-500 text-white text-xs font-bold rounded hover:bg-gray-600 shadow-sm"><i
                            class="fas fa-times mr-1"></i>Cancel</button>
                    <button type="button" id="resetFormBtn"
                        class="px-3 py-1.5 bg-white border border-gray-300 text-gray-700 text-xs font-bold rounded hover:bg-gray-50 shadow-sm"
                        title="Reset"><i class="fas fa-undo"></i></button>
                    <button type="button" id="deleteSaleBtn"
                        class="hidden px-3 py-1.5 bg-red-600 text-white text-xs font-bold rounded hover:bg-red-700 shadow-sm"><i
                            class="fas fa-trash mr-1"></i>Delete</button>
                    <button type="button" id="updateSaleBtn"
                        class="hidden px-4 py-1.5 bg-blue-600 text-white text-xs font-bold rounded hover:bg-blue-700 shadow-sm"><i
                            class="fas fa-edit mr-1"></i>Update</button>
                    <button type="button" id="sellGoldBtn"
                        class="px-5 py-1.5 bg-indigo-600 text-white text-xs font-bold rounded hover:bg-indigo-700 shadow-sm"><i
                            class="fas fa-save mr-1"></i>Save</button>
                </div>
            </form>
        </div>

        <!-- Right: Recent Sales List -->
        <div class="bg-white rounded-lg shadow-md border border-gray-200" style="flex: 0 0 45%;">
            <div class="bg-blue-50 px-3 py-1.5 border-b border-blue-100 rounded-t-lg">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-xs font-bold text-blue-800 flex items-center">
                        <i class="fas fa-list mr-1.5 text-xs"></i> Recent Sales
                    </h2>
                    <form method="GET" action="" class="flex items-center gap-1.5">
                        <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>"
                            class="px-1.5 py-0.5 border border-gray-200 rounded text-[10px] w-24 focus:ring-1 focus:ring-blue-400 bg-white font-medium">
                        <span class="text-gray-400 text-[10px] font-bold">to</span>
                        <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>"
                            class="px-1.5 py-0.5 border border-gray-200 rounded text-[10px] w-24 focus:ring-1 focus:ring-blue-400 bg-white font-medium">
                        <button type="submit"
                            class="px-1.5 py-0.5 bg-blue-600 text-white text-[10px] font-bold rounded hover:bg-blue-700 shadow-sm"
                            title="Filter">
                            <i class="fas fa-filter text-[10px]"></i>
                        </button>
                    </form>
                </div>
            </div>
            <div class="p-2">
                <div class="ge-txn-scroll">
                    <table class="w-full text-sm text-left text-gray-500">
                        <thead class="bg-slate-50 border-b border-slate-100">
                            <tr>
                                <th class="py-2 px-2 text-left text-[9px] font-bold text-slate-500 w-14">Id</th>
                                <th class="py-2 px-1 text-center text-[9px] font-bold text-slate-500 w-7" title="Cash or Bank sale"><i class="fas fa-store text-[8px] text-slate-400" aria-hidden="true"></i></th>
                                <th class="py-2 px-2 text-left text-[9px] font-bold text-slate-500">Party</th>
                                <th class="py-2 px-2 text-left text-[9px] font-bold text-slate-500">Stock</th>
                                <th class="py-2 px-2 text-right text-[9px] font-bold text-slate-500">Weight</th>
                                <th class="py-2 px-2 text-right text-[9px] font-bold text-slate-500">Rate</th>
                                <th class="py-2 px-2 text-right text-[9px] font-bold text-slate-500">Amount</th>
                                <th class="py-2 px-2 text-right text-[9px] font-bold text-slate-500">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php
                            $transRows = ($transactions && $transactions->num_rows > 0);
                            if ($transRows):
                                foreach ($transactions as $t):
                                    $stock_label = trim((string)($t['sale_stock_names'] ?? ''));
                                    if ($stock_label === '') {
                                        $stock_label = '—';
                                    }
                                    $sale_mode = trim((string)($t['booking_type'] ?? ''));
                                    if ($sale_mode === '') {
                                        $sale_mode = trim((string)($t['receipt_method'] ?? $t['mode'] ?? 'Cash'));
                                    }
                                    if ($sale_mode === '') {
                                        $sale_mode = 'Cash';
                                    }
                                    $is_bank_sale = (strcasecmp($sale_mode, 'Bank') === 0);
                                ?>
                                    <tr class="hover:bg-slate-50 transition-colors border-b border-slate-100 last:border-b-0">
                                        <td class="py-1.5 px-2 align-top">
                                            <div class="text-[10px] font-bold text-blue-600 truncate">
                                                #<?php echo htmlspecialchars($t['receipt_id']); ?></div>
                                            <div class="text-[8px] font-bold text-slate-400 uppercase leading-tight">
                                                <?php echo date('d M', strtotime($t['date_of_transaction'])); ?></div>
                                        </td>
                                        <td class="py-1.5 px-1 align-top text-center">
                                            <?php if ($is_bank_sale): ?>
                                                <span class="inline-flex items-center justify-center w-5 h-5 rounded bg-indigo-50 text-indigo-700 border border-indigo-100" title="Bank sale">
                                                    <i class="fas fa-university text-[9px]" aria-hidden="true"></i>
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center justify-center w-5 h-5 rounded bg-emerald-50 text-emerald-700 border border-emerald-100" title="Cash sale">
                                                    <i class="fas fa-wallet text-[9px]" aria-hidden="true"></i>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-1.5 px-2 align-top">
                                            <div
                                                class="text-[10px] font-semibold text-slate-800 truncate max-w-[72px] uppercase"
                                                title="<?php echo htmlspecialchars($t['party_name'] ?? ''); ?>">
                                                <?php echo htmlspecialchars($t['party_name'] ?? '—'); ?></div>
                                        </td>
                                        <td class="py-1.5 px-2 align-top">
                                            <div class="text-[10px] font-medium text-slate-700 truncate max-w-[88px] leading-tight"
                                                title="<?php echo htmlspecialchars($stock_label); ?>">
                                                <?php echo htmlspecialchars($stock_label); ?></div>
                                        </td>
                                        <td class="py-1.5 px-2 align-top text-right">
                                            <div class="text-[10px] font-bold text-slate-700 leading-none">
                                                <?php echo number_format($t['gold_weight'] ?? 0, 3); ?><span
                                                    class="text-[8px] font-normal ml-0.5">g</span></div>
                                            <div class="text-[8px] font-bold text-slate-400 mt-0.5">
                                                <?php echo number_format($t['purity'] ?? 0, 1); ?>%</div>
                                        </td>
                                        <td class="py-1.5 px-2 align-top text-right">
                                            <div class="text-[10px] font-semibold text-slate-800 leading-none">&#8377;<?php echo number_format(gold_rate_to_display(floatval($t['rate'] ?? 0), $gold_rate_unit), 0); ?><span class="text-[8px] font-normal text-slate-500"><?= htmlspecialchars($gold_rate_suffix) ?></span></div>
                                        </td>
                                        <td class="py-1.5 px-2 align-top text-right">
                                            <div class="text-[10px] font-bold text-slate-800 leading-none">
                                                &#8377;<?php echo number_format($t['gold_amount'] ?? 0, 0); ?></div>
                                            <div class="mt-1">
                                                <?php $paid = $t['payment_amount'] ?? 0;
                                                $amt = $t['gold_amount'] ?? 0; ?>
                                                <?php if ($paid >= $amt && $amt > 0): ?><span
                                                        class="text-[7.5px] px-1 py-0.5 rounded bg-slate-100 text-slate-800 font-bold uppercase">Paid</span>
                                                <?php elseif ($paid > 0): ?><span
                                                        class="text-[7.5px] px-1 py-0.5 rounded bg-yellow-100 text-yellow-700 font-bold uppercase">Part</span>
                                                <?php else: ?><span
                                                        class="text-[7.5px] px-1 py-0.5 rounded bg-rose-100 text-rose-700 font-bold uppercase">Due</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="py-1.5 px-2 align-top">
                                            <div class="flex items-center justify-end gap-1">
                                                <button type="button" onclick="loadSale('<?php echo htmlspecialchars($t['receipt_id'], ENT_QUOTES, 'UTF-8'); ?>')"
                                                    class="text-blue-500 hover:text-blue-700 p-0.5" title="Edit">
                                                    <i class="fas fa-edit text-[9px]"></i>
                                                </button>
                                                <button type="button"
                                                    onclick="event.stopPropagation(); openSaleReceiptPrint(<?php echo (int)$t['id']; ?>);"
                                                    class="text-slate-500 hover:text-slate-800 p-0.5" title="Print receipt">
                                                    <i class="fas fa-print text-[9px]"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach;
                            else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-8 text-gray-500">
                                        <i class="fas fa-inbox text-2xl mb-2"></i><br>No sales found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// End content capture
$content = ob_get_clean();

// Start script capture
ob_start();
?>
<script>
    // Global configuration for dynamic tax logic
    const COMPANY_STATE = "<?php echo $company_state; ?>";
    window.GOLD_RATE_CONFIG = <?= json_encode(gold_rate_js_config($gold_rate_unit)) ?>;
</script>
<script src="js/gold-rate-utils.js"></script>
<script>

    function formatIndian(num, decimals = 2) {
        if (isNaN(num)) return '0.00';
        return new Intl.NumberFormat('en-IN', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        }).format(num);
    }

    function openSaleReceiptPrint(transactionId) {
        if (!transactionId) return null;
        const url = 'print_sale_receipt.php?id=' + encodeURIComponent(transactionId);
        const width = Math.min(1100, window.screen.availWidth - 20);
        const height = Math.min(820, window.screen.availHeight - 40);
        const left = window.screenX + Math.max(0, (window.outerWidth - width) / 2);
        const top = window.screenY + 20;
        const features = [
            'popup=yes', 'width=' + width, 'height=' + height,
            'left=' + Math.round(left), 'top=' + Math.round(top),
            'scrollbars=yes', 'resizable=yes', 'toolbar=no',
            'menubar=no', 'location=no', 'status=no'
        ].join(',');
        const win = window.open(url, 'saleReceiptPrint_' + transactionId, features);
        if (!win) {
            Swal.fire({
                icon: 'warning', title: 'Popup Blocked',
                html: 'Please allow popups, or <a href="' + url + '" target="_blank" rel="noopener">click here</a>.',
                confirmButtonText: 'OK'
            });
            return null;
        }
        win.focus();
        return win;
    }

    // Unified Party Modal Trigger
    window.openAddPartyModal = function (preName = '') {
        SharedPartyHandler.showAddPartyModal({
            prefillName: preName,
            apiPath: '', // Submit to current page
            onSuccess: (response, partyData) => {
                const partyNameInput = document.getElementById('partyNameInput');
                const partyIdInput = document.getElementById('partyId');

                partyNameInput.value = partyData.party_name;
                partyNameInput.dataset.state = partyData.state || '';
                partyIdInput.value = response.party_id;

                // Refresh totals for correct IGST vs CGST/SGST
                recalculateSellTotals();
                loadPartyDues(partyData.party_name);

                // Focus items
                const firstSelect = document.querySelector('.sell-item-select');
                if (firstSelect) {
                    for (let opt of firstSelect.options) {
                        if (opt.text.toLowerCase().includes('fine')) {
                            firstSelect.value = opt.value;
                            firstSelect.dispatchEvent(new Event('change'));
                            break;
                        }
                    }
                    firstSelect.focus();
                }
            }
        });
    };

    let sellItemCount = 1;

    // Stock options for JS (value = gold_stock.id; labels include Au/Ag)
    const stockOptionsHTML = '<option value="" data-purity="0" data-stock-name="" data-category="" data-mode="">Select Stock</option>' + <?php echo json_encode($sell_stock_options_inner, JSON_UNESCAPED_UNICODE); ?>;

    function addSellItem() {
        sellItemCount++;
        const tbody = document.getElementById('sellItemsTable');
        const mode = document.getElementById('payModeInternal').value;
        const rowModeClass = mode === 'Cash' ? 'bg-slate-50 text-slate-600 border border-slate-200' : 'bg-blue-50 text-blue-600 border border-blue-100';
        const rowModeText = mode;

        const row = document.createElement('tr');
        row.className = 'sell-item-row group';
        row.innerHTML = `
            <td class="px-2 py-1 border-b text-gray-500 font-bold item-number" style="width: 5%;">${sellItemCount}</td>
            <td class="px-2 py-1 border-b" style="width: 35%;">
                <select class="w-full px-1 py-1 text-xs font-bold text-blue-800 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-blue-400 sell-item-select">
                    ${stockOptionsHTML}
                </select>
                <input type="hidden" class="sell-purity" value="0">
                <input type="hidden" class="sell-stock-id" value="">
                <input type="hidden" class="sell-stock-name" value="">
            </td>
            <td class="px-2 py-1 border-b" style="width: 15%;"><input type="number" step="0.001" class="w-full px-1 py-1 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-indigo-400 sell-weight" placeholder="0.000"></td>
            <td class="px-2 py-1 border-b" style="width: 15%;"><input type="number" step="0.01" class="w-full px-1 py-1 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-orange-400 sell-rate" placeholder="0.00"></td>
            <td class="px-2 py-1 border-b" style="width: 22%;"><input type="text" data-value="0" class="w-full px-1 py-1 text-[10px] font-bold text-gray-600 bg-gray-50 border border-gray-200 rounded sell-amount cursor-not-allowed" readonly></td>
            
            <input type="hidden" class="sell-fine" value="0">
            <td class="px-2 py-1 border-b text-center" style="width: 8%;"><button type="button" onclick="removeSellItem(this)" class="text-red-400 hover:text-red-600 text-xs transition-colors"><i class="fas fa-trash-alt"></i></button></td>`;
        tbody.appendChild(row);
        bindSellItemEvents(row);
    }

    function removeSellItem(btn) {
        const rows = document.querySelectorAll('.sell-item-row');
        if (rows.length <= 1) return;
        btn.closest('tr').remove();
        renumberSellItems();
        recalculateSellTotals();
    }

    function renumberSellItems() {
        document.querySelectorAll('.sell-item-row').forEach((r, i) => {
            r.querySelector('.item-number').textContent = i + 1;
        });
        sellItemCount = document.querySelectorAll('.sell-item-row').length;
    }

    function bindSellItemEvents(row) {
        const itemSelect = row.querySelector('.sell-item-select');
        const wInput = row.querySelector('.sell-weight');
        const rInput = row.querySelector('.sell-rate');
        const pInput = row.querySelector('.sell-purity');
        const fInput = row.querySelector('.sell-fine');
        const aInput = row.querySelector('.sell-amount');

        const calc = () => {
            const opt = itemSelect.options[itemSelect.selectedIndex];
            const purity = parseFloat(opt?.dataset?.purity) || 0;
            const stockId = itemSelect.value || '';
            const stockName = opt?.dataset?.stockName || '';
            pInput.value = purity;
            const idEl = row.querySelector('.sell-stock-id');
            if (idEl) idEl.value = stockId;
            row.querySelector('.sell-stock-name').value = stockName;
            const w = parseFloat(wInput.value) || 0;
            const displayRate = parseFloat(rInput.value) || 0;
            const r = (window.GoldRateUtils && GoldRateUtils.effectivePerGram)
                ? GoldRateUtils.effectivePerGram(displayRate)
                : displayRate;

            const fine = (w * purity / 100);
            fInput.value = fine.toFixed(3);

            const amount = Math.round(w * r);
            aInput.dataset.value = amount; // Store raw for totals logic
            aInput.value = formatIndian(amount, 0);

            recalculateSellTotals();
        };

        itemSelect.addEventListener('change', calc);
        wInput.addEventListener('input', calc);
        rInput.addEventListener('input', calc);

        itemSelect.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                wInput.focus();
                wInput.select();
            }
        });
        wInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                rInput.focus();
                rInput.select();
            }
        });
        rInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const nextRow = row.nextElementSibling;
                const nextSelect = nextRow ? nextRow.querySelector('.sell-item-select') : null;
                if (nextSelect) {
                    nextSelect.focus();
                } else {
                    const paidAmt = document.getElementById('paidAmountInput');
                    if (paidAmt) { paidAmt.focus(); paidAmt.select(); }
                }
            }
        });

        calc();
    }

    function collectSellLineItem(row) {
        const w = parseFloat(row.querySelector('.sell-weight').value) || 0;
        if (w <= 0) return null;
        const sel = row.querySelector('.sell-item-select');
        const opt = sel.options[sel.selectedIndex];
        const sidFromSelect = parseInt(String(sel.value || ''), 10) || 0;
        const sidHidden = parseInt(String(row.querySelector('.sell-stock-id')?.value || ''), 10) || 0;
        const sid = sidFromSelect || sidHidden;
        const p = parseFloat(row.querySelector('.sell-purity').value) || 0;
        const f = parseFloat(row.querySelector('.sell-fine').value) || 0;
        const r = parseFloat(row.querySelector('.sell-rate').value) || 0;
        const sn = (row.querySelector('.sell-stock-name').value || opt?.dataset?.stockName || '').trim();
        const category = opt?.dataset?.category || '';
        const mode = opt?.dataset?.mode || '';
        return { weight: w, purity: p, fine: f, rate: r, stock_name: sn, stock_id: sid, category, mode };
    }

    function recalculateSellTotals() {
        let totalWeight = 0, totalFine = 0, totalAmount = 0;
        document.querySelectorAll('.sell-item-row').forEach(row => {
            totalWeight += parseFloat(row.querySelector('.sell-weight').value) || 0;
            totalFine += parseFloat(row.querySelector('.sell-fine').value) || 0;
            // Use data-value for totalAmount if it's formatted
            totalAmount += parseFloat(row.querySelector('.sell-amount').dataset.value) || 0;
        });

        document.getElementById('totalSellWeight').value = totalWeight.toFixed(3);
        const tfEl = document.getElementById('totalSellFine');
        if (tfEl) tfEl.value = totalFine.toFixed(3);
        document.getElementById('totalAmountInput').value = totalAmount.toFixed(2);

        const mode = document.getElementById('payModeInternal').value;
        const gstRow = document.getElementById('gstRow');
        const roundOffRow = document.getElementById('roundOffRow');
        const finalTotalRow = document.getElementById('finalTotalRow');

        let finalAmount = totalAmount;

        if (mode === 'Bank') {
            const state = partyNameInput.dataset.state || '';
            const myState = COMPANY_STATE || 'West Bengal';
            let gstRate = 0.03;
            let gstLabelText = 'IGST (3%):';

            // Default to CGST/SGST if no state is specified OR if state matches company state
            if (!state || (myState && state.toLowerCase().trim() === myState.toLowerCase().trim())) {
                gstLabelText = 'CGST (1.5%) + SGST (1.5%):';
            }

            const gstAmount = totalAmount * gstRate;
            const tempFinal = totalAmount + gstAmount;
            const roundedFinal = Math.round(tempFinal);
            const roundOff = roundedFinal - tempFinal;

            gstRow.classList.remove('hidden');
            roundOffRow.classList.remove('hidden');
            finalTotalRow.classList.remove('hidden');

            document.getElementById('gstLabel').textContent = gstLabelText;
            document.getElementById('gstAmountInput').value = formatIndian(gstAmount);
            document.getElementById('roundOffInput').value = roundOff.toFixed(2);
            document.getElementById('finalTotalDisplay').value = formatIndian(roundedFinal);
            document.getElementById('finalAmountHidden').value = roundedFinal;

            // NEW: Populate hidden tax fields for DB
            document.getElementById('taxableAmountHidden').value = totalAmount.toFixed(2);
            document.getElementById('totalGstHidden').value = gstAmount.toFixed(2);
            if (gstLabelText.includes('CGST')) {
                document.getElementById('cgstHidden').value = (gstAmount / 2).toFixed(2);
                document.getElementById('sgstHidden').value = (gstAmount / 2).toFixed(2);
                document.getElementById('igstHidden').value = 0;
            } else {
                document.getElementById('cgstHidden').value = 0;
                document.getElementById('sgstHidden').value = 0;
                document.getElementById('igstHidden').value = gstAmount.toFixed(2);
            }
        } else {
            gstRow.classList.add('hidden');
            roundOffRow.classList.add('hidden');
            finalTotalRow.classList.add('hidden');
            document.getElementById('finalAmountHidden').value = totalAmount;

            // NEW: Reset hidden tax fields for Cash mode
            document.getElementById('taxableAmountHidden').value = 0;
            document.getElementById('cgstHidden').value = 0;
            document.getElementById('sgstHidden').value = 0;
            document.getElementById('igstHidden').value = 0;
            document.getElementById('totalGstHidden').value = 0;
        }

        // Keep mode hidden field updated
        document.getElementById('modeHidden').value = mode;

        // Also format subtotal amount
        document.getElementById('totalAmountInput').value = formatIndian(totalAmount, 0);

        const firstRate = document.querySelector('.sell-rate')?.value || 0;
        document.getElementById('rateInput').value = firstRate;
    }

    bindSellItemEvents(document.querySelector('.sell-item-row'));

    // Party Search
    const partyNameInput = document.getElementById('partyNameInput');
    const partyList = document.getElementById('partyList');
    let selectedIndex = -1;

    partyNameInput.addEventListener('input', function () {
        const term = this.value.trim();
        if (this.value === '') {
            partyList.classList.add('hidden');
            selectedIndex = -1;
            return;
        }
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=search_parties&term=' + encodeURIComponent(term)
        }).then(r => r.json()).then(parties => {
            partyList.innerHTML = '';
            selectedIndex = -1;

            if (!parties.length) {
                const div = document.createElement('div');
                div.className = 'px-3 py-2 cursor-pointer border-b border-gray-100 last:border-b-0 flex items-center gap-2 suggestion-item bg-slate-50';
                div.innerHTML = `
                    <div class="w-6 h-6 rounded-full bg-slate-100 flex items-center justify-center text-slate-600 suggestion-icon-container">
                        <i class="fas fa-plus-circle text-[10px] suggestion-icon"></i>
                    </div>
                    <div>
                        <div class="text-xs font-bold text-slate-800 suggestion-name">Create New: "${term}"</div>
                        <div class="text-[9px] text-slate-600 italic suggestion-address">Click or press Enter to add</div>
                    </div>
                `;
                div.addEventListener('click', () => openAddPartyModal(term));
                partyList.appendChild(div);
                partyList.classList.remove('hidden');
                return;
            }

            parties.forEach((p, index) => {
                const div = document.createElement('div');
                div.className = 'px-3 py-2 cursor-pointer border-b border-gray-100 last:border-b-0 flex items-center gap-2 suggestion-item';
                div.setAttribute('data-index', index);
                div.innerHTML = `
                    <div class="w-6 h-6 rounded-full bg-blue-50 flex items-center justify-center text-blue-500 suggestion-icon-container">
                        <i class="fas fa-user text-[10px] suggestion-icon"></i>
                    </div>
                    <div>
                        <div class="text-xs font-bold text-gray-900 suggestion-name">${p.party_name}</div>
                        <div class="text-[9px] text-gray-500 italic suggestion-address">${p.address || 'No Address'}</div>
                    </div>
                `;
                div.addEventListener('click', () => selectParty(p));
                partyList.appendChild(div);
            });

            // Add "Create New" at bottom as well
            const addDiv = document.createElement('div');
            addDiv.className = 'px-3 py-2 cursor-pointer border-t-2 border-slate-200 flex items-center gap-2 suggestion-item bg-slate-50';
            addDiv.innerHTML = `
                <div class="w-6 h-6 rounded-full bg-slate-100 flex items-center justify-center text-slate-600 suggestion-icon-container">
                    <i class="fas fa-plus-circle text-[10px] suggestion-icon"></i>
                </div>
                <div>
                    <div class="text-xs font-bold text-slate-800 suggestion-name">Add New Party</div>
                </div>
            `;
            addDiv.addEventListener('click', () => openAddPartyModal(term));
            partyList.appendChild(addDiv);

            partyList.classList.remove('hidden');
        }).catch(console.error);
    });

    partyNameInput.addEventListener('keydown', function (e) {
        // Alt+A shortcut
        if (e.altKey && (e.key.toLowerCase() === 'a')) {
            e.preventDefault();
            openAddPartyModal(this.value.trim());
            return;
        }

        const items = partyList.querySelectorAll('.suggestion-item');
        if (partyList.classList.contains('hidden') || items.length === 0) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            selectedIndex = (selectedIndex + 1) % items.length;
            updateSelection(items);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            selectedIndex = (selectedIndex - 1 + items.length) % items.length;
            updateSelection(items);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (selectedIndex > -1) {
                items[selectedIndex].click();
            }
        } else if (e.key === 'Escape') {
            partyList.classList.add('hidden');
        } else if (e.key === ' ' && !partyNameInput.value.trim()) {
            e.preventDefault();
            // Force small trigger
            const event = new Event('input');
            partyNameInput.dispatchEvent(event);
            // We need to bypass the length check, so we simulate a trigger with a flag
            // Actually, simpler: call the logic directly or just use a space
            partyNameInput.value = ' ';
            partyNameInput.dispatchEvent(new Event('input'));
            partyNameInput.value = ''; // Clean up after
        }
    });

    function updateSelection(items) {
        items.forEach((item, index) => {
            const nameEl = item.querySelector('.suggestion-name');
            const addrEl = item.querySelector('.suggestion-address');
            const iconBgEl = item.querySelector('.suggestion-icon-container');
            const iconEl = item.querySelector('.suggestion-icon');

            if (index === selectedIndex) {
                item.classList.add('bg-blue-600', 'selected');
                if (nameEl) {
                    nameEl.classList.remove('text-gray-900');
                    nameEl.classList.add('text-white');
                }
                if (addrEl) {
                    addrEl.classList.remove('text-gray-500');
                    addrEl.classList.add('text-blue-100');
                }
                if (iconBgEl) {
                    iconBgEl.classList.remove('bg-blue-50');
                    iconBgEl.classList.add('bg-blue-500');
                }
                if (iconEl) {
                    iconEl.classList.remove('text-blue-500');
                    iconEl.classList.add('text-white');
                }
                item.scrollIntoView({ block: 'nearest' });
            } else {
                item.classList.remove('bg-blue-600', 'selected');
                if (nameEl) {
                    nameEl.classList.add('text-gray-900');
                    nameEl.classList.remove('text-white');
                }
                if (addrEl) {
                    addrEl.classList.add('text-gray-500');
                    addrEl.classList.remove('text-blue-100');
                }
                if (iconBgEl) {
                    iconBgEl.classList.add('bg-blue-50');
                    iconBgEl.classList.remove('bg-blue-500');
                }
                if (iconEl) {
                    iconEl.classList.add('text-blue-500');
                    iconEl.classList.remove('text-white');
                }
            }
        });
    }

    function selectParty(p) {
        partyNameInput.value = p.party_name;
        partyNameInput.dataset.state = p.state || '';
        document.getElementById('partyId').value = p.id;
        partyList.classList.add('hidden');
        selectedIndex = -1;
        recalculateSellTotals(); // Trigger tax recalculation based on selected party's state
        loadPartyDues(p.party_name);

        // Focus first item select and default to Fine
        const firstSelect = document.querySelector('.sell-item-select');
        if (firstSelect) {
            for (let opt of firstSelect.options) {
                if (opt.text.toLowerCase().includes('fine')) {
                    firstSelect.value = opt.value;
                    firstSelect.dispatchEvent(new Event('change'));
                    break;
                }
            }
            firstSelect.focus();
        }
    }

    // Attach Add New Party button listener
    const addNewPartyBtn = document.getElementById('addNewPartyBtn');
    if (addNewPartyBtn) {
        addNewPartyBtn.addEventListener('click', () => window.openAddPartyModal());
    }

    function loadPartyDues(partyName) {
        const infoEl = document.getElementById('partyDueInfoInline');
        if (!partyName) {
            if (infoEl) infoEl.classList.add('hidden');
            return;
        }
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=get_party_stats&party_id=' + encodeURIComponent(document.getElementById('partyId').value)
        }).then(r => r.json()).then(data => {
            if (data && (parseFloat(data.current_balance) > 0 || parseFloat(data.current_gold_balance) > 0)) {
                const balEl = document.getElementById('dueAmountValueInline');
                const gBalEl = document.getElementById('dueGoldValueInline');
                if (balEl) balEl.innerText = '₹' + parseFloat(data.current_balance).toLocaleString();
                if (gBalEl) gBalEl.innerText = parseFloat(data.current_gold_balance).toFixed(3) + 'g';
                if (infoEl) infoEl.classList.remove('hidden');
            } else {
                if (infoEl) infoEl.classList.add('hidden');
            }
        }).catch(e => {
            console.error('Error loading dues:', e);
            if (infoEl) infoEl.classList.add('hidden');
        });
    }

    document.addEventListener('click', e => {
        if (partyNameInput && partyList && !partyNameInput.contains(e.target) && !partyList.contains(e.target)) {
            partyList.classList.add('hidden');
        }
        const saleListEl = document.getElementById('saleList');
        if (saleListEl && !e.target.closest('#saleList, #showSaleListBtn, #saleIdInput')) {
            saleListEl.classList.add('hidden');
        }
    });

    function escapeSaleListHtml(text) {
        if (text == null) return '';
        const d = document.createElement('div');
        d.textContent = String(text);
        return d.innerHTML;
    }

    function sellListIsBank(sale) {
        let ch = String(sale.booking_type || '').trim();
        if (!ch) ch = String(sale.receipt_method || sale.mode || 'Cash').trim();
        return ch.toLowerCase() === 'bank';
    }

    function showSaleList() {
        const saleListEl = document.getElementById('saleList');
        if (!saleListEl) return;
        saleListEl.innerHTML = '<div class="py-2 px-2 text-center text-gray-500"><i class="fas fa-spinner fa-spin"></i></div>';
        saleListEl.classList.remove('hidden');
        const fd = new FormData();
        fd.append('action', 'get_sell_list');
        fetch('', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data && data.error) {
                    saleListEl.innerHTML = '<div class="py-2 px-2 text-center text-red-600">Error</div>';
                    return;
                }
                if (!Array.isArray(data) || data.length === 0) {
                    saleListEl.innerHTML = '<div class="py-2 px-2 text-center text-gray-500">No sales</div>';
                    return;
                }
                saleListEl.innerHTML = '';
                data.forEach(sale => {
                    const row = document.createElement('div');
                    row.className = 'sale-list-item px-2 py-1 border-b border-gray-100 hover:bg-blue-50 cursor-pointer text-left';
                    const dt = sale.date_of_transaction ? String(sale.date_of_transaction).split(' ')[0] : '';
                    const party = (sale.party_name || '').trim() || '—';
                    const wtStr = (parseFloat(sale.gold_weight) || 0).toFixed(3) + ' g';
                    const amtStr = '₹' + Number(sale.gold_amount || 0).toLocaleString('en-IN');
                    const tip = [sale.receipt_id, dt, party, wtStr, amtStr].join(' · ');
                    const bank = sellListIsBank(sale);
                    const modeIcon = bank
                        ? '<span class="inline-flex items-center justify-center w-4 h-4 rounded bg-indigo-100 text-indigo-700 border border-indigo-200 shrink-0" title="Bank"><i class="fas fa-university text-[7px]"></i></span>'
                        : '<span class="inline-flex items-center justify-center w-4 h-4 rounded bg-emerald-100 text-emerald-700 border border-emerald-200 shrink-0" title="Cash"><i class="fas fa-wallet text-[7px]"></i></span>';
                    row.innerHTML = `
                        <div class="flex justify-between items-center gap-1">
                            <span class="flex items-center gap-1 min-w-0">${modeIcon}<span class="font-bold text-blue-600 truncate">${escapeSaleListHtml(sale.receipt_id)}</span></span>
                            <span class="text-gray-400 shrink-0">${escapeSaleListHtml(dt)}</span>
                        </div>`;
                    const nameEl = document.createElement('div');
                    nameEl.className = 'text-gray-800 truncate mt-px font-medium';
                    nameEl.textContent = party;
                    nameEl.title = tip;
                    row.appendChild(nameEl);
                    const wAmtEl = document.createElement('div');
                    wAmtEl.className = 'text-gray-600 mt-px';
                    wAmtEl.textContent = wtStr + ' · ' + amtStr;
                    wAmtEl.title = tip;
                    row.appendChild(wAmtEl);
                    row.addEventListener('click', () => {
                        loadSale(sale.receipt_id);
                        saleListEl.classList.add('hidden');
                    });
                    saleListEl.appendChild(row);
                });
            })
            .catch(() => {
                saleListEl.innerHTML = '<div class="py-2 px-2 text-center text-red-600">Error</div>';
            });
    }

    const showSaleListBtn = document.getElementById('showSaleListBtn');
    const saleIdInputEl = document.getElementById('saleIdInput');
    if (showSaleListBtn) {
        showSaleListBtn.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); showSaleList(); });
    }
    if (saleIdInputEl) {
        saleIdInputEl.addEventListener('click', e => { e.preventDefault(); showSaleList(); });
    }

    // Generate Sale ID
    async function generateSaleId() {
        try {
            const res = await fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=generate_sale_id' });
            const data = await res.json();
            if (data.status === 'success') document.getElementById('saleIdInput').value = data.sale_id;
        } catch (e) { console.error(e); }
    }

    // Enter-key chain through the Payment Details section: Paid Amt -> Method -> Narration -> Save
    (function () {
        const paidAmtEl = document.getElementById('paidAmountInput');
        const payMethodEl = document.getElementById('payMethodSelect');
        const narrationEl = document.getElementById('saleNarrationInput');
        const saveBtnEl = document.getElementById('sellGoldBtn');

        if (paidAmtEl) {
            paidAmtEl.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (payMethodEl) payMethodEl.focus();
                }
            });
        }
        if (payMethodEl) {
            payMethodEl.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (narrationEl) narrationEl.focus();
                }
            });
        }
        if (narrationEl) {
            narrationEl.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (saveBtnEl) saveBtnEl.focus();
                }
            });
        }
    })();

    // Init
    (function () {
        generateSaleId();
        const now = new Date();
        now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
        document.querySelector('[name="date_of_transaction"]').value = now.toISOString().slice(0, 16);

        // Auto-focus party name input
        if (partyNameInput) partyNameInput.focus();
    })();

    // Save
    document.getElementById('sellGoldBtn').addEventListener('click', function () {
        const party = document.getElementById('partyId').value;
        const total = parseFloat(document.getElementById('totalSellWeight').value) || 0;
        if (!party) {
            Swal.fire('Error', 'Please select a party', 'warning').then(() => {
                if (partyNameInput) { partyNameInput.focus(); partyNameInput.select(); }
            });
            return;
        }
        // Rate of 0 is allowed (e.g. gift/adjustment entries) - only block missing items below.
        if (total <= 0) {
            Swal.fire('Error', 'Add at least one gold item', 'warning').then(() => {
                const firstWeightInput = document.querySelector('.sell-item-row .sell-weight');
                if (firstWeightInput) { firstWeightInput.focus(); firstWeightInput.select(); }
            });
            return;
        }

        const rows = document.querySelectorAll('.sell-item-row');
        let totalFine = 0, firstPurity = 0;
        rows.forEach((r, i) => {
            const p = parseFloat(r.querySelector('.sell-purity').value) || 0;
            if (i === 0) firstPurity = p;
            totalFine += parseFloat(r.querySelector('.sell-fine').value) || 0;
        });
        document.getElementById('sellWeightHidden').value = total.toFixed(3);
        document.getElementById('purityHidden').value = firstPurity;

        const payMethod = document.getElementById('payMethodSelect').value;
        const paidAmt = parseFloat(document.getElementById('paidAmountInput').value) || 0;
        document.getElementById('additionalCash').value = payMethod === 'Cash' ? paidAmt : 0;
        document.getElementById('additionalBank').value = payMethod !== 'Cash' ? paidAmt : 0;

        const formData = new FormData(document.getElementById('sellForm'));

        // Collect multi-items (stock_id = gold_stock.id from the Stock select value)
        const items = [];
        document.querySelectorAll('.sell-item-row').forEach(row => {
            const it = collectSellLineItem(row);
            if (it) items.push(it);
        });
        formData.append('sell_items', JSON.stringify(items));

        fetch('', { method: 'POST', body: formData }).then(r => r.json()).then(res => {
            if (res.status === 'success') {
                Swal.fire({
                    icon: 'success',
                    title: 'Saved',
                    text: res.message || 'Sale saved successfully',
                    showCancelButton: true,
                    confirmButtonText: 'Print receipt',
                    cancelButtonText: 'Close',
                    confirmButtonColor: '#2563eb',
                    cancelButtonColor: '#6B7280'
                }).then((result) => {
                    if (result.isConfirmed && res.transaction_id) {
                        openSaleReceiptPrint(res.transaction_id);
                    }
                    location.reload();
                });
            } else { Swal.fire('Error', res.message || 'Failed', 'error'); }
        }).catch(() => Swal.fire('Error', 'Network error', 'error'));
    });

    // Reset
    function resetSellForm() {
        document.getElementById('sellForm').reset();
        document.getElementById('sellItemsTable').innerHTML = `
            <tr class="sell-item-row group">
                <td class="px-2 py-1 border-b text-gray-500 font-bold item-number w-8">1</td>
                <td class="px-2 py-1 border-b">
                    <select class="w-full px-2 py-1 text-xs font-bold text-blue-800 bg-white border border-gray-200 rounded field-focus sell-item-select">
                        ${stockOptionsHTML}
                    </select>
                    <input type="hidden" class="sell-purity" value="0">
                    <input type="hidden" class="sell-stock-id" value="">
                    <input type="hidden" class="sell-stock-name" value="">
                </td>
                <td class="px-2 py-1 border-b"><input type="number" step="0.001" class="w-full px-2 py-1 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded sell-weight" placeholder="0.000"></td>
                <td class="px-2 py-1 border-b"><input type="number" step="0.01" class="w-full px-2 py-1 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded sell-rate" placeholder="0.00"></td>
                <td class="px-2 py-1 border-b"><input type="number" step="0.01" class="w-full px-2 py-1 text-[10px] font-bold text-gray-600 bg-gray-50 border border-gray-200 rounded sell-amount cursor-not-allowed" readonly></td>
                <input type="hidden" class="sell-fine" value="0">
                <td class="px-2 py-1 border-b text-center w-10"><button type="button" onclick="removeSellItem(this)" class="text-red-400 hover:text-red-600 text-xs"><i class="fas fa-trash-alt"></i></button></td>
            </tr>`;
        sellItemCount = 1;
        bindSellItemEvents(document.querySelector('.sell-item-row'));
        document.getElementById('totalSellWeight').value = '0.000';
        document.getElementById('totalSellFine').value = '0.000';
        document.getElementById('totalAmountInput').value = '';
        generateSaleId();
        const now = new Date(); now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
        document.querySelector('[name="date_of_transaction"]').value = now.toISOString().slice(0, 16);
    }
    document.getElementById('resetFormBtn').addEventListener('click', resetSellForm);

    // Load Sale for Edit
    function loadSale(receiptId) {
        fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=get_sell_details&receipt_id=' + encodeURIComponent(receiptId) })
            .then(r => r.json()).then(s => {
                if (s.status === 'error') { Swal.fire('Error', s.message, 'error'); return; }
                document.getElementById('saleIdInput').value = s.receipt_id;
                document.querySelector('[name="date_of_transaction"]').value = s.date_of_transaction ? s.date_of_transaction.replace(' ', 'T') : '';
                document.getElementById('partyNameInput').value = s.party_name || '';
                document.getElementById('partyId').value = s.party_id || '';
                document.getElementById('rateInput').value = s.rate || '';

                // Populate multiple items
                const tbody = document.getElementById('sellItemsTable');
                tbody.innerHTML = '';
                if (s.items && s.items.length > 0) {
                    s.items.forEach((it, idx) => {
                        const itemWeight = parseFloat(it.gold_weight ?? it.weight ?? 0) || 0;
                        const itemFine = parseFloat(it.fine_weight ?? it.fine ?? 0) || 0;
                        const itemRate = parseFloat(it.rate ?? s.rate ?? 0) || 0;
                        const itemAmount = parseFloat(it.amount ?? 0) || 0;
                        const row = document.createElement('tr');
                        row.className = 'sell-item-row group';
                        row.innerHTML = `
                        <td class="px-2 py-1 border-b text-gray-500 font-bold item-number w-8">${idx + 1}</td>
                        <td class="px-2 py-1 border-b">
                            <select class="w-full px-2 py-1 text-xs font-bold text-blue-800 bg-white border border-gray-200 rounded sell-item-select">
                                ${stockOptionsHTML}
                            </select>
                            <input type="hidden" class="sell-purity" value="${it.purity ?? 0}">
                            <input type="hidden" class="sell-stock-id" value="${it.stock_ref_id != null ? it.stock_ref_id : ''}">
                            <input type="hidden" class="sell-stock-name" value="${it.stock_name || ''}">
                        </td>
                        <td class="px-2 py-1 border-b"><input type="number" step="0.001" class="w-full px-2 py-1 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded sell-weight" value="${itemWeight}"></td>
                        <td class="px-2 py-1 border-b"><input type="number" step="0.01" class="w-full px-2 py-1 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded sell-rate" value="${itemRate}"></td>
                        <td class="px-2 py-1 border-b"><input type="number" step="0.01" class="w-full px-2 py-1 text-[10px] font-bold text-gray-600 bg-gray-50 border border-gray-200 rounded sell-amount cursor-not-allowed" readonly data-value="${itemAmount}" value="${formatIndian(itemAmount, 0)}"></td>
                        <input type="hidden" class="sell-fine" value="${itemFine.toFixed(3)}">
                        <td class="px-2 py-1 border-b text-center w-10"><button type="button" onclick="removeSellItem(this)" class="text-red-400 hover:text-red-600 text-xs transition-colors"><i class="fas fa-trash-alt"></i></button></td>
                    `;
                        tbody.appendChild(row);
                        // Match select to stock_ref_id first, then stock name + purity
                        const sel = row.querySelector('.sell-item-select');
                        const refId = it.stock_ref_id != null && String(it.stock_ref_id) !== '' ? String(it.stock_ref_id) : '';
                        if (refId && [...sel.options].some(o => o.value === refId)) sel.value = refId;
                        else if (it.stock_name) {
                            const match = [...sel.options].find(o => o.dataset.stockName === it.stock_name && Math.abs(parseFloat(o.dataset.purity) - parseFloat(it.purity)) < 0.001);
                            if (match) sel.value = match.value;
                            else {
                                for (let o of sel.options) { if (parseFloat(o.dataset.purity) === parseFloat(it.purity)) { sel.value = o.value; break; } }
                            }
                        }
                        bindSellItemEvents(row);
                        row.querySelector('.sell-item-select')?.dispatchEvent(new Event('change'));
                    });
                    sellItemCount = s.items.length;
                } else {
                    tbody.innerHTML = `
                    <tr class="sell-item-row group">
                        <td class="px-2 py-1 border-b text-gray-500 font-bold item-number w-8">1</td>
                        <td class="px-2 py-1 border-b">
                            <select class="w-full px-2 py-1 text-xs font-bold text-blue-800 bg-white border border-gray-200 rounded sell-item-select">
                                ${stockOptionsHTML}
                            </select>
                            <input type="hidden" class="sell-purity" value="${s.purity || 0}">
                            <input type="hidden" class="sell-stock-id" value="">
                            <input type="hidden" class="sell-stock-name" value="${s.stock_name || ''}">
                        </td>
                        <td class="px-2 py-1 border-b"><input type="number" step="0.001" class="w-full px-2 py-1 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded sell-weight" value="${s.gold_weight || ''}"></td>
                        <td class="px-2 py-1 border-b"><input type="number" step="0.01" class="w-full px-2 py-1 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded sell-rate" value="${s.rate || 0}"></td>
                        <td class="px-2 py-1 border-b"><input type="number" step="0.01" class="w-full px-2 py-1 text-[10px] font-bold text-gray-600 bg-gray-50 border border-gray-200 rounded sell-amount cursor-not-allowed" readonly value="${(parseFloat(s.gold_weight || 0) * parseFloat(s.rate || 0)).toFixed(2)}"></td>
                        <input type="hidden" class="sell-fine" value="${(parseFloat(s.gold_weight || 0) * parseFloat(s.purity || 0) / 100).toFixed(3)}">
                        <td class="px-2 py-1 border-b text-center w-10"><button type="button" onclick="removeSellItem(this)" class="text-red-400 hover:text-red-600 text-xs transition-colors"><i class="fas fa-trash-alt"></i></button></td>
                    </tr>`;
                    sellItemCount = 1;
                    const sel = tbody.querySelector('.sell-item-select');
                    for (let o of sel.options) { if (parseFloat(o.dataset.purity) === parseFloat(s.purity)) { sel.value = o.value; break; } }
                    bindSellItemEvents(tbody.querySelector('.sell-item-row'));
                    tbody.querySelector('.sell-item-select')?.dispatchEvent(new Event('change'));
                }
                recalculateSellTotals();
                document.getElementById('paidAmountInput').value = s.payment_amount || 0;
                if (typeof setPaymentMode === 'function') {
                    setPaymentMode(sellListIsBank(s) ? 'Bank' : 'Cash');
                }
                const payMethodSelect = document.getElementById('payMethodSelect');
                if (payMethodSelect && s.payment_method) {
                    payMethodSelect.value = s.payment_method;
                }
                document.querySelector('[name="narration"]').value = s.narration || '';
                document.getElementById('sellGoldBtn').classList.add('hidden');
                document.getElementById('updateSaleBtn').classList.remove('hidden');
                document.getElementById('deleteSaleBtn').classList.remove('hidden');
                document.getElementById('cancelEditBtn').classList.remove('hidden');
                document.getElementById('updateSaleBtn').dataset.originalId = s.receipt_id;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
    }

    document.getElementById('updateSaleBtn').addEventListener('click', function () {
        const originalId = this.dataset.originalId;
        if (!originalId) return;
        const total = parseFloat(document.getElementById('totalSellWeight').value) || 0;
        if (total <= 0) { Swal.fire('Error', 'Add at least one item', 'warning'); return; }
        let firstPurity = 0;
        document.querySelectorAll('.sell-item-row').forEach((r, i) => { if (i === 0) firstPurity = parseFloat(r.querySelector('.sell-purity').value) || 0; });
        document.getElementById('sellWeightHidden').value = total.toFixed(3);
        document.getElementById('purityHidden').value = firstPurity;
        const payMethod = document.getElementById('payModeInternal').value;
        const paidAmt = parseFloat(document.getElementById('paidAmountInput').value) || 0;
        document.getElementById('additionalCash').value = (payMethod === 'Cash') ? paidAmt : 0;
        document.getElementById('additionalBank').value = (payMethod !== 'Cash') ? paidAmt : 0;
        const formData = new FormData(document.getElementById('sellForm'));

        const items = [];
        document.querySelectorAll('.sell-item-row').forEach(row => {
            const it = collectSellLineItem(row);
            if (it) items.push(it);
        });
        formData.append('sell_items', JSON.stringify(items));

        formData.set('action', 'update_sale');
        formData.set('original_receipt_id', originalId);
        fetch('', { method: 'POST', body: formData }).then(r => r.json()).then(res => {
            if (res.status === 'success') { Swal.fire({ icon: 'success', title: 'Updated!', timer: 1500, showConfirmButton: false }).then(() => location.reload()); }
            else { Swal.fire('Error', res.message || 'Update failed', 'error'); }
        });
    });

    document.getElementById('deleteSaleBtn').addEventListener('click', function () {
        const receiptId = document.getElementById('saleIdInput').value;
        Swal.fire({ title: 'Delete this sale?', text: 'Receipt: ' + receiptId, icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, Delete' })
            .then(r => {
                if (r.isConfirmed) {
                    fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=delete_sale&receipt_id=' + encodeURIComponent(receiptId) })
                        .then(r => r.json()).then(res => {
                            if (res.status === 'success') { Swal.fire({ icon: 'success', title: 'Deleted!', timer: 1500, showConfirmButton: false }).then(() => location.reload()); }
                            else { Swal.fire('Error', res.message, 'error'); }
                        });
                }
            });
    });

    document.getElementById('cancelEditBtn').addEventListener('click', function () {
        resetSellForm();
        document.getElementById('sellGoldBtn').classList.remove('hidden');
        document.getElementById('updateSaleBtn').classList.add('hidden');
        document.getElementById('deleteSaleBtn').classList.add('hidden');
        document.getElementById('cancelEditBtn').classList.add('hidden');
    });

    // Mode Toggle Logic
    const cashBtn = document.getElementById('cashModeBtn');
    const bankBtn = document.getElementById('bankModeBtn');
    const payModeHidden = document.getElementById('payModeInternal');

    function setPaymentMode(mode) {
        if (!payModeHidden) return;
        payModeHidden.value = mode;
        if (mode === 'Cash') {
            if (cashBtn) {
                cashBtn.classList.add('bg-white', 'text-slate-600', 'shadow-sm', 'border-slate-200');
                cashBtn.classList.remove('text-slate-500');
            }
            if (bankBtn) {
                bankBtn.classList.remove('bg-white', 'text-blue-600', 'shadow-sm', 'border-blue-100');
                bankBtn.classList.add('text-slate-500');
            }
        } else {
            if (bankBtn) {
                bankBtn.classList.add('bg-white', 'text-blue-600', 'shadow-sm', 'border-blue-100');
                bankBtn.classList.remove('text-slate-500');
            }
            if (cashBtn) {
                cashBtn.classList.remove('bg-white', 'text-slate-600', 'shadow-sm', 'border-slate-200');
                cashBtn.classList.add('text-slate-500');
            }
        }
        recalculateSellTotals();
    }

    if (cashBtn && bankBtn) {
        cashBtn.addEventListener('click', () => {
            setPaymentMode('Cash');
            document.querySelectorAll('.sell-row-mode').forEach(badge => {
                badge.textContent = 'Cash';
                badge.className = 'sell-row-mode text-[8px] font-bold px-1 py-0.5 rounded uppercase bg-green-50 text-green-600 border border-green-100';
            });
        });
        bankBtn.addEventListener('click', () => {
            setPaymentMode('Bank');
            document.querySelectorAll('.sell-row-mode').forEach(badge => {
                badge.textContent = 'Bank';
                badge.className = 'sell-row-mode text-[8px] font-bold px-1 py-0.5 rounded uppercase bg-blue-50 text-blue-600 border border-blue-100';
            });
        });
    }
</script>
<?php
// Capture scripts
$additional_scripts = ob_get_clean();

// Set page title and include layout
$page_title = "Sales";
include 'components/layout.php';
?>