<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/account_balance_helper.php';

header('Content-Type: application/json');

function msc_ensure_fine_transferred_column(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $r = @$conn->query("SHOW COLUMNS FROM transactions LIKE 'fine_transferred'");
    if ($r && $r->num_rows === 0) {
        @$conn->query("ALTER TABLE transactions ADD COLUMN fine_transferred DECIMAL(10,3) NOT NULL DEFAULT 0 COMMENT 'Fine g moved to fine stock'");
    }
    if ($r) {
        $r->free();
    }
}

function msc_sql_fine_purity(): string
{
    return '(purity >= 99.50 OR purity = 100.00 OR purity = 100.0 OR purity = 100)';
}

function msc_fetch_fine_stock(mysqli $conn, int $company_id, string $material): ?array
{
    $material = (strcasecmp(trim($material), 'Silver') === 0) ? 'Silver' : 'Gold';
    $fineP = msc_sql_fine_purity();
    if ($material === 'Silver') {
        $sql = "SELECT id, current_stock, stock_name, purity FROM gold_stock
                WHERE company_id = ? AND mode = 'Cash'
                AND (LOWER(stock_name) LIKE '%silver%')
                ORDER BY CASE WHEN ({$fineP}) THEN 0 ELSE 1 END,
                    CASE WHEN stock_name LIKE '%Fine%' OR stock_name LIKE '%fine%' THEN 1 ELSE 2 END,
                    purity DESC, id ASC LIMIT 1 FOR UPDATE";
    } else {
        $sql = "SELECT id, current_stock, stock_name, purity FROM gold_stock
                WHERE company_id = ? AND mode = 'Cash'
                AND {$fineP}
                AND NOT (LOWER(stock_name) LIKE '%silver%')
                ORDER BY CASE WHEN stock_name LIKE '%Fine%' OR stock_name LIKE '%fine%' THEN 1 ELSE 2 END,
                    purity DESC, id ASC LIMIT 1 FOR UPDATE";
    }
    $st = $conn->prepare($sql);
    if (!$st) {
        return null;
    }
    $st->bind_param('i', $company_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

function msc_fetch_mix_stock(mysqli $conn, int $company_id): ?array
{
    $stock_name = 'MIX Stock';
    $mix_purity = 0.0;
    $st = $conn->prepare("SELECT id, current_stock FROM gold_stock WHERE company_id = ? AND stock_name = ? AND purity = ? AND mode = 'Cash' LIMIT 1 FOR UPDATE");
    if (!$st) {
        return null;
    }
    $st->bind_param('isd', $company_id, $stock_name, $mix_purity);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

function msc_apply_party_exchange_delta(mysqli $conn, int $party_id, float $due_delta, float $metal_delta, string $payment_method, bool $is_silver): void
{
    $is_cash = !in_array($payment_method, ['Bank', 'UPI', 'Cheque', 'NEFT', 'RTGS'], true);
    if ($is_cash) {
        if ($is_silver) {
            $sql = 'UPDATE parties SET cash_balance = cash_balance + ?, silver_balance = silver_balance + ? WHERE id = ?';
            $st = $conn->prepare($sql);
            $st->bind_param('ddi', $due_delta, $metal_delta, $party_id);
        } else {
            $sql = 'UPDATE parties SET cash_balance = cash_balance + ? WHERE id = ?';
            $st = $conn->prepare($sql);
            $st->bind_param('di', $due_delta, $party_id);
        }
    } else {
        if ($is_silver) {
            $sql = 'UPDATE parties SET bank_balance = bank_balance + ?, silver_balance = silver_balance + ? WHERE id = ?';
            $st = $conn->prepare($sql);
            $st->bind_param('ddi', $due_delta, $metal_delta, $party_id);
        } else {
            $sql = 'UPDATE parties SET bank_balance = bank_balance + ? WHERE id = ?';
            $st = $conn->prepare($sql);
            $st->bind_param('di', $due_delta, $party_id);
        }
    }
    $st->execute();
    $st->close();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$company_id = $_SESSION['company_id'];
$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

msc_ensure_fine_transferred_column($conn);

try {
    $conn->begin_transaction();

    switch ($action) {
        case 'add_stock':
            $category = $conn->real_escape_string($_POST['category'] ?? 'Gold');
            $purity = floatval($_POST['purity']);
            $amount = floatval($_POST['amount']);
            $stock_name = $conn->real_escape_string($_POST['stock_name'] ?? '');
            $stock_type = $conn->real_escape_string($_POST['stock_type'] ?? 'Cash');
            $notes = $conn->real_escape_string($_POST['notes'] ?? '');
            
            $stock_id = isset($_POST['stock_id']) ? intval($_POST['stock_id']) : 0;
            
            // Check if stock entry exists (by ID first, then fallback to category+purity+mode check)
            if ($stock_id > 0) {
                 $check_sql = "SELECT id, current_stock FROM gold_stock WHERE id = $stock_id AND company_id = $company_id";
            } else {
                 $check_sql = "SELECT id, current_stock FROM gold_stock 
                              WHERE category = '$category' 
                              AND purity = $purity 
                              AND mode = '$stock_type' 
                              AND company_id = $company_id 
                              ORDER BY id DESC LIMIT 1";
            }
            $check_result = $conn->query($check_sql);
            
            if ($check_result->num_rows > 0) {
                // Update existing stock
                $row = $check_result->fetch_assoc();
                $new_stock = $row['current_stock'] + $amount;
                $update_sql = "UPDATE gold_stock SET current_stock = $new_stock, stock_name = '$stock_name', last_updated = NOW() WHERE id = {$row['id']}";
                $conn->query($update_sql);
            } else {
                // Insert new stock entry
                $insert_sql = "INSERT INTO gold_stock (company_id, category, mode, purity, stock_name, current_stock, last_updated) 
                               VALUES ($company_id, '$category', '$stock_type', $purity, '$stock_name', $amount, NOW())";
                $conn->query($insert_sql);
            }
            
            // Log transaction with stock_type and category
            $receipt_id = 'STK-' . strtoupper(uniqid());
            $log_sql = "INSERT INTO transactions (company_id, party_id, receipt_id, transaction_type, gold_weight, purity, payment_method, date_of_transaction, narration, created_by) 
                        VALUES ($company_id, NULL, '$receipt_id', 'Stock_Addition', $amount, $purity, '$stock_type', NOW(), '[$category] Stock Addition ($stock_name - $stock_type): $notes', $user_id)";
            $conn->query($log_sql);
            
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Stock added successfully']);
            break;

        case 'reset_stock':
            $purity = floatval($_POST['purity']);
            $notes = $conn->real_escape_string($_POST['notes'] ?? '');
            
            $stock_id = isset($_POST['stock_id']) ? intval($_POST['stock_id']) : 0;
            
             // Check if stock entry exists (by ID first, then fallback to purity check)
            if ($stock_id > 0) {
                 $check_sql = "SELECT id, current_stock FROM gold_stock WHERE id = $stock_id AND company_id = $company_id";
            } else {
                 $check_sql = "SELECT id, current_stock FROM gold_stock WHERE purity = $purity AND company_id = $company_id ORDER BY id DESC LIMIT 1";
            }
            $check_result = $conn->query($check_sql);
            
            if ($check_result->num_rows > 0) {
                $row = $check_result->fetch_assoc();
                $old_stock = $row['current_stock'];
                
                // Reset to 0
                $update_sql = "UPDATE gold_stock SET current_stock = 0, last_updated = NOW() WHERE id = {$row['id']}";
                $conn->query($update_sql);
                
                // Log transaction
                $receipt_id = 'STK-RST-' . strtoupper(uniqid());
                $log_sql = "INSERT INTO transactions (company_id, party_id, receipt_id, transaction_type, gold_weight, purity, date_of_transaction, narration, created_by) 
                            VALUES ($company_id, NULL, '$receipt_id', 'Stock_Reset', $old_stock, $purity, NOW(), 'Stock Reset (Previous: $old_stock g): $notes', $user_id)";
                $conn->query($log_sql);
            }
            
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Stock reset successfully']);
            break;

        case 'add_cash':
            $amount = floatval($_POST['amount']);
            $notes = $conn->real_escape_string($_POST['notes'] ?? '');
            
            // Add cash transaction
            $receipt_id = 'CSH-' . strtoupper(uniqid());
            $log_sql = "INSERT INTO transactions (company_id, party_id, receipt_id, transaction_type, payment_type, payment_method, payment_amount, date_of_transaction, narration, created_by) 
                        VALUES ($company_id, NULL, '$receipt_id', 'Payment', 'Payment_In', 'Cash', $amount, NOW(), 'Cash Addition: $notes', $user_id)";
            
            if (!$conn->query($log_sql)) {
                throw new Exception('Failed to add cash transaction: ' . $conn->error);
            }
            
            // Update Account Balance
            updateAccountBalance($conn, $company_id, 'Cash', $amount);
            
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Cash added successfully']);
            break;

        case 'add_bank':
            $amount = floatval($_POST['amount']);
            $notes = $conn->real_escape_string($_POST['notes'] ?? '');
            
            // Add bank transaction
            $receipt_id = 'BNK-' . strtoupper(uniqid());
            $log_sql = "INSERT INTO transactions (company_id, party_id, receipt_id, transaction_type, payment_type, payment_method, payment_amount, date_of_transaction, narration, created_by) 
                        VALUES ($company_id, NULL, '$receipt_id', 'Payment', 'Payment_In', 'Bank', $amount, NOW(), 'Bank Addition: $notes', $user_id)";
            
            if (!$conn->query($log_sql)) {
                throw new Exception('Failed to add bank transaction: ' . $conn->error);
            }
            
            // Update Account Balance
            updateAccountBalance($conn, $company_id, 'Bank', $amount);
            
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Bank amount added successfully']);
            break;

        case 'reset_cash':
            $notes = $conn->real_escape_string($_POST['notes'] ?? '');
            
            // Get current cash balance from account_balances
            $current_cash = getAccountBalance($conn, $company_id, 'Cash');
            
            if ($current_cash != 0) {
                // Add offsetting transaction to reset to 0
                $adjustment_type = $current_cash > 0 ? 'Payment_Out' : 'Payment_In';
                $adjustment_amount = abs($current_cash);
                
                $adjustment_type = $current_cash > 0 ? 'Payment_Out' : 'Payment_In';
                $adjustment_amount = abs($current_cash);
                $receipt_id = 'CSH-RST-' . strtoupper(uniqid());
                
                $log_sql = "INSERT INTO transactions (company_id, party_id, receipt_id, transaction_type, payment_type, payment_method, payment_amount, date_of_transaction, narration, created_by) 
                            VALUES ($company_id, NULL, '$receipt_id', 'Payment', '$adjustment_type', 'Cash', $adjustment_amount, NOW(), 'Cash Reset (Previous Balance: ₹$current_cash): $notes', $user_id)";
                $conn->query($log_sql);
                
                // Update Account Balance to 0 (subtract current balance)
                updateAccountBalance($conn, $company_id, 'Cash', -$current_cash);
            }
            
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Cash balance reset successfully']);
            break;

        case 'reset_bank':
            $notes = $conn->real_escape_string($_POST['notes'] ?? '');
            
            // Get current bank balance from account_balances
            $current_bank = getAccountBalance($conn, $company_id, 'Bank');
            
            if ($current_bank != 0) {
                // Add offsetting transaction to reset to 0
                $adjustment_type = $current_bank > 0 ? 'Payment_Out' : 'Payment_In';
                $adjustment_amount = abs($current_bank);
                
                $adjustment_type = $current_bank > 0 ? 'Payment_Out' : 'Payment_In';
                $adjustment_amount = abs($current_bank);
                $receipt_id = 'BNK-RST-' . strtoupper(uniqid());
                
                $log_sql = "INSERT INTO transactions (company_id, party_id, receipt_id, transaction_type, payment_type, payment_method, payment_amount, date_of_transaction, narration, created_by) 
                            VALUES ($company_id, NULL, '$receipt_id', 'Payment', '$adjustment_type', 'Bank', $adjustment_amount, NOW(), 'Bank Reset (Previous Balance: ₹$current_bank): $notes', $user_id)";
                $conn->query($log_sql);
                
                 // Update Account Balance to 0 (subtract current balance)
                 updateAccountBalance($conn, $company_id, 'Bank', -$current_bank);
            }
            
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Bank balance reset successfully']);
            break;

        case 'get_transactions':
            $type = $_POST['type'] ?? '';
            $limit = 30;
            
            if ($type === 'cash') {
                $sql = "SELECT id, transaction_type, payment_type, payment_method, payment_amount, 
                        date_of_transaction, narration, created_at 
                        FROM transactions 
                        WHERE company_id = $company_id 
                        AND payment_method = 'Cash'
                        AND transaction_type IN ('Payment', 'Received', 'Purchase')
                        ORDER BY date_of_transaction DESC, created_at DESC 
                        LIMIT $limit";
            } elseif ($type === 'bank') {
                $sql = "SELECT id, transaction_type, payment_type, payment_method, payment_amount, 
                        date_of_transaction, narration, created_at 
                        FROM transactions 
                        WHERE company_id = $company_id 
                        AND payment_method IN ('Bank', 'UPI', 'Cheque')
                        AND transaction_type IN ('Payment', 'Received', 'Purchase')
                        ORDER BY date_of_transaction DESC, created_at DESC 
                        LIMIT $limit";
            } elseif ($type === 'stock') {
                $stock_id = isset($_POST['stock_id']) ? intval($_POST['stock_id']) : 0;
                $purity = floatval($_POST['purity'] ?? 0);
                
                if ($stock_id > 0) {
                    // Try to filter by stock ID if possible, but transaction table doesn't have stock_id. 
                    // It logs transactions with transaction_type like 'Stock_Addition' and purity.
                    // However, we don't have stock_id in transactions.
                    // But we can filter by purity from the stock_id
                     $stock_info_sql = "SELECT purity FROM gold_stock WHERE id = $stock_id AND company_id = $company_id";
                     $s_res = $conn->query($stock_info_sql);
                     if ($s_res && $row = $s_res->fetch_assoc()) {
                         $purity = $row['purity'];
                         // Note: This relies on purity being unique per stock type or us accepting all logs for that purity. 
                         // With "Exchange Received", we use purity too.
                     }
                }
                
                // If we are looking at 100% purity stock (Fine Gold), we should also include Exchange transactions 
                // where fine_weight/issue_weight was deducted, regardless of the 'purity' column 
                // (which usually tracks the received item's purity).
                $purity_condition = "purity = $purity";
                if ($purity == 100) {
                     $purity_condition = "(purity = 100 OR (transaction_type = 'Exchange' AND fine_weight > 0))";
                }

                $sql = "SELECT id, transaction_type, gold_weight, fine_weight, purity, payment_method, 
                        date_of_transaction, narration, created_at 
                        FROM transactions 
                        WHERE company_id = $company_id 
                        AND $purity_condition
                        AND transaction_type IN ('Stock_Addition', 'Stock_Reset', 'Purchase', 'Sale', 'Exchange')
                        ORDER BY date_of_transaction DESC, created_at DESC 
                        LIMIT $limit";
            } else {
                throw new Exception('Invalid transaction type');
            }
            
            $result = $conn->query($sql);
            $transactions = [];
            while ($row = $result->fetch_assoc()) {
                $transactions[] = $row;
            }
            
            echo json_encode(['success' => true, 'transactions' => $transactions]);
            break;

        case 'delete_transaction':
            $transaction_id = intval($_POST['transaction_id']);
            
            // Get transaction details before deletion for audit log
            $check_sql = "SELECT * FROM transactions WHERE id = $transaction_id AND company_id = $company_id";
            $check_result = $conn->query($check_sql);
            
            if ($check_result->num_rows === 0) {
                throw new Exception('Transaction not found or unauthorized');
            }
            
            $transaction = $check_result->fetch_assoc();
            
            // Create audit log entry
            $audit_details = json_encode([
                'deleted_transaction_id' => $transaction_id,
                'original_type' => $transaction['transaction_type'],
                'amount' => $transaction['payment_amount'] ?? $transaction['gold_weight'],
                'payment_method' => $transaction['payment_method'],
                'original_date' => $transaction['date_of_transaction'],
                'original_narration' => $transaction['narration']
            ]);
            
            $audit_details = json_encode([
                'deleted_transaction_id' => $transaction_id,
                'original_type' => $transaction['transaction_type'],
                'amount' => $transaction['payment_amount'] ?? $transaction['gold_weight'],
                'payment_method' => $transaction['payment_method'],
                'original_date' => $transaction['date_of_transaction'],
                'original_narration' => $transaction['narration']
            ]);
            
            $receipt_id = 'DEL-' . strtoupper(uniqid());
            $audit_sql = "INSERT INTO transactions (company_id, party_id, receipt_id, transaction_type, date_of_transaction, narration, created_by) 
                         VALUES ($company_id, NULL, '$receipt_id', 'Transaction_Deleted', NOW(), 'Deleted Transaction #$transaction_id: $audit_details', $user_id)";
            $conn->query($audit_sql);
            
            // Revert Account Balance for Cash/Bank transactions
            if ($transaction['transaction_type'] === 'Payment' || $transaction['transaction_type'] === 'Received') {
                $amt = floatval($transaction['payment_amount']);
                $type = $transaction['payment_type'] ?? 'Payment_In'; // Default to In for manual adds
                
                // If it was Payment_In (Received/Added), Reversal is Subtract (-).
                // If it was Payment_Out (Paid/Reset), Reversal is Add (+).
                $reversal_amt = ($type === 'Payment_In') ? -$amt : $amt;
                
                if ($transaction['payment_method'] === 'Cash') {
                    updateAccountBalance($conn, $company_id, 'Cash', $reversal_amt);
                } elseif (in_array($transaction['payment_method'], ['Bank', 'UPI', 'Cheque'])) {
                    updateAccountBalance($conn, $company_id, 'Bank', $reversal_amt);
                }
            }

            // Revert Stock for Stock transactions
            if ($transaction['transaction_type'] === 'Stock_Addition') {
                // Stock Addition -> Reversal is subtract stock
                 $purity = floatval($transaction['purity']);
                 $weight = floatval($transaction['gold_weight']);
                 
                 $stock_sql = "SELECT id, current_stock FROM gold_stock WHERE purity = $purity AND company_id = $company_id ORDER BY id DESC LIMIT 1";
                 $stock_res = $conn->query($stock_sql);
                 if ($stock_res && $stock_res->num_rows > 0) {
                     $row = $stock_res->fetch_assoc();
                     $new_stock = max(0, $row['current_stock'] - $weight);
                     $update_sql = "UPDATE gold_stock SET current_stock = $new_stock, last_updated = NOW() WHERE id = " . $row['id'];
                     $conn->query($update_sql);
                 }
            }
            // Note: Stock_Reset reversal is complicated (we don't know the exact old amount from just the transaction unless parsed from narration, which is risky). 
            // For now, we'll leave Stock_Reset without auto-reversal of stock value (it's a hard reset anyway).

            // Delete the transaction
            $delete_sql = "DELETE FROM transactions WHERE id = $transaction_id AND company_id = $company_id";
            $conn->query($delete_sql);
            
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Transaction deleted successfully']);
            break;

        case 'update_exchange_fine':
            $transaction_id = intval($_POST['transaction_id'] ?? 0);
            $new_fine = max(0, floatval($_POST['fine_weight'] ?? 0));
            if ($transaction_id <= 0) {
                throw new Exception('Invalid exchange');
            }

            $st = $conn->prepare("SELECT id, party_id, received_weight, delivered_weight, fine_weight, fine_transferred, rate, payment_amount, payment_method, due_amount, difference_weight, exchange_material
                FROM transactions WHERE id = ? AND company_id = ? AND transaction_type = 'Exchange' FOR UPDATE");
            $st->bind_param('ii', $transaction_id, $company_id);
            $st->execute();
            $txn = $st->get_result()->fetch_assoc();
            $st->close();
            if (!$txn) {
                throw new Exception('Exchange not found');
            }

            $old_fine = (float) $txn['fine_weight'];
            $transferred = (float) ($txn['fine_transferred'] ?? 0);
            if ($new_fine + 0.0005 < $transferred) {
                throw new Exception('Fine weight cannot be less than already transferred (' . number_format($transferred, 3) . ' g)');
            }

            $received = (float) $txn['received_weight'];
            $delivered = max(0, (float) $txn['delivered_weight']);
            $rate = (float) $txn['rate'];
            $payment_amount = (float) $txn['payment_amount'];
            $old_due = (float) $txn['due_amount'];
            $old_diff = (float) $txn['difference_weight'];
            $payment_method = (string) ($txn['payment_method'] ?? 'Cash');
            $is_silver = strcasecmp((string) ($txn['exchange_material'] ?? 'Gold'), 'Silver') === 0;
            $party_id = (int) $txn['party_id'];

            $new_purity = $received > 0.0005 ? ($new_fine / $received * 100) : 0;
            $new_diff = $delivered - $new_fine;
            $new_amount = round(abs($new_diff) * $rate);
            $new_due = $new_amount - $payment_amount;
            $payment_status = ($new_amount > 0 && $payment_amount >= $new_amount) ? 'Paid' : (($payment_amount > 0) ? 'Partial' : 'Due');

            if ($party_id > 0) {
                msc_apply_party_exchange_delta($conn, $party_id, -$old_due, -$old_diff, $payment_method, $is_silver);
                msc_apply_party_exchange_delta($conn, $party_id, $new_due, $new_diff, $payment_method, $is_silver);
            }

            $upd = $conn->prepare("UPDATE transactions SET fine_weight = ?, purity = ?, difference_weight = ?, amount = ?, due_amount = ?, payment_status = ? WHERE id = ? AND company_id = ?");
            $upd->bind_param('dddddsii', $new_fine, $new_purity, $new_diff, $new_amount, $new_due, $payment_status, $transaction_id, $company_id);
            if (!$upd->execute()) {
                throw new Exception('Failed to update exchange: ' . $upd->error);
            }
            $upd->close();

            if ($old_fine > 0.0005) {
                $ratio = $new_fine / $old_fine;
                $items_st = $conn->prepare('SELECT id, weight, fine_weight FROM exchange_items WHERE transaction_id = ? AND company_id = ?');
                $items_st->bind_param('ii', $transaction_id, $company_id);
                $items_st->execute();
                $items_res = $items_st->get_result();
                while ($item = $items_res->fetch_assoc()) {
                    $item_fine = (float) $item['fine_weight'] * $ratio;
                    $item_wt = (float) $item['weight'];
                    $item_purity = $item_wt > 0.0005 ? ($item_fine / $item_wt * 100) : 0;
                    $item_upd = $conn->prepare('UPDATE exchange_items SET fine_weight = ?, purity = ? WHERE id = ?');
                    $item_upd->bind_param('ddi', $item_fine, $item_purity, $item['id']);
                    $item_upd->execute();
                    $item_upd->close();
                }
                $items_st->close();
            }

            $conn->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Fine weight updated',
                'fine_weight' => $new_fine,
                'purity' => $new_purity,
                'difference_weight' => $new_diff,
                'amount' => $new_amount,
            ]);
            break;

        case 'transfer_exchange_fine':
            $transaction_id = intval($_POST['transaction_id'] ?? 0);
            $requested_fine = isset($_POST['fine_weight']) ? floatval($_POST['fine_weight']) : null;
            if ($transaction_id <= 0) {
                throw new Exception('Invalid exchange');
            }

            $st = $conn->prepare("SELECT id, receipt_id, received_weight, fine_weight, fine_transferred, exchange_material
                FROM transactions WHERE id = ? AND company_id = ? AND transaction_type = 'Exchange' FOR UPDATE");
            $st->bind_param('ii', $transaction_id, $company_id);
            $st->execute();
            $txn = $st->get_result()->fetch_assoc();
            $st->close();
            if (!$txn) {
                throw new Exception('Exchange not found');
            }

            $fine_total = (float) $txn['fine_weight'];
            $transferred = (float) ($txn['fine_transferred'] ?? 0);
            $remaining = max(0, $fine_total - $transferred);
            if ($remaining <= 0.0005) {
                throw new Exception('Fine weight already transferred for this exchange');
            }

            $transfer_fine = ($requested_fine !== null && $requested_fine > 0) ? min($requested_fine, $remaining) : $remaining;
            $received = (float) $txn['received_weight'];
            $transfer_rcv = ($fine_total > 0.0005) ? ($received * ($transfer_fine / $fine_total)) : $received;
            $material = (strcasecmp((string) ($txn['exchange_material'] ?? 'Gold'), 'Silver') === 0) ? 'Silver' : 'Gold';

            $fine_stock = msc_fetch_fine_stock($conn, $company_id, $material);
            if (!$fine_stock) {
                throw new Exception($material === 'Silver'
                    ? 'Fine silver stock not found. Add a Cash stock row with Silver in the name.'
                    : 'Fine gold stock not found. Add a Cash fine gold stock row (99.5%+ purity).');
            }

            $mix_stock = msc_fetch_mix_stock($conn, $company_id);
            if ($transfer_rcv > 0.0005) {
                if (!$mix_stock) {
                    throw new Exception('MIX Stock not found');
                }
                if ((float) $mix_stock['current_stock'] + 0.00001 < $transfer_rcv) {
                    throw new Exception('Insufficient MIX stock. Available: ' . number_format((float) $mix_stock['current_stock'], 3) . ' g, need: ' . number_format($transfer_rcv, 3) . ' g');
                }
                $new_mix = max(0, (float) $mix_stock['current_stock'] - $transfer_rcv);
                $mix_upd = $conn->prepare('UPDATE gold_stock SET current_stock = ?, last_updated = NOW() WHERE id = ?');
                $mix_upd->bind_param('di', $new_mix, $mix_stock['id']);
                if (!$mix_upd->execute()) {
                    throw new Exception('Failed to update MIX stock');
                }
                $mix_upd->close();
            }

            $new_fine_stock = (float) $fine_stock['current_stock'] + $transfer_fine;
            $fine_upd = $conn->prepare('UPDATE gold_stock SET current_stock = ?, last_updated = NOW() WHERE id = ?');
            $fine_upd->bind_param('di', $new_fine_stock, $fine_stock['id']);
            if (!$fine_upd->execute()) {
                throw new Exception('Failed to update fine stock');
            }
            $fine_upd->close();

            $new_transferred = $transferred + $transfer_fine;
            $tr_upd = $conn->prepare('UPDATE transactions SET fine_transferred = ? WHERE id = ? AND company_id = ?');
            $tr_upd->bind_param('dii', $new_transferred, $transaction_id, $company_id);
            $tr_upd->execute();
            $tr_upd->close();

            $receipt_id = 'XFN-' . strtoupper(uniqid());
            $notes = $conn->real_escape_string('Exchange #' . $txn['receipt_id'] . ': ' . number_format($transfer_fine, 3) . ' g fine → ' . $fine_stock['stock_name']);
            $log_purity = (float) ($fine_stock['purity'] ?? 99.9);
            $log_sql = "INSERT INTO transactions (company_id, party_id, receipt_id, transaction_type, gold_weight, fine_weight, purity, payment_method, date_of_transaction, narration, created_by)
                        VALUES ($company_id, NULL, '$receipt_id', 'Stock_Addition', $transfer_fine, $transfer_fine, $log_purity, 'Cash', NOW(), '$notes', $user_id)";
            if (!$conn->query($log_sql)) {
                throw new Exception('Failed to log stock transfer');
            }

            $conn->commit();
            echo json_encode([
                'success' => true,
                'message' => number_format($transfer_fine, 3) . ' g transferred to ' . $fine_stock['stock_name'],
                'fine_transferred' => $new_transferred,
                'remaining' => max(0, $fine_total - $new_transferred),
            ]);
            break;

        case 'transfer_period_fine':
            $start_date = $conn->real_escape_string($_POST['start_date'] ?? date('Y-m-d'));
            $end_date = $conn->real_escape_string($_POST['end_date'] ?? date('Y-m-d'));
            $target_fine = max(0, floatval($_POST['fine_weight'] ?? 0));
            if ($target_fine <= 0.0005) {
                throw new Exception('Enter a valid total fine weight');
            }

            $list_st = $conn->prepare("SELECT id, receipt_id, received_weight, fine_weight, fine_transferred, exchange_material
                FROM transactions
                WHERE company_id = ? AND transaction_type = 'Exchange'
                AND DATE(date_of_transaction) BETWEEN ? AND ?
                FOR UPDATE");
            $list_st->bind_param('iss', $company_id, $start_date, $end_date);
            $list_st->execute();
            $list_res = $list_st->get_result();
            $pending = [];
            $sum_pending_fine = 0.0;
            $sum_pending_rcv = 0.0;
            while ($row = $list_res->fetch_assoc()) {
                $fn = (float) $row['fine_weight'];
                $xf = (float) ($row['fine_transferred'] ?? 0);
                $rem = max(0, $fn - $xf);
                if ($rem <= 0.0005) {
                    continue;
                }
                $rcv = (float) $row['received_weight'];
                $pending[] = $row;
                $sum_pending_fine += $rem;
                $sum_pending_rcv += ($fn > 0.0005) ? ($rcv * ($rem / $fn)) : $rcv;
            }
            $list_st->close();

            if (empty($pending)) {
                throw new Exception('No pending fine to transfer for this period');
            }
            if ($target_fine + 0.0005 < $sum_pending_fine * 0.5) {
                throw new Exception('Total fine seems too low for this period');
            }

            $scale = ($sum_pending_fine > 0.0005) ? ($target_fine / $sum_pending_fine) : 1.0;
            $transfer_rcv = 0.0;
            $material = 'Gold';

            foreach ($pending as $txn) {
                $fn = (float) $txn['fine_weight'];
                $xf = (float) ($txn['fine_transferred'] ?? 0);
                $rem = max(0, $fn - $xf);
                $new_fine = $rem * $scale;
                $rcv = (float) $txn['received_weight'];
                $rcv_part = ($fn > 0.0005) ? ($rcv * ($new_fine / $fn)) : $rcv;
                $transfer_rcv += $rcv_part;

                if (strcasecmp((string) ($txn['exchange_material'] ?? 'Gold'), 'Silver') === 0) {
                    $material = 'Silver';
                }

                $final_fine = $xf + $new_fine;
                $tr_upd = $conn->prepare('UPDATE transactions SET fine_transferred = ? WHERE id = ? AND company_id = ?');
                $tr_upd->bind_param('dii', $final_fine, $txn['id'], $company_id);
                $tr_upd->execute();
                $tr_upd->close();
            }

            $fine_stock = msc_fetch_fine_stock($conn, $company_id, $material);
            if (!$fine_stock) {
                throw new Exception($material === 'Silver'
                    ? 'Fine silver stock not found.'
                    : 'Fine gold stock not found.');
            }

            $mix_stock = msc_fetch_mix_stock($conn, $company_id);
            if ($transfer_rcv > 0.0005) {
                if (!$mix_stock) {
                    throw new Exception('MIX Stock not found');
                }
                if ((float) $mix_stock['current_stock'] + 0.00001 < $transfer_rcv) {
                    throw new Exception('Insufficient MIX stock. Available: ' . number_format((float) $mix_stock['current_stock'], 3) . ' g');
                }
                $new_mix = max(0, (float) $mix_stock['current_stock'] - $transfer_rcv);
                $mix_upd = $conn->prepare('UPDATE gold_stock SET current_stock = ?, last_updated = NOW() WHERE id = ?');
                $mix_upd->bind_param('di', $new_mix, $mix_stock['id']);
                if (!$mix_upd->execute()) {
                    throw new Exception('Failed to update MIX stock');
                }
                $mix_upd->close();
            }

            $new_fine_stock = (float) $fine_stock['current_stock'] + $target_fine;
            $fine_upd = $conn->prepare('UPDATE gold_stock SET current_stock = ?, last_updated = NOW() WHERE id = ?');
            $fine_upd->bind_param('di', $new_fine_stock, $fine_stock['id']);
            if (!$fine_upd->execute()) {
                throw new Exception('Failed to update fine stock');
            }
            $fine_upd->close();

            $receipt_id = 'XFN-' . strtoupper(uniqid());
            $period_label = $conn->real_escape_string($start_date . ' to ' . $end_date);
            $notes = $conn->real_escape_string("Period {$period_label}: " . number_format($target_fine, 3) . ' g fine → ' . $fine_stock['stock_name']);
            $log_purity = (float) ($fine_stock['purity'] ?? 99.9);
            $log_sql = "INSERT INTO transactions (company_id, party_id, receipt_id, transaction_type, gold_weight, fine_weight, purity, payment_method, date_of_transaction, narration, created_by)
                        VALUES ($company_id, NULL, '$receipt_id', 'Stock_Addition', $target_fine, $target_fine, $log_purity, 'Cash', NOW(), '$notes', $user_id)";
            if (!$conn->query($log_sql)) {
                throw new Exception('Failed to log stock transfer');
            }

            $conn->commit();
            echo json_encode([
                'success' => true,
                'message' => number_format($target_fine, 3) . ' g transferred to ' . $fine_stock['stock_name'],
            ]);
            break;

        default:
            throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

$conn->close();
?>
