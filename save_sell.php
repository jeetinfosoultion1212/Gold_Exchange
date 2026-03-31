<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit;
}

// Load database configuration
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/handlers/account_balance_helper.php';

if ($conn->connect_error) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

// Get user and company info
$company_id = $_SESSION['company_id'];
$user_id = $_SESSION['user_id'];

// Handle AJAX request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_sell') {
    // Debug: Log all POST data
    error_log("=== SAVE_SELL POST DATA ===");
    error_log(print_r($_POST, true));
    error_log("===========================");
    
    $conn->begin_transaction();
    
    try {
        // Get form data
        $receipt_id = $conn->real_escape_string($_POST['receipt_id']);
        $party_id = intval($_POST['party_id']);
        $date_of_transaction = $conn->real_escape_string($_POST['date_of_transaction']);
        $sell_weight = floatval($_POST['sell_weight'] ?? 0);
        $purity = floatval($_POST['purity']);
        $rate = floatval($_POST['rate']);
        $amount = floatval(str_replace(',', '', $_POST['amount']));
        
        // Mode and Taxes
        $mode = $conn->real_escape_string($_POST['mode'] ?? 'Cash');
        $taxable_amount = floatval($_POST['taxable_amount'] ?? 0);
        $cgst = floatval($_POST['cgst'] ?? 0);
        $sgst = floatval($_POST['sgst'] ?? 0);
        $igst = floatval($_POST['igst'] ?? 0);
        $total_gst = floatval($_POST['total_gst'] ?? 0);
        
        $additional_cash = floatval($_POST['additional_cash'] ?? 0);
        $additional_bank = floatval($_POST['additional_bank'] ?? 0);
        $bank_payment_type = $conn->real_escape_string($_POST['bank_payment_type'] ?? $_POST['payment_method'] ?? '');
        $narration = $conn->real_escape_string($_POST['narration'] ?? '');
        
        // Multi-item Support
        $sell_items_json = $_POST['sell_items'] ?? '[]';
        $sell_items = json_decode($sell_items_json, true);

        // Get payment_type and convert to proper case for booking_type column
        $payment_type = strtolower($_POST['payment_type'] ?? 'bank');
        $booking_type = ucfirst($payment_type); // Convert 'cash' to 'Cash', 'bank' to 'Bank'
        
        // Validate required fields
        if (empty($receipt_id) || empty($party_id) || empty($sell_items)) {
            throw new Exception("Please fill all required fields and add at least one item.");
        }
        
        // Fetch Party Data
        $party_sql = "SELECT (cash_balance + bank_balance) as current_balance, cash_balance, bank_balance, gold_balance FROM parties WHERE id = $party_id AND company_id = $company_id";
        $party_result = $conn->query($party_sql);
        if (!$party_result || $party_result->num_rows == 0) {
             throw new Exception("Party not found");
        }
        $balance_data = $party_result->fetch_assoc();
        
        $available_gold = $balance_data['gold_balance'];
        $payment_amount = $additional_cash + $additional_bank;
        $payment_method = $conn->real_escape_string($_POST['payment_method'] ?? 'Cash');
        
        // Conditional Payment Info
        $pay_meth_val = ($payment_amount > 0) ? $payment_method : null;
        $pay_type_val = ($payment_amount > 0) ? 'Payment_In' : null;
        $rcpt_meth_val = ($payment_amount > 0) ? $payment_method : null;
        $gold_val_calc = $sell_weight * $rate;
        $payment_status = ($payment_amount >= $amount) ? 'Paid' : (($payment_amount > 0) ? 'Partial' : 'Due');
        $bal_before = floatval($balance_data['current_balance']);
        $bal_after = $bal_before - $amount;
        $gold_bal_before = floatval($balance_data['gold_balance']);
        $gold_bal_after = $gold_bal_before - $sell_weight;

        // Insert sale using prepared statement for safety and correct column index
        $sql = "INSERT INTO transactions (
            company_id, user_id, party_id, receipt_id, transaction_type, date_of_transaction,
            gold_weight, purity, rate, gold_amount, payment_amount, payment_method, payment_type, 
            receipt_method, mode, amount, taxable_amount, cgst, sgst, igst, total_gst,
            party_balance_before, party_balance_after, party_gold_balance_before, party_gold_balance_after,
            narration, booking_type, payment_status, created_by
        ) VALUES (?, ?, ?, ?, 'Sale', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        // Types: 
        // 1-5: iiiss (comp, user, party, receipt, date)
        // 6-10: ddddd (weight, pur, rate, gold_val, pay_amt)
        // 11-15: sssss (pay_meth, pay_type, rcpt_meth, mode, narration) NO! narration is 26th
        // 11-14: ssss (pay_meth, pay_type, rcpt_meth, mode)
        // 15-20: dddddd (amount, taxable, cgst, sgst, igst, tot_gst)
        // 21-24: dddd (bal_bef, bal_aft, gold_bef, gold_aft)
        // 25-27: sss (narration, book_type, status)
        // 28: i (user_id)
        $stmt->bind_param(
            "iiissdddddssssddddddddddsssi",
            $company_id, $user_id, $party_id, $receipt_id, $date_of_transaction,
            $sell_weight, $purity, $rate, $gold_val_calc, $payment_amount, 
            $pay_meth_val, $pay_type_val, $rcpt_meth_val, $mode, $amount,
            $taxable_amount, $cgst, $sgst, $igst, $total_gst,
            $bal_before, $bal_after, $gold_bal_before, $gold_bal_after,
            $narration, $booking_type, $payment_status, $user_id
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Error creating sale transaction: " . $stmt->error);
        }
        
        $sale_id = $stmt->insert_id;
        
        // Save Multi-items and Deduct Stock
        foreach ($sell_items as $item) {
            $wt = floatval($item['weight']);
            $pur = floatval($item['purity']);
            $fn = floatval($item['fine']);
            $sn = $conn->real_escape_string($item['stock_name'] ?? '');
            $i_rate = floatval($item['rate'] ?? $rate);
            $i_amt = round($wt * $i_rate);
            
            $item_sql = "INSERT INTO gold_sale_items (
                company_id, transaction_id, receipt_id, stock_name, gold_weight, purity, fine_weight, rate, amount
            ) VALUES (
                $company_id, $sale_id, '$receipt_id', '$sn', $wt, $pur, $fn, $i_rate, $i_amt
            )";
            $conn->query($item_sql);
            
            // DEDUCT STOCK: Look for specific stock name first, then by purity
            $where = !empty($sn) ? "stock_name = '$sn' AND purity = $pur" : "purity = $pur";
            $stock_update = "UPDATE gold_stock SET current_stock = current_stock - $wt, last_updated = NOW() 
                            WHERE $where AND company_id = $company_id LIMIT 1";
            
            if (!$conn->query($stock_update) || $conn->affected_rows == 0) {
                // FALLBACK: If specific stock name not found or doesn't match name, use purity only
                $conn->query("UPDATE gold_stock SET current_stock = current_stock - $wt, last_updated = NOW() 
                              WHERE purity = $pur AND company_id = $company_id LIMIT 1");
            }
        }
        
        // Calculate settlement
        $advance_received = 0; // Column not in parties table currently
        $advance_settlement = min($advance_received, $amount);
        $total_payment_received = $advance_settlement + $additional_cash + $additional_bank;
        
        // Update main transaction payment info
        $conn->query("UPDATE transactions SET payment_amount = $total_payment_received WHERE id = $sale_id");
        
        // Handle settlement entries (Advance, Cash, Bank)
        // Note: advance_received is not in parties table, so we omit advance_settlement for now
        
        if ($additional_cash > 0) {
            $csh_id = 'CSH-' . date('Ymd') . '-' . rand(1000, 9999);
            $conn->query("INSERT INTO transactions (company_id, party_id, receipt_id, transaction_type, date_of_transaction, payment_amount, payment_method, payment_type, booking_type, narration)
                         VALUES ($company_id, $party_id, '$csh_id', 'Received', '$date_of_transaction', $additional_cash, 'Cash', 'Payment_In', 'Cash', 'Cash for sale $receipt_id')");
            updateAccountBalance($conn, $company_id, 'Cash', $additional_cash);
        }
        
        if ($additional_bank > 0) {
            $bnk_id = 'BNK-' . date('Ymd') . '-' . rand(1000, 9999);
            $conn->query("INSERT INTO transactions (company_id, party_id, receipt_id, transaction_type, date_of_transaction, payment_amount, payment_method, payment_type, booking_type, narration)
                         VALUES ($company_id, $party_id, '$bnk_id', 'Received', '$date_of_transaction', $additional_bank, 'Bank', 'Payment_In', 'Bank', 'Bank for sale $receipt_id')");
            updateAccountBalance($conn, $company_id, 'Bank', $additional_bank);
        }
        
        // Update Party Final Balances
        // Use $mode to decide which balance column to update
        $c_change = ($mode == 'Cash' ? $amount : 0) - $additional_cash;
        $b_change = ($mode == 'Bank' ? $amount : 0) - $additional_bank;

        $conn->query("UPDATE parties SET 
            gold_balance = gold_balance - $sell_weight,
            cash_balance = cash_balance + $c_change,
            bank_balance = bank_balance + $b_change
            WHERE id = $party_id");

        $conn->commit();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'message' => 'Sale saved successfully', 'data' => ['receipt_id' => $receipt_id]]);
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
} else {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request'
    ]);
    exit;
}
?>
