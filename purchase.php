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
                $conn->begin_transaction();
                try {
                    $transaction_id = isset($_POST['transaction_id']) ? intval($_POST['transaction_id']) : 0;
                    $is_edit = $transaction_id > 0;
                    $receipt_id = $conn->real_escape_string($_POST['receipt_id']);
                    $party_id = intval($_POST['party_id']);
                    $date_of_transaction = $conn->real_escape_string($_POST['date_of_transaction']);
                    $purchase_weight = floatval($_POST['purchase_weight'] ?? 0);
                    $purity = floatval($_POST['purity'] ?? 0);
                    $rate = floatval($_POST['rate'] ?? 0);
                    $amount = floatval($_POST['amount'] ?? 0);
                    $payment_type = $conn->real_escape_string($_POST['payment_type'] ?? '');
                    $cash_amount = floatval($_POST['cash_amount'] ?? 0);
                    $bank_amount = floatval($_POST['bank_amount'] ?? 0);
                    $bank_payment_type = $conn->real_escape_string($_POST['bank_payment_type'] ?? '');
                    $payment_amount = $cash_amount + $bank_amount;
                    
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
                    
                    // Determine payment method for database
                    if ($payment_type === 'cash') {
                        $payment_method = 'Cash';
                    } else {
                        $payment_method = $bank_payment_type ?: 'Bank';
                    }
                    $narration = $conn->real_escape_string($_POST['narration'] ?? '');
                    
                    // Validate required fields
                    if (empty($receipt_id) || empty($party_id) || $purchase_weight <= 0 || $rate <= 0) {
                        throw new Exception("Please fill all required fields with valid values");
                    }

                     // Check for Duplicate Receipt ID and Auto-Fix
                    if (!$is_edit) {
                        $check_dup_sql = "SELECT id FROM transactions WHERE receipt_id = '$receipt_id' AND company_id = $company_id LIMIT 1";
                        if ($conn->query($check_dup_sql)->num_rows > 0) {
                            // ID exists, generate a new one
                            // Pattern: P + company_id + 001
                            $prefix = "P{$company_id}"; 
                            $lastIdSql = "SELECT receipt_id FROM transactions WHERE company_id = $company_id AND receipt_id LIKE '{$prefix}%' ORDER BY id DESC LIMIT 1";
                            $lastIdResult = $conn->query($lastIdSql);
                            if ($lastIdResult->num_rows > 0) {
                                $lastReceiptId = $lastIdResult->fetch_assoc()['receipt_id'];
                                $serial = (int)substr($lastReceiptId, strlen($prefix)) + 1;
                            } else {
                                $serial = 1;
                            }
                            $receipt_id = $prefix . str_pad($serial, 3, '0', STR_PAD_LEFT);
                        }
                    }
                    
                    // Get party details
                    $party_sql = "SELECT party_name FROM parties WHERE id = $party_id";
                    $party_result = $conn->query($party_sql);
                    $party_data = $party_result->fetch_assoc();
                    $party_name = $party_data['party_name'];
                    
                    // Get party balance before transaction
                    $party_balance_sql = "SELECT current_balance, cash_balance, bank_balance FROM parties WHERE id = $party_id AND company_id = $company_id";
                    $party_balance_result = $conn->query($party_balance_sql);
                    $party_balance_data = $party_balance_result->fetch_assoc();
                    
                    $current_balance_before = floatval($party_balance_data['current_balance'] ?? 0);
                    $cash_balance_before = floatval($party_balance_data['cash_balance'] ?? 0);
                    $bank_balance_before = floatval($party_balance_data['bank_balance'] ?? 0);
                    
                    // If editing, reverse old transaction effects first
                    if ($is_edit && $old_transaction) {
                        // Reverse old party balance changes
                        $old_balance_change = floatval($old_transaction['party_balance_after']) - floatval($old_transaction['party_balance_before']);
                        $old_payment_method = $old_transaction['payment_method'];
                        $old_payment_amount = floatval($old_transaction['payment_amount']);
                        
                        // Reverse old gold stock
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
                        
                        // Reverse old party balance
                        $old_party_id = intval($old_transaction['party_id']);
                        $reverse_balance_sql = "UPDATE parties SET current_balance = current_balance - $old_balance_change";
                        if ($old_payment_method === 'Cash') {
                            $reverse_balance_sql .= ", cash_balance = cash_balance + $old_payment_amount";
                        } else {
                            $reverse_balance_sql .= ", bank_balance = bank_balance + $old_payment_amount";
                        }
                        $reverse_balance_sql .= " WHERE id = $old_party_id AND company_id = $company_id";
                        if (!$conn->query($reverse_balance_sql)) {
                            throw new Exception("Error reversing party balance: " . $conn->error);
                        }
                        
                        // Refresh party balance after reversal if it's the same party
                        if ($old_party_id == $party_id) {
                            $party_balance_result = $conn->query($party_balance_sql);
                            $party_balance_data = $party_balance_result->fetch_assoc();
                            $current_balance_before = floatval($party_balance_data['current_balance'] ?? 0);
                            $cash_balance_before = floatval($party_balance_data['cash_balance'] ?? 0);
                            $bank_balance_before = floatval($party_balance_data['bank_balance'] ?? 0);
                        }
                        
                        // Reverse Account Balance (Shop's physical balance)
                        // Old transaction was Purchase (Money Out). Reversal means Money In (+).
                        if ($old_payment_method === 'Cash') {
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
                    
                    $purchase_amount = floatval($amount); // Total purchase value
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
                            gold_amount = $amount,
                            payment_amount = $payment_amount,
                            payment_method = '$payment_method',
                            party_balance_before = $current_balance_before,
                            party_balance_after = $current_balance_after,
                            narration = 'Gold purchase from $party_name - $receipt_id" . (!empty($narration) ? " - $narration" : "") . "'
                            WHERE id = $transaction_id AND company_id = $company_id";
                    } else {
                        // Insert new transaction
                        $purchase_sql = "INSERT INTO transactions (
                            company_id, party_id, receipt_id, transaction_type, date_of_transaction,
                            gold_weight, purity, rate, gold_amount, payment_amount, payment_method, payment_type,
                            party_balance_before, party_balance_after, party_gold_balance_before, party_gold_balance_after,
                            narration
                        ) VALUES (
                            $company_id, $party_id, '$receipt_id', 'Purchase', '$date_of_transaction',
                            $purchase_weight, $purity, $rate, $amount, $payment_amount, '$payment_method', 'Payment_Out',
                            $current_balance_before, $current_balance_after, 0, 0,
                            'Gold purchase from $party_name - $receipt_id" . (!empty($narration) ? " - $narration" : "") . "'
                        )";
                    }
                    
                    if (!$conn->query($purchase_sql)) {
                        throw new Exception("Error " . ($is_edit ? "updating" : "creating") . " purchase transaction: " . $conn->error);
                    }
                    
                    // Update party balance
                    // Purchase increases what company owes (negative balance change)
                    // Payment reduces what company owes (positive balance change)
                    $party_balance_sql = "SELECT current_balance, cash_balance, bank_balance FROM parties WHERE id = $party_id AND company_id = $company_id";
                    $party_balance_result = $conn->query($party_balance_sql);
                    $party_balance_data = $party_balance_result->fetch_assoc();
                    
                    $current_balance = floatval($party_balance_data['current_balance'] ?? 0);
                    $cash_balance = floatval($party_balance_data['cash_balance'] ?? 0);
                    $bank_balance = floatval($party_balance_data['bank_balance'] ?? 0);
                    
                    // Calculate new balance
                    // Purchase reduces what party owes you (or increases what you owe them): -$purchase_amount
                    // Payment increases what party owes you (or reduces what you owe them): +$payment_amount
                    // Net change = -$purchase_amount + $payment_amount
                    $new_current_balance = $current_balance + $balance_change;
                    
                    // Update cash or bank balance based on payment method
                    if ($payment_type === 'cash') {
                        // Cash payment reduces cash balance (company pays cash to party)
                        $new_cash_balance = $cash_balance + $cash_amount;
                        $update_party_sql = "UPDATE parties SET 
                            current_balance = $new_current_balance,
                            cash_balance = $new_cash_balance
                            WHERE id = $party_id AND company_id = $company_id";
                    } else {
                        // Bank payment reduces bank balance (company pays via bank to party)
                        $new_bank_balance = $bank_balance + $bank_amount;
                        $update_party_sql = "UPDATE parties SET 
                            current_balance = $new_current_balance,
                            bank_balance = $new_bank_balance
                            WHERE id = $party_id AND company_id = $company_id";
                    }
                    
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
                    
                    // Update gold stock (increase company stock when purchasing)
                    $stock_sql = "SELECT id, current_stock FROM gold_stock WHERE purity = $purity AND company_id = $company_id ORDER BY id DESC LIMIT 1";
                    $stock_result = $conn->query($stock_sql);
                    
                    if ($stock_result && $stock_result->num_rows > 0) {
                        $stock_data = $stock_result->fetch_assoc();
                        $stock_id = $stock_data['id'];
                        $current_stock = $stock_data['current_stock'];
                        $new_stock = $current_stock + $purchase_weight;
                        
                        $update_stock_sql = "UPDATE gold_stock SET current_stock = $new_stock, last_updated = NOW() WHERE id = $stock_id";
                        if (!$conn->query($update_stock_sql)) {
                            throw new Exception("Error updating gold stock: " . $conn->error);
                        }
                    } else {
                        // Create new stock record
                        $insert_stock_sql = "INSERT INTO gold_stock (company_id, purity, current_stock, last_updated) VALUES ($company_id, $purity, $purchase_weight, NOW())";
                        if (!$conn->query($insert_stock_sql)) {
                            throw new Exception("Error creating gold stock record: " . $conn->error);
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
                            'receipt_id' => $receipt_id,
                            'party_name' => $party_name,
                            'party_contact' => $party_contact,
                            'purchase_weight' => $purchase_weight,
                            'purity' => $purity,
                            'rate' => $rate,
                            'amount' => $amount,
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
                $sql = "SELECT DISTINCT p.id, p.party_name, p.address, p.contact_no
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
                        'contact_no' => $row['contact_no']
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
                // Generate unique purchase ID: P + company_id + serial
                $prefix = "P{$company_id}";
                
                // Get the last purchase ID for this company
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
                    // First purchase for this company
                    $serial = 1;
                }
                
                $purchaseId = $prefix . str_pad($serial, 3, '0', STR_PAD_LEFT);
                
                echo json_encode([
                    'status' => 'success',
                    'purchase_id' => $purchaseId
                ]);
                exit;
                
            case 'get_purchase_details':
                $receipt_id = $conn->real_escape_string($_POST['receipt_id'] ?? '');
                $transaction_id = isset($_POST['transaction_id']) ? intval($_POST['transaction_id']) : 0;
                
                $sql = "SELECT t.*, p.party_name, p.contact_no
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
                    $party_balance_after = floatval($transaction['party_balance_after']);
                    $party_balance_before = floatval($transaction['party_balance_before']);
                    
                    // Reverse party balance (add back the payment)
                    $balance_change = $party_balance_after - $party_balance_before;
                    $update_party_sql = "UPDATE parties SET 
                        current_balance = current_balance - $balance_change";
                    
                    if ($payment_method === 'Cash') {
                        $update_party_sql .= ", cash_balance = cash_balance + $payment_amount";
                    } else {
                        $update_party_sql .= ", bank_balance = bank_balance + $payment_amount";
                    }
                    $update_party_sql .= " WHERE id = $party_id AND company_id = $company_id";
                    
                    if (!$conn->query($update_party_sql)) {
                        throw new Exception("Error reversing party balance: " . $conn->error);
                    }
                    
                    // Reverse gold stock (decrease stock)
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
                // Fetch recent purchase transactions for dropdown
                $list_sql = "SELECT t.id, t.receipt_id, t.date_of_transaction, t.gold_weight, t.rate, t.gold_amount, p.party_name
                            FROM transactions t
                            LEFT JOIN parties p ON t.party_id = p.id
                            WHERE t.transaction_type = 'Purchase' AND t.company_id = $company_id
                            ORDER BY t.date_of_transaction DESC, t.id DESC
                            LIMIT 20";
                
                $list_result = $conn->query($list_sql);
                
                if ($list_result) {
                    $purchases = [];
                    while ($row = $list_result->fetch_assoc()) {
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

// Enhanced statistics SQL query for purchase page
$stats_sql = "
SELECT 
    SUM(CASE WHEN transaction_type = 'Purchase' THEN gold_weight ELSE 0 END) AS total_purchase_weight,
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
WHERE DATE(date_of_transaction) = CURRENT_DATE AND company_id = $company_id";

$stats_result = $conn->query($stats_sql);
if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
} else {
    $stats = [
        'total_purchase_weight' => 0,
        'total_purchase_amount' => 0,
        'total_cash_purchase' => 0,
        'total_bank_purchase' => 0,
        'total_purchases' => 0,
        'purity_99_50_stock' => 0,
        'purity_99_90_stock' => 0,
        'purity_91_60_stock' => 0
    ];
}

// Get ALL gold stock information
$stock_sql = "SELECT stock_name, purity, current_stock FROM gold_stock WHERE company_id = $company_id ORDER BY purity DESC";
$stock_result = $conn->query($stock_sql);
$all_stocks = [];
if ($stock_result) {
    while ($stock_row = $stock_result->fetch_assoc()) {
        $all_stocks[] = $stock_row;
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

// Get recent purchase transactions
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$where_clause = $search ? "AND (t.receipt_id LIKE '%$search%' OR t.narration LIKE '%$search%')" : '';

$transactions_sql = "SELECT t.*, p.party_name, p.contact_no
                    FROM transactions t 
                    LEFT JOIN parties p ON t.party_id = p.id
                    WHERE t.transaction_type = 'Purchase' 
                    AND t.company_id = $company_id
                    $where_clause 
                    ORDER BY t.date_of_transaction DESC, t.id DESC 
                    LIMIT $offset, $limit";

// Debug: Check if transactions are being fetched
// Uncomment the line below to debug
// error_log("Transactions SQL: " . $transactions_sql);

$transactions = $conn->query($transactions_sql);

// Count the total number of Purchase transactions
$total_sql = "SELECT COUNT(*) as count 
              FROM transactions t 
              WHERE t.transaction_type = 'Purchase' 
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
    </style>

<!-- Main Content Container -->
<div class="w-full">
        <!-- Colorful Statistics with Icons -->
        <!-- Colorful Statistics with Icons -->
        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-7 gap-2 mb-6">
            <!-- Purchase Weight -->
            <div class="soft-gradient-green rounded-xl p-2 shadow-sm h-full">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-green-700 mb-0.5">Purchase Weight</p>
                        <p class="text-lg font-bold text-green-800 mb-0"><?= number_format($stats['total_purchase_weight'] ?? 0, 1) ?>g</p>
                        <p class="text-xs text-green-600 mb-0">Gold Purchased Today</p>
                    </div>
                    <div class="w-8 h-8 bg-green-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-shopping-basket text-white text-xs"></i>
                    </div>
                </div>
            </div>
            
            <!-- Total Amount -->
            <div class="soft-gradient-purple rounded-xl p-2 shadow-sm h-full">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-purple-700 mb-0.5">Total Value</p>
                        <p class="text-lg font-bold text-purple-800 mb-0">₹<?= number_format($stats['total_purchase_amount'] ?? 0, 0) ?></p>
                        <p class="text-xs text-purple-600 mb-0">Purchase Value</p>
                    </div>
                    <div class="w-8 h-8 bg-purple-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-coins text-white text-xs"></i>
                    </div>
                </div>
            </div>

            <!-- Amount Received -->
            <div class="soft-gradient-teal rounded-xl p-2 shadow-sm h-full">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-teal-700 mb-0.5">Amount Received</p>
                        <p class="text-lg font-bold text-teal-800 mb-0">₹<?= number_format(($stats['total_cash_received'] ?? 0) + ($stats['total_bank_received'] ?? 0), 0) ?></p>
                        <p class="text-xs text-teal-600 mb-0">Cash: ₹<?= number_format($stats['total_cash_received'] ?? 0, 0) ?> | Bank: ₹<?= number_format($stats['total_bank_received'] ?? 0, 0) ?></p>
                    </div>
                    <div class="w-8 h-8 bg-teal-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-hand-holding-usd text-white text-xs"></i>
                    </div>
                </div>
            </div>
            
            <!-- Amount Paid -->
            <div class="bg-danger-soft rounded-xl p-2 shadow-sm h-full">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-red-700 mb-0.5">Amount Paid</p>
                        <p class="text-lg font-bold text-red-800 mb-0">₹<?= number_format(($stats['total_cash_purchase'] ?? 0) + ($stats['total_bank_purchase'] ?? 0), 0) ?></p>
                        <p class="text-xs text-red-600 mb-0">Cash: ₹<?= number_format($stats['total_cash_purchase'] ?? 0, 0) ?> | Bank: ₹<?= number_format($stats['total_bank_purchase'] ?? 0, 0) ?></p>
                    </div>
                    <div class="w-8 h-8 bg-red-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-money-bill-wave text-white text-xs"></i>
                    </div>
                </div>
            </div>

            <!-- Cash in Hand -->
            <div class="soft-gradient-blue rounded-xl p-2 shadow-sm h-full">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-cyan-700 mb-0.5">Cash in Hand</p>
                        <p class="text-lg font-bold text-cyan-800 mb-0">₹<?= number_format($cash_in_hand, 0) ?></p>
                        <p class="text-xs text-cyan-600 mb-0">Current Balance</p>
                    </div>
                    <div class="w-8 h-8 bg-cyan-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-wallet text-white text-xs"></i>
                    </div>
                </div>
            </div>

            <!-- Stock Cards (Dynamic) -->
            <?php if (!empty($all_stocks)): ?>
                <?php foreach ($all_stocks as $stock): ?>
                    <div class="soft-gradient-orange rounded-xl p-2 shadow-sm h-full">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-medium text-orange-700 mb-0.5"><?= htmlspecialchars($stock['stock_name']) ?></p>
                                <p class="text-lg font-bold text-orange-800 mb-0"><?= number_format($stock['current_stock'], 3) ?>g</p>
                                <p class="text-xs text-orange-600 mb-0">Purity: <?= number_format($stock['purity'], 2) ?>%</p>
                            </div>
                            <div class="w-8 h-8 bg-orange-500 rounded-lg flex items-center justify-center">
                                <i class="fas fa-box text-white text-xs"></i>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Main Form and List Layout -->
        <div class="flex flex-col lg:flex-row gap-6">
            <!-- Left Side - Purchase Gold Form -->
            <div class="bg-white rounded-lg shadow-md border border-gray-200" style="flex: 0 0 55%;">
                <div class="bg-gradient-to-r from-blue-500 to-blue-600 px-4 py-2 rounded-t-lg">
                    <h2 class="text-base font-semibold text-white flex items-center">
                        <i class="fas fa-shopping-basket mr-2"></i>
                        Purchase Gold Transaction
                    </h2>
                </div>
                <div class="p-3">
                    <form id="purchaseForm" method="POST" class="space-y-3">
                        <input type="hidden" name="action" value="save_purchase">
                        <input type="hidden" name="transaction_id" id="editTransactionId" value="">
                        
                        <!-- Row 1: Purchase ID, Date & Party Selection -->
                        <div class="grid grid-cols-1 md:grid-cols-12 gap-3">
                            <!-- Purchase ID (25%) -->
                            <div class="md:col-span-3">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Purchase ID <span id="editModeIndicator" class="text-xs text-orange-600 font-semibold hidden">(Editing)</span></label>
                                <div class="relative">
                                    <input type="text" class="block w-full px-3 py-2 pr-10 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer" name="receipt_id" readonly id="purchaseIdInput" tabindex="0">
                                    <button type="button" class="absolute right-2 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600" id="showPurchaseListBtn" title="Show previous purchases">
                                        <i class="fas fa-history"></i>
                                    </button>
                                </div>
                                <div id="purchaseList" class="absolute z-10 mt-1 bg-white border border-gray-200 rounded-lg shadow-xl max-h-64 overflow-y-auto hidden" style="width: 400px; max-width: 90vw;"></div>
                            </div>
                            
                            <!-- Date (25%) -->
                            <div class="md:col-span-3">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                                <input type="datetime-local" class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" name="date_of_transaction" value="<?= date('Y-m-d\TH:i') ?>" required>
                            </div>

                            <!-- Party Name (50%) -->
                            <div class="md:col-span-6">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Party Name</label>
                                <div class="relative">
                                    <input type="hidden" name="party_id" id="partyId">
                                    <input type="text" 
                                           class="block w-full pl-3 pr-20 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500" 
                                           name="party_name" 
                                           id="partyNameInput"
                                           autocomplete="off"
                                           required 
                                           placeholder="Enter party name...">
                                    <div class="absolute inset-y-0 right-0 flex items-center pr-2">
                                        <button type="button" class="px-3 py-1 text-sm bg-purple-500 text-white rounded-lg hover:bg-purple-600 transition-colors duration-200" id="addNewPartyBtn" title="Add New Party (Alt+A)">
                                            <i class="fas fa-plus mr-1"></i>New
                                        </button>
                                    </div>
                                    <div id="partyList" class="absolute top-full left-0 right-0 bg-white border border-gray-200 rounded-lg shadow-xl max-h-64 overflow-y-auto z-50 hidden" style="width: calc(100% - 0px);"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Row 3: Weight & Purity -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Weight (g)</label>
                                <input type="number" step="0.001" class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" name="purchase_weight" id="purchaseWeight" required placeholder="0.000">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Purity (%)</label>
                                    <div class="relative">
                                        <input type="number" step="0.01" class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" name="purity" id="purityInput" required placeholder="Enter purity %" autocomplete="off">
                                        <div id="purityList" class="hidden absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-xl max-h-48 overflow-y-auto"></div>
                                    </div>
                            </div>
                        </div>
                        

                        
                        <!-- Row 4: Rate & Total -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Rate (₹/g)</label>
                                <input type="number" step="0.01" class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" name="rate" id="rateInput" required placeholder="0.00">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Total (₹)</label>
                                <input type="number" step="0.01" class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" name="amount" id="totalAmountInput" placeholder="0.00">
                            </div>
                        </div>
                        
                        <!-- Row 5: Ledger Adjustment & Payment Type -->
                        <div class="mb-3">
                            <label class="inline-flex items-center">
                                <input type="checkbox" id="adjustFromLedger" class="form-checkbox h-5 w-5 text-blue-600 rounded border-gray-300 focus:ring-blue-500 transition duration-150 ease-in-out">
                                <span class="ml-2 text-sm text-gray-700 font-medium">Adjust from Pending Balance (Don't Pay Now)</span>
                            </label>
                            <p class="text-xs text-gray-500 ml-7 mt-0.5">Check this if you are not paying immediately and want to credit the party's ledger.</p>
                        </div>
                        
                        <div class="grid grid-cols-4 gap-3" id="paymentSection">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Payment Type</label>
                                <select class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" name="payment_type" id="paymentTypeSelect">
                                    <option value="">Select Type</option>
                                    <option value="cash">Cash</option>
                                    <option value="bank" selected>Bank</option>
                                </select>
                            </div>
                            
                            <!-- Cash Payment Field -->
                            <div id="cashPaymentField" class="payment-field hidden">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Cash Paid (₹)</label>
                                <input type="number" step="0.01" class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" name="cash_amount" value="0.00" placeholder="0.00">
                            </div>

                            <!-- Bank Payment Field -->
                            <div id="bankPaymentField" class="payment-field">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Bank Paid (₹)</label>
                                <input type="number" step="0.01" class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" name="bank_amount" value="0.00" placeholder="0.00">
                            </div>
                            
                            <!-- Bank Payment Method -->
                            <div id="bankMethodField" class="payment-field">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Bank Type</label>
                                <select class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" name="bank_payment_type">
                                    <option value="">Select Type</option>
                                    <option value="RTGS">RTGS</option>
                                    <option value="NEFT">NEFT</option>
                                    <option value="Cheque">Cheque</option>
                                    <option value="UPI">UPI</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>

                        <!-- Row 6: Narration -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Narration (Optional)</label>
                            <textarea class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" name="narration" rows="2" placeholder="Enter any additional notes..."></textarea>
                        </div>

                        <!-- Row 7: Submit and Reset Buttons -->
                        <div class="grid grid-cols-2 gap-3">
                            <button type="submit" id="purchaseGoldBtn" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 flex items-center justify-center">
                                <i class="fas fa-shopping-basket mr-2"></i><span id="submitButtonText">Purchase Gold</span>
                            </button>
                            <button type="button" id="resetFormBtn" class="px-4 py-2 bg-gray-500 text-white text-sm font-medium rounded-lg hover:bg-gray-600 focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 flex items-center justify-center">
                                <i class="fas fa-undo mr-2"></i>Reset
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Right Side - Recent Purchases List -->
            <div class="bg-white rounded-lg shadow-md border border-gray-200" style="flex: 0 0 45%;">
                <div class="bg-gradient-to-r from-blue-500 to-blue-600 px-4 py-2 rounded-t-lg">
                    <h2 class="text-base font-semibold text-white flex items-center">
                        <i class="fas fa-list mr-2"></i>
                        Recent Purchases
                    </h2>
                </div>
                <div class="p-3 max-w-full">
                    <div class="overflow-x-auto max-w-full">
                        <table class="w-full text-sm responsive-table" style="table-layout: fixed; width: 100%; max-width: 100%;">
                            <thead>
                                <tr class="bg-gray-50 border-b">
                                    <th class="text-left py-2 px-1 font-medium text-gray-700 text-sm" style="width: 25%;">ID & Date</th>
                                    <th class="text-left py-2 px-1 font-medium text-gray-700 text-sm" style="width: 25%;">Party</th>
                                    <th class="text-left py-2 px-1 font-medium text-gray-700 text-sm" style="width: 25%;">Weight & Type</th>
                                    <th class="text-left py-2 px-1 font-medium text-gray-700 text-sm" style="width: 15%;">Amount</th>
                                    <th class="text-left py-2 px-1 font-medium text-gray-700 text-sm" style="width: 10%;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($transactions && $transactions->num_rows > 0): 
                                foreach ($transactions as $t): ?>
                                    <tr class="border-b hover:bg-gray-50 cursor-pointer selectable-row" 
                                        data-receipt-id="<?= htmlspecialchars($t['receipt_id'], ENT_QUOTES, 'UTF-8') ?>" 
                                        data-transaction-id="<?= intval($t['id']) ?>" 
                                        data-transaction="<?= htmlspecialchars(base64_encode(json_encode($t)), ENT_QUOTES, 'UTF-8') ?>"
                                        style="cursor: pointer;">
                                        <td class="py-2 px-1">
                                            <div class="flex items-center">
                                                <span class="bg-blue-500 text-white text-xs px-1.5 py-0.5 rounded-full mr-1 font-bold">P</span>
                                                <div class="cursor-pointer">
                                                    <div class="font-mono text-sm font-bold text-gray-900 hover:text-blue-600"><?= htmlspecialchars($t['receipt_id']) ?></div>
                                                    <div class="text-xs text-gray-500 border-b border-gray-300 pb-0.5"><?= date('d M Y', strtotime($t['date_of_transaction'])) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-2 px-1">
                                            <div class="font-semibold text-gray-900 text-sm"><?= htmlspecialchars($t['party_name'] ?? 'Unknown Party') ?></div>
                                            <?php if (!empty($t['contact_no'])): ?>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars($t['contact_no']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-2 px-1">
                                            <div class="flex items-center">
                                                <span class="bg-blue-500 text-white text-xs px-1.5 py-0.5 rounded-full mr-1 font-bold">P</span>
                                                <div>
                                                    <div class="text-sm font-bold text-blue-600"><?= number_format($t['gold_weight'], 2) ?>g</div>
                                                    <div class="text-xs text-gray-500">
                                                <?= match($t['purity']) {
                                                    '99.90' => 'Gold Coin',
                                                    '99.50' => 'Gold Bar',
                                                    '91.60' => 'Gold Ornament',
                                                    default => 'Gold'
                                                } ?> • ₹<?= number_format($t['rate'], 2) ?>/g
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-2 px-1">
                                            <div class="text-sm font-bold text-blue-600">₹<?= number_format($t['gold_amount'], 2) ?></div>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars($t['payment_method']) ?></div>
                                        </td>
                                        <td class="py-2 px-1">
                                            <div class="flex items-center space-x-1">
                                                <button type="button" class="print-transaction text-blue-600 hover:text-blue-800" title="View/Print" data-id="<?= $t['id'] ?>" data-receipt-id="<?= htmlspecialchars($t['receipt_id']) ?>" data-transaction='<?= htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8') ?>'>
                                                    <i class="fas fa-eye text-xs"></i>
                                                </button>
                                                <button type="button" class="text-red-600 hover:text-red-800 delete-transaction" title="Delete" data-id="<?= $t['id'] ?>" data-receipt-id="<?= htmlspecialchars($t['receipt_id']) ?>" data-weight="<?= $t['gold_weight'] ?>" data-amount="<?= $t['gold_amount'] ?>">
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
                                            No purchases found
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
                                   class="px-3 py-2 text-sm border border-gray-300 rounded-lg <?= $i == $page ? 'bg-blue-500 text-white' : 'text-gray-700 hover:bg-gray-50' ?>">
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
                                <span>₹${parseFloat(purchaseData?.rate || 0).toLocaleString('en-IN', {minimumFractionDigits: 2})}/g</span>
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
                    // Print receipt
                    printPurchaseReceipt(purchaseData, companyName);
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
                `Rate: ₹${parseFloat(purchaseData.rate).toLocaleString('en-IN', {minimumFractionDigits: 2})}/g\n\n` +
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
                            <span>₹${parseFloat(purchaseData.rate).toLocaleString('en-IN', {minimumFractionDigits: 2})}/g</span>
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

            // Initialize keyboard navigation for purchase form
            if (typeof KeyboardNavigationGeneric !== 'undefined') {
                KeyboardNavigationGeneric.init({
                    formId: 'purchaseForm',
                    fieldOrder: [
                        'purchaseIdInput',       // 1. Purchase ID (readonly)
                        'date_of_transaction',   // 2. Date
                        'party_name',            // 3. Party Name
                        'purchase_weight',       // 4. Weight
                        'purity',                // 5. Purity
                        'rate',                  // 6. Rate
                        'amount',                // 7. Total Amount
                        'payment_type',          // 8. Payment Type
                        'cash_amount',           // 9. Cash Paid (conditional)
                        'bank_amount',           // 10. Bank Paid (conditional)
                        'bank_payment_type',     // 11. Bank Payment Type
                        'narration'              // 12. Narration
                    ],
                    skipFields: [],
                    submitButtonId: 'purchaseGoldBtn',
                    formName: 'purchase'
                });
                window.KeyboardNavigation = KeyboardNavigationGeneric; // Make globally available
            }

            // Calculate total amount when weight or rate changes
            $('[name="purchase_weight"], [name="rate"]').on('input', function() {
                const weight = parseFloat($('[name="purchase_weight"]').val()) || 0;
                const rate = parseFloat($('[name="rate"]').val()) || 0;
                const amount = weight * rate;
                $('#totalAmountInput').val(amount.toFixed(2));
            });

            // Keyboard navigation is now handled by KeyboardNavigationGeneric
            // Removed individual field Enter key handlers
            
            // Total amount field - move to payment type on Enter (kept for backward compatibility)
            $('#totalAmountInput').on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    $('#paymentTypeSelect').focus();
                }
            });

            // Party search functionality
            let partyListVisible = false;
            let currentIndex = -1;
            let selectedPartyName = '';
            
            // Function to update party selection status
            function updatePartySelectionStatus(isSelected) {
                if (isSelected) {
                    $('#partyNameInput').addClass('border-purple-500');
                } else {
                    $('#partyNameInput').removeClass('border-purple-500');
                }
            }

            $('#partyNameInput').on('input', function () {
                const term = $(this).val();
                
                // Reset selection if user clears or modifies the selected party name
                if (term !== selectedPartyName) {
                    selectedPartyName = '';
                    $('#partyId').val('');
                    updatePartySelectionStatus(false);
                }
                
                if (term.length >= 1) {
                    $.post('', {
                        action: 'search_parties',
                        term: term
                    }, function (parties) {
                        const partyList = $('#partyList');
                        partyList.empty();
                        currentIndex = -1; // Reset index when new results load
                        parties.forEach((party, index) => {
                            const partyItem = document.createElement('div');
                            partyItem.className = 'px-2 py-1.5 hover:bg-purple-50 cursor-pointer border-b border-gray-100 last:border-b-0 transition-colors duration-150 party-item';
                            partyItem.setAttribute('data-index', index);
                            partyItem.setAttribute('data-id', party.id || '');
                            partyItem.setAttribute('data-name', party.party_name || '');
                            partyItem.setAttribute('data-address', party.address || '');
                            
                            partyItem.innerHTML = `
                                <div class="flex items-center">
                                    <div class="w-6 h-6 bg-gradient-to-br from-purple-500 to-purple-600 rounded-full flex items-center justify-center text-white text-xs font-semibold mr-2 shadow-sm">
                                        ${(party.party_name || 'U').charAt(0).toUpperCase()}
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center space-x-2">
                                            <div class="text-sm font-medium text-gray-900 truncate">${party.party_name || 'Unknown Party'}</div>
                                            <span class="text-xs text-purple-600 font-mono bg-purple-50 px-1.5 py-0.5 rounded">ID: ${party.id}</span>
                                        </div>
                                        <div class="text-xs text-gray-500 truncate">${party.address || 'No address provided'}</div>
                                        ${party.contact_no ? `<div class="text-xs text-gray-400">${party.contact_no}</div>` : ''}
                                    </div>
                                    <div class="text-xs text-gray-400 ml-2">
                                        <i class="fas fa-chevron-right"></i>
                                    </div>
                                </div>
                            `;
                            
                            // Add click handler
                            partyItem.addEventListener('click', (e) => {
                                e.stopPropagation();
                                const partyData = {
                                    id: partyItem.getAttribute('data-id'),
                                    party_name: partyItem.getAttribute('data-name'),
                                    address: partyItem.getAttribute('data-address')
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
                    // Reset when input is completely cleared
                    selectedPartyName = '';
                    $('#partyId').val('');
                    updatePartySelectionStatus(false);
                }
            });
            
            // Party selection function
            function selectParty(party) {
                // Store selected party
                selectedPartyName = party.party_name;
                $('#partyId').val(party.id);
                $('#partyNameInput').val(party.party_name);
                $('#partyList').addClass('hidden');
                partyListVisible = false;
                currentIndex = -1;
                
                // Update visual status
                updatePartySelectionStatus(true);
            }
            
            // Keyboard navigation for party list
            $('#partyNameInput').on('keydown', function(e) {
                if (e.altKey && (e.key === 'a' || e.key === 'A')) {
                    e.preventDefault();
                    e.stopPropagation();
                    showAddPartyModal($('#partyNameInput').val().trim(), null);
                    return;
                }
                
                const partyItems = document.querySelectorAll('#partyList .party-item');
                
                if (e.key === 'ArrowDown' && partyListVisible && partyItems.length > 0) {
                    e.preventDefault();
                    if (currentIndex < 0) {
                        currentIndex = 0; // Start from first item
                    } else {
                        currentIndex = Math.min(currentIndex + 1, partyItems.length - 1);
                    }
                    updatePartyHighlight();
                } else if (e.key === 'ArrowUp' && partyListVisible && partyItems.length > 0) {
                    e.preventDefault();
                    if (currentIndex <= 0) {
                        currentIndex = -1; // Deselect all
                    } else {
                        currentIndex = Math.max(currentIndex - 1, 0);
                    }
                    updatePartyHighlight();
                } else if (e.key === 'Enter' && partyListVisible && currentIndex >= 0) {
                    e.preventDefault();
                    const selectedItem = partyItems[currentIndex];
                    if (selectedItem) {
                        selectedItem.click();
                    }
                } else if (e.key === 'Escape') {
                    $('#partyList').addClass('hidden');
                    partyListVisible = false;
                    currentIndex = -1;
                }
            });
            
            // Function to update party selection highlighting
            function updatePartyHighlight() {
                const partyItems = document.querySelectorAll('#partyList .party-item');
                
                partyItems.forEach((item, index) => {
                    if (index === currentIndex && currentIndex >= 0) {
                        item.classList.add('bg-purple-100', 'border-l-4', 'border-purple-500');
                        item.classList.remove('hover:bg-purple-50');
                    } else {
                        item.classList.remove('bg-purple-100', 'border-l-4', 'border-purple-500');
                        item.classList.add('hover:bg-purple-50');
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

            // Function to show add party modal
            function showAddPartyModal(partyName, form) {
                SharedPartyHandler.showAddPartyModal({
                    onSuccess: function(response, partyData) {
                        // Automatically select the newly created party
                        const newParty = {
                            id: response.party_id,
                            party_name: partyData.party_name,
                            address: partyData.address
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
            $('#paymentTypeSelect').val('bank');
            $('input[name="cash_amount"]').val('0.00');
            $('input[name="bank_amount"]').val('0.00');
            $('select[name="bank_payment_type"]').val('');
            $('#cashPaymentField').addClass('hidden');
            $('#bankPaymentField').removeClass('hidden');
            $('#bankMethodField').removeClass('hidden');
            $('#adjustFromLedger').prop('checked', false);
            $('#paymentSection').removeClass('opacity-50 pointer-events-none');
            $('#paymentSection').find('input, select').prop('disabled', false);
        }

        // Adjust from Ledger toggle
        $('#adjustFromLedger').on('change', function() {
            if ($(this).is(':checked')) {
                // Determine total amount
                const totalAmount = parseFloat($('#totalAmountInput').val()) || 0;
                
                // Hide/Disable payment section
                $('#paymentSection').addClass('opacity-50 pointer-events-none');
                $('#paymentSection').find('input, select').prop('disabled', true);
                
                // Reset values internally so submitted payment is 0
                $('#paymentTypeSelect').val('');
                $('input[name="cash_amount"]').val('0.00');
                $('input[name="bank_amount"]').val('0.00');
            } else {
                // Enable payment section
                $('#paymentSection').removeClass('opacity-50 pointer-events-none');
                $('#paymentSection').find('input, select').prop('disabled', false);
                
                // Set default to Bank
                $('#paymentTypeSelect').val('bank').trigger('change');
            }
        });



        // Payment type switching functionality
            $('#paymentTypeSelect').on('change', function() {
                const paymentType = $(this).val();
                
                // Hide all payment fields
                $('.payment-field').addClass('hidden');
                
                if (paymentType === 'cash') {
                    $('#cashPaymentField').removeClass('hidden');
                    $('#bankMethodField').addClass('hidden');
                    // Auto-copy amount to cash field
                    const amount = $('[name="amount"]').val();
                    $('[name="cash_amount"]').val(amount);
                    // Update keyboard navigation to skip bank fields
                    if (window.KeyboardNavigation && window.KeyboardNavigation.updateSkipFields) {
                        window.KeyboardNavigation.updateSkipFields(['bank_amount', 'bank_payment_type']);
                    }
                } else if (paymentType === 'bank') {
                    $('#bankPaymentField').removeClass('hidden');
                    $('#bankMethodField').removeClass('hidden');
                    // Auto-copy amount to bank field
                    const amount = $('[name="amount"]').val();
                    $('[name="bank_amount"]').val(amount);
                    // Update keyboard navigation to skip cash field
                    if (window.KeyboardNavigation && window.KeyboardNavigation.updateSkipFields) {
                        window.KeyboardNavigation.updateSkipFields(['cash_amount']);
                    }
                } else {
                    // No payment type selected, skip all payment fields
                    if (window.KeyboardNavigation && window.KeyboardNavigation.updateSkipFields) {
                        window.KeyboardNavigation.updateSkipFields(['cash_amount', 'bank_amount', 'bank_payment_type']);
                    }
                }
            });

            // Auto-update payment amounts when total changes
            $('[name="amount"]').on('input', function() {
                const amount = $(this).val();
                const paymentType = $('#paymentTypeSelect').val();
                
                if (paymentType === 'cash') {
                    $('[name="cash_amount"]').val(amount);
                } else if (paymentType === 'bank') {
                    $('[name="bank_amount"]').val(amount);
                }
            });

            // Initialize payment fields on page load
            $('#paymentTypeSelect').trigger('change');

            // ===== PURITY AUTOCOMPLETE FUNCTIONALITY =====
            let purityStocks = [];

            // Fetch purity stocks from server
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

            // Initialize purity autocomplete
            fetchPurityStocks();
            
            // Show suggestions on focus
            $('#purityInput').on('focus', function() {
                showPuritySuggestions($(this).val());
            });
            
            // Filter on input
            $('#purityInput').on('input', function() {
                showPuritySuggestions($(this).val());
            });
            
            // Select purity from list
            $(document).on('click', '.purity-item', function() {
                const purity = $(this).data('purity');
                $('#purityInput').val(purity);
                $('#purityList').addClass('hidden');
                $('#purityInput').trigger('change');
                $('#rateInput').focus(); // Move to next field
            });
            
            // Hide list when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('#purityInput, #purityList').length) {
                    $('#purityList').addClass('hidden');
                }
            });

            // Handle arrow keys for purity list
            $('#purityInput').on('keydown', function(e) {
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
                    } else {
                         // Default behavior (submit or move next) already handled
                    }
                } else if (e.key === 'Escape') {
                    purityList.addClass('hidden');
                }
            });
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
                const purchaseWeight = $('[name="purchase_weight"]').val();
                const purity = $('[name="purity"] option:selected').text();
                const rate = $('[name="rate"]').val();
                const amount = $('[name="amount"]').val();
                const paymentType = $('[name="payment_type"]').val();
                const cashAmount = $('[name="cash_amount"]').val();
                const bankAmount = $('[name="bank_amount"]').val();
                const bankPaymentType = $('[name="bank_payment_type"]').val();
                
                // Show confirmation dialog
                Swal.fire({
                    title: '<div style="font-size: 20px; font-weight: 700; color: #1f2937; font-family: \'Poppins\', sans-serif;">Confirm Gold Purchase</div>',
                    html: `
                        <div style="font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; padding: 4px;">
                            <!-- Party Section -->
                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 8px;">
                                <div style="display: flex; align-items: center; margin-bottom: 6px;">
                                    <div style="width: 20px; height: 20px; background: #9333ea; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 8px;">
                                        <i class="fas fa-user" style="color: white; font-size: 10px;"></i>
                                    </div>
                                    <span style="font-size: 13px; font-weight: 600; color: #374151;">Party</span>
                                </div>
                                <div style="font-size: 14px; color: #1f2937; font-weight: 500;">${partyName}</div>
                            </div>
                            
                            <!-- Purchase Details -->
                            <div style="background: #f0f9ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 12px; margin-bottom: 8px;">
                                <div style="display: flex; align-items: center; margin-bottom: 6px;">
                                    <div style="width: 20px; height: 20px; background: #2563eb; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 8px;">
                                        <i class="fas fa-shopping-basket" style="color: white; font-size: 10px;"></i>
                                    </div>
                                    <span style="font-size: 13px; font-weight: 600; color: #374151;">Purchase Details</span>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 13px;">
                                    <div><span style="color: #6b7280;">Weight:</span> <span style="color: #1f2937; font-weight: 500;">${purchaseWeight}g</span></div>
                                    <div><span style="color: #6b7280;">Purity:</span> <span style="color: #1f2937; font-weight: 500;">${purity}</span></div>
                                    <div><span style="color: #6b7280;">Rate:</span> <span style="color: #1f2937; font-weight: 500;">₹${parseFloat(rate).toLocaleString('en-IN')}/g</span></div>
                                    <div><span style="color: #6b7280;">Total:</span> <span style="color: #2563eb; font-weight: 600;">₹${parseFloat(amount).toLocaleString('en-IN')}</span></div>
                                </div>
                            </div>
                            
                            <!-- Payment Details -->
                            <div style="background: #fef3c7; border: 1px solid #fde68a; border-radius: 8px; padding: 12px;">
                                <div style="display: flex; align-items: center; margin-bottom: 6px;">
                                    <div style="width: 20px; height: 20px; background: #f59e0b; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 8px;">
                                        <i class="fas fa-credit-card" style="color: white; font-size: 10px;"></i>
                                    </div>
                                    <span style="font-size: 13px; font-weight: 600; color: #374151;">Payment</span>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 13px;">
                                    <div><span style="color: #6b7280;">Type:</span> <span style="color: #1f2937; font-weight: 500; text-transform: capitalize;">${paymentType === 'cash' ? 'Cash' : 'Bank'}</span></div>
                                    ${paymentType === 'bank' && bankPaymentType ? `<div><span style="color: #6b7280;">Method:</span> <span style="color: #1f2937; font-weight: 500;">${bankPaymentType}</span></div>` : ''}
                                    <div><span style="color: #6b7280;">Cash:</span> <span style="color: #1f2937; font-weight: 500;">₹${parseFloat(cashAmount || 0).toLocaleString('en-IN')}</span></div>
                                    <div><span style="color: #6b7280;">Bank:</span> <span style="color: #1f2937; font-weight: 500;">₹${parseFloat(bankAmount || 0).toLocaleString('en-IN')}</span></div>
                                    <div><span style="color: #6b7280;">Total:</span> <span style="color: #f59e0b; font-weight: 600;">₹${(parseFloat(cashAmount || 0) + parseFloat(bankAmount || 0)).toLocaleString('en-IN')}</span></div>
                                </div>
                            </div>
                        </div>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Complete Purchase',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#2563eb',
                    cancelButtonColor: '#6b7280',
                    width: '420px',
                    customClass: {
                        popup: 'swal-popup-enhanced',
                        title: 'swal-title-enhanced',
                        htmlContainer: 'swal-html-enhanced',
                        confirmButton: 'swal-btn-confirm',
                        cancelButton: 'swal-btn-cancel'
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        const formData = new FormData(form);
                        
                        // Parse formatted values (remove commas) before sending
                        const amountValue = ($('#totalAmountInput').val() || '0').replace(/,/g, '');
                        const cashValue = ($('[name="cash_amount"]').val() || '0').replace(/,/g, '');
                        const bankValue = ($('[name="bank_amount"]').val() || '0').replace(/,/g, '');
                        formData.set('amount', amountValue);
                        formData.set('cash_amount', cashValue);
                        formData.set('bank_amount', bankValue);
                        
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
                selectedPartyName = '';
                updatePartySelectionStatus(false);
                $('[name="purchase_weight"]').val('');
                $('[name="rate"]').val('');
                $('[name="amount"]').val('');
                $('[name="payment_type"]').val('bank');
                $('[name="cash_amount"]').val('0.00');
                $('[name="bank_amount"]').val('0.00');
                $('[name="bank_payment_type"]').val('');
                $('[name="narration"]').val('');
                
                // Trigger payment type change
                $('#paymentTypeSelect').trigger('change');
                
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
                                        ${purchase.gold_weight}g @ ₹${purchase.rate}/g = ₹${parseFloat(purchase.gold_amount).toLocaleString('en-IN')}
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
                const clickedButton = $(e.target).closest('button, .print-transaction, .delete-transaction');
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
                if (typeof selectedPartyName !== 'undefined') {
                    selectedPartyName = data.party_name || '';
                }
                if (typeof updatePartySelectionStatus === 'function') {
                    updatePartySelectionStatus(true);
                }
                
                // Set purchase details
                $('[name="purchase_weight"]').val(data.gold_weight || '');
                $('[name="purity"]').val(data.purity || '99.90');
                $('[name="rate"]').val(data.rate || '');
                $('[name="amount"]').val(data.gold_amount || '');
                
                // Set payment details
                const paymentMethod = data.payment_method || '';
                const paymentAmount = parseFloat(data.payment_amount || 0);
                
                if (paymentMethod === 'Cash') {
                    $('[name="payment_type"]').val('cash');
                    $('[name="cash_amount"]').val(paymentAmount.toFixed(2));
                    $('[name="bank_amount"]').val('0.00');
                } else {
                    $('[name="payment_type"]').val('bank');
                    $('[name="bank_amount"]').val(paymentAmount.toFixed(2));
                    $('[name="cash_amount"]').val('0.00');
                    $('[name="bank_payment_type"]').val(paymentMethod || '');
                }
                
                // Trigger payment type change to show/hide fields
                $('#paymentTypeSelect').trigger('change');
                
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
            
            // Handle print/view transaction button in recent transactions table
            $(document).on('click', '.print-transaction', function(e) {
                e.stopPropagation(); // Prevent row selection
                const tr = $(this).closest('tr');
                printPurchaseTransaction(tr[0]);
            });
            
            function printPurchaseTransaction(tr) {
                // Get receipt ID from the row
                const receiptId = tr.getAttribute('data-receipt-id') || tr.querySelector('.font-mono')?.textContent.trim();
                if (!receiptId) {
                    Swal.fire('Error', 'Receipt ID not found', 'error');
                    return;
                }
                
                // Try to get transaction data from data attribute first
                let transaction = null;
                const transactionData = tr.getAttribute('data-transaction');
                
                if (transactionData) {
                    try {
                        // Decode base64 encoded JSON or parse directly
                        let jsonString = transactionData;
                        
                        // Try base64 decode first
                        try {
                            jsonString = atob(transactionData);
                        } catch (e) {
                            // If base64 decode fails, try parsing as-is
                            jsonString = transactionData;
                            
                            // Decode HTML entities if present
                            const tempDiv = document.createElement('div');
                            tempDiv.innerHTML = jsonString;
                            jsonString = tempDiv.textContent || tempDiv.innerText || jsonString;
                            
                            // Manual HTML entity decoding as fallback
                            if (jsonString.includes('&')) {
                                jsonString = jsonString
                                    .replace(/&quot;/g, '"')
                                    .replace(/&#039;/g, "'")
                                    .replace(/&amp;/g, '&')
                                    .replace(/&lt;/g, '<')
                                    .replace(/&gt;/g, '>');
                            }
                        }
                        
                        // Parse the JSON
                        transaction = JSON.parse(jsonString);
                    } catch (error) {
                        console.warn('Failed to parse transaction data from attribute:', error);
                        // Will fetch from server instead
                    }
                }
                
                // If we have transaction data and it's a purchase transaction, use it
                if (transaction && transaction.transaction_type === 'Purchase') {
                    showPurchaseReceiptModal(transaction);
                    return;
                }
                
                // Otherwise, fetch purchase details from server using receipt_id
                fetchPurchaseByReceiptId(receiptId);
            }
            
            function fetchPurchaseByReceiptId(receiptId) {
                // Show loading indicator
                Swal.fire({
                    title: 'Loading...',
                    text: 'Fetching purchase details',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                // Fetch purchase details
                $.post('', {
                    action: 'get_purchase_details',
                    receipt_id: receiptId
                }, function(response) {
                    if (response.status === 'success' && response.data) {
                        showPurchaseReceiptModal(response.data);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to fetch purchase details'
                        });
                    }
                }, 'json').fail(function(xhr, status, error) {
                    console.error('AJAX error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to fetch purchase details: ' + error
                    });
                });
            }
            
            function showPurchaseReceiptModal(transaction) {
                const purchaseData = {
                    receipt_id: transaction.receipt_id || '',
                    party_name: transaction.party_name || 'Unknown Party',
                    party_contact: transaction.contact_no || '',
                    purchase_weight: transaction.gold_weight || 0,
                    purity: transaction.purity || 0,
                    rate: transaction.rate || 0,
                    amount: transaction.gold_amount || 0,
                    payment_method: transaction.payment_method || '',
                    cash_amount: transaction.payment_type === 'Cash' ? transaction.payment_amount : 0,
                    bank_amount: transaction.payment_type !== 'Cash' ? transaction.payment_amount : 0,
                    date_of_transaction: transaction.date_of_transaction || new Date().toISOString()
                };
                
                // Show the receipt in a modal
                showPurchaseSuccess('Purchase Receipt', purchaseData);
            }
            
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
$page_title = "Purchase Gold";
include 'components/layout.php';
?>