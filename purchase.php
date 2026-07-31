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
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_error.log');

// Load database configuration
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'handlers' . DIRECTORY_SEPARATOR . 'account_balance_helper.php';
require_once __DIR__ . '/helpers/gold_rate_helper.php';
require_once __DIR__ . '/helpers/receipt_id_helper.php';

if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error . '. Please run setup_database.php first.');
}

/** Ensure line-items table exists (mirror of gold_sale_items for purchases). */
function purchase_ensure_gold_purchase_items_table(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS `gold_purchase_items` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `company_id` int(11) NOT NULL,
      `transaction_id` int(11) NOT NULL,
      `receipt_id` varchar(50) NOT NULL,
      `stock_name` varchar(100) NOT NULL,
      `gold_weight` decimal(15,3) NOT NULL,
      `purity` decimal(10,2) NOT NULL,
      `fine_weight` decimal(15,3) NOT NULL,
      `rate` decimal(15,2) NOT NULL,
      `amount` decimal(15,2) NOT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `idx_gp_tx` (`transaction_id`),
      KEY `idx_gp_co` (`company_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

/** Link purchase lines to gold_stock.id so Gold vs Silver / Cash vs Bank update the correct row. */
function purchase_ensure_gold_purchase_items_stock_ref(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $r = $conn->query("SHOW COLUMNS FROM `gold_purchase_items` LIKE 'stock_ref_id'");
    if ($r && $r->num_rows > 0) {
        return;
    }
    if (!$conn->query("ALTER TABLE `gold_purchase_items` ADD COLUMN `stock_ref_id` INT NULL DEFAULT NULL AFTER `purity`")) {
        error_log('purchase: ALTER gold_purchase_items.stock_ref_id failed: ' . $conn->error);
    }
}

/**
 * Resolve to one gold_stock row id (never purity-only), so Silver does not hit Gold.
 * Same rules as sales_resolve_stock_row_id.
 */
function purchase_resolve_stock_row_id(mysqli $conn, int $company_id, array $item): int
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

/** Add (+) or remove (-) weight for a specific gold_stock row. */
function purchase_adjust_stock_by_row_id(mysqli $conn, int $company_id, int $stock_id, float $weight_delta): void
{
    $sid = (int) $stock_id;
    $cid = (int) $company_id;
    $w = floatval($weight_delta);
    if ($sid <= 0 || abs($w) < 0.000001) {
        return;
    }
    if (!$conn->query("UPDATE gold_stock SET current_stock = current_stock + ($w), last_updated = NOW() WHERE id = $sid AND company_id = $cid LIMIT 1")) {
        throw new Exception('Stock update failed: ' . $conn->error);
    }
}

/** Add (+) or remove (-) weight from gold_stock for one purchase line (legacy: stock_name + purity only). */
function purchase_adjust_line_stock(mysqli $conn, int $company_id, string $stock_name, float $purity, float $weight_delta): void
{
    $sn = $conn->real_escape_string($stock_name);
    $p = floatval($purity);
    $w = floatval($weight_delta);
    if (abs($w) < 0.000001) {
        return;
    }
    $where = $sn !== '' ? "stock_name = '$sn' AND purity = $p" : "purity = $p";
    $stock_check = $conn->query("SELECT id FROM gold_stock WHERE $where AND company_id = $company_id LIMIT 1");
    if ($stock_check && $row = $stock_check->fetch_assoc()) {
        $sid = intval($row['id']);
        if (!$conn->query("UPDATE gold_stock SET current_stock = current_stock + $w, last_updated = NOW() WHERE id = $sid")) {
            throw new Exception('Stock update failed: ' . $conn->error);
        }
    } else {
        if ($w < 0) {
            return;
        }
        if (!$conn->query("INSERT INTO gold_stock (company_id, category, mode, stock_name, purity, current_stock, last_updated) VALUES ($company_id, 'Gold', 'Cash', '$sn', $p, $w, NOW())")) {
            throw new Exception('Stock insert failed: ' . $conn->error);
        }
    }
}

purchase_ensure_gold_purchase_items_table($conn);
purchase_ensure_gold_purchase_items_stock_ref($conn);

// Get user and company info
$company_id = $_SESSION['company_id'];
$user_id = $_SESSION['user_id'];
$company_name = $_SESSION['company_name'];
$user_name = $_SESSION['full_name'];
$gold_rate_unit = gold_rate_get_unit($conn, $company_id);
$gold_rate_label = gold_rate_label($gold_rate_unit);
$gold_rate_suffix = gold_rate_suffix($gold_rate_unit);

$company_state = '';
$state_q = $conn->query("SELECT state FROM companies WHERE id = $company_id");
if ($state_q && $state_res = $state_q->fetch_assoc()) {
    $company_state = $state_res['state'] ?? '';
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
            case 'get_purity_stocks':
                // Fetch available purity stocks
                $stocks_sql = "SELECT DISTINCT purity, stock_name, current_stock 
                               FROM gold_stock 
                               WHERE company_id = $company_id 
                               ORDER BY purity DESC";
                
                $stocks_result = $conn->query($stocks_sql);
                $stocks = [];
                
                if ($stocks_result) {
                    while ($row = $stocks_result->fetch_assoc()) {
                        $stocks[] = [
                            'purity' => $row['purity'],
                            'stock_name' => $row['stock_name'],
                            'current_stock' => $row['current_stock']
                        ];
                    }
                }
                
                echo json_encode(['success' => true, 'stocks' => $stocks]);
                exit;

            case 'save_purchase':
                purchase_ensure_gold_purchase_items_table($conn);
                purchase_ensure_gold_purchase_items_stock_ref($conn);
                $conn->begin_transaction();
                try {
                    $transaction_id = isset($_POST['transaction_id']) ? intval($_POST['transaction_id']) : 0;
                    $is_edit = $transaction_id > 0;
                    $receipt_id = $conn->real_escape_string($_POST['receipt_id']);
                    $party_id = intval($_POST['party_id']);
                    $date_of_transaction = $conn->real_escape_string($_POST['date_of_transaction']);
                    $pms = trim($_POST['payment_method_select'] ?? '');
                    $valid_bank_methods = ['Bank', 'UPI', 'Cheque'];
                    if ($pms === 'cash') {
                        $payment_type = 'cash';
                        $bank_payment_type = '';
                    } elseif (in_array($pms, $valid_bank_methods, true)) {
                        $payment_type = 'bank';
                        $bank_payment_type = $conn->real_escape_string($pms);
                    } elseif ($pms !== '') {
                        $payment_type = 'bank';
                        $bank_payment_type = 'Bank';
                    } else {
                        $payment_type = '';
                        $bank_payment_type = '';
                    }
                    $cash_amount = floatval($_POST['cash_amount'] ?? 0);
                    $bank_amount = floatval($_POST['bank_amount'] ?? 0);
                    $payment_amount = $cash_amount + $bank_amount;

                    $line_items = json_decode($_POST['purchase_items'] ?? '', true);
                    if (!is_array($line_items)) {
                        $line_items = [];
                    }
                    $parsed_lines = [];
                    foreach ($line_items as $raw) {
                        $w = floatval($raw['weight'] ?? 0);
                        if ($w <= 0) {
                            continue;
                        }
                        $r = gold_rate_from_display(floatval($raw['rate'] ?? 0), $gold_rate_unit);
                        if ($r <= 0) {
                            throw new Exception('Each line needs stock, weight, and rate');
                        }
                        $stock_id = purchase_resolve_stock_row_id($conn, $company_id, [
                            'stock_id' => $raw['stock_id'] ?? 0,
                            'stock_name' => $raw['stock_name'] ?? '',
                            'purity' => $raw['purity'] ?? 0,
                            'category' => $raw['category'] ?? '',
                            'mode' => $raw['mode'] ?? '',
                        ]);
                        if ($stock_id <= 0) {
                            throw new Exception('Each line needs a valid stock (select from list)');
                        }
                        $meta_q = $conn->query("SELECT stock_name, purity FROM gold_stock WHERE id = $stock_id AND company_id = $company_id LIMIT 1");
                        if (!$meta_q || !$meta_row = $meta_q->fetch_assoc()) {
                            throw new Exception('Stock row not found for purchase line');
                        }
                        $sn = $meta_row['stock_name'];
                        $p = floatval($meta_row['purity']);
                        if ($p <= 0) {
                            throw new Exception('Invalid purity on stock row');
                        }
                        $fine = round($w * $p / 100, 3);
                        $amt = round($w * $r, 2);
                        $parsed_lines[] = [
                            'stock_id' => $stock_id,
                            'stock_name' => $sn,
                            'weight' => $w,
                            'purity' => $p,
                            'fine' => $fine,
                            'rate' => $r,
                            'amount' => $amt,
                        ];
                    }
                    if (count($parsed_lines) === 0) {
                        throw new Exception('Add at least one purchase line item (stock, weight, rate)');
                    }

                    $purchase_weight = 0.0;
                    $w_purity = 0.0;
                    $amount = 0.0;
                    foreach ($parsed_lines as $pl) {
                        $purchase_weight += $pl['weight'];
                        $w_purity += $pl['weight'] * $pl['purity'];
                        $amount += $pl['amount'];
                    }
                    $purity = $purchase_weight > 0 ? round($w_purity / $purchase_weight, 2) : 0;
                    $rate = $purchase_weight > 0 ? round($amount / $purchase_weight, 2) : 0;
                    
                    // If editing, get old transaction data to reverse balances
                    $old_transaction = null;
                    if ($is_edit) {
                        $old_sql = "SELECT * FROM transactions WHERE id = $transaction_id AND company_id = $company_id AND transaction_type = 'Purchase'";
                        $old_result = $conn->query($old_sql);
                        if ($old_result && $old_result->num_rows > 0) {
                            $old_transaction = $old_result->fetch_assoc();
                        } else {
                            throw new Exception("Transaction not found for editing");
                        }
                    }
                    
                    if ($payment_type === 'cash') {
                        $payment_method = 'Cash';
                    } elseif ($payment_type === 'bank' && $bank_payment_type !== '') {
                        $payment_method = $bank_payment_type;
                    } else {
                        $payment_method = 'Cash';
                    }
                    $narration = $conn->real_escape_string($_POST['narration'] ?? '');

                    $inv_mode = $_POST['mode'] ?? $_POST['receipt_method'] ?? 'Cash';
                    if ($inv_mode !== 'Bank') {
                        $inv_mode = 'Cash';
                    }
                    $inv_mode_sql = $conn->real_escape_string($inv_mode);

                    $subtotal = floatval($amount);
                    $purchase_invoice_total = $subtotal;
                    $taxable_amount = 0.0;
                    $cgst = 0.0;
                    $sgst = 0.0;
                    $igst = 0.0;
                    $total_gst = 0.0;

                    if ($inv_mode === 'Bank') {
                        $purchase_invoice_total = floatval(preg_replace('/[^0-9.\-]/', '', (string) ($_POST['final_invoice_amount'] ?? $subtotal)));
                        $taxable_amount = floatval($_POST['taxable_amount'] ?? $subtotal);
                        $cgst = floatval($_POST['cgst'] ?? 0);
                        $sgst = floatval($_POST['sgst'] ?? 0);
                        $igst = floatval($_POST['igst'] ?? 0);
                        $total_gst = floatval($_POST['total_gst'] ?? 0);
                        if ($purchase_invoice_total <= 0) {
                            $purchase_invoice_total = round($subtotal + ($subtotal * 0.03));
                        }
                    }

                    $amount_invoice = $purchase_invoice_total;
                    
                    if (empty($receipt_id) || empty($party_id) || $purchase_weight <= 0) {
                        throw new Exception("Please fill all required fields with valid values");
                    }

                     // Check for Duplicate Receipt ID and Auto-Fix
                    if (!$is_edit) {
                        $receipt_id = ensure_unique_receipt_id(
                            $conn,
                            $company_id,
                            'P',
                            $receipt_id,
                            ['transaction_type' => 'Purchase', 'pad_length' => 3]
                        );
                    }
                    
                    // Get party details
                    $party_sql = "SELECT party_name FROM parties WHERE id = $party_id";
                    $party_result = $conn->query($party_sql);
                    $party_data = $party_result->fetch_assoc();
                    $party_name = $party_data['party_name'];
                    
                    // Get party balance before transaction (total = cash + bank; no current_balance column)
                    $party_balance_sql = "SELECT (cash_balance + bank_balance) AS party_total, cash_balance, bank_balance FROM parties WHERE id = $party_id AND company_id = $company_id";
                    $party_balance_result = $conn->query($party_balance_sql);
                    $party_balance_data = $party_balance_result->fetch_assoc();
                    
                    $current_balance_before = floatval($party_balance_data['party_total'] ?? 0);
                    $cash_balance_before = floatval($party_balance_data['cash_balance'] ?? 0);
                    $bank_balance_before = floatval($party_balance_data['bank_balance'] ?? 0);
                    
                    // If editing, reverse old transaction effects first
                    if ($is_edit && $old_transaction) {
                        // Reverse old party balance changes
                        $old_payment_amount = floatval($old_transaction['payment_amount']);
                        
                        $old_items_q = $conn->query("SELECT stock_ref_id, stock_name, purity, gold_weight FROM gold_purchase_items WHERE transaction_id = $transaction_id AND company_id = $company_id");
                        $had_line_items = ($old_items_q && $old_items_q->num_rows > 0);
                        if ($had_line_items) {
                            while ($oi = $old_items_q->fetch_assoc()) {
                                $ref_id = isset($oi['stock_ref_id']) ? intval($oi['stock_ref_id']) : 0;
                                if ($ref_id > 0) {
                                    purchase_adjust_stock_by_row_id($conn, $company_id, $ref_id, -floatval($oi['gold_weight']));
                                } else {
                                    purchase_adjust_line_stock($conn, $company_id, $oi['stock_name'], floatval($oi['purity']), -floatval($oi['gold_weight']));
                                }
                            }
                            $conn->query("DELETE FROM gold_purchase_items WHERE transaction_id = $transaction_id AND company_id = $company_id");
                        } else {
                            // Legacy: single purity row
                            $old_purity = floatval($old_transaction['purity']);
                            $old_weight = floatval($old_transaction['gold_weight']);
                            $old_stock_sql = "SELECT id, current_stock FROM gold_stock WHERE purity = $old_purity AND company_id = $company_id ORDER BY id DESC LIMIT 1";
                            $old_stock_result = $conn->query($old_stock_sql);
                            if ($old_stock_result && $old_stock_result->num_rows > 0) {
                                $old_stock_data = $old_stock_result->fetch_assoc();
                                $old_stock_id = $old_stock_data['id'];
                                $old_current_stock = $old_stock_data['current_stock'];
                                $reversed_stock = max(0, $old_current_stock - $old_weight);
                                $reverse_stock_sql = "UPDATE gold_stock SET current_stock = $reversed_stock, last_updated = NOW() WHERE id = $old_stock_id";
                                if (!$conn->query($reverse_stock_sql)) {
                                    throw new Exception("Error reversing gold stock: " . $conn->error);
                                }
                            }
                        }
                        
                        // Reverse old party balance on cash/bank legs only
                        $old_party_id = intval($old_transaction['party_id']);
                        $old_purchase_amt = floatval($old_transaction['amount'] ?? $old_transaction['gold_amount'] ?? 0);
                        $old_pm = $old_transaction['payment_method'] ?? 'Cash';
                        if ($old_payment_amount <= 0) {
                            $reverse_balance_sql = "UPDATE parties SET cash_balance = cash_balance + $old_purchase_amt WHERE id = $old_party_id AND company_id = $company_id";
                        } elseif ($old_pm === 'Cash') {
                            $old_leg_delta = $old_payment_amount - $old_purchase_amt;
                            $reverse_balance_sql = "UPDATE parties SET cash_balance = cash_balance - $old_leg_delta WHERE id = $old_party_id AND company_id = $company_id";
                        } else {
                            $old_leg_delta = $old_payment_amount - $old_purchase_amt;
                            $reverse_balance_sql = "UPDATE parties SET bank_balance = bank_balance - $old_leg_delta WHERE id = $old_party_id AND company_id = $company_id";
                        }
                        if (!$conn->query($reverse_balance_sql)) {
                            throw new Exception("Error reversing party balance: " . $conn->error);
                        }
                        
                        // Refresh party balance after reversal if it's the same party
                        if ($old_party_id == $party_id) {
                            $party_balance_result = $conn->query($party_balance_sql);
                            $party_balance_data = $party_balance_result->fetch_assoc();
                            $current_balance_before = floatval($party_balance_data['party_total'] ?? 0);
                            $cash_balance_before = floatval($party_balance_data['cash_balance'] ?? 0);
                            $bank_balance_before = floatval($party_balance_data['bank_balance'] ?? 0);
                        }
                        
                        // Reverse Account Balance (Shop's physical balance)
                        // Old transaction was Purchase (Money Out). Reversal means Money In (+).
                        if ($old_pm === 'Cash') {
                             updateAccountBalance($conn, $company_id, 'Cash', $old_payment_amount);
                        } else {
                             updateAccountBalance($conn, $company_id, 'Bank', $old_payment_amount);
                        }
                    }
                    
                    // Calculate new balances
                    // When company purchases from party:
                    // - If party owes you (positive balance): Purchase REDUCES what they owe
                    //   Example: Party owes +50L, you buy 25L gold → New balance = 50 - 25 = +25L
                    // - If you owe party (negative balance): Purchase INCREASES what you owe
                    //   Example: You owe -50L, you buy 25L gold → New balance = -50 - 25 = -75L
                    // - Payment INCREASES what party owes (or reduces what you owe)
                    //   Example: You pay 10L → balance increases by +10L
                    
                    $purchase_amount = $amount_invoice;
                    $payment_amount_value = floatval($payment_amount); // Amount paid
                    
                    // Net balance change:
                    // Purchase reduces balance (you owe them or they owe you less): -$purchase_amount
                    // Payment increases balance (they owe you more or you owe them less): +$payment_amount
                    $balance_change = -$purchase_amount + $payment_amount_value;
                    $current_balance_after = $current_balance_before + $balance_change;
                    
                    
                    // Insert or update purchase transaction
                    if ($is_edit) {
                        // Update existing transaction
                        $purchase_sql = "UPDATE transactions SET
                            party_id = $party_id,
                            date_of_transaction = '$date_of_transaction',
                            gold_weight = $purchase_weight,
                            purity = $purity,
                            rate = $rate,
                            gold_amount = $amount_invoice,
                            amount = $amount_invoice,
                            payment_amount = $payment_amount,
                            payment_method = '$payment_method',
                            receipt_method = '$inv_mode_sql',
                            mode = '$inv_mode_sql',
                            taxable_amount = $taxable_amount,
                            cgst = $cgst,
                            sgst = $sgst,
                            igst = $igst,
                            total_gst = $total_gst,
                            party_balance_before = $current_balance_before,
                            party_balance_after = $current_balance_after,
                            narration = 'Gold purchase from $party_name - $receipt_id" . (!empty($narration) ? " - $narration" : "") . "'
                            WHERE id = $transaction_id AND company_id = $company_id";
                    } else {
                        // Insert new transaction
                        $purchase_sql = "INSERT INTO transactions (
                            company_id, party_id, receipt_id, transaction_type, date_of_transaction,
                            gold_weight, purity, rate, gold_amount, amount, payment_amount, payment_method, payment_type,
                            receipt_method, mode,
                            taxable_amount, cgst, sgst, igst, total_gst,
                            party_balance_before, party_balance_after, party_gold_balance_before, party_gold_balance_after,
                            narration
                        ) VALUES (
                            $company_id, $party_id, '$receipt_id', 'Purchase', '$date_of_transaction',
                            $purchase_weight, $purity, $rate, $amount_invoice, $amount_invoice, $payment_amount, '$payment_method', 'Payment_Out',
                            '$inv_mode_sql', '$inv_mode_sql',
                            $taxable_amount, $cgst, $sgst, $igst, $total_gst,
                            $current_balance_before, $current_balance_after, 0, 0,
                            'Gold purchase from $party_name - $receipt_id" . (!empty($narration) ? " - $narration" : "") . "'
                        )";
                    }
                    
                    if (!$conn->query($purchase_sql)) {
                        throw new Exception("Error " . ($is_edit ? "updating" : "creating") . " purchase transaction: " . $conn->error);
                    }

                    $tid_for_items = $is_edit ? $transaction_id : (int) $conn->insert_id;
                    
                    // Update party balance
                    // Purchase increases what company owes (negative balance change)
                    // Payment reduces what company owes (positive balance change)
                    $party_balance_sql = "SELECT (cash_balance + bank_balance) AS party_total, cash_balance, bank_balance FROM parties WHERE id = $party_id AND company_id = $company_id";
                    $party_balance_result = $conn->query($party_balance_sql);
                    $party_balance_data = $party_balance_result->fetch_assoc();
                    
                    $cash_balance = floatval($party_balance_data['cash_balance'] ?? 0);
                    $bank_balance = floatval($party_balance_data['bank_balance'] ?? 0);
                    
                    // Party total (cash + bank) changes by balance_change = -invoice + payment.
                    // Apply invoice to the same leg as payment; ledger-only adjustments hit cash.
                    if ($payment_type === 'cash') {
                        $new_cash_balance = $cash_balance + $cash_amount - $amount_invoice;
                        $new_bank_balance = $bank_balance;
                    } elseif ($payment_type === 'bank') {
                        $new_cash_balance = $cash_balance;
                        $new_bank_balance = $bank_balance + $bank_amount - $amount_invoice;
                    } else {
                        $new_cash_balance = $cash_balance - $amount_invoice;
                        $new_bank_balance = $bank_balance;
                    }
                    $update_party_sql = "UPDATE parties SET 
                        cash_balance = $new_cash_balance,
                        bank_balance = $new_bank_balance
                        WHERE id = $party_id AND company_id = $company_id";
                    
                    if (!$conn->query($update_party_sql)) {
                        throw new Exception("Error updating party balance: " . $conn->error);
                    }
                    
                    // Update Account Balance (Shop's physical balance)
                    // Purchase = Money Out (-).
                    // Use $payment_amount (Total) and $payment_method determined earlier.
                    if ($payment_method === 'Cash') {
                        updateAccountBalance($conn, $company_id, 'Cash', -$payment_amount);
                    } else {
                        updateAccountBalance($conn, $company_id, 'Bank', -$payment_amount);
                    }

                    foreach ($parsed_lines as $pl) {
                        $sid_line = (int) $pl['stock_id'];
                        purchase_adjust_stock_by_row_id($conn, $company_id, $sid_line, $pl['weight']);
                        $sn_ins = $conn->real_escape_string($pl['stock_name']);
                        $gw = $pl['weight'];
                        $pu = $pl['purity'];
                        $fn = $pl['fine'];
                        $rt = $pl['rate'];
                        $am = $pl['amount'];
                        $ins_it = "INSERT INTO gold_purchase_items (company_id, transaction_id, receipt_id, stock_name, gold_weight, purity, stock_ref_id, fine_weight, rate, amount) VALUES ($company_id, $tid_for_items, '$receipt_id', '$sn_ins', $gw, $pu, $sid_line, $fn, $rt, $am)";
                        if (!$conn->query($ins_it)) {
                            throw new Exception('Error saving purchase lines: ' . $conn->error);
                        }
                    }
                    
                    $conn->commit();
                    
                    // Get party contact for receipt
                    $party_contact_sql = "SELECT contact_no FROM parties WHERE id = $party_id";
                    $party_contact_result = $conn->query($party_contact_sql);
                    $party_contact_data = $party_contact_result->fetch_assoc();
                    $party_contact = $party_contact_data['contact_no'] ?? '';
                    
                    // Return success response
                    header('Content-Type: application/json');
                    echo json_encode([
                        'status' => 'success',
                        'message' => $is_edit ? 'Gold purchase updated successfully' : 'Gold purchase completed successfully',
                        'data' => [
                            'transaction_id' => $tid_for_items,
                            'receipt_id' => $receipt_id,
                            'party_name' => $party_name,
                            'party_contact' => $party_contact,
                            'purchase_weight' => $purchase_weight,
                            'purity' => $purity,
                            'rate' => gold_rate_to_display($rate, $gold_rate_unit),
                            'amount' => $amount_invoice,
                            'payment_amount' => $payment_amount,
                            'payment_method' => $payment_method,
                            'cash_amount' => $cash_amount,
                            'bank_amount' => $bank_amount,
                            'date_of_transaction' => $date_of_transaction
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
                
            case 'search_parties':
                $search = $conn->real_escape_string($_POST['term']);
                $sql = "SELECT DISTINCT p.id, p.party_name, p.address, p.contact_no, p.state
                        FROM parties p 
                        WHERE p.company_id = $company_id AND p.party_name LIKE '%$search%' 
                        ORDER BY p.party_name
                        LIMIT 10";
                $result = $conn->query($sql);
                $parties = [];
                while ($row = $result->fetch_assoc()) {
                    $parties[] = [
                        'id' => $row['id'],
                        'party_name' => $row['party_name'],
                        'address' => $row['address'],
                        'contact_no' => $row['contact_no'],
                        'state' => $row['state'] ?? ''
                    ];
                }
                echo json_encode($parties);
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
                
                $sql = "INSERT INTO parties (company_id, party_name, address, contact_no, gstin, state, city, bank_name, account_no, ifsc_code, cash_balance, bank_balance, gold_balance) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("isssssssssddd", $company_id, $party_name, $address, $contact_no, $gstin, $state, $city, $bank_name, $account_no, $ifsc_code, $cash_balance, $bank_balance, $gold_balance);
                
                if ($stmt->execute()) {
                    $party_id = $stmt->insert_id;
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Party added successfully',
                        'party_id' => $party_id
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Error adding party: ' . $stmt->error
                    ]);
                }
                exit;
                
            case 'generate_purchase_id':
                $purchaseId = next_receipt_id($conn, $company_id, 'P', [
                    'transaction_type' => 'Purchase',
                    'pad_length' => 3,
                ]);

                echo json_encode([
                    'status' => 'success',
                    'purchase_id' => $purchaseId
                ]);
                exit;
                
            case 'get_purchase_details':
                purchase_ensure_gold_purchase_items_stock_ref($conn);
                $receipt_id = $conn->real_escape_string($_POST['receipt_id'] ?? '');
                $transaction_id = isset($_POST['transaction_id']) ? intval($_POST['transaction_id']) : 0;
                
                $sql = "SELECT t.*, p.party_name, p.contact_no, p.state AS party_state
                        FROM transactions t
                        LEFT JOIN parties p ON t.party_id = p.id
                        WHERE t.company_id = $company_id
                        AND t.transaction_type = 'Purchase'";
                
                if ($transaction_id > 0) {
                    $sql .= " AND t.id = $transaction_id";
                } else if ($receipt_id) {
                    $sql .= " AND t.receipt_id = '$receipt_id'";
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Transaction ID or Receipt ID required'
                    ]);
                    exit;
                }
                
                $sql .= " LIMIT 1";
                $result = $conn->query($sql);
                
                if ($result && $result->num_rows > 0) {
                    $transaction = $result->fetch_assoc();
                    purchase_ensure_gold_purchase_items_table($conn);
                    $items = [];
                    $tid = intval($transaction['id']);
                    $iq = $conn->query("SELECT * FROM gold_purchase_items WHERE transaction_id = $tid AND company_id = $company_id ORDER BY id");
                    if ($iq) {
                        while ($row = $iq->fetch_assoc()) {
                            gold_rate_apply_display_to_row($row, $gold_rate_unit);
                            $items[] = $row;
                        }
                    }
                    gold_rate_apply_display_to_row($transaction, $gold_rate_unit);
                    $transaction['items'] = $items;
                    echo json_encode([
                        'status' => 'success',
                        'data' => $transaction
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Purchase transaction not found'
                    ]);
                }
                exit;
                
            case 'delete_purchase':
                purchase_ensure_gold_purchase_items_table($conn);
                purchase_ensure_gold_purchase_items_stock_ref($conn);
                $conn->begin_transaction();
                try {
                    $transaction_id = intval($_POST['transaction_id']);
                    
                    // Get transaction details before deleting
                    $get_sql = "SELECT t.*, p.party_name 
                               FROM transactions t
                               LEFT JOIN parties p ON t.party_id = p.id
                               WHERE t.id = $transaction_id 
                               AND t.company_id = $company_id
                               AND t.transaction_type = 'Purchase'";
                    $get_result = $conn->query($get_sql);
                    
                    if (!$get_result || $get_result->num_rows === 0) {
                        throw new Exception("Transaction not found");
                    }
                    
                    $transaction = $get_result->fetch_assoc();
                    $party_id = $transaction['party_id'];
                    $gold_weight = floatval($transaction['gold_weight']);
                    $purity = floatval($transaction['purity']);
                    $payment_amount = floatval($transaction['payment_amount']);
                    $payment_method = $transaction['payment_method'];
                    
                    $purchase_amt_del = floatval($transaction['amount']);
                    if ($payment_amount <= 0) {
                        $update_party_sql = "UPDATE parties SET cash_balance = cash_balance + $purchase_amt_del WHERE id = $party_id AND company_id = $company_id";
                    } elseif ($payment_method === 'Cash') {
                        $del_delta = $payment_amount - $purchase_amt_del;
                        $update_party_sql = "UPDATE parties SET cash_balance = cash_balance - $del_delta WHERE id = $party_id AND company_id = $company_id";
                    } else {
                        $del_delta = $payment_amount - $purchase_amt_del;
                        $update_party_sql = "UPDATE parties SET bank_balance = bank_balance - $del_delta WHERE id = $party_id AND company_id = $company_id";
                    }
                    
                    if (!$conn->query($update_party_sql)) {
                        throw new Exception("Error reversing party balance: " . $conn->error);
                    }

                    $line_q = $conn->query("SELECT stock_ref_id, stock_name, purity, gold_weight FROM gold_purchase_items WHERE transaction_id = $transaction_id AND company_id = $company_id");
                    $had_lines = ($line_q && $line_q->num_rows > 0);
                    if ($had_lines) {
                        while ($lr = $line_q->fetch_assoc()) {
                            $ref_id = isset($lr['stock_ref_id']) ? intval($lr['stock_ref_id']) : 0;
                            if ($ref_id > 0) {
                                purchase_adjust_stock_by_row_id($conn, $company_id, $ref_id, -floatval($lr['gold_weight']));
                            } else {
                                purchase_adjust_line_stock($conn, $company_id, $lr['stock_name'], floatval($lr['purity']), -floatval($lr['gold_weight']));
                            }
                        }
                    } else {
                        $stock_sql = "SELECT id, current_stock FROM gold_stock WHERE purity = $purity AND company_id = $company_id ORDER BY id DESC LIMIT 1";
                        $stock_result = $conn->query($stock_sql);
                        if ($stock_result && $stock_result->num_rows > 0) {
                            $stock_data = $stock_result->fetch_assoc();
                            $stock_id = $stock_data['id'];
                            $current_stock = $stock_data['current_stock'];
                            $new_stock = max(0, $current_stock - $gold_weight);
                            $update_stock_sql = "UPDATE gold_stock SET current_stock = $new_stock, last_updated = NOW() WHERE id = $stock_id";
                            if (!$conn->query($update_stock_sql)) {
                                throw new Exception("Error reversing gold stock: " . $conn->error);
                            }
                        }
                    }
                    $conn->query("DELETE FROM gold_purchase_items WHERE transaction_id = $transaction_id AND company_id = $company_id");
                    
                    // Delete transaction
                    $delete_sql = "DELETE FROM transactions WHERE id = $transaction_id AND company_id = $company_id";
                    if (!$conn->query($delete_sql)) {
                        throw new Exception("Error deleting transaction: " . $conn->error);
                    }
                    
                    $conn->commit();
                    
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Purchase transaction deleted successfully'
                    ]);
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }
                exit;
                
            case 'get_purchase_list':
                // Fetch recent purchase transactions for dropdown / refreshes
                $list_sql = "SELECT t.id, t.receipt_id, t.date_of_transaction, t.gold_weight, t.rate, t.gold_amount, t.purity, t.payment_amount, t.payment_method, p.party_name,
                            (SELECT GROUP_CONCAT(DISTINCT gpi.stock_name ORDER BY gpi.id SEPARATOR ', ')
                             FROM gold_purchase_items gpi
                             WHERE gpi.transaction_id = t.id AND gpi.company_id = t.company_id) AS purchase_stock_names
                            FROM transactions t
                            LEFT JOIN parties p ON t.party_id = p.id
                            WHERE t.transaction_type = 'Purchase' AND t.company_id = $company_id
                            ORDER BY t.date_of_transaction DESC, t.id DESC
                            LIMIT 20";
                
                $list_result = $conn->query($list_sql);
                
                if ($list_result) {
                    $purchases = [];
                    while ($row = $list_result->fetch_assoc()) {
                        gold_rate_apply_display_to_row($row, $gold_rate_unit);
                        $purchases[] = $row;
                    }
                    
                    echo json_encode([
                        'status' => 'success',
                        'data' => $purchases
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Failed to fetch purchase list'
                    ]);
                }
                exit;
        }
    }
}

// Get date range from user input (default: today only)
$start_date = (isset($_GET['start_date']) && $_GET['start_date'] !== '') ? $conn->real_escape_string($_GET['start_date']) : date('Y-m-d');
$end_date = (isset($_GET['end_date']) && $_GET['end_date'] !== '') ? $conn->real_escape_string($_GET['end_date']) : date('Y-m-d');

// Enhanced statistics SQL query for purchase page (reflects the same date range as the list below)
$stats_sql = "
SELECT 
    SUM(CASE WHEN transaction_type = 'Purchase' THEN gold_weight ELSE 0 END) AS total_purchase_weight,
    SUM(CASE WHEN transaction_type = 'Purchase' AND payment_method = 'Cash' THEN gold_weight ELSE 0 END) AS total_purchase_weight_cash,
    SUM(CASE WHEN transaction_type = 'Purchase' AND payment_method IN ('Bank', 'UPI', 'Cheque') THEN gold_weight ELSE 0 END) AS total_purchase_weight_bank,
    SUM(CASE WHEN transaction_type = 'Purchase' THEN gold_amount ELSE 0 END) AS total_purchase_amount,
    SUM(CASE WHEN transaction_type = 'Purchase' AND payment_method = 'Cash' THEN payment_amount ELSE 0 END) AS total_cash_purchase,
    SUM(CASE WHEN transaction_type = 'Purchase' AND payment_method IN ('Bank', 'UPI', 'Cheque') THEN payment_amount ELSE 0 END) AS total_bank_purchase,
    COUNT(CASE WHEN transaction_type = 'Purchase' THEN 1 END) AS total_purchases,

    -- Amount Received (Sales)
    SUM(CASE WHEN transaction_type = 'Received' AND payment_method = 'Cash' THEN payment_amount ELSE 0 END) AS total_cash_received,
    SUM(CASE WHEN transaction_type = 'Received' AND payment_method = 'Bank' THEN payment_amount ELSE 0 END) AS total_bank_received,
    SUM(CASE WHEN transaction_type = 'Received' AND payment_method = 'UPI' THEN payment_amount ELSE 0 END) AS total_upi_received,
    SUM(CASE WHEN transaction_type = 'Received' AND payment_method = 'Cheque' THEN payment_amount ELSE 0 END) AS total_cheque_received,
    
    -- Stock information
    (SELECT current_stock FROM gold_stock WHERE purity = 99.50 AND company_id = $company_id ORDER BY id DESC LIMIT 1) AS purity_99_50_stock,
    (SELECT current_stock FROM gold_stock WHERE purity = 99.90 AND company_id = $company_id ORDER BY id DESC LIMIT 1) AS purity_99_90_stock,
    (SELECT current_stock FROM gold_stock WHERE purity = 91.60 AND company_id = $company_id ORDER BY id DESC LIMIT 1) AS purity_91_60_stock
FROM transactions
WHERE DATE(date_of_transaction) BETWEEN '$start_date' AND '$end_date' AND company_id = $company_id";

$stats_result = $conn->query($stats_sql);
if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
} else {
    $stats = [
        'total_purchase_weight' => 0,
        'total_purchase_weight_cash' => 0,
        'total_purchase_weight_bank' => 0,
        'total_purchase_amount' => 0,
        'total_cash_purchase' => 0,
        'total_bank_purchase' => 0,
        'total_purchases' => 0,
        'purity_99_50_stock' => 0,
        'purity_99_90_stock' => 0,
        'purity_91_60_stock' => 0
    ];
}

// Each vault row (Gold / Silver, Cash / Bank) — one dropdown option per row (no purity-only merge)
$purchase_stock_sql = "SELECT id, stock_name, purity, current_stock, mode, category FROM gold_stock WHERE company_id = $company_id ORDER BY category ASC, purity DESC, mode ASC, stock_name ASC";
$purchase_stock_result = $conn->query($purchase_stock_sql);
$purchase_stock_rows = [];
$purchase_stock_options_inner = '';
if ($purchase_stock_result) {
    while ($stock_row = $purchase_stock_result->fetch_assoc()) {
        if (stripos((string) $stock_row['stock_name'], 'mix') !== false) {
            continue;
        }
        $purchase_stock_rows[] = $stock_row;
        $is_silver = (isset($stock_row['category']) && strcasecmp((string) $stock_row['category'], 'Silver') === 0)
            || stripos((string) $stock_row['stock_name'], 'silver') !== false;
        $pfx = $is_silver ? 'Ag · ' : 'Au · ';
        $mode_lbl = $stock_row['mode'] ?? 'Cash';
        $p_disp = number_format((float) $stock_row['purity'], 2);
        $opt_label = $pfx . $stock_row['stock_name'] . ' (' . $p_disp . '%) · ' . $mode_lbl;
        $purchase_stock_options_inner .= sprintf(
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

// Get cash in hand from account_balances table
$cash_in_hand = 0;
// We check for 'Cash' account type
$cash_sql = "SELECT current_balance FROM account_balances WHERE company_id = $company_id AND account_type = 'Cash'";
$cash_result = $conn->query($cash_sql);
if ($cash_result && $cash_result->num_rows > 0) {
    $cash_row = $cash_result->fetch_assoc();
    $cash_in_hand = $cash_row['current_balance'] ?? 0;
}

$bank_balance_shop = 0;
$bank_shop_sql = "SELECT SUM(current_balance) as total FROM account_balances WHERE company_id = $company_id AND account_type = 'Bank'";
$bank_shop_result = $conn->query($bank_shop_sql);
if ($bank_shop_result) {
    $bank_balance_shop = $bank_shop_result->fetch_assoc()['total'] ?? 0;
}

// Get recent purchase transactions for the selected date range (defaults to today, shows ALL matches - no pagination cap)
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$where_clause = $search ? "AND (t.receipt_id LIKE '%$search%' OR t.narration LIKE '%$search%')" : '';

$date_clause = " AND DATE(t.date_of_transaction) BETWEEN '$start_date' AND '$end_date' ";

$transactions_sql = "SELECT t.*, p.party_name, p.contact_no,
                    (SELECT GROUP_CONCAT(DISTINCT gpi.stock_name ORDER BY gpi.id SEPARATOR ', ')
                     FROM gold_purchase_items gpi
                     WHERE gpi.transaction_id = t.id AND gpi.company_id = t.company_id) AS purchase_stock_names
                    FROM transactions t 
                    LEFT JOIN parties p ON t.party_id = p.id
                    WHERE t.transaction_type = 'Purchase' 
                    AND t.company_id = $company_id
                    $date_clause
                    $where_clause 
                    ORDER BY t.date_of_transaction DESC, t.id DESC";

// Debug: Check if transactions are being fetched
// Uncomment the line below to debug
// error_log("Transactions SQL: " . $transactions_sql);

$transactions = $conn->query($transactions_sql);
$total_transactions = $transactions ? $transactions->num_rows : 0;
?>

<!-- Page-specific styles -->
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

        .btn-primary {
            background-color: var(--primary);
            border: none;
            color: #000;
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
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
        
        /* Professional party list styling */
        .party-item {
            white-space: normal;
            overflow: hidden;
            word-wrap: break-word;
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-weight: 400;
            letter-spacing: -0.01em;
        }
        
        .party-item .text-sm {
            font-weight: 500;
            letter-spacing: -0.02em;
        }
        
        .party-item .text-xs {
            font-weight: 400;
            letter-spacing: 0;
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

        /* Statistics grid responsive */
        @media (max-width: 768px) {
            .grid.grid-cols-1.md\\:grid-cols-2.lg\\:grid-cols-5 {
                grid-template-columns: repeat(1, minmax(0, 1fr)) !important;
            }
            
            .responsive-table th,
            .responsive-table td {
                font-size: 0.7rem !important;
                padding: 0.25rem 0.125rem !important;
            }
        }

        @media (min-width: 769px) and (max-width: 1024px) {
            .grid.grid-cols-1.md\\:grid-cols-2.lg\\:grid-cols-5 {
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
    
    /* Receipt modal styles */
    .receipt-modal {
        font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    }
    
    .receipt-html-container {
        max-height: 70vh;
        overflow-y: auto;
    }
    
    /* Hide payment fields that are not active */
    .payment-field.hidden {
        display: none !important;
    }
    
    /* Clickable row styles */
    .selectable-row {
        transition: background-color 0.2s ease;
    }
    
    .selectable-row:hover {
        background-color: #f0f9ff !important;
        cursor: pointer;
    }
    
    .selectable-row:active {
        background-color: #e0f2fe !important;
    }
    .soft-gradient-cyan { background: linear-gradient(135deg, rgba(6, 182, 212, 0.1), rgba(6, 182, 212, 0.05)); }
    .bg-danger-soft { background: linear-gradient(135deg, rgba(254, 226, 226, 0.55), rgba(254, 202, 202, 0.2)); }

    /* Keep the Recent Purchases list within a fixed viewport height and scroll internally
       instead of growing the whole page when there are many purchases for the day. */
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

<div class="w-full px-1 pb-4">
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 xl:grid-cols-8 gap-2 mb-4">
        <div class="soft-gradient-green rounded-xl p-2 shadow-sm flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[9px] font-bold text-slate-800 uppercase tracking-tighter opacity-80 leading-none mb-0.5">Purch. Wt</p>
                    <p class="text-[13px] font-bold text-slate-800 leading-none"><?= number_format($stats['total_purchase_weight'] ?? 0, 2) ?><span class="text-[9px] ml-0.5">g</span></p>
                </div>
                <div class="w-6 h-6 bg-slate-500 rounded flex items-center justify-center"><i class="fas fa-shopping-basket text-white text-[9px]"></i></div>
            </div>
            <div class="flex items-center gap-3 mt-1 opacity-90">
                <div class="flex items-center gap-1 min-w-0">
                    <i class="fas fa-wallet text-slate-600 text-[9px] shrink-0"></i>
                    <span class="text-[10px] font-bold text-slate-800 leading-none truncate"><?= number_format((float)($stats['total_purchase_weight_cash'] ?? 0), 2) ?> <span class="text-[9px] font-bold text-slate-600">g</span></span>
                </div>
                <div class="flex items-center gap-1 min-w-0">
                    <i class="fas fa-university text-slate-600 text-[9px] shrink-0"></i>
                    <span class="text-[10px] font-bold text-slate-800 leading-none truncate"><?= number_format((float)($stats['total_purchase_weight_bank'] ?? 0), 2) ?> <span class="text-[9px] font-bold text-slate-600">g</span></span>
                </div>
            </div>
        </div>
        <div class="soft-gradient-teal rounded-xl p-2 shadow-sm flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[9px] font-bold text-teal-700 uppercase tracking-tighter opacity-80 leading-none mb-0.5">Paid Out</p>
                    <p class="text-[13px] font-bold text-teal-800 leading-none">₹<?= number_format(($stats['total_cash_purchase'] ?? 0) + ($stats['total_bank_purchase'] ?? 0), 0) ?></p>
                </div>
                <div class="w-6 h-6 bg-teal-500 rounded flex items-center justify-center"><i class="fas fa-arrow-down text-white text-[9px]"></i></div>
            </div>
            <div class="flex items-center gap-3 mt-1 opacity-90">
                <div class="flex items-center gap-1 min-w-0">
                    <i class="fas fa-wallet text-teal-600 text-[9px] shrink-0"></i>
                    <span class="text-[10px] font-bold text-teal-700 leading-none truncate"><?= number_format($stats['total_cash_purchase'] ?? 0, 0) ?></span>
                </div>
                <div class="flex items-center gap-1 min-w-0">
                    <i class="fas fa-university text-teal-600 text-[9px] shrink-0"></i>
                    <span class="text-[10px] font-bold text-teal-700 leading-none truncate"><?= number_format($stats['total_bank_purchase'] ?? 0, 0) ?></span>
                </div>
            </div>
        </div>
        <div class="soft-gradient-cyan rounded-xl p-2 shadow-sm flex flex-col justify-center">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[9px] font-bold text-cyan-700 uppercase tracking-tighter opacity-80 leading-none mb-0.5">Cash</p>
                    <p class="text-[13px] font-bold text-cyan-800 leading-none">₹<?= number_format($cash_in_hand, 0) ?></p>
                </div>
                <div class="w-6 h-6 bg-cyan-500 rounded flex items-center justify-center"><i class="fas fa-wallet text-white text-[9px]"></i></div>
            </div>
        </div>
        <div class="soft-gradient-blue rounded-xl p-2 shadow-sm flex flex-col justify-center">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[9px] font-bold text-blue-700 uppercase tracking-tighter opacity-80 leading-none mb-0.5">Bank</p>
                    <p class="text-[13px] font-bold text-blue-800 leading-none">₹<?= number_format($bank_balance_shop, 0) ?></p>
                </div>
                <div class="w-6 h-6 bg-blue-500 rounded flex items-center justify-center"><i class="fas fa-university text-white text-[9px]"></i></div>
            </div>
        </div>
        <?php foreach ($purchase_stock_rows as $stock):
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
        <div class="soft-gradient-orange rounded-xl p-2 shadow-sm flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[9px] font-bold text-orange-700 uppercase tracking-tighter opacity-80 leading-none mb-0.5">
                        <span class="text-amber-800">Au</span>
                        <?= htmlspecialchars($stock['stock_name']) ?></p>
                    <p class="text-[13px] font-bold text-orange-800 leading-none"><?= number_format($tot, 2) ?><span class="text-[9px] ml-0.5">g</span></p>
                </div>
                <div class="w-6 h-6 bg-orange-500 rounded flex items-center justify-center"><i class="fas fa-box text-white text-[9px]"></i></div>
            </div>
            <div class="flex items-center gap-2 mt-1 opacity-90">
                <div class="flex items-center gap-0.5">
                    <i class="fas fa-wallet text-orange-600 text-[7px]"></i>
                    <span class="text-[8px] font-bold text-orange-700"><?= number_format($cash_part, 2) ?></span>
                </div>
                <div class="flex items-center gap-0.5">
                    <i class="fas fa-bank text-orange-600 text-[7px]"></i>
                    <span class="text-[8px] font-bold text-orange-700"><?= number_format($bank_part, 2) ?></span>
                </div>
                <span class="text-[7px] font-bold text-orange-400 ml-auto"><?= number_format((float) $stock['purity'], 2) ?>%</span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="flex flex-col lg:flex-row gap-3">
        <div class="bg-white rounded-lg shadow-md border border-gray-200" style="flex: 0 0 55%;">
            <form id="purchaseForm" method="POST" onsubmit="return false;" class="overflow-hidden">
                <input type="hidden" name="action" value="save_purchase">
                <input type="hidden" name="transaction_id" id="editTransactionId" value="">
                <input type="hidden" name="purchase_items" id="purchaseItemsJson" value="">
                <input type="hidden" name="purchase_weight" id="purchaseWeightHidden" value="">
                <input type="hidden" name="purity" id="purityHidden" value="">
                <input type="hidden" name="rate" id="rateHidden" value="">
                <input type="hidden" name="amount" id="amountHidden" value="">
                <input type="hidden" id="purchaseSubtotalHidden" value="0">
                <input type="hidden" name="final_invoice_amount" id="purchaseFinalInvoiceHidden" value="0">
                <input type="hidden" name="receipt_method" id="purchasePayModeInternal" value="Cash">
                <input type="hidden" name="mode" id="purchaseModeHidden" value="Cash">
                <input type="hidden" name="taxable_amount" id="purchaseTaxableAmountHidden" value="0">
                <input type="hidden" name="cgst" id="purchaseCgstHidden" value="0">
                <input type="hidden" name="sgst" id="purchaseSgstHidden" value="0">
                <input type="hidden" name="igst" id="purchaseIgstHidden" value="0">
                <input type="hidden" name="total_gst" id="purchaseTotalGstHidden" value="0">

                <div class="bg-violet-50 px-3 py-1 border-b border-violet-100">
                    <h3 class="text-xs font-bold text-violet-900 flex items-center"><i class="fas fa-file-invoice mr-1.5 text-xs"></i> Transaction Details</h3>
                </div>
                <div class="p-2 grid grid-cols-12 gap-1.5">
                    <div class="relative col-span-3">
                        <label class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter">Purchase ID <span id="editModeIndicator" class="text-orange-600 hidden">(Edit)</span></label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-gray-500"><i class="fas fa-hashtag text-xs"></i></span>
                            <input type="text" name="receipt_id" id="purchaseIdInput" readonly tabindex="0" class="block w-full pl-7 pr-8 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-violet-400 cursor-pointer">
                            <button type="button" class="absolute right-1 top-1/2 -translate-y-1/2 text-gray-400 hover:text-violet-600" id="showPurchaseListBtn" title="History"><i class="fas fa-history text-xs"></i></button>
                        </div>
                        <div id="purchaseList" class="hidden absolute z-[60] mt-1 bg-white border border-gray-200 rounded-lg shadow-xl max-h-64 overflow-y-auto w-[min(100%,22rem)]"></div>
                    </div>
                    <div class="relative col-span-3">
                        <label class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter">Date</label>
                        <span class="absolute inset-y-0 left-0 top-5 pl-2 flex items-center pointer-events-none text-purple-500"><i class="fas fa-calendar-alt text-xs"></i></span>
                        <input type="datetime-local" name="date_of_transaction" value="<?= date('Y-m-d\TH:i') ?>" required class="block w-full pl-7 pr-1 py-1.5 text-[11px] font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-purple-400">
                    </div>
                    <div class="relative col-span-6">
                        <label class="block text-[10px] font-bold text-gray-700 mb-0.5 flex items-center justify-between uppercase tracking-tighter compact-label">
                            <span>Party Name</span>
                            <button type="button" id="addNewPartyBtn" class="text-blue-600 hover:text-blue-800 font-bold transition-all hover:scale-105 active:scale-95 flex items-center uppercase tracking-tighter text-[9px]">
                                <i class="fas fa-plus-circle mr-1 text-[10px]"></i> Add New
                            </button>
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-blue-500">
                                <i class="fas fa-user text-xs"></i>
                            </span>
                            <input type="hidden" name="party_id" id="partyId">
                            <input type="text" name="party_name" id="partyNameInput" required autocomplete="off" spellcheck="false" placeholder="Select Party"
                                class="block w-full pl-7 pr-2 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-blue-400 compact-input">
                        </div>
                        <div id="partyList" class="hidden absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-48 overflow-y-auto"></div>
                    </div>
                </div>

                <div class="bg-slate-50 px-3 py-1 border-t border-b border-slate-200">
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <h3 class="text-xs font-bold text-violet-900 flex items-center"><i class="fas fa-arrow-down mr-1.5 text-xs"></i> Gold items (purchase)</h3>
                        <div class="flex items-center gap-2 flex-wrap">
                            <div class="inline-flex bg-white/50 rounded-lg p-0.5 border border-slate-200">
                                <button type="button" id="purchaseCashModeBtn"
                                    class="px-3 py-1 rounded text-[9px] font-bold uppercase transition-all flex items-center gap-1.5 bg-white text-violet-700 shadow-sm border border-violet-100">
                                    <i class="fas fa-money-bill-wave"></i> Cash
                                </button>
                                <button type="button" id="purchaseBankModeBtn"
                                    class="px-3 py-1 rounded text-[9px] font-bold uppercase transition-all flex items-center gap-1.5 text-slate-500 hover:text-blue-600">
                                    <i class="fas fa-university"></i> Bank
                                </button>
                            </div>
                            <button type="button" id="addPurchaseItemBtn" class="bg-violet-600 hover:bg-violet-700 text-white px-3 py-1 rounded text-[10px] font-bold shadow-sm"><i class="fas fa-plus mr-1"></i> Add item</button>
                        </div>
                    </div>
                </div>
                <div class="p-2">
                    <div class="border border-gray-200 rounded overflow-hidden">
                        <table class="w-full text-xs table-fixed">
                            <thead class="bg-slate-100">
                                <tr class="text-[9px] uppercase font-bold text-slate-600 tracking-tighter">
                                    <th class="px-2 py-1.5 text-left border-b w-[6%]">#</th>
                                    <th class="px-2 py-1.5 text-left border-b w-[34%]"><i class="fas fa-box text-violet-500 mr-1"></i>Stock</th>
                                    <th class="px-2 py-1.5 text-left border-b w-[16%]">Wt (g)</th>
                                    <th class="px-2 py-1.5 text-left border-b w-[16%]">Rate (<?= htmlspecialchars($gold_rate_label) ?>)</th>
                                    <th class="px-2 py-1.5 text-left border-b w-[20%]">Amount</th>
                                    <th class="px-2 py-1.5 text-center border-b w-[8%]"></th>
                                </tr>
                            </thead>
                        </table>
                        <div class="overflow-y-auto border-t border-gray-100" style="max-height: 140px;">
                            <table class="w-full text-xs table-fixed">
                                <tbody id="purchaseItemsBody">
                                    <tr class="purchase-item-row group">
                                        <td class="px-2 py-1 border-b text-gray-500 font-bold w-[6%] item-num">1</td>
                                        <td class="px-2 py-1 border-b w-[34%]">
                                            <select class="w-full px-1 py-1 text-xs font-bold text-violet-900 bg-white border border-gray-200 rounded purchase-stock-select">
                                                <option value="" data-purity="0" data-stock-name="" data-category="" data-mode="">Select stock</option>
                                                <?= $purchase_stock_options_inner ?>
                                            </select>
                                        </td>
                                        <td class="px-2 py-1 border-b w-[16%]"><input type="number" step="0.001" class="w-full px-1 py-1 text-xs font-bold border border-gray-200 rounded purchase-weight" placeholder="0.000"></td>
                                        <td class="px-2 py-1 border-b w-[16%]"><input type="number" step="0.01" class="w-full px-1 py-1 text-xs font-bold border border-gray-200 rounded purchase-rate" placeholder="0.00"></td>
                                        <td class="px-2 py-1 border-b w-[20%]"><input type="text" readonly class="w-full px-1 py-1 text-[10px] font-bold text-gray-600 bg-gray-50 border border-gray-200 rounded purchase-amt cursor-not-allowed" value=""></td>
                                        <td class="px-2 py-1 border-b text-center w-[8%]"><button type="button" class="text-red-400 hover:text-red-600 purchase-remove-row" title="Remove"><i class="fas fa-trash-alt"></i></button></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <table class="w-full text-xs border-t border-slate-200">
                            <tfoot class="bg-slate-50/80 font-bold">
                                <tr>
                                    <td colspan="2" class="px-2 py-1.5 text-right text-[10px] uppercase text-slate-500">Totals</td>
                                    <td class="px-2 py-1.5"><input type="text" id="purchaseTotalWeightDisplay" readonly class="w-full bg-transparent border-none text-[11px] font-semibold text-slate-800 p-0" value="0.000"></td>
                                    <td class="px-2 py-1.5"></td>
                                    <td class="px-2 py-1.5"><input type="text" id="purchaseTotalAmountDisplay" readonly class="w-full bg-transparent border-none text-xs font-semibold text-violet-800 p-0" value="0.00"></td>
                                    <td></td>
                                </tr>
                                <tr id="purchaseGstRow" class="hidden bg-blue-50/50 border-t border-blue-100">
                                    <td colspan="4" class="px-2 py-1 text-right text-[9px] font-semibold text-blue-600 uppercase tracking-tighter" id="purchaseGstLabel">GST (3%):</td>
                                    <td class="px-2 py-1">
                                        <input type="text" id="purchaseGstAmountInput" readonly class="w-full bg-transparent border-none text-[10px] font-semibold text-blue-700 p-0 cursor-not-allowed" value="0.00">
                                    </td>
                                    <td></td>
                                </tr>
                                <tr id="purchaseRoundOffRow" class="hidden bg-gray-50/50 border-t border-gray-100">
                                    <td colspan="4" class="px-2 py-1 text-right text-[9px] font-semibold text-gray-500 uppercase tracking-tighter">Round off:</td>
                                    <td class="px-2 py-1">
                                        <input type="text" id="purchaseRoundOffInput" readonly class="w-full bg-transparent border-none text-[10px] font-semibold text-gray-500 p-0 cursor-not-allowed" value="0.00">
                                    </td>
                                    <td></td>
                                </tr>
                                <tr id="purchaseFinalTotalRow" class="hidden bg-violet-600 border-t border-violet-700">
                                    <td colspan="4" class="px-2 py-1.5 text-right text-[10px] font-semibold text-white uppercase tracking-tighter">Final (incl. GST):</td>
                                    <td class="px-2 py-1.5">
                                        <input type="text" id="purchaseFinalTotalDisplay" readonly class="w-full bg-transparent border-none text-xs font-semibold text-white p-0 cursor-not-allowed" value="0.00">
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <div class="bg-violet-50 px-3 py-1.5 border-t border-violet-100 flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                    <h3 class="text-xs font-bold text-violet-900 flex items-center"><i class="fas fa-money-bill-wave mr-1.5 text-xs"></i> Payment</h3>
                    <label class="inline-flex items-center gap-1.5 cursor-pointer text-[10px] text-violet-900 shrink-0" title="No cash or bank movement: purchase is booked only on the party running balance.">
                        <input type="checkbox" id="adjustFromLedger" class="rounded border-gray-300 text-violet-600 focus:ring-violet-500 shrink-0">
                        <span class="font-semibold">Party balance only</span>
                    </label>
                </div>
                <div class="p-2" id="paymentSection">
                    <div class="flex flex-wrap items-end gap-2">
                        <div id="paymentMethodWrap" class="w-full min-w-0 sm:w-[11rem] sm:shrink-0">
                            <label class="block text-[10px] font-bold text-gray-600 mb-0.5 uppercase">Payment method</label>
                            <select name="payment_method_select" id="purchasePaymentMethodSelect" class="block w-full px-2 py-1.5 text-xs font-bold border border-gray-200 rounded bg-white">
                                <option value="">— Balance only —</option>
                                <option value="cash">Cash</option>
                                <option value="Bank" selected>Bank</option>
                                <option value="UPI">UPI</option>
                                <option value="Cheque">Cheque</option>
                            </select>
                        </div>
                        <div id="paymentSectionPayFields" class="w-full min-w-0 sm:w-[11rem] sm:shrink-0">
                            <div id="cashPaymentField" class="payment-field hidden">
                                <label class="block text-[10px] font-bold text-gray-600 mb-0.5 uppercase">Cash paid</label>
                                <input type="number" step="0.01" name="cash_amount" value="" placeholder="Amount" class="block w-full px-2 py-1.5 text-xs border border-gray-200 rounded bg-white">
                            </div>
                            <div id="bankPaymentField" class="payment-field">
                                <label class="block text-[10px] font-bold text-gray-600 mb-0.5 uppercase">Amount paid</label>
                                <input type="number" step="0.01" name="bank_amount" value="" placeholder="Amount" class="block w-full px-2 py-1.5 text-xs border border-gray-200 rounded bg-white">
                            </div>
                        </div>
                        <div id="narrationWrap" class="w-full min-w-0 flex-1 sm:min-w-[12rem]">
                            <label class="block text-[10px] font-bold text-gray-600 mb-0.5 uppercase">Narration</label>
                            <input type="text" name="narration" class="block w-full px-2 py-1.5 text-xs border border-gray-200 rounded bg-white" placeholder="Optional notes">
                        </div>
                    </div>
                </div>

                <div class="bg-gray-50 px-3 py-2 border-t border-gray-200 flex items-center gap-2 justify-end">
                    <button type="button" id="resetFormBtn" class="px-3 py-1.5 bg-white border border-gray-300 text-gray-700 text-xs font-bold rounded hover:bg-gray-50"><i class="fas fa-undo mr-1"></i>Reset</button>
                    <button type="submit" id="purchaseGoldBtn" class="px-5 py-1.5 bg-violet-600 text-white text-xs font-bold rounded hover:bg-violet-700 shadow-sm"><i class="fas fa-save mr-1"></i><span id="submitButtonText">Save purchase</span></button>
                </div>
            </form>
        </div>

            <!-- Right Side - Recent Purchases (same table layout as Recent Sales) -->
            <div class="bg-white rounded-lg shadow-md border border-gray-200" style="flex: 0 0 45%;">
                <div class="bg-blue-50 px-3 py-1.5 border-b border-blue-100 rounded-t-lg">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-xs font-bold text-blue-800 flex items-center">
                            <i class="fas fa-list mr-1.5 text-xs"></i> Recent purchases
                        </h2>
                        <form method="GET" action="" class="flex items-center gap-1.5">
                            <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>"
                                class="px-1.5 py-0.5 border border-gray-200 rounded text-[10px] w-24 focus:ring-1 focus:ring-blue-400 bg-white font-medium">
                            <span class="text-gray-400 text-[10px] font-bold">to</span>
                            <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>"
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
                                    <th class="py-2 px-2 text-left text-[9px] font-bold text-slate-500">Party</th>
                                    <th class="py-2 px-2 text-left text-[9px] font-bold text-slate-500">Stock</th>
                                    <th class="py-2 px-2 text-right text-[9px] font-bold text-slate-500">Weight</th>
                                    <th class="py-2 px-2 text-right text-[9px] font-bold text-slate-500">Rate</th>
                                    <th class="py-2 px-2 text-right text-[9px] font-bold text-slate-500">Amount</th>
                                    <th class="py-2 px-2 text-right text-[9px] font-bold text-slate-500">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php if ($transactions && $transactions->num_rows > 0):
                                foreach ($transactions as $t):
                                    $gw = floatval($t['gold_weight'] ?? 0);
                                    $pu = floatval($t['purity'] ?? 0);
                                    $gamt = floatval($t['gold_amount'] ?? 0);
                                    $paid = floatval($t['payment_amount'] ?? 0);
                                    $stock_label = trim((string)($t['purchase_stock_names'] ?? ''));
                                    if ($stock_label === '') {
                                        $stock_label = '—';
                                    }
                                    $t_row = $t;
                                ?>
                                    <tr class="hover:bg-slate-50 transition-colors border-b border-slate-100 last:border-b-0 selectable-row cursor-pointer"
                                        data-receipt-id="<?= htmlspecialchars($t['receipt_id'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-transaction-id="<?= intval($t['id']) ?>"
                                        data-transaction="<?= htmlspecialchars(base64_encode(json_encode($t_row)), ENT_QUOTES, 'UTF-8') ?>">
                                        <td class="py-1.5 px-2 align-top">
                                            <div class="text-[10px] font-bold text-blue-600 truncate">#<?= htmlspecialchars($t['receipt_id']) ?></div>
                                            <div class="text-[8px] font-bold text-slate-400 uppercase leading-tight"><?= date('d M', strtotime($t['date_of_transaction'])) ?></div>
                                        </td>
                                        <td class="py-1.5 px-2 align-top">
                                            <div class="text-[10px] font-semibold text-slate-800 truncate max-w-[72px] uppercase" title="<?= htmlspecialchars($t['party_name'] ?? '') ?>"><?= htmlspecialchars($t['party_name'] ?? '—') ?></div>
                                        </td>
                                        <td class="py-1.5 px-2 align-top">
                                            <div class="text-[10px] font-medium text-slate-700 truncate max-w-[88px] leading-tight" title="<?= htmlspecialchars($stock_label) ?>"><?= htmlspecialchars($stock_label) ?></div>
                                        </td>
                                        <td class="py-1.5 px-2 align-top text-right">
                                            <div class="text-[10px] font-bold text-slate-700 leading-none"><?= number_format($gw, 3) ?><span class="text-[8px] font-normal ml-0.5">g</span></div>
                                            <div class="text-[8px] font-bold text-slate-400 mt-0.5"><?= number_format($pu, 1) ?>%</div>
                                        </td>
                                        <td class="py-1.5 px-2 align-top text-right">
                                            <div class="text-[10px] font-semibold text-slate-800 leading-none">&#8377;<?= number_format(gold_rate_to_display(floatval($t['rate'] ?? 0), $gold_rate_unit), 0) ?><span class="text-[8px] font-normal text-slate-500"><?= htmlspecialchars($gold_rate_suffix) ?></span></div>
                                        </td>
                                        <td class="py-1.5 px-2 align-top text-right">
                                            <div class="text-[10px] font-bold text-slate-800 leading-none">&#8377;<?= number_format($gamt, 0) ?></div>
                                            <div class="mt-1">
                                                <?php if ($paid >= $gamt && $gamt > 0): ?>
                                                    <span class="text-[7.5px] px-1 py-0.5 rounded bg-slate-100 text-slate-800 font-bold uppercase">Paid</span>
                                                <?php elseif ($paid > 0): ?>
                                                    <span class="text-[7.5px] px-1 py-0.5 rounded bg-yellow-100 text-yellow-700 font-bold uppercase">Part</span>
                                                <?php else: ?>
                                                    <span class="text-[7.5px] px-1 py-0.5 rounded bg-rose-100 text-rose-700 font-bold uppercase">Due</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="py-1.5 px-2 align-top">
                                            <div class="flex items-center justify-end gap-1">
                                                <button type="button" class="edit-purchase-row text-blue-500 hover:text-blue-700 p-0.5" title="Edit"
                                                    data-transaction-id="<?= (int)$t['id'] ?>">
                                                    <i class="fas fa-edit text-[9px]"></i>
                                                </button>
                                                <button type="button" class="print-transaction text-blue-500 hover:text-blue-700 p-0.5" title="Print"
                                                    data-id="<?= (int)$t['id'] ?>">
                                                    <i class="fas fa-print text-[9px]"></i>
                                                </button>
                                                <button type="button" class="delete-transaction text-slate-400 hover:text-red-600 p-0.5" title="Delete"
                                                    data-id="<?= (int)$t['id'] ?>" data-receipt-id="<?= htmlspecialchars($t['receipt_id']) ?>"
                                                    data-weight="<?= htmlspecialchars((string)$gw) ?>" data-amount="<?= htmlspecialchars((string)$gamt) ?>">
                                                    <i class="fas fa-trash text-[9px]"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach;
                                else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-8 text-gray-500">
                                            <i class="fas fa-inbox text-2xl mb-2"></i><br>No purchases found
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

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="js/keyboard-navigation-generic.js"></script>
    <script src="js/gold-rate-utils.js"></script>
    <script>
        const COMPANY_STATE = "<?php echo htmlspecialchars($company_state ?? '', ENT_QUOTES, 'UTF-8'); ?>";
        window.GOLD_RATE_CONFIG = <?= json_encode(gold_rate_js_config($gold_rate_unit)) ?>;

        function formatIndian(num, decimals) {
            if (typeof decimals !== 'number') decimals = 2;
            if (isNaN(num)) return decimals === 0 ? '0' : '0.00';
            return new Intl.NumberFormat('en-IN', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals
            }).format(num);
        }

        function openPurchaseReceiptPrint(transactionId) {
            if (!transactionId) return null;
            const url = 'print_purchase_receipt.php?id=' + encodeURIComponent(transactionId);
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
            const win = window.open(url, 'purchaseReceiptPrint_' + transactionId, features);
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

        // Global function to show purchase success modal with receipt
        function showPurchaseSuccess(msg, purchaseData) {
            if (!window.Swal) {
                alert(msg);
                return Promise.resolve();
            }
            
            // Format date
            const purchaseDate = purchaseData?.date_of_transaction 
                ? new Date(purchaseData.date_of_transaction).toLocaleString('en-IN', {
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                })
                : new Date().toLocaleString('en-IN');
            
            // Calculate amounts
            const totalAmount = parseFloat(purchaseData?.amount || 0);
            const cashAmount = parseFloat(purchaseData?.cash_amount || 0);
            const bankAmount = parseFloat(purchaseData?.bank_amount || 0);
            const totalPaid = cashAmount + bankAmount;
            
            // Get company name
            const companyName = '<?= htmlspecialchars($company_name) ?>' || 'Gold Trading Company';
            
            // Create receipt HTML
            const receiptHTML = `
                <div id="purchase-receipt" class="receipt-container" style="max-width: 400px; margin: 0 auto; font-family: Arial, sans-serif;">
                    <div class="receipt-header" style="text-align: center; border-bottom: 2px dashed #333; padding-bottom: 15px; margin-bottom: 15px;">
                        <div style="font-size: 20px; font-weight: bold; margin-bottom: 5px;">${companyName}</div>
                        <div style="font-size: 12px; color: #666;">Purchase Receipt</div>
                    </div>
                    
                    <div class="receipt-body" style="font-size: 13px;">
                        <div style="margin-bottom: 12px;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                <span style="color: #666;">Receipt ID:</span>
                                <span style="font-weight: bold;">${purchaseData?.receipt_id || 'N/A'}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                <span style="color: #666;">Date:</span>
                                <span>${purchaseDate}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between;">
                                <span style="color: #666;">Party:</span>
                                <span style="font-weight: bold;">${purchaseData?.party_name || 'N/A'}</span>
                            </div>
                        </div>
                        
                        <div style="border-top: 1px dashed #ccc; border-bottom: 1px dashed #ccc; padding: 12px 0; margin: 12px 0;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span style="color: #666;">Weight:</span>
                                <span style="font-weight: bold;">${parseFloat(purchaseData?.purchase_weight || 0).toFixed(3)} g</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span style="color: #666;">Purity:</span>
                                <span>${parseFloat(purchaseData?.purity || 0).toFixed(2)}%</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span style="color: #666;">Rate:</span>
                                <span>${window.GoldRateUtils ? GoldRateUtils.formatRateText(purchaseData?.rate || 0, 2) : '₹' + parseFloat(purchaseData?.rate || 0).toLocaleString('en-IN', {minimumFractionDigits: 2}) + '/g'}</span>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 12px; border-radius: 5px; margin: 12px 0;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span style="color: #666;">Total Amount:</span>
                                <span style="font-weight: bold; font-size: 16px;">₹${totalAmount.toLocaleString('en-IN', {minimumFractionDigits: 2})}</span>
                            </div>
                            ${cashAmount > 0 ? `
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span style="color: #666;">Cash Paid:</span>
                                <span style="color: #dc3545; font-weight: bold;">₹${cashAmount.toLocaleString('en-IN', {minimumFractionDigits: 2})}</span>
                            </div>
                            ` : ''}
                            ${bankAmount > 0 ? `
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span style="color: #666;">Bank Paid:</span>
                                <span style="color: #dc3545; font-weight: bold;">₹${bankAmount.toLocaleString('en-IN', {minimumFractionDigits: 2})}</span>
                            </div>
                            ` : ''}
                            ${totalPaid > 0 ? `
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px; border-top: 1px solid #ddd; padding-top: 8px; margin-top: 8px;">
                                <span style="color: #666;">Total Paid:</span>
                                <span style="color: #dc3545; font-weight: bold;">₹${totalPaid.toLocaleString('en-IN', {minimumFractionDigits: 2})}</span>
                            </div>
                            ` : ''}
                            ${purchaseData?.payment_method ? `
                            <div style="display: flex; justify-content: space-between; margin-top: 8px;">
                                <span style="color: #666;">Payment Method:</span>
                                <span style="font-weight: 500;">${purchaseData.payment_method}</span>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                    
                    <div class="receipt-footer" style="text-align: center; border-top: 2px dashed #333; padding-top: 15px; margin-top: 15px; font-size: 11px; color: #666;">
                        <div>Thank you for your business!</div>
                    </div>
                </div>
            `;
            
            // Store the promise from Swal.fire() so we can return it
            const swalPromise = Swal.fire({
                title: 'Purchase Completed Successfully!',
                html: receiptHTML,
                width: '500px',
                showCancelButton: true,
                showDenyButton: true,
                confirmButtonText: '<i class="fab fa-whatsapp"></i> Send WhatsApp',
                denyButtonText: '<i class="fas fa-print"></i> Print',
                cancelButtonText: 'OK',
                confirmButtonColor: '#25D366',
                denyButtonColor: '#007bff',
                cancelButtonColor: '#6c757d',
                reverseButtons: true,
                allowOutsideClick: false,
                allowEscapeKey: true,
                focusConfirm: false,
                customClass: {
                    popup: 'receipt-modal',
                    htmlContainer: 'receipt-html-container'
                },
                didOpen: () => {
                    // Ensure buttons are keyboard accessible
                    const modal = document.querySelector('.swal2-popup');
                    if (modal) {
                        const confirmBtn = modal.querySelector('.swal2-confirm');
                        const denyBtn = modal.querySelector('.swal2-deny');
                        const cancelBtn = modal.querySelector('.swal2-cancel');
                        
                        // Add proper ARIA labels and ensure buttons are focusable
                        [confirmBtn, denyBtn, cancelBtn].forEach(btn => {
                            if (btn) {
                                btn.setAttribute('tabindex', '0');
                                btn.setAttribute('role', 'button');
                                if (btn.textContent.trim() === '' || btn.innerHTML.includes('<i')) {
                                    const text = btn.innerHTML.match(/>([^<]+)</)?.[1] || '';
                                    if (text) {
                                        btn.setAttribute('aria-label', text.trim());
                                    }
                                }
                            }
                        });
                        
                        // Focus first button after a short delay
                        setTimeout(() => {
                            if (cancelBtn) {
                                cancelBtn.focus();
                            } else if (denyBtn) {
                                denyBtn.focus();
                            } else if (confirmBtn) {
                                confirmBtn.focus();
                            }
                        }, 200);
                    }
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    // Send WhatsApp
                    sendPurchaseWhatsApp(purchaseData, companyName);
                } else if (result.isDenied) {
                    const tid = purchaseData?.transaction_id;
                    if (tid) {
                        openPurchaseReceiptPrint(tid);
                    } else {
                        printPurchaseReceipt(purchaseData, companyName);
                    }
                }
                return result;
            });
            
            return swalPromise;
        }
        
        // Send purchase receipt via WhatsApp
        function sendPurchaseWhatsApp(purchaseData, companyName) {
            if (!purchaseData?.party_contact) {
                Swal.fire('Error', 'Party contact number not available', 'error');
                return;
            }
            
            const totalAmount = parseFloat(purchaseData.amount || 0);
            const cashAmount = parseFloat(purchaseData.cash_amount || 0);
            const bankAmount = parseFloat(purchaseData.bank_amount || 0);
            const totalPaid = cashAmount + bankAmount;
            
            const message = `*${companyName}*\n` +
                `*Purchase Receipt*\n\n` +
                `Receipt ID: *${purchaseData.receipt_id}*\n` +
                `Date: ${new Date(purchaseData.date_of_transaction || new Date()).toLocaleString('en-IN')}\n` +
                `Party: *${purchaseData.party_name}*\n\n` +
                `Weight: ${parseFloat(purchaseData.purchase_weight).toFixed(3)} g\n` +
                `Purity: ${parseFloat(purchaseData.purity).toFixed(2)}%\n` +
                `Rate: ${window.GoldRateUtils ? GoldRateUtils.formatRateText(purchaseData.rate, 2) : '₹' + parseFloat(purchaseData.rate).toLocaleString('en-IN', {minimumFractionDigits: 2}) + '/g'}\n\n` +
                `Total Amount: *₹${totalAmount.toLocaleString('en-IN', {minimumFractionDigits: 2})}*\n` +
                (totalPaid > 0 ? `Total Paid: ₹${totalPaid.toLocaleString('en-IN', {minimumFractionDigits: 2})}\n` : '') +
                (purchaseData.payment_method ? `Payment Method: ${purchaseData.payment_method}\n` : '') +
                `\nThank you for your business!`;
            
            const phoneNumber = purchaseData.party_contact.replace(/[\s\-\(\)]/g, '');
            const whatsappUrl = `https://wa.me/${phoneNumber}?text=${encodeURIComponent(message)}`;
            
            window.open(whatsappUrl, '_blank');
        }
        
        // Print purchase receipt (thermal printer compatible)
        function printPurchaseReceipt(purchaseData, companyName) {
            const totalAmount = parseFloat(purchaseData.amount || 0);
            const cashAmount = parseFloat(purchaseData.cash_amount || 0);
            const bankAmount = parseFloat(purchaseData.bank_amount || 0);
            const totalPaid = cashAmount + bankAmount;
            const purchaseDate = purchaseData.date_of_transaction 
                ? new Date(purchaseData.date_of_transaction).toLocaleString('en-IN')
                : new Date().toLocaleString('en-IN');
            
            const printContent = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Print Receipt - ${purchaseData.receipt_id}</title>
                    <style>
                        @page {
                            size: 80mm auto;
                            margin: 5mm;
                        }
                        
                        * {
                            margin: 0;
                            padding: 0;
                            box-sizing: border-box;
                        }
                        
                        body {
                            font-family: 'Courier New', monospace;
                            font-size: 11pt;
                            width: 70mm;
                            margin: 0 auto;
                            padding: 5mm;
                        }
                        
                        .receipt-header {
                            text-align: center;
                            border-bottom: 1px dashed #000;
                            padding-bottom: 8px;
                            margin-bottom: 8px;
                        }
                        
                        .company-name {
                            font-size: 14pt;
                            font-weight: bold;
                            margin-bottom: 3px;
                        }
                        
                        .receipt-title {
                            font-size: 10pt;
                            color: #666;
                        }
                        
                        .receipt-body {
                            font-size: 10pt;
                            line-height: 1.4;
                        }
                        
                        .receipt-row {
                            display: flex;
                            justify-content: space-between;
                            margin-bottom: 4px;
                        }
                        
                        .receipt-label {
                            color: #666;
                        }
                        
                        .receipt-value {
                            font-weight: bold;
                        }
                        
                        .receipt-divider {
                            border-top: 1px dashed #000;
                            margin: 8px 0;
                        }
                        
                        .receipt-footer {
                            text-align: center;
                            border-top: 1px dashed #000;
                            padding-top: 8px;
                            margin-top: 8px;
                            font-size: 9pt;
                            color: #666;
                        }
                        
                        @media print {
                            body {
                                margin: 0;
                                padding: 5mm;
                            }
                        }
                    </style>
                </head>
                <body>
                    <div class="receipt-header">
                        <div class="company-name">${companyName}</div>
                        <div class="receipt-title">Purchase Receipt</div>
                    </div>
                    
                    <div class="receipt-body">
                        <div class="receipt-row">
                            <span class="receipt-label">Receipt ID:</span>
                            <span class="receipt-value">${purchaseData.receipt_id}</span>
                        </div>
                        <div class="receipt-row">
                            <span class="receipt-label">Date:</span>
                            <span>${purchaseDate}</span>
                        </div>
                        <div class="receipt-row">
                            <span class="receipt-label">Party:</span>
                            <span class="receipt-value">${purchaseData.party_name}</span>
                        </div>
                        
                        <div class="receipt-divider"></div>
                        
                        <div class="receipt-row">
                            <span class="receipt-label">Weight:</span>
                            <span class="receipt-value">${parseFloat(purchaseData.purchase_weight).toFixed(3)} g</span>
                        </div>
                        <div class="receipt-row">
                            <span class="receipt-label">Purity:</span>
                            <span>${parseFloat(purchaseData.purity).toFixed(2)}%</span>
                        </div>
                        <div class="receipt-row">
                            <span class="receipt-label">Rate:</span>
                            <span>${window.GoldRateUtils ? GoldRateUtils.formatRateText(purchaseData.rate, 2) : '₹' + parseFloat(purchaseData.rate).toLocaleString('en-IN', {minimumFractionDigits: 2}) + '/g'}</span>
                        </div>
                        
                        <div class="receipt-divider"></div>
                        
                        <div class="receipt-row">
                            <span class="receipt-label">Total Amount:</span>
                            <span class="receipt-value">₹${totalAmount.toLocaleString('en-IN', {minimumFractionDigits: 2})}</span>
                        </div>
                        ${cashAmount > 0 ? `
                        <div class="receipt-row">
                            <span class="receipt-label">Cash Paid:</span>
                            <span>₹${cashAmount.toLocaleString('en-IN', {minimumFractionDigits: 2})}</span>
                        </div>
                        ` : ''}
                        ${bankAmount > 0 ? `
                        <div class="receipt-row">
                            <span class="receipt-label">Bank Paid:</span>
                            <span>₹${bankAmount.toLocaleString('en-IN', {minimumFractionDigits: 2})}</span>
                        </div>
                        ` : ''}
                        ${totalPaid > 0 ? `
                        <div class="receipt-row">
                            <span class="receipt-label">Total Paid:</span>
                            <span class="receipt-value">₹${totalPaid.toLocaleString('en-IN', {minimumFractionDigits: 2})}</span>
                        </div>
                        ` : ''}
                        ${purchaseData.payment_method ? `
                        <div class="receipt-row">
                            <span class="receipt-label">Payment Method:</span>
                            <span>${purchaseData.payment_method}</span>
                        </div>
                        ` : ''}
                    </div>
                    
                    <div class="receipt-footer">
                        <div>Thank you for your business!</div>
                    </div>
                </body>
                </html>
            `;
            
            const printWindow = window.open('', '_blank');
            printWindow.document.write(printContent);
            printWindow.document.close();
            
            setTimeout(() => {
                printWindow.print();
                printWindow.close();
            }, 250);
        }

        $(document).ready(function() {
            // Generate purchase ID
            function generatePurchaseId() {
                return new Promise((resolve, reject) => {
                    fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=generate_purchase_id'
                    })
                    .then(response => response.json())
                    .then(result => {
                        if (result.status === 'success') {
                            resolve(result.purchase_id);
                        } else {
                            // Fallback to client-side generation
                            const companyId = <?= $company_id ?>;
                            const serial = Math.floor(Math.random() * 999) + 1;
                            resolve(`P${companyId}${serial.toString().padStart(3, '0')}`);
                        }
                    })
                    .catch(error => {
                        // Fallback to client-side generation
                        const companyId = <?= $company_id ?>;
                        const serial = Math.floor(Math.random() * 999) + 1;
                        resolve(`P${companyId}${serial.toString().padStart(3, '0')}`);
                    });
                });
            }
            
            // Set initial values
            async function initializeForm() {
                try {
                    const purchaseId = await generatePurchaseId();
                    $('#purchaseIdInput').val(purchaseId);
                } catch (error) {
                    console.error('Error generating purchase ID:', error);
                    // Fallback to client-side generation
                    const companyId = <?= $company_id ?>;
                    const serial = Math.floor(Math.random() * 999) + 1;
                    $('#purchaseIdInput').val(`P${companyId}${serial.toString().padStart(3, '0')}`);
                }
                
                const now = new Date();
                now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
                $('[name="date_of_transaction"]').val(now.toISOString().slice(0, 16));
            }
            
            initializeForm();

            const purchaseItemRowTemplate = $('#purchaseItemsBody tr.purchase-item-row:first').prop('outerHTML');

            function bindPurchaseItemEvents(row) {
                const itemSelect = row.querySelector('.purchase-stock-select');
                const wInput = row.querySelector('.purchase-weight');
                const rInput = row.querySelector('.purchase-rate');
                if (!itemSelect || !wInput || !rInput) return;

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
                        const nextSelect = nextRow ? nextRow.querySelector('.purchase-stock-select') : null;
                        if (nextSelect) {
                            nextSelect.focus();
                        } else {
                            const cashAmt = document.querySelector('[name="cash_amount"]');
                            const paySel = document.getElementById('purchasePaymentMethodSelect');
                            if (cashAmt && paySel && paySel.value === 'cash' && !cashAmt.disabled) {
                                cashAmt.focus();
                                cashAmt.select();
                            } else {
                                const bankAmt = document.querySelector('[name="bank_amount"]');
                                if (bankAmt && !bankAmt.disabled) {
                                    bankAmt.focus();
                                    bankAmt.select();
                                } else if (paySel) {
                                    paySel.focus();
                                }
                            }
                        }
                    }
                });
            }

            function bindAllPurchaseItemRows() {
                document.querySelectorAll('#purchaseItemsBody tr.purchase-item-row').forEach(bindPurchaseItemEvents);
            }

            function renumberPurchaseRows() {
                $('#purchaseItemsBody tr.purchase-item-row').each(function (i) {
                    $(this).find('.item-num').text(i + 1);
                });
            }

            function setPurchasePaymentMode(mode) {
                const payH = document.getElementById('purchasePayModeInternal');
                const modeH = document.getElementById('purchaseModeHidden');
                const cashBtn = document.getElementById('purchaseCashModeBtn');
                const bankBtn = document.getElementById('purchaseBankModeBtn');
                if (!payH || !modeH) return;
                payH.value = mode;
                modeH.value = mode;
                if (mode === 'Cash') {
                    if (cashBtn) {
                        cashBtn.classList.add('bg-white', 'text-violet-700', 'shadow-sm', 'border-violet-100');
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
                        cashBtn.classList.remove('bg-white', 'text-violet-700', 'shadow-sm', 'border-violet-100');
                        cashBtn.classList.add('text-slate-500');
                    }
                }
                const pPay = document.getElementById('purchasePaymentMethodSelect');
                if (pPay && !document.getElementById('adjustFromLedger').checked) {
                    pPay.value = mode === 'Cash' ? 'cash' : 'Bank';
                    $(pPay).trigger('change');
                }
            }

            function applyPurchaseGstTotals(totalAmount) {
                const mode = document.getElementById('purchasePayModeInternal').value;
                const gstRow = document.getElementById('purchaseGstRow');
                const roundOffRow = document.getElementById('purchaseRoundOffRow');
                const finalTotalRow = document.getElementById('purchaseFinalTotalRow');
                const partyEl = document.getElementById('partyNameInput');
                const state = partyEl ? (partyEl.dataset.state || '') : '';
                const myState = COMPANY_STATE || 'West Bengal';

                $('#purchaseTotalAmountDisplay').val(formatIndian(totalAmount, 0));
                $('#purchaseSubtotalHidden').val(totalAmount.toFixed(2));

                if (!gstRow || !roundOffRow || !finalTotalRow) return;

                if (mode === 'Bank') {
                    let gstRate = 0.03;
                    let gstLabelText = 'IGST (3%):';
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
                    document.getElementById('purchaseGstLabel').textContent = gstLabelText;
                    document.getElementById('purchaseGstAmountInput').value = formatIndian(gstAmount, 0);
                    document.getElementById('purchaseRoundOffInput').value = roundOff.toFixed(2);
                    document.getElementById('purchaseFinalTotalDisplay').value = formatIndian(roundedFinal, 0);

                    $('#purchaseTaxableAmountHidden').val(totalAmount.toFixed(2));
                    $('#purchaseTotalGstHidden').val(gstAmount.toFixed(2));
                    if (gstLabelText.includes('CGST')) {
                        $('#purchaseCgstHidden').val((gstAmount / 2).toFixed(2));
                        $('#purchaseSgstHidden').val((gstAmount / 2).toFixed(2));
                        $('#purchaseIgstHidden').val('0');
                    } else {
                        $('#purchaseCgstHidden').val('0');
                        $('#purchaseSgstHidden').val('0');
                        $('#purchaseIgstHidden').val(gstAmount.toFixed(2));
                    }
                    $('#amountHidden').val(roundedFinal.toFixed(2));
                    $('#purchaseFinalInvoiceHidden').val(roundedFinal.toFixed(2));
                } else {
                    gstRow.classList.add('hidden');
                    roundOffRow.classList.add('hidden');
                    finalTotalRow.classList.add('hidden');
                    $('#purchaseTaxableAmountHidden').val('0');
                    $('#purchaseCgstHidden').val('0');
                    $('#purchaseSgstHidden').val('0');
                    $('#purchaseIgstHidden').val('0');
                    $('#purchaseTotalGstHidden').val('0');
                    $('#amountHidden').val(totalAmount.toFixed(2));
                    $('#purchaseFinalInvoiceHidden').val(totalAmount.toFixed(2));
                }
                $(document).trigger('purchaseTotalsUpdated');
            }

            function focusFirstPurchaseStockFine() {
                const firstSel = document.querySelector('#purchaseItemsBody tr.purchase-item-row .purchase-stock-select');
                if (!firstSel) return;
                for (let i = 0; i < firstSel.options.length; i++) {
                    const opt = firstSel.options[i];
                    if (opt.text.toLowerCase().includes('fine')) {
                        firstSel.value = opt.value;
                        firstSel.dispatchEvent(new Event('change', { bubbles: true }));
                        break;
                    }
                }
                firstSel.focus();
            }

            function recalcPurchaseItems() {
                const items = [];
                let tw = 0, ta = 0, wp = 0;
                $('#purchaseItemsBody tr.purchase-item-row').each(function () {
                    const $r = $(this);
                    const sel = $r.find('.purchase-stock-select')[0];
                    if (!sel || sel.selectedIndex < 0) return;
                    const opt = sel.options[sel.selectedIndex];
                    if (!opt) return;
                    const stockId = parseInt(String(sel.value), 10) || 0;
                    const sn = opt.getAttribute('data-stock-name') || '';
                    const p = parseFloat(opt.getAttribute('data-purity') || '0') || 0;
                    const category = opt.getAttribute('data-category') || '';
                    const mode = opt.getAttribute('data-mode') || '';
                    const w = parseFloat($r.find('.purchase-weight').val()) || 0;
                    const rt = parseFloat($r.find('.purchase-rate').val()) || 0;
                    const ratePerGram = (window.GoldRateUtils && GoldRateUtils.effectivePerGram)
                        ? GoldRateUtils.effectivePerGram(rt)
                        : rt;
                    const amt = Math.round(w * ratePerGram * 100) / 100;
                    $r.find('.purchase-amt').val(amt > 0 ? amt.toFixed(2) : '');
                    if (w > 0 && stockId > 0) {
                        items.push({
                            stock_id: stockId,
                            stock_name: sn,
                            purity: p,
                            category: category,
                            mode: mode,
                            weight: w,
                            rate: rt,
                            fine: Math.round(w * p / 100 * 1000) / 1000
                        });
                        tw += w;
                        ta += amt;
                        wp += w * p;
                    }
                });
                $('#purchaseTotalWeightDisplay').val(tw.toFixed(3));
                $('#purchaseWeightHidden').val(tw.toFixed(3));
                $('#purityHidden').val(tw > 0 ? (wp / tw).toFixed(2) : '0');
                $('#rateHidden').val(tw > 0 ? (ta / tw).toFixed(2) : '0');
                $('#purchaseItemsJson').val(JSON.stringify(items));
                renumberPurchaseRows();
                applyPurchaseGstTotals(ta);
            }

            $(document).on('input change', '.purchase-weight, .purchase-rate, .purchase-stock-select', recalcPurchaseItems);
            $('#addPurchaseItemBtn').on('click', function () {
                const $row = $(purchaseItemRowTemplate);
                $('#purchaseItemsBody').append($row);
                bindPurchaseItemEvents($row[0]);
                recalcPurchaseItems();
            });
            $(document).on('click', '.purchase-remove-row', function () {
                if ($('#purchaseItemsBody tr.purchase-item-row').length <= 1) return;
                $(this).closest('tr').remove();
                recalcPurchaseItems();
            });
            recalcPurchaseItems();
            bindAllPurchaseItemRows();
            setPurchasePaymentMode('Cash');

            const purchaseCashModeBtn = document.getElementById('purchaseCashModeBtn');
            const purchaseBankModeBtn = document.getElementById('purchaseBankModeBtn');
            if (purchaseCashModeBtn && purchaseBankModeBtn) {
                purchaseCashModeBtn.addEventListener('click', () => {
                    setPurchasePaymentMode('Cash');
                    applyPurchaseGstTotals(parseFloat($('#purchaseSubtotalHidden').val()) || 0);
                });
                purchaseBankModeBtn.addEventListener('click', () => {
                    setPurchasePaymentMode('Bank');
                    applyPurchaseGstTotals(parseFloat($('#purchaseSubtotalHidden').val()) || 0);
                });
            }

            if (typeof KeyboardNavigationGeneric !== 'undefined') {
                KeyboardNavigationGeneric.init({
                    formId: 'purchaseForm',
                    fieldOrder: [
                        'purchaseIdInput',
                        'date_of_transaction',
                        'partyNameInput',
                        'purchasePaymentMethodSelect',
                        'cash_amount',
                        'bank_amount',
                        'narration'
                    ],
                    skipFields: [],
                    submitButtonId: 'purchaseGoldBtn',
                    formName: 'purchase'
                });
                window.KeyboardNavigation = KeyboardNavigationGeneric;
            }

            // Party search (same UX as sales.php: suggestion-item + keyboard + create-new rows)
            let partyListVisible = false;
            let partySelectIndex = -1;
            let selectedPartyName = '';

            function updatePartySelectionStatus(isSelected) {
                /* Visual state handled by validation module; avoid extra border toggles */
            }

            function updatePurchasePartySuggestionHighlight(items) {
                items.forEach((item, index) => {
                    const nameEl = item.querySelector('.suggestion-name');
                    const addrEl = item.querySelector('.suggestion-address');
                    const iconBgEl = item.querySelector('.suggestion-icon-container');
                    const iconEl = item.querySelector('.suggestion-icon');
                    const isSlateRow = item.classList.contains('bg-slate-50');

                    if (index === partySelectIndex) {
                        item.classList.add('bg-blue-600', 'selected');
                        if (nameEl) {
                            nameEl.classList.remove('text-gray-900', 'text-slate-800');
                            nameEl.classList.add('text-white');
                        }
                        if (addrEl) {
                            addrEl.classList.remove('text-gray-500', 'text-slate-600');
                            addrEl.classList.add('text-blue-100');
                        }
                        if (iconBgEl) {
                            iconBgEl.classList.remove('bg-blue-50', 'bg-slate-100');
                            iconBgEl.classList.add('bg-blue-500');
                        }
                        if (iconEl) {
                            iconEl.classList.remove('text-blue-500', 'text-slate-600');
                            iconEl.classList.add('text-white');
                        }
                        item.scrollIntoView({ block: 'nearest' });
                    } else {
                        item.classList.remove('bg-blue-600', 'selected');
                        if (nameEl) {
                            nameEl.classList.remove('text-white');
                            nameEl.classList.add(isSlateRow ? 'text-slate-800' : 'text-gray-900');
                        }
                        if (addrEl) {
                            addrEl.classList.remove('text-blue-100');
                            addrEl.classList.add(isSlateRow ? 'text-slate-600' : 'text-gray-500');
                        }
                        if (iconBgEl) {
                            iconBgEl.classList.remove('bg-blue-500');
                            if (isSlateRow) {
                                iconBgEl.classList.remove('bg-blue-50');
                                iconBgEl.classList.add('bg-slate-100');
                            } else {
                                iconBgEl.classList.remove('bg-slate-100');
                                iconBgEl.classList.add('bg-blue-50');
                            }
                        }
                        if (iconEl) {
                            iconEl.classList.remove('text-white');
                            iconEl.classList.add(isSlateRow ? 'text-slate-600' : 'text-blue-500');
                        }
                    }
                });
            }

            function selectParty(party) {
                selectedPartyName = party.party_name || '';
                $('#partyId').val(party.id != null && party.id !== '' ? String(party.id) : '');
                const pn = document.getElementById('partyNameInput');
                if (pn) {
                    pn.value = party.party_name || '';
                    pn.dataset.state = party.state || '';
                }
                $('#partyList').addClass('hidden');
                partyListVisible = false;
                partySelectIndex = -1;
                updatePartySelectionStatus(true);
                if (typeof KeyboardNavigationGeneric !== 'undefined' && KeyboardNavigationGeneric.clearValidationError) {
                    KeyboardNavigationGeneric.clearValidationError('partyNameInput');
                }
                focusFirstPurchaseStockFine();
            }

            function renderPurchasePartySuggestions(parties, term) {
                const partyList = $('#partyList');
                partyList.empty();
                partySelectIndex = -1;

                if (!parties || !parties.length) {
                    const div = document.createElement('div');
                    div.className = 'px-3 py-2 cursor-pointer border-b border-gray-100 last:border-b-0 flex items-center gap-2 suggestion-item bg-slate-50';
                    div.innerHTML = `
                        <div class="w-6 h-6 rounded-full bg-slate-100 flex items-center justify-center text-slate-600 suggestion-icon-container">
                            <i class="fas fa-plus-circle text-[10px] suggestion-icon"></i>
                        </div>
                        <div>
                            <div class="text-xs font-bold text-slate-800 suggestion-name">Create New: &quot;${(term || '').replace(/"/g, '&quot;')}&quot;</div>
                            <div class="text-[9px] text-slate-600 italic suggestion-address">Click or press Enter to add</div>
                        </div>
                    `;
                    div.addEventListener('click', () => showAddPartyModal(term, null));
                    partyList.append(div);
                    partyList.removeClass('hidden');
                    partyListVisible = true;
                    return;
                }

                parties.forEach((p) => {
                    const div = document.createElement('div');
                    div.className = 'px-3 py-2 cursor-pointer border-b border-gray-100 last:border-b-0 flex items-center gap-2 suggestion-item';
                    const addr = (p.address || 'No address').replace(/</g, '&lt;');
                    const pname = (p.party_name || '').replace(/</g, '&lt;');
                    const contact = p.contact_no ? `<div class="text-[9px] text-gray-400 suggestion-contact">${String(p.contact_no).replace(/</g, '&lt;')}</div>` : '';
                    div.innerHTML = `
                        <div class="w-6 h-6 rounded-full bg-blue-50 flex items-center justify-center text-blue-500 suggestion-icon-container">
                            <i class="fas fa-user text-[10px] suggestion-icon"></i>
                        </div>
                        <div>
                            <div class="text-xs font-bold text-gray-900 suggestion-name">${pname}</div>
                            <div class="text-[9px] text-gray-500 italic suggestion-address">${addr}</div>
                            ${contact}
                        </div>
                    `;
                    div.addEventListener('click', () => selectParty({ id: p.id, party_name: p.party_name, address: p.address || '', state: p.state || '' }));
                    partyList.append(div);
                });

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
                addDiv.addEventListener('click', () => showAddPartyModal(term, null));
                partyList.append(addDiv);

                partyList.removeClass('hidden');
                partyListVisible = true;
            }

            $('#partyNameInput').on('input', function () {
                const raw = $(this).val();
                const term = raw.trim();

                if (raw === '') {
                    $('#partyList').addClass('hidden');
                    partyListVisible = false;
                    partySelectIndex = -1;
                    selectedPartyName = '';
                    $('#partyId').val('');
                    const pnIn = document.getElementById('partyNameInput');
                    if (pnIn) pnIn.dataset.state = '';
                    updatePartySelectionStatus(false);
                    return;
                }

                if (raw !== selectedPartyName) {
                    selectedPartyName = '';
                    $('#partyId').val('');
                    updatePartySelectionStatus(false);
                }

                $.post('', { action: 'search_parties', term: term }, function (parties) {
                    renderPurchasePartySuggestions(parties, term);
                }, 'json');
            });

            $('#partyNameInput').on('keydown', function (e) {
                if (e.altKey && (e.key === 'a' || e.key === 'A')) {
                    e.preventDefault();
                    showAddPartyModal($(this).val().trim(), null);
                    return;
                }

                const partyListEl = document.getElementById('partyList');
                const items = partyListEl ? partyListEl.querySelectorAll('.suggestion-item') : [];
                if (!partyListEl || partyListEl.classList.contains('hidden') || !items.length) {
                    return;
                }

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    partySelectIndex = (partySelectIndex + 1) % items.length;
                    updatePurchasePartySuggestionHighlight(items);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    partySelectIndex = (partySelectIndex - 1 + items.length) % items.length;
                    updatePurchasePartySuggestionHighlight(items);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (partySelectIndex >= 0 && items[partySelectIndex]) {
                        items[partySelectIndex].click();
                    }
                } else if (e.key === 'Escape') {
                    $('#partyList').addClass('hidden');
                    partyListVisible = false;
                    partySelectIndex = -1;
                } else if (e.key === ' ' && !$(this).val().trim()) {
                    e.preventDefault();
                    const el = this;
                    el.value = ' ';
                    el.dispatchEvent(new Event('input', { bubbles: true }));
                    el.value = '';
                }
            });

            $(document).on('click', function (e) {
                if (!$(e.target).closest('#partyNameInput, #partyList, #addNewPartyBtn').length && partyListVisible) {
                    $('#partyList').addClass('hidden');
                    partyListVisible = false;
                    partySelectIndex = -1;
                }
            });

            // Function to show add party modal
            function showAddPartyModal(partyName, form) {
                SharedPartyHandler.showAddPartyModal({
                    onSuccess: function(response, partyData) {
                        // Automatically select the newly created party
                        const newParty = {
                            id: response.party_id,
                            party_name: partyData.party_name,
                            address: partyData.address,
                            state: partyData.state || ''
                        };
                        
                        selectParty(newParty);
                        
                        // If form is provided, retry form submission after party is selected
                        if (form) {
                            setTimeout(() => {
                                $('#purchaseForm').trigger('submit');
                            }, 500);
                        }
                    }
                });
            }

            // Add New Party button click handler
            $('#addNewPartyBtn').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                showAddPartyModal('', null);
            });

            // Helper to reset payment fields
        function resetPaymentFields() {
            $('#purchasePaymentMethodSelect').val('Bank').prop('disabled', false);
            $('input[name="cash_amount"]').val('');
            $('input[name="bank_amount"]').val('');
            $('#adjustFromLedger').prop('checked', false);
            $('#paymentMethodWrap').removeClass('opacity-50 pointer-events-none');
            $('#paymentSectionPayFields').removeClass('opacity-50 pointer-events-none');
            $('#paymentSection').find('input, select').prop('disabled', false);
            $('#purchasePaymentMethodSelect').trigger('change');
        }

        // Adjust from Ledger toggle (checkbox stays clickable — not inside disabled overlay)
        $('#adjustFromLedger').on('change', function() {
            if ($(this).is(':checked')) {
                $('#paymentMethodWrap').addClass('opacity-50 pointer-events-none');
                $('#paymentSectionPayFields').addClass('opacity-50 pointer-events-none');
                $('#purchasePaymentMethodSelect').val('').prop('disabled', true);
                $('input[name="cash_amount"], input[name="bank_amount"]').val('').prop('disabled', true);
                applyPurchasePaymentMethodFromSelect();
            } else {
                $('#paymentMethodWrap').removeClass('opacity-50 pointer-events-none');
                $('#paymentSectionPayFields').removeClass('opacity-50 pointer-events-none');
                $('#purchasePaymentMethodSelect').prop('disabled', false);
                $('input[name="cash_amount"], input[name="bank_amount"]').prop('disabled', false);
                $('input[name="narration"]').prop('disabled', false);
                const inv = document.getElementById('purchasePayModeInternal').value;
                $('#purchasePaymentMethodSelect').val(inv === 'Bank' ? 'Bank' : 'cash').trigger('change');
            }
        });

            function applyPurchasePaymentMethodFromSelect() {
                const val = $('#purchasePaymentMethodSelect').val();
                $('.payment-field').addClass('hidden');
                if (val === 'cash') {
                    $('#cashPaymentField').removeClass('hidden');
                    $('[name="bank_amount"]').val('');
                    if (window.KeyboardNavigation && window.KeyboardNavigation.updateSkipFields) {
                        window.KeyboardNavigation.updateSkipFields(['bank_amount']);
                    }
                } else if (val && val !== '') {
                    $('#bankPaymentField').removeClass('hidden');
                    $('[name="cash_amount"]').val('');
                    if (window.KeyboardNavigation && window.KeyboardNavigation.updateSkipFields) {
                        window.KeyboardNavigation.updateSkipFields(['cash_amount']);
                    }
                } else {
                    if (window.KeyboardNavigation && window.KeyboardNavigation.updateSkipFields) {
                        window.KeyboardNavigation.updateSkipFields(['cash_amount', 'bank_amount']);
                    }
                }
            }

            $('#purchasePaymentMethodSelect').on('change', applyPurchasePaymentMethodFromSelect);

            // Initialize payment row visibility on load
            $('#purchasePaymentMethodSelect').trigger('change');

            // ===== PURITY AUTOCOMPLETE (legacy single field — skipped when using line items) =====
            let purityStocks = [];

            function fetchPurityStocks() {
                $.ajax({
                    url: '', // Post to same file
                    method: 'POST',
                    data: { action: 'get_purity_stocks' },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            purityStocks = response.stocks;
                        }
                    }
                });
            }

            // Show purity suggestions
            function showPuritySuggestions(value) {
                const purityList = $('#purityList');
                
                if (!value) {
                    // Show all stocks when empty
                    displayPurityList(purityStocks);
                    return;
                }
                
                // Filter stocks by typed value
                const filteredStocks = purityStocks.filter(stock => 
                    stock.purity.toString().includes(value)
                );
                
                displayPurityList(filteredStocks);
            }

            // Display purity list
            function displayPurityList(stocks) {
                const purityList = $('#purityList');
                
                if (stocks.length === 0) {
                    purityList.addClass('hidden');
                    return;
                }
                
                let html = '';
                stocks.forEach(stock => {
                    // For purchase, we don't necessarily care if stock is low, but we can reuse the visual cues or adapt them
                    // Let's use blue for available stock to match purchase theme, or just gray
                    const stockColor = 'text-gray-600'; 
                    const stockIcon = 'fa-check-circle';
                    
                    html += `
                        <div class="purity-item px-3 py-2 hover:bg-gray-100 cursor-pointer border-b border-gray-100 last:border-b-0"
                             data-purity="${stock.purity}">
                            <div class="flex justify-between items-center">
                                <div>
                                    <span class="font-semibold text-gray-900">${stock.purity}%</span>
                                    <span class="text-xs text-gray-500 ml-2">${stock.stock_name || 'Gold'}</span>
                                </div>
                                <div class="flex items-center ${stockColor}">
                                    <i class="fas ${stockIcon} text-xs mr-1"></i>
                                    <span class="text-sm font-medium">${parseFloat(stock.current_stock).toFixed(3)}g</span>
                                </div>
                            </div>
                        </div>
                    `;
                });
                
                purityList.html(html).removeClass('hidden');
            }

            if ($('#purityInput').length) {
                fetchPurityStocks();
                $('#purityInput').on('focus', function () { showPuritySuggestions($(this).val()); });
                $('#purityInput').on('input', function () { showPuritySuggestions($(this).val()); });
                $(document).on('click', '.purity-item', function () {
                    const purity = $(this).data('purity');
                    $('#purityInput').val(purity);
                    $('#purityList').addClass('hidden');
                    $('#purityInput').trigger('change');
                });
                $(document).on('click', function (e) {
                    if (!$(e.target).closest('#purityInput, #purityList').length) {
                        $('#purityList').addClass('hidden');
                    }
                });
                $('#purityInput').on('keydown', function (e) {
                    const purityList = $('#purityList');
                    const items = $('#purityList .purity-item');
                    const activeItem = $('#purityList .purity-item.bg-gray-200');
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        if (!purityList.hasClass('hidden')) {
                            if (activeItem.length === 0) {
                                items.first().addClass('bg-gray-200');
                            } else {
                                activeItem.removeClass('bg-gray-200');
                                const next = activeItem.next('.purity-item');
                                if (next.length > 0) {
                                    next.addClass('bg-gray-200');
                                    next[0].scrollIntoView({ block: 'nearest' });
                                }
                            }
                        }
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        if (!purityList.hasClass('hidden') && activeItem.length > 0) {
                            activeItem.removeClass('bg-gray-200');
                            const prev = activeItem.prev('.purity-item');
                            if (prev.length > 0) {
                                prev.addClass('bg-gray-200');
                                prev[0].scrollIntoView({ block: 'nearest' });
                            }
                        }
                    } else if (e.key === 'Enter') {
                        if (!purityList.hasClass('hidden') && activeItem.length > 0) {
                            e.preventDefault();
                            activeItem.click();
                        }
                    } else if (e.key === 'Escape') {
                        purityList.addClass('hidden');
                    }
                });
            }
            // ===== END PURITY AUTOCOMPLETE =====

            // Form submission
            $('#purchaseForm').on('submit', function(e) {
                e.preventDefault();
                
                const form = this;
                const partyId = $('#partyId').val();
                
                // Get party name for confirmation dialog (define it early)
                const partyName = $('#partyNameInput').val().trim() || 'Unknown Party';
                
                if (!partyId) {
                    if (partyName && partyName !== 'Unknown Party') {
                        showAddPartyModal(partyName, form);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Party Not Selected',
                            text: 'Please select a party from the dropdown list or add a new party first.'
                        });
                        $('#partyNameInput').focus();
                    }
                    return;
                }
                recalcPurchaseItems();
                const purchaseWeight = $('#purchaseWeightHidden').val();
                const purity = $('#purityHidden').val();
                const rate = $('#rateHidden').val();
                const amount = $('#amountHidden').val();
                let lineParsed = [];
                try { lineParsed = JSON.parse($('#purchaseItemsJson').val() || '[]'); } catch (e) { lineParsed = []; }
                if (!lineParsed.length || parseFloat(purchaseWeight) <= 0) {
                    Swal.fire({ icon: 'error', title: 'Line items', text: 'Add at least one row with stock, weight, and rate.' });
                    return;
                }
                if (!$('#adjustFromLedger').is(':checked') && !$('#purchasePaymentMethodSelect').val()) {
                    Swal.fire({ icon: 'warning', title: 'Payment method', text: 'Choose how you paid (Cash, Bank, etc.) or tick “Adjust from party balance only”.' });
                    $('#purchasePaymentMethodSelect').focus();
                    return;
                }
                const pmSel = $('#purchasePaymentMethodSelect').val();
                const paymentType = pmSel === 'cash' ? 'cash' : (pmSel ? 'bank' : '');
                const bankPaymentType = pmSel === 'cash' ? '' : (pmSel || '');
                const cashAmount = $('[name="cash_amount"]').val();
                const bankAmount = $('[name="bank_amount"]').val();
                const adjustLedger = $('#adjustFromLedger').is(':checked');
                const narrationRaw = ($('[name="narration"]').val() || '').trim();
                const escConfirm = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
                const partyEsc = escConfirm(partyName);
                const narrEsc = escConfirm(narrationRaw);
                const totalPurchase = parseFloat(String(amount).replace(/,/g, '')) || 0;
                const paidNum = pmSel === 'cash' ? (parseFloat(String(cashAmount).replace(/,/g, '')) || 0) : (parseFloat(String(bankAmount).replace(/,/g, '')) || 0);
                const methodLabel = adjustLedger ? 'Party balance only' : (pmSel === 'cash' ? 'Cash' : (pmSel ? bankPaymentType : '—'));
                let paymentBodyHtml = '';
                if (adjustLedger) {
                    paymentBodyHtml = `
                        <div style="display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:6px 12px;padding-top:6px;">
                            <span style="font-size:10px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;">Payment</span>
                            <span style="font-size:12px;color:#4b5563;line-height:1.35;flex:1;min-width:0;">Party balance only — no cash/bank on this entry.</span>
                        </div>`;
                } else {
                    paymentBodyHtml = `
                        <div style="display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:8px 16px;padding-top:6px;">
                            <div style="display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;">
                                <span style="font-size:10px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;">Method</span>
                                <span style="font-size:13px;font-weight:600;color:#111827;">${escConfirm(methodLabel)}</span>
                            </div>
                            <div style="display:flex;align-items:baseline;gap:8px;">
                                <span style="font-size:10px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;">Paid</span>
                                <span style="font-size:13px;font-weight:700;color:#111827;">₹${paidNum.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                            </div>
                        </div>`;
                }
                const narrBlock = narrationRaw ? `
                    <div style="margin-top:8px;padding-top:8px;border-top:1px solid #e5e7eb;">
                        <div style="font-size:10px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:2px;">Narration</div>
                        <div style="font-size:12px;color:#374151;line-height:1.4;word-break:break-word;">${narrEsc}</div>
                    </div>` : '';

                Swal.fire({
                    title: '<span style="font-size:0.95rem;font-weight:600;color:#111827;letter-spacing:-0.01em;">Confirm gold purchase</span>',
                    html: `
                        <div style="font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;text-align:left;margin-top:2px;font-size:13px;color:#111827;border:1px solid #e5e7eb;border-radius:10px;padding:10px 12px;background:#fafafa;">
                            <div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px;padding-bottom:8px;border-bottom:1px solid #e5e7eb;">
                                <span style="font-size:10px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;flex-shrink:0;">Party</span>
                                <span style="font-weight:600;text-align:right;word-break:break-word;line-height:1.3;">${partyEsc}</span>
                            </div>
                            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px 10px;padding:8px 0;border-bottom:1px solid #e5e7eb;">
                                <div>
                                    <div style="font-size:10px;color:#6b7280;text-transform:uppercase;letter-spacing:0.03em;font-weight:600;">Weight</div>
                                    <div style="font-weight:600;margin-top:1px;">${purchaseWeight} g</div>
                                </div>
                                <div>
                                    <div style="font-size:10px;color:#6b7280;text-transform:uppercase;letter-spacing:0.03em;font-weight:600;">Purity</div>
                                    <div style="font-weight:600;margin-top:1px;">${purity}%</div>
                                </div>
                                <div>
                                    <div style="font-size:10px;color:#6b7280;text-transform:uppercase;letter-spacing:0.03em;font-weight:600;">Rate</div>
                                    <div style="font-weight:600;margin-top:1px;">${window.GoldRateUtils ? GoldRateUtils.formatRateText(rate, 0) : '₹' + (parseFloat(String(rate).replace(/,/g, '')) || 0).toLocaleString('en-IN') + '/g'}</div>
                                </div>
                            </div>
                            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #e5e7eb;">
                                <span style="font-size:10px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;">Invoice total</span>
                                <span style="font-size:15px;font-weight:700;color:#111827;letter-spacing:-0.02em;">₹${totalPurchase.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                            </div>
                            ${paymentBodyHtml}
                            ${narrBlock}
                        </div>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Complete purchase',
                    cancelButtonText: 'Cancel',
                    buttonsStyling: true,
                    confirmButtonColor: '#7c3aed',
                    cancelButtonColor: '#64748b',
                    width: 'min(400px, 92vw)',
                    padding: '0.65rem 0.85rem 0.85rem',
                    customClass: {
                        popup: 'swal-purchase-confirm rounded-2xl',
                        title: 'swal-title-enhanced',
                        htmlContainer: 'swal-html-enhanced text-left',
                        confirmButton: 'swal-btn-confirm font-bold',
                        cancelButton: 'swal-btn-cancel'
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        const formData = new FormData(form);
                        
                        // Parse formatted values (remove commas) before sending
                        const amountValue = ($('#amountHidden').val() || '0').replace(/,/g, '');
                        const cashValue = ($('[name="cash_amount"]').val() || '0').replace(/,/g, '');
                        const bankValue = ($('[name="bank_amount"]').val() || '0').replace(/,/g, '');
                        formData.set('amount', amountValue);
                        formData.set('purchase_weight', $('#purchaseWeightHidden').val() || '0');
                        formData.set('purity', $('#purityHidden').val() || '0');
                        formData.set('rate', $('#rateHidden').val() || '0');
                        formData.set('purchase_items', $('#purchaseItemsJson').val() || '[]');
                        if ($('#adjustFromLedger').is(':checked')) {
                            formData.set('payment_method_select', '');
                            formData.set('cash_amount', '0');
                            formData.set('bank_amount', '0');
                        } else {
                            formData.set('payment_method_select', $('#purchasePaymentMethodSelect').val() || '');
                            formData.set('cash_amount', cashValue);
                            formData.set('bank_amount', bankValue);
                        }
                        
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
                                    text: 'Please wait while we process your purchase',
                                    allowOutsideClick: false,
                                    allowEscapeKey: false,
                                    didOpen: () => {
                                        Swal.showLoading();
                                    }
                                });
                            },
                            success: function(response) {
                                if(response.status === 'success') {
                                    const isEdit = $('#editTransactionId').val() !== '';
                                    const successMessage = isEdit ? 'Purchase updated successfully!' : 'Purchase completed successfully!';
                                    
                                    // Clear form first
                                    resetPurchaseForm();
                                    
                                    // Show success modal with receipt
                                    showPurchaseSuccess(successMessage, response.data || {})
                                        .then(() => {
                                            // Focus party field after modal closes
                                    setTimeout(() => {
                                                const partyNameField = document.getElementById('partyNameInput');
                                                if (partyNameField) {
                                                    partyNameField.focus();
                                                }
                                            }, 100);
                                            // Reload page after modal closes
                                        location.reload();
                                        });
                                } else {
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Error',
                                        text: response.message || 'Failed to save purchase'
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
                    cancelButtonColor: '#2563eb'
                }).then((result) => {
                    if (result.isConfirmed) {
                        resetPurchaseForm();
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

            // Function to reset the purchase form
            function resetPurchaseForm() {
                // Reset edit mode
                $('#editTransactionId').val('');
                $('#editModeIndicator').addClass('hidden');
                $('#submitButtonText').text('Purchase Gold');
                $('#purchaseGoldBtn').removeClass('bg-orange-600 hover:bg-orange-700').addClass('bg-blue-600 hover:bg-blue-700');
                
                // Remove form highlight
                $('#purchaseForm').closest('.bg-white').css('border', '1px solid #e5e7eb');
                
                // Reset form fields
                $('#partyId').val('');
                $('#partyNameInput').val('');
                const pnR = document.getElementById('partyNameInput');
                if (pnR) pnR.dataset.state = '';
                selectedPartyName = '';
                updatePartySelectionStatus(false);
                $('#purchaseItemsBody').html(purchaseItemRowTemplate);
                bindAllPurchaseItemRows();
                recalcPurchaseItems();
                setPurchasePaymentMode('Cash');
                $('input[name="cash_amount"], input[name="bank_amount"]').val('');
                $('[name="narration"]').val('');
                
                // Generate new purchase ID
                generatePurchaseId().then(purchaseId => {
                    $('#purchaseIdInput').val(purchaseId);
                }).catch(error => {
                    console.error('Error generating purchase ID:', error);
                    const companyId = <?= $company_id ?>;
                    const serial = Math.floor(Math.random() * 999) + 1;
                    $('#purchaseIdInput').val(`P${companyId}${serial.toString().padStart(3, '0')}`);
                });
                
                // Reset date to current time
                const now = new Date();
                now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
                $('[name="date_of_transaction"]').val(now.toISOString().slice(0, 16));
                
                // Focus on party name field
                $('#partyNameInput').focus();
            }

            // Purchase History Dropdown
            $('#showPurchaseListBtn, #purchaseIdInput').on('click', function(e) {
                e.preventDefault();
                showPurchaseList();
            });

            function showPurchaseList() {
                const purchaseList = $('#purchaseList');
                
                // Show loading
                purchaseList.html('<div class="p-3 text-center text-gray-500"><i class="fas fa-spinner fa-spin"></i> Loading...</div>');
                purchaseList.removeClass('hidden');
                
                // Fetch purchase list
                $.post('', {
                    action: 'get_purchase_list'
                }, function(response) {
                    if (response.status === 'success' && response.data && response.data.length > 0) {
                        purchaseList.html('');
                        response.data.forEach(function(purchase) {
                            const purchaseItem = $('<div>')
                                .addClass('purchase-item p-2 border-b hover:bg-blue-100 cursor-pointer')
                                .html(`
                                    <div class="flex justify-between items-center">
                                        <div>
                                            <b class="text-blue-600">${purchase.receipt_id}</b>
                                            <span class="text-xs text-gray-500 ml-2">${purchase.party_name || ''}</span>
                                        </div>
                                        <span class="text-xs text-gray-400">${purchase.date_of_transaction ? purchase.date_of_transaction.split(' ')[0] : ''}</span>
                                    </div>
                                    <div class="text-xs text-gray-600 mt-1">
                                        ${purchase.gold_weight}g @ ${window.GoldRateUtils ? GoldRateUtils.formatRateText(purchase.rate, 0) : '₹' + purchase.rate + '/g'} = ₹${parseFloat(purchase.gold_amount).toLocaleString('en-IN')}
                                    </div>
                                `)
                                .on('click', function() {
                                    loadPurchaseForEditByReceiptId(purchase.receipt_id);
                                    purchaseList.addClass('hidden');
                                });
                            purchaseList.append(purchaseItem);
                        });
                    } else {
                        purchaseList.html('<div class="p-3 text-center text-gray-500">No previous purchases found</div>');
                    }
                }, 'json').fail(function() {
                    purchaseList.html('<div class="p-3 text-center text-red-500">Error loading purchases</div>');
                });
            }

            // Hide purchase list when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('#purchaseList, #showPurchaseListBtn, #purchaseIdInput').length) {
                    $('#purchaseList').addClass('hidden');
                }
            });

            // Auto-focus party name field on wide screens
            if ($(window).width() >= 992) {
                setTimeout(function() {
                    $('#partyNameInput').focus();
                }, 500);
            }
            
            // Handle clicking on purchase row to load for editing
            $(document).on('click', '.selectable-row', function(e) {
                
                // Don't trigger if clicking on action buttons
                const clickedButton = $(e.target).closest('button, .edit-purchase-row, .print-transaction, .delete-transaction');
                if (clickedButton.length > 0) {
                    console.log('Click on button ignored:', clickedButton);
                    return false;
                }
                
                // Don't trigger if clicking on icon inside button
                if ($(e.target).is('i') || $(e.target).closest('i').length > 0) {
                    const $icon = $(e.target).is('i') ? $(e.target) : $(e.target).closest('i');
                    if ($icon.closest('button').length > 0) {
                        console.log('Click on icon in button ignored');
                        return false;
                    }
                }
                
                e.preventDefault();
                e.stopPropagation();
                
                const $row = $(this);
                let transactionId = $row.data('transaction-id') || $row.attr('data-transaction-id');
                let receiptId = $row.data('receipt-id') || $row.attr('data-receipt-id');
                
                console.log('Initial IDs from data attributes:', {transactionId, receiptId});
                
                // Try to get from embedded transaction data if IDs are missing
                if (!transactionId) {
                    const transactionData = $row.attr('data-transaction');
                    if (transactionData) {
                        try {
                            const decoded = atob(transactionData);
                            const transaction = JSON.parse(decoded);
                            console.log('Parsed transaction data:', transaction);
                            if (transaction && transaction.id) {
                                transactionId = transaction.id;
                            }
                            if (transaction && transaction.receipt_id && !receiptId) {
                                receiptId = transaction.receipt_id;
                            }
                        } catch (err) {
                            console.error('Error parsing transaction data:', err);
                        }
                    }
                }
                
                // Convert to number if it's a string
                if (transactionId) {
                    transactionId = parseInt(transactionId);
                }
                
                console.log('Final IDs:', {transactionId, receiptId});
                console.log('loadPurchaseForEdit function exists:', typeof loadPurchaseForEdit);
                
                if (transactionId && transactionId > 0) {
                    console.log('Calling loadPurchaseForEdit with ID:', transactionId);
                    try {
                        loadPurchaseForEdit(transactionId);
                    } catch (err) {
                        console.error('Error calling loadPurchaseForEdit:', err);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Error loading purchase: ' + err.message
                        });
                    }
                } else if (receiptId) {
                    console.log('Calling loadPurchaseForEditByReceiptId with ID:', receiptId);
                    try {
                        loadPurchaseForEditByReceiptId(receiptId);
                    } catch (err) {
                        console.error('Error calling loadPurchaseForEditByReceiptId:', err);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Error loading purchase: ' + err.message
                        });
                    }
                } else {
                    console.error('No transaction ID or receipt ID found on row');
                    Swal.fire({
                        icon: 'warning',
                        title: 'Error',
                        text: 'Unable to load purchase details. Missing transaction information.'
                    });
                }
            });
            
            // Function to load purchase transaction for editing
            function loadPurchaseForEdit(transactionId) {
                console.log('=== loadPurchaseForEdit called ===', transactionId);
                
                if (!transactionId) {
                    console.error('No transaction ID provided');
                    return;
                }
                
                console.log('Loading purchase for edit, Transaction ID:', transactionId);
                console.log('Swal available:', typeof Swal !== 'undefined');
                
                // Show loading
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: 'Loading...',
                        text: 'Loading purchase details',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                } else {
                    console.warn('SweetAlert2 not available, showing alert instead');
                    alert('Loading purchase details...');
                }
                
                console.log('Making AJAX request...');
                $.ajax({
                    url: window.location.href,
                    method: 'POST',
                    data: {
                        action: 'get_purchase_details',
                        transaction_id: transactionId
                    },
                    dataType: 'json',
                    beforeSend: function() {
                        console.log('AJAX request sent');
                    },
                    success: function(response) {
                        console.log('=== AJAX SUCCESS ===');
                        console.log('Response received:', response);
                        console.log('Response type:', typeof response);
                        console.log('Response status:', response ? response.status : 'no response');
                        
                        if (typeof Swal !== 'undefined') {
                            Swal.close();
                        }
                        
                        if (response && response.status === 'success' && response.data) {
                            console.log('Response data:', response.data);
                            console.log('Calling populateFormForEdit...');
                            try {
                                populateFormForEdit(response.data);
                                console.log('Form populated successfully');
                                
                                // Scroll to form
                                $('html, body').animate({
                                    scrollTop: $('#purchaseForm').offset().top - 100
                                }, 500);
                                
                                // Focus on party name field
                                setTimeout(() => {
                                    $('#partyNameInput').focus();
                                }, 600);
                            } catch (err) {
                                console.error('Error in populateFormForEdit:', err);
                                if (typeof Swal !== 'undefined') {
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Error',
                                        text: 'Error populating form: ' + err.message
                                    });
                                }
                            }
                        } else {
                            console.error('Invalid response:', response);
                            if (typeof Swal !== 'undefined') {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: (response && response.message) ? response.message : 'Failed to load purchase details'
                                });
                            } else {
                                alert('Failed to load purchase details');
                            }
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('=== AJAX ERROR ===');
                        console.error('Status:', status);
                        console.error('Error:', error);
                        console.error('Response Text:', xhr.responseText);
                        console.error('Status Code:', xhr.status);
                        
                        if (typeof Swal !== 'undefined') {
                            Swal.close();
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'Failed to load purchase details: ' + error + (xhr.responseText ? ' - ' + xhr.responseText.substring(0, 100) : '')
                            });
                        } else {
                            alert('Error: ' + error);
                        }
                    }
                });
            }
            
            // Function to load purchase by receipt ID
            function loadPurchaseForEditByReceiptId(receiptId) {
                if (!receiptId) return;
                
                // Show loading
                Swal.fire({
                    title: 'Loading...',
                    text: 'Loading purchase details',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                $.post('', {
                    action: 'get_purchase_details',
                    receipt_id: receiptId
                }, function(response) {
                    Swal.close();
                    
                    if (response.status === 'success' && response.data) {
                        populateFormForEdit(response.data);
                        
                        // Scroll to form
                        $('html, body').animate({
                            scrollTop: $('#purchaseForm').offset().top - 100
                        }, 500);
                        
                        // Focus on party name field
                        setTimeout(() => {
                            $('#partyNameInput').focus();
                        }, 600);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to load purchase details'
                        });
                    }
                }, 'json').fail(function(xhr, status, error) {
                    Swal.close();
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to load purchase details: ' + error
                    });
                });
            }
            
            // Function to populate form with purchase data for editing
            function populateFormForEdit(data) {
                console.log('Populating form with data:', data);
                
                if (!data || !data.id) {
                    console.error('Invalid data provided to populateFormForEdit');
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Invalid purchase data received'
                    });
                    return;
                }
                
                // Set edit mode
                $('#editTransactionId').val(data.id);
                $('#editModeIndicator').removeClass('hidden');
                $('#submitButtonText').text('Update Purchase');
                $('#purchaseGoldBtn').removeClass('bg-blue-600 hover:bg-blue-700').addClass('bg-orange-600 hover:bg-orange-700');
                
                // Highlight the form to indicate edit mode
                $('#purchaseForm').closest('.bg-white').css('border', '2px solid #f97316');
                
                // Populate form fields
                $('#purchaseIdInput').val(data.receipt_id || '');
                
                // Format date for datetime-local input
                let dateValue = '';
                if (data.date_of_transaction) {
                    const date = new Date(data.date_of_transaction);
                    if (!isNaN(date.getTime())) {
                        dateValue = date.toISOString().slice(0, 16);
                    } else {
                        // Try parsing as-is if ISO format fails
                        dateValue = data.date_of_transaction.replace(' ', 'T').substring(0, 16);
                    }
                }
                $('[name="date_of_transaction"]').val(dateValue);
                
                // Set party
                $('#partyId').val(data.party_id || '');
                $('#partyNameInput').val(data.party_name || '');
                const pnEd = document.getElementById('partyNameInput');
                if (pnEd) pnEd.dataset.state = data.party_state || '';
                if (typeof selectedPartyName !== 'undefined') {
                    selectedPartyName = data.party_name || '';
                }
                if (typeof updatePartySelectionStatus === 'function') {
                    updatePartySelectionStatus(true);
                }
                
                $('#purchaseItemsBody').empty();
                if (data.items && data.items.length > 0) {
                    data.items.forEach(function (it) {
                        const $r = $(purchaseItemRowTemplate);
                        $('#purchaseItemsBody').append($r);
                        bindPurchaseItemEvents($r[0]);
                        const $last = $('#purchaseItemsBody tr.purchase-item-row').last();
                        const refId = parseInt(String(it.stock_ref_id || '0'), 10) || 0;
                        const sn = it.stock_name || '';
                        const p = parseFloat(it.purity) || 0;
                        $last.find('.purchase-stock-select option').each(function () {
                            if (refId > 0) {
                                if (parseInt(String(this.value), 10) === refId) {
                                    this.selected = true;
                                }
                            } else {
                                const dsn = this.getAttribute('data-stock-name') || '';
                                const dp = parseFloat(this.getAttribute('data-purity') || '0') || 0;
                                if (dsn === sn && Math.abs(dp - p) < 0.02) {
                                    this.selected = true;
                                }
                            }
                        });
                        $last.find('.purchase-weight').val(parseFloat(it.gold_weight || 0).toFixed(3));
                        $last.find('.purchase-rate').val(parseFloat(it.rate || 0).toFixed(2));
                    });
                } else {
                    $('#purchaseItemsBody').html(purchaseItemRowTemplate);
                    bindPurchaseItemEvents(document.querySelector('#purchaseItemsBody tr.purchase-item-row'));
                    const $r = $('#purchaseItemsBody tr.purchase-item-row').first();
                    $r.find('.purchase-weight').val(parseFloat(data.gold_weight || 0).toFixed(3));
                    $r.find('.purchase-rate').val(parseFloat(data.rate || 0).toFixed(2));
                    const pur = parseFloat(data.purity) || 0;
                    $r.find('.purchase-stock-select option').each(function () {
                        if (Math.abs(parseFloat(this.getAttribute('data-purity') || '0') - pur) < 0.05) {
                            this.selected = true;
                        }
                    });
                }
                {
                    const pmode = (data.mode || data.receipt_method || 'Cash');
                    setPurchasePaymentMode(pmode === 'Bank' ? 'Bank' : 'Cash');
                }
                recalcPurchaseItems();
                
                // Set payment details
                const paymentMethod = data.payment_method || '';
                const paymentAmount = parseFloat(data.payment_amount || 0);
                
                if (paymentMethod === 'Cash') {
                    $('#purchasePaymentMethodSelect').val('cash');
                    $('[name="cash_amount"]').val(paymentAmount > 0 ? paymentAmount.toFixed(2) : '');
                    $('[name="bank_amount"]').val('');
                } else if (paymentMethod) {
                    let pm = 'Bank';
                    if (paymentMethod === 'UPI') pm = 'UPI';
                    else if (paymentMethod === 'Cheque') pm = 'Cheque';
                    $('#purchasePaymentMethodSelect').val(pm);
                    $('[name="bank_amount"]').val(paymentAmount > 0 ? paymentAmount.toFixed(2) : '');
                    $('[name="cash_amount"]').val('');
                } else {
                    $('#purchasePaymentMethodSelect').val('Bank');
                    $('[name="cash_amount"], input[name="bank_amount"]').val('');
                }
                $('#purchasePaymentMethodSelect').trigger('change');
                
                // Set narration - extract from full narration if it contains the receipt ID
                let narration = data.narration || '';
                if (narration && data.receipt_id) {
                    // Remove the auto-generated part if present
                    const autoPart = `Gold purchase from ${data.party_name || ''} - ${data.receipt_id}`;
                    if (narration.startsWith(autoPart)) {
                        narration = narration.substring(autoPart.length).trim();
                        if (narration.startsWith('-')) {
                            narration = narration.substring(1).trim();
                        }
                    }
                }
                $('[name="narration"]').val(narration);
                
                console.log('Form populated successfully');
            }
            
            $(document).on('click', '.edit-purchase-row', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const tid = parseInt($(this).data('transaction-id'), 10);
                if (tid > 0 && typeof loadPurchaseForEdit === 'function') {
                    loadPurchaseForEdit(tid);
                }
            });

            // Print purchase receipt from recent transactions list
            $(document).on('click', '.print-transaction', function(e) {
                e.stopPropagation();
                const tid = parseInt($(this).data('id'), 10);
                if (tid > 0) {
                    openPurchaseReceiptPrint(tid);
                } else {
                    Swal.fire('Error', 'Transaction ID not found', 'error');
                }
            });

            // Handle delete transaction
            $(document).on('click', '.delete-transaction', function(e) {
                e.stopPropagation();
                const transactionId = $(this).data('id');
                const receiptId = $(this).data('receipt-id');
                const weight = $(this).data('weight');
                const amount = $(this).data('amount');
                
                Swal.fire({
                    title: 'Delete Purchase?',
                    html: `
                        <div style="text-align: left; padding: 10px;">
                            <p><strong>Receipt ID:</strong> ${receiptId}</p>
                            <p><strong>Weight:</strong> ${weight}g</p>
                            <p><strong>Amount:</strong> ₹${parseFloat(amount).toLocaleString('en-IN')}</p>
                            <p style="color: #dc3545; margin-top: 10px;">This action cannot be undone!</p>
                        </div>
                    `,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, Delete',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Delete transaction
                        $.post('', {
                            action: 'delete_purchase',
                            transaction_id: transactionId
                        }, function(response) {
                            if (response.status === 'success') {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Deleted!',
                                    text: 'Purchase transaction has been deleted.',
                                    timer: 1500,
                                    showConfirmButton: false
                                }).then(() => {
                                    location.reload();
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: response.message || 'Failed to delete transaction'
                                });
                            }
                        }, 'json');
                    }
                });
            });
        });
    </script>
</div>

<?php
// Capture the content
$content = ob_get_clean();

// Set page title and include layout
$page_title = "Purchase";
include 'components/layout.php';
?>