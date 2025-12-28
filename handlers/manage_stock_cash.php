<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/account_balance_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$company_id = $_SESSION['company_id'];
$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

try {
    $conn->begin_transaction();

    switch ($action) {
        case 'add_stock':
            $purity = floatval($_POST['purity']);
            $amount = floatval($_POST['amount']);
            $stock_name = $conn->real_escape_string($_POST['stock_name'] ?? '');
            $stock_type = $conn->real_escape_string($_POST['stock_type'] ?? 'Cash');
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
                // Update existing stock
                $row = $check_result->fetch_assoc();
                $new_stock = $row['current_stock'] + $amount;
                $update_sql = "UPDATE gold_stock SET current_stock = $new_stock, stock_name = '$stock_name', last_updated = NOW() WHERE id = {$row['id']}";
                $conn->query($update_sql);
            } else {
                // Insert new stock entry
                $insert_sql = "INSERT INTO gold_stock (company_id, stock_name, purity, current_stock, last_updated) 
                               VALUES ($company_id, '$stock_name', $purity, $amount, NOW())";
                $conn->query($insert_sql);
            }
            
            // Log transaction with stock_type
            $receipt_id = 'STK-' . strtoupper(uniqid());
            $log_sql = "INSERT INTO transactions (company_id, party_id, receipt_id, transaction_type, gold_weight, purity, payment_method, date_of_transaction, narration, created_by) 
                        VALUES ($company_id, NULL, '$receipt_id', 'Stock_Addition', $amount, $purity, '$stock_type', NOW(), 'Stock Addition ($stock_name - $stock_type): $notes', $user_id)";
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

        default:
            throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

$conn->close();
?>
