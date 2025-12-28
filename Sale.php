<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

header("Cache-Control: no-cache, must-revalidate");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
header("Pragma: no-cache");
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_error.log');

// Load database configuration
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/handlers/account_balance_helper.php';

if ($conn->connect_error) {
    die(json_encode(['status' => 'error', 'message' => 'Connection failed: ' . $conn->connect_error]));
}

// Get company_id from session
$company_id = $_SESSION['company_id'];

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'search_parties':
                $search = $conn->real_escape_string($_POST['term']);
                $sql = "SELECT 
                        pb.id, 
                        pb.party_name, 
                        pb.address,
                        pb.current_balance AS total_due_amount,
                        pb.current_gold_balance AS total_due_gold
                    FROM parties pb
                    WHERE pb.company_id = $company_id AND pb.party_name LIKE '%$search%'
                    LIMIT 5";
                
                $result = $conn->query($sql);
                $parties = [];
                while ($row = $result->fetch_assoc()) {
                    $parties[] = [
                        'id' => $row['id'],
                        'party_name' => $row['party_name'],
                        'address' => $row['address'],
                        'total_due_amount' => $row['total_due_amount'],
                        'total_due_gold' => $row['total_due_gold']
                    ];
                }
                echo json_encode($parties);
                exit;
                
            case 'save_party':
                $party_name = $conn->real_escape_string($_POST['party_name']);
                $address = $conn->real_escape_string($_POST['address']);
                $contact_no = $conn->real_escape_string($_POST['contact_no']);
                
                $conn->begin_transaction();
                try {
                    $sql = "INSERT INTO parties (company_id, party_name, address, contact_no) VALUES (?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("isss", $company_id, $party_name, $address, $contact_no);
                    $stmt->execute();
                    
                    $conn->commit();
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Party added successfully'
                    ]);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Error adding party: ' . $e->getMessage()
                    ]);
                }
                exit;
                
                
            case 'search_receipt_ids':
                $term = $conn->real_escape_string($_POST['term']);
                $sql = "SELECT receipt_id, date_of_transaction, p.party_name 
                        FROM transactions t
                        LEFT JOIN parties p ON t.party_id = p.id
                        WHERE t.company_id = $company_id 
                        AND t.transaction_type = 'Sale' 
                        AND (t.receipt_id LIKE '%$term%' OR p.party_name LIKE '%$term%')
                        ORDER BY t.date_of_transaction DESC 
                        LIMIT 10";
                
                $result = $conn->query($sql);
                $receipts = [];
                while ($row = $result->fetch_assoc()) {
                    $receipts[] = [
                        'receipt_id' => $row['receipt_id'],
                        'label' => $row['receipt_id'] . ' - ' . $row['party_name'] . ' (' . date('d M Y', strtotime($row['date_of_transaction'])) . ')',
                        'value' => $row['receipt_id']
                    ];
                }
                echo json_encode($receipts);
                exit;

            case 'get_exchange_by_receipt_id':
                $receipt_id = $conn->real_escape_string($_POST['receipt_id'] ?? '');
                
                $sql = "SELECT t.*, p.party_name 
                        FROM transactions t 
                        LEFT JOIN parties p ON t.party_id = p.id
                        WHERE t.receipt_id = ? AND t.company_id = ? AND t.transaction_type = 'Sale'";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("si", $receipt_id, $company_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    echo json_encode([
                        'status' => 'success',
                        'data' => $result->fetch_assoc()
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Receipt not found'
                    ]);
                }
                exit;
                
            case 'get_stocks_by_purity':
                // Get available stocks with purity information for autocomplete
                $sql = "SELECT DISTINCT purity, stock_name, current_stock 
                        FROM gold_stock 
                        WHERE company_id = ? AND current_stock > 0
                        ORDER BY purity DESC";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('i', $company_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                $stocks = [];
                while ($row = $result->fetch_assoc()) {
                    $stocks[] = [
                        'purity' => $row['purity'],
                        'stock_name' => $row['stock_name'],
                        'current_stock' => $row['current_stock']
                    ];
                }
                echo json_encode($stocks);
                exit;
                
            case 'save_transaction':
                $conn->begin_transaction();
                try {
                    $data = $_POST;
                    $receipt_id = $conn->real_escape_string($data['receipt_id']);
                    $party_name = $conn->real_escape_string($data['party_name']);
                    $date_of_transaction = $conn->real_escape_string($data['date_of_transaction']);
                    $weight = floatval($data['weight']); // Sale weight (from 'weight' field)
                    $purity = floatval($data['purity']);
                    $rate = floatval($data['rate']);
                    $amount = floatval($data['amount']);
                    $payment_amount = floatval($data['payment_amount'] ?? 0);
                    $payment_method = $conn->real_escape_string($data['payment_method'] ?? 'Cash');
                    $payment_status = $conn->real_escape_string($data['payment_status'] ?? 'Due');
                    $narration = $conn->real_escape_string($data['narration'] ?? '');
                    
                    // Calculate fine weight
                    $fine_weight = $weight * ($purity / 100);
                    
                    $transaction_id = isset($data['transaction_id']) && !empty($data['transaction_id']) ? intval($data['transaction_id']) : null;

                    // Get stock for selected purity
                    $stock_query = "SELECT id, current_stock, stock_name FROM gold_stock WHERE company_id = $company_id AND purity = $purity ORDER BY id DESC LIMIT 1 FOR UPDATE";
                    $stock_result = $conn->query($stock_query);

                    if ($stock_result->num_rows === 0) {
                        throw new Exception("No stock found for purity $purity%");
                    }
                    
                    $stock_data = $stock_result->fetch_assoc();
                    $stock_id = $stock_data['id'];
                    $current_stock = $stock_data['current_stock'];

                    // If editing, get original transaction details
                    $original_weight = 0;
                    $original_purity = 0;
                    if ($transaction_id) {
                        $original_sql = "SELECT gold_weight, purity, party_id FROM transactions WHERE id = ? FOR UPDATE";
                        $original_stmt = $conn->prepare($original_sql);
                        $original_stmt->bind_param("i", $transaction_id);
                        $original_stmt->execute();
                        $original_result = $original_stmt->get_result();
                        
                        if ($original_result->num_rows === 0) {
                            throw new Exception("Original transaction not found");
                        }
                        
                        $original_transaction = $original_result->fetch_assoc();
                        $original_weight = $original_transaction['gold_weight'];
                        $original_purity = $original_transaction['purity'];
                        $party_id = $original_transaction['party_id'];
                        
                        // Rollback original stock (add back)
                        if ($original_purity == $purity) {
                            $current_stock += $original_weight;
                        } else {
                            // Different purity, rollback to original purity stock
                            $rollback_sql = "UPDATE gold_stock SET current_stock = current_stock + ?, last_updated = NOW() WHERE company_id = ? AND purity = ?";
                            $rollback_stmt = $conn->prepare($rollback_sql);
                            $rollback_stmt->bind_param("did", $original_weight, $company_id, $original_purity);
                            $rollback_stmt->execute();
                        }
                    } else {
                        // Get party ID for new transaction
                        $party_sql = "SELECT id FROM parties WHERE company_id = ? AND party_name = ?";
                        $party_stmt = $conn->prepare($party_sql);
                        $party_stmt->bind_param("is", $company_id, $party_name);
                        $party_stmt->execute();
                        $party_result = $party_stmt->get_result();
                        
                        if ($party_result->num_rows === 0) {
                            throw new Exception("Party not found. Please select or create a party first.");
                        } else {
                            $party_id = $party_result->fetch_assoc()['id'];
                        }
                    }

                    // Check if sufficient stock available (after rollback if edit)
                    if ($current_stock < $weight) {
                        throw new Exception("Insufficient stock. Available: " . number_format($current_stock, 3) . "g, Required: " . number_format($weight, 3) . "g");
                    }

                    // Calculate new stock after deduction
                    $new_stock = $current_stock - $weight;

                    // Update stock
                    $stock_update = "UPDATE gold_stock SET current_stock = ?, last_updated = NOW() WHERE id = ?";
                    $stock_stmt = $conn->prepare($stock_update);
                    $stock_stmt->bind_param("di", $new_stock, $stock_id);
                    if (!$stock_stmt->execute()) {
                        throw new Exception("Failed to update stock");
                    }

                    // Save transaction
                    $type = 'Sale';
                    $payment_type = 'Payment_In'; // Sales always receive payment

                    if ($transaction_id) {
                        //Update existing
                        $sql = "UPDATE transactions SET 
                            receipt_id = ?, company_id = ?, user_id = ?, party_id = ?, date_of_transaction = ?, 
                            gold_weight = ?, purity = ?, fine_weight = ?, 
                            rate = ?, gold_amount = ?, payment_method = ?, 
                            payment_status = ?, narration = ?, payment_type = ?, 
                            transaction_type = ?, payment_amount = ? 
                            WHERE id = ?";
                        
                        $stmt = $conn->prepare($sql);
                        $user_id = $_SESSION['user_id'];
                        $stmt->bind_param(
                            "siiisdddddsssssdi",
                            $receipt_id, $company_id, $user_id, $party_id, $date_of_transaction, $weight,
                            $purity, $fine_weight, $rate, $amount, $payment_method, $payment_status, 
                            $narration, $payment_type, $type, $payment_amount, $transaction_id
                        );
                    } else {
                        // Insert new
                        $sql = "INSERT INTO transactions (
                            company_id, user_id, receipt_id, party_id, date_of_transaction, gold_weight,
                            purity, fine_weight, rate, gold_amount, payment_method, payment_status, narration,
                            payment_type, transaction_type, payment_amount
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        
                        $stmt = $conn->prepare($sql);
                        $user_id = $_SESSION['user_id'];
                        $stmt->bind_param(
                            "iisisdddddsssssd",
                            $company_id, $user_id, $receipt_id, $party_id, $date_of_transaction, $weight,
                            $purity, $fine_weight, $rate, $amount, $payment_method, $payment_status, $narration,
                            $payment_type, $type, $payment_amount
                        );
                    }

                    if (!$stmt->execute()) {
                        throw new Exception("Failed to save transaction: " . $stmt->error);
                    }

                    if (!$transaction_id) {
                        $transaction_id = $stmt->insert_id;
                    }

                    // Update Account Balance if payment received
                    if ($payment_amount > 0) {
                        if ($payment_method === 'Cash') {
                            updateAccountBalance($conn, $company_id, 'Cash', $payment_amount);
                        } elseif (in_array($payment_method, ['Bank', 'UPI', 'Cheque', 'NEFT', 'RTGS'])) {
                            updateAccountBalance($conn, $company_id, 'Bank', $payment_amount);
                        }
                    }

                    $conn->commit();
                    
                    // Get party name for receipt
                    $party_name_sql = "SELECT party_name FROM parties WHERE id = ?";
                    $party_stmt = $conn->prepare($party_name_sql);
                    $party_stmt->bind_param("i", $party_id);
                    $party_stmt->execute();
                    $party_name_result = $party_stmt->get_result()->fetch_assoc();
                    $party_name_for_receipt = $party_name_result['party_name'];
                    
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Sale saved successfully',
                        'transaction_id' => $transaction_id,
                        'data' => [
                            'receipt_id' => $receipt_id,
                            'party_name' => $party_name_for_receipt,
                            'date_of_transaction' => $date_of_transaction,
                            'weight' => $weight,
                            'purity' => $purity,
                            'fine_weight' => $fine_weight,
                            'rate' => $rate,
                            'amount' => $amount,
                            'payment_amount' => $payment_amount,
                            'payment_method' => $payment_method,
                            'payment_status' => $payment_status,
                            'payment_type' => $payment_type,
                            'narration' => $narration
                        ]
                    ]);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }
                exit;
                try {
                    $data = $_POST;
                    $receipt_id = $conn->real_escape_string($data['receipt_id']);
                    $party_name = $conn->real_escape_string($data['party_name']);
                    $date_of_transaction = $conn->real_escape_string($data['date_of_transaction']);
                    $received_weight = floatval($data['received_weight']);
                    $purity = floatval($data['purity']);
                    $fine_weight = floatval($data['fine_weight']);
                    $issue_weight = floatval($data['issue_weight']);
                    $difference_weight = $issue_weight - $fine_weight;
                    $rate = floatval($data['rate']);
                    $amount = floatval($data['amount']);
                    $payment_method = $conn->real_escape_string($data['payment_method']);
                    $payment_amount = floatval($data['payment_amount']);
                    $due_amount = $amount - $payment_amount;
                    $payment_status = $conn->real_escape_string($data['payment_status']);
                    $narration = $conn->real_escape_string($data['narration']);

                    $transaction_id = isset($data['transaction_id']) && !empty($data['transaction_id']) ? intval($data['transaction_id']) : null;

                    // Get current stock for 100% pure gold (fine gold)
                    $stock_query = "SELECT id, current_stock, stock_name FROM gold_stock WHERE company_id = $company_id AND purity = 100.00 ORDER BY id ASC LIMIT 1 FOR UPDATE";
                    $stock_result = $conn->query($stock_query);

                    if ($stock_result->num_rows === 0) {
                        throw new Exception("Stock record not found for 100% pure gold. Please add a gold stock entry with 100% purity.");
                    }

                    $stock_data = $stock_result->fetch_assoc();
                    $stock_id = $stock_data['id'];
                    $current_current_stock = $stock_data['current_stock'];

                    // If this is an edit, get the original transaction details
                    $original_fine_weight = 0;
                    if ($transaction_id) {
                        $original_sql = "SELECT fine_weight, party_id, received_weight FROM transactions WHERE id = ? FOR UPDATE";
                        $original_stmt = $conn->prepare($original_sql);
                        $original_stmt->bind_param("i", $transaction_id);
                        $original_stmt->execute();
                        $original_result = $original_stmt->get_result();
                        $original_transaction = $original_result->fetch_assoc();
                        
                        if (!$original_transaction) {
                            throw new Exception("Original transaction not found");
                        }
                        
                        $original_fine_weight = $original_transaction['fine_weight'];
                        $party_id = $original_transaction['party_id'];
                    } else {
                        // Get party ID for new transaction
                        $party_sql = "SELECT id FROM parties WHERE company_id = ? AND party_name = ?";
                        $party_stmt = $conn->prepare($party_sql);
                        $party_stmt->bind_param("is", $company_id, $party_name);
                        $party_stmt->execute();
                        $party_result = $party_stmt->get_result();

                        if ($party_result->num_rows === 0) {
                            // Party doesn't exist - auto-create it
                            $create_party_sql = "INSERT INTO parties (company_id, party_name, address, contact_no) VALUES (?, ?, '', '')";
                            $create_party_stmt = $conn->prepare($create_party_sql);
                            $create_party_stmt->bind_param("is", $company_id, $party_name);
                            
                            if (!$create_party_stmt->execute()) {
                                throw new Exception("Failed to create party: {$party_name}");
                            }
                            
                            $party_id = $create_party_stmt->insert_id;
                            $create_party_stmt->close();
                        } else {
                            $party_id = $party_result->fetch_assoc()['id'];
                        }
                    }

                    // Calculate stock adjustment
                    if ($transaction_id) {
                        $stock_adjustment = $original_fine_weight - $fine_weight;
                        $new_current_stock = $current_current_stock + $stock_adjustment;
                    } else {
                        $new_current_stock = $current_current_stock - $fine_weight;
                    }

                    // Check if there is enough stock
                    if ($new_current_stock < 0) {
                        throw new Exception("Insufficient stock. Available: {$current_current_stock}g, Required: {$fine_weight}g.");
                    }

                    // Update current_stock in the gold_stock table
                    $stock_update = "UPDATE gold_stock SET 
                        current_stock = ?, 
                        last_updated = NOW() 
                        WHERE id = ?";

                    $stock_stmt = $conn->prepare($stock_update);
                    $stock_stmt->bind_param("di", $new_current_stock, $stock_id);

                    if (!$stock_stmt->execute()) {
                        throw new Exception("Failed to update stock: " . $stock_stmt->error);
                    }

                    // --- RECEIVED GOLD STOCK LOGIC (MIX STOCK) ---
                    // 1. If Edit: Revert Old Received Stock (Subtract from MIX Stock)
                    if ($transaction_id) {
                        $old_rcv_wt = floatval($original_transaction['received_weight']);
                        $stock_name = "MIX Stock";
                        $mix_purity = 0.00;
                        
                        if ($old_rcv_wt > 0) {
                            // Find existing stock
                            $find_stock_sql = "SELECT id, current_stock FROM gold_stock WHERE company_id = ? AND stock_name = ? AND purity = ?";
                            $find_stock_stmt = $conn->prepare($find_stock_sql);
                            $find_stock_stmt->bind_param("isd", $company_id, $stock_name, $mix_purity);
                            $find_stock_stmt->execute();
                            $rs_res = $find_stock_stmt->get_result();
                            
                            if ($rs_res->num_rows > 0) {
                                $rs_row = $rs_res->fetch_assoc();
                                $new_rs_val = max(0, $rs_row['current_stock'] - $old_rcv_wt);
                                $upd_rs_sql = "UPDATE gold_stock SET current_stock = ?, last_updated = NOW() WHERE id = ?";
                                $upd_rs_stmt = $conn->prepare($upd_rs_sql);
                                $upd_rs_stmt->bind_param("di", $new_rs_val, $rs_row['id']);
                                $upd_rs_stmt->execute();
                            }
                        }
                    }

                    // 2. New/Edit: Add New Received Stock (Add to MIX Stock)
                    if ($received_weight > 0) {
                        $new_rcv_wt = floatval($received_weight);
                        $stock_name = "MIX Stock";
                        $mix_purity = 0.00;
                        
                        $find_stock_sql = "SELECT id, current_stock FROM gold_stock WHERE company_id = ? AND stock_name = ? AND purity = ?";
                        $find_stock_stmt = $conn->prepare($find_stock_sql);
                        $find_stock_stmt->bind_param("isd", $company_id, $stock_name, $mix_purity);
                        $find_stock_stmt->execute();
                        $rs_res = $find_stock_stmt->get_result();
                        
                        if ($rs_res->num_rows > 0) {
                            // Update
                            $rs_row = $rs_res->fetch_assoc();
                            $new_rs_val = $rs_row['current_stock'] + $new_rcv_wt;
                            $upd_rs_sql = "UPDATE gold_stock SET current_stock = ?, last_updated = NOW() WHERE id = ?";
                            $upd_rs_stmt = $conn->prepare($upd_rs_sql);
                            $upd_rs_stmt->bind_param("di", $new_rs_val, $rs_row['id']);
                            $upd_rs_stmt->execute();
                        } else {
                            // Insert
                            $ins_rs_sql = "INSERT INTO gold_stock (company_id, stock_name, purity, current_stock, last_updated) VALUES (?, ?, ?, ?, NOW())";
                            $ins_rs_stmt = $conn->prepare($ins_rs_sql);
                            $ins_rs_stmt->bind_param("isdd", $company_id, $stock_name, $mix_purity, $new_rcv_wt);
                            $ins_rs_stmt->execute();
                        }
                    }
                    // --- END RECEIVED GOLD STOCK LOGIC ---

                    // Save transaction
                    $type = 'Exchange';
                    $payment_type = $difference_weight > 0 ? 'Payment_In' : 'Payment_Out';

                    if ($transaction_id) {
                        // Update existing transaction
                        $sql = "UPDATE transactions SET 
                            receipt_id = ?, company_id = ?, user_id = ?, party_id = ?, date_of_transaction = ?, 
                            received_weight = ?, purity = ?, fine_weight = ?, delivered_weight = ?, 
                            difference_weight = ?, rate = ?, amount = ?, payment_method = ?, 
                            payment_status = ?, due_amount = ?, narration = ?, payment_type = ?, 
                            transaction_type = ?, payment_amount = ? 
                            WHERE id = ?";
                        
                        $stmt = $conn->prepare($sql);
                        $user_id = $_SESSION['user_id'];
                        $stmt->bind_param(
                            "siiisdddddddsdssssdi",
                            $receipt_id, $company_id, $user_id, $party_id, $date_of_transaction, $received_weight,
                            $purity, $fine_weight, $issue_weight, $difference_weight,
                            $rate, $amount, $payment_method, $payment_status, $due_amount, $narration,
                            $payment_type, $type, $payment_amount,
                            $transaction_id
                        );
                    } else {
                        // Insert new transaction
                        $sql = "INSERT INTO transactions (
                            company_id, user_id, receipt_id, party_id, date_of_transaction, received_weight,
                            purity, fine_weight, delivered_weight, difference_weight,
                            rate, amount, payment_method, payment_status, due_amount, narration,
                            payment_type, transaction_type, payment_amount, gold_weight, gold_amount
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        
                        $stmt = $conn->prepare($sql);
                        $user_id = $_SESSION['user_id'];
                        $gold_weight = $issue_weight; // delivered weight
                        $gold_amount = $amount;
                        $stmt->bind_param(
                            "iisisddddddsdsssssddd",
                            $company_id, $user_id, $receipt_id, $party_id, $date_of_transaction, $received_weight,
                            $purity, $fine_weight, $issue_weight, $difference_weight,
                            $rate, $amount, $payment_method, $payment_status, $due_amount, $narration,
                            $payment_type, $type, $payment_amount, $gold_weight, $gold_amount
                        );
                    }

                    if (!$stmt->execute()) {
                        throw new Exception("Failed to save transaction: " . $stmt->error);
                    }

                    if (!$transaction_id) {
                        $transaction_id = $stmt->insert_id;
                    }

                    // Payment info is stored directly in the transactions table
                    // AND we create a separate transaction for Cash/Bank stats visibility

                    // 1. Delete any existing linked payment transactions for this receipt (for updates)
                    // First, revert balance changes for these transactions
                    $get_linked_sql = "SELECT payment_amount, payment_method, transaction_type FROM transactions WHERE company_id = ? AND narration LIKE ? AND (transaction_type = 'Payment' OR transaction_type = 'Received')";
                    $linked_pattern = "%Payment for Exchange " . $receipt_id . "%";
                    $get_linked_stmt = $conn->prepare($get_linked_sql);
                    $get_linked_stmt->bind_param("is", $company_id, $linked_pattern);
                    $get_linked_stmt->execute();
                    $linked_result = $get_linked_stmt->get_result();
                    while ($old_linked = $linked_result->fetch_assoc()) {
                        $old_amt = floatval($old_linked['payment_amount']);
                        $old_method = $old_linked['payment_method'];
                        $old_type = $old_linked['transaction_type'];
                        
                        // Reversal Logic:
                        // If it was Received (we got money), we remove it (Subtract).
                        // If it was Payment (we paid money), we add it back.
                        $reversal_amt = ($old_type === 'Received') ? -$old_amt : $old_amt;
                        
                        // Only Cash/Bank affect the balance table (UPI/Cheque -> Bank)
                        if ($old_method === 'Cash') {
                           updateAccountBalance($conn, $company_id, 'Cash', $reversal_amt);
                        } elseif (in_array($old_method, ['Bank', 'UPI', 'Cheque', 'NEFT', 'RTGS'])) {
                           updateAccountBalance($conn, $company_id, 'Bank', $reversal_amt);
                        }
                    }

                    $delete_linked_sql = "DELETE FROM transactions WHERE company_id = ? AND narration LIKE ? AND (transaction_type = 'Payment' OR transaction_type = 'Received')";
                    // $linked_pattern is already set
                    $delete_linked_stmt = $conn->prepare($delete_linked_sql);
                    $delete_linked_stmt->bind_param("is", $company_id, $linked_pattern);
                    $delete_linked_stmt->execute();

                    // 2. Insert new linked payment transaction if amount > 0
                    if ($payment_amount > 0) {
                        $linked_type = $payment_type === 'Payment_In' ? 'Received' : 'Payment';
                        $linked_receipt_id = 'PAY-' . $receipt_id . '-' . rand(1000, 9999);
                        $linked_narration = "Payment for Exchange " . $receipt_id;
                        
                        $linked_sql = "INSERT INTO transactions (
                            company_id, user_id, receipt_id, party_id, date_of_transaction,
                            transaction_type, payment_type, payment_method, payment_amount,
                            narration, payment_status, due_amount, amount
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Paid', 0, ?)";
                        
                        $linked_stmt = $conn->prepare($linked_sql);
                        $linked_stmt->bind_param(
                            "iisissssdsd",
                            $company_id, $user_id, $linked_receipt_id, $party_id, $date_of_transaction,
                            $linked_type, $payment_type, $payment_method, $payment_amount,
                            $linked_narration, $payment_amount
                        );
                        
                        if (!$linked_stmt->execute()) {
                            throw new Exception("Failed to save linked payment transaction: " . $linked_stmt->error);
                        }
                        
                        // Update Account Balance for the new transaction
                        // Linked Type: 'Received' (In) or 'Payment' (Out)
                        $balance_amt = ($linked_type === 'Received') ? $payment_amount : -$payment_amount;
                        
                        if ($payment_method === 'Cash') {
                            updateAccountBalance($conn, $company_id, 'Cash', $balance_amt);
                        } elseif (in_array($payment_method, ['Bank', 'UPI', 'Cheque', 'NEFT', 'RTGS'])) {
                            updateAccountBalance($conn, $company_id, 'Bank', $balance_amt);
                        }
                    }

                    // Stock logging removed - gold_stock_log table doesn't exist
                    // Stock changes are tracked in the gold_stock table itself

                    $conn->commit();
                    
                    // Get party name for receipt
                    $party_name_sql = "SELECT party_name FROM parties WHERE id = ?";
                    $party_stmt = $conn->prepare($party_name_sql);
                    $party_stmt->bind_param("i", $party_id);
                    $party_stmt->execute();
                    $party_name_result = $party_stmt->get_result()->fetch_assoc();
                    $party_name_for_receipt = $party_name_result['party_name'];
                    
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Transaction saved successfully',
                        'transaction_id' => $transaction_id,
                        'data' => [
                            'receipt_id' => $receipt_id,
                            'party_name' => $party_name_for_receipt,
                            'date_of_transaction' => $date_of_transaction,
                            'received_weight' => $received_weight,
                            'purity' => $purity,
                            'fine_weight' => $fine_weight,
                            'issue_weight' => $issue_weight,
                            'difference_weight' => $difference_weight,
                            'rate' => $rate,
                            'amount' => $amount,
                            'payment_amount' => $payment_amount,
                            'payment_method' => $payment_method,
                            'payment_status' => $payment_status,
                            'payment_type' => $payment_type,
                            'narration' => $narration
                        ]
                    ]);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }
                exit;
                
            case 'get_transaction_details':
                $id = intval($_POST['id']);
                $sql = "SELECT * FROM transactions WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                $transaction = $result->fetch_assoc();
                echo json_encode($transaction);
                exit;

            case 'delete_transaction':
                $id = intval($_POST['id']);
                
                $conn->begin_transaction();
                
                try {
                    // Get transaction details first
                    $sql = "SELECT * FROM transactions WHERE id = ? AND transaction_type = 'Sale' FOR UPDATE";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $transaction = $result->fetch_assoc();
                    
                    if (!$transaction) {
                        throw new Exception("Sale transaction not found");
                    }

                    $weight = $transaction['gold_weight'];
                    $purity = $transaction['purity'];

                    // Add back the sold weight to stock (reverse the sale)
                    $stock_query = "SELECT id, current_stock FROM gold_stock WHERE company_id = $company_id AND purity = $purity FOR UPDATE";
                    $stock_result = $conn->query($stock_query);
                    
                    if ($stock_result->num_rows > 0) {
                        $stock_data = $stock_result->fetch_assoc();
                        $stock_id = $stock_data['id'];
                        $current_stock = $stock_data['current_stock'];
                        $new_stock = $current_stock + $weight; // Add back
                        
                        $stock_update = "UPDATE gold_stock SET current_stock = ?, last_updated = NOW() WHERE id = ?";
                        $stock_stmt = $conn->prepare($stock_update);
                        $stock_stmt->bind_param("di", $new_stock, $stock_id);
                        if (!$stock_stmt->execute()) {
                            throw new Exception("Failed to update stock");
                        }
                    }

                    // Revert account balance if payment was made
                    if ($transaction['payment_amount'] > 0) {
                        $reversal_amount = -$transaction['payment_amount'];
                        if ($transaction['payment_method'] === 'Cash') {
                           updateAccountBalance($conn, $company_id, 'Cash', $reversal_amount);
                        } elseif (in_array($transaction['payment_method'], ['Bank', 'UPI', 'Cheque', 'NEFT', 'RTGS'])) {
                           updateAccountBalance($conn, $company_id, 'Bank', $reversal_amount);
                        }
                    }

                    // Delete the transaction
                    $delete_sql = "DELETE FROM transactions WHERE id = ?";
                    $delete_stmt = $conn->prepare($delete_sql);
                    $delete_stmt->bind_param("i", $id);
                    
                    if (!$delete_stmt->execute()) {
                        throw new Exception("Failed to delete transaction");
                    }
                    $delete_stmt->close();

                    $conn->commit();
                    
                    echo json_encode(['status' => 'success', 'message' => 'Sale deleted successfully']);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }
                exit;
                
                $conn->begin_transaction();
                
                try {
                    // Get transaction details first
                    $sql = "SELECT * FROM transactions WHERE id = ? FOR UPDATE";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $transaction = $result->fetch_assoc();
                    
                    if (!$transaction) {
                        throw new Exception("Transaction not found");
                    }

                    // Delete linked payment transactions from transactions table
                    // Delete linked payment transactions from transactions table
                    // First, revert account balances
                    $get_linked_sql = "SELECT payment_amount, payment_method, transaction_type FROM transactions WHERE company_id = ? AND narration LIKE ? AND (transaction_type = 'Payment' OR transaction_type = 'Received')";
                    $linked_pattern = "%Payment for Exchange " . $transaction['receipt_id'] . "%";
                    $get_linked_stmt = $conn->prepare($get_linked_sql);
                    $get_linked_stmt->bind_param("is", $company_id, $linked_pattern);
                    $get_linked_stmt->execute();
                    $linked_result = $get_linked_stmt->get_result();
                    while ($old_linked = $linked_result->fetch_assoc()) {
                        $old_amt = floatval($old_linked['payment_amount']);
                        $reversal_amt = ($old_linked['transaction_type'] === 'Received') ? -$old_amt : $old_amt;
                        
                        if ($old_linked['payment_method'] === 'Cash') {
                           updateAccountBalance($conn, $company_id, 'Cash', $reversal_amt);
                        } elseif (in_array($old_linked['payment_method'], ['Bank', 'UPI', 'Cheque', 'NEFT', 'RTGS'])) {
                           updateAccountBalance($conn, $company_id, 'Bank', $reversal_amt);
                        }
                    }

                    $delete_linked_sql = "DELETE FROM transactions WHERE company_id = ? AND narration LIKE ? AND (transaction_type = 'Payment' OR transaction_type = 'Received')";
                    $linked_stmt = $conn->prepare($delete_linked_sql);
                    $linked_stmt->bind_param("is", $company_id, $linked_pattern);
                    $linked_stmt->execute();

                    // --- REVERT RECEIVED GOLD STOCK LOGIC (MIX STOCK) ---
                    if ($transaction['received_weight'] > 0) {
                        $del_rcv_wt = floatval($transaction['received_weight']);
                        $stock_name = "MIX Stock";
                        $mix_purity = 0.00;
                        
                        $find_stock_sql = "SELECT id, current_stock FROM gold_stock WHERE company_id = ? AND stock_name = ? AND purity = ?";
                        $find_stock_stmt = $conn->prepare($find_stock_sql);
                        $find_stock_stmt->bind_param("isd", $company_id, $stock_name, $mix_purity);
                        $find_stock_stmt->execute();
                        $rs_res = $find_stock_stmt->get_result();
                        
                        if ($rs_res->num_rows > 0) {
                            $rs_row = $rs_res->fetch_assoc();
                            $new_rs_val = max(0, $rs_row['current_stock'] - $del_rcv_wt);
                            $upd_rs_sql = "UPDATE gold_stock SET current_stock = ?, last_updated = NOW() WHERE id = ?";
                            $upd_rs_stmt = $conn->prepare($upd_rs_sql);
                            $upd_rs_stmt->bind_param("di", $new_rs_val, $rs_row['id']);
                            $upd_rs_stmt->execute();
                        }
                    }
                    // --- END REVERT RECEIVED GOLD STOCK LOGIC ---

                    // Add back the fine weight to stock
                    $stock_query = "SELECT id, current_stock FROM gold_stock WHERE company_id = $company_id AND (stock_name = 'Fine Gold' OR stock_name = 'fine gold') AND purity = 100.00 FOR UPDATE";
                    $stock_result = $conn->query($stock_query);
                    if ($stock_result->num_rows === 0) {
                        throw new Exception("Stock record not found");
                    }
                    $stock_data = $stock_result->fetch_assoc();
                    $stock_id = $stock_data['id'];
                    $current_current_stock = $stock_data['current_stock'];
                    $new_current_stock = $current_current_stock + $transaction['fine_weight'];
                    
                    $stock_update = "UPDATE gold_stock SET current_stock = ?, last_updated = NOW() WHERE id = ?";
                    $stock_stmt = $conn->prepare($stock_update);
                    $stock_stmt->bind_param("di", $new_current_stock, $stock_id);
                    if (!$stock_stmt->execute()) {
                        throw new Exception("Failed to update stock");
                    }

                    // Finally delete the transaction
                    $delete_sql = "DELETE FROM transactions WHERE id = ?";
                    $delete_stmt = $conn->prepare($delete_sql);
                    $delete_stmt->bind_param("i", $id);
                    
                    if (!$delete_stmt->execute()) {
                        throw new Exception("Failed to delete transaction");
                    }
                    $delete_stmt->close();

                    $conn->commit();
                    
                    echo json_encode(['status' => 'success', 'message' => 'Transaction deleted successfully']);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }
                exit;

            case 'get_party_dues':
                $party_name = $conn->real_escape_string($_POST['party_name']);
                
                // Get party by name
                $party_sql = "SELECT id, current_balance, current_gold_balance FROM parties WHERE company_id = ? AND party_name = ?";
                $stmt = $conn->prepare($party_sql);
                $stmt->bind_param('is', $company_id, $party_name);
                $stmt->execute();
                $party_result = $stmt->get_result()->fetch_assoc();
                
                echo json_encode([
                    'due_amount' => floatval($party_result['current_balance'] ?? 0),
                    'due_gold' => floatval($party_result['current_gold_balance'] ?? 0)
                ]);
                exit;
                
            case 'search_receipt_ids':
                $search = $conn->real_escape_string($_POST['term'] ?? '');
                
                // If search is empty, show all recent receipts
                if (empty($search)) {
                    $sql = "SELECT DISTINCT receipt_id, date_of_transaction, party_id
                            FROM transactions 
                            WHERE company_id = ? AND transaction_type = 'Exchange'
                            ORDER BY id DESC LIMIT 10";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param('i', $company_id);
                } else {
                    $sql = "SELECT DISTINCT receipt_id, date_of_transaction, party_id
                            FROM transactions 
                            WHERE company_id = ? AND transaction_type = 'Exchange' 
                            AND receipt_id LIKE ?
                            ORDER BY id DESC LIMIT 10";
                    $stmt = $conn->prepare($sql);
                    $searchTerm = $search . '%';
                    $stmt->bind_param('is', $company_id, $searchTerm);
                }
                
                $stmt->execute();
                $result = $stmt->get_result();
                
                $receipts = [];
                while ($row = $result->fetch_assoc()) {
                    // Get party name
                    $party_sql = "SELECT party_name FROM parties WHERE id = ?";
                    $party_stmt = $conn->prepare($party_sql);
                    $party_stmt->bind_param('i', $row['party_id']);
                    $party_stmt->execute();
                    $party_result = $party_stmt->get_result();
                    $party_name = $party_result->num_rows > 0 ? $party_result->fetch_assoc()['party_name'] : 'Unknown';
                    
                    $receipts[] = [
                        'receipt_id' => $row['receipt_id'],
                        'date' => date('d M Y', strtotime($row['date_of_transaction'])),
                        'party_name' => $party_name
                    ];
                }
                echo json_encode($receipts);
                exit;
                
            case 'get_next_receipt_id':
                // Find the highest Sale receipt ID (S + CompanyID + Number)
                // Filter specifically for 'Sale' transactions to ensure we don't mix with Exchange IDs
                $sql = "SELECT receipt_id FROM transactions 
                        WHERE company_id = $company_id 
                        AND transaction_type = 'Sale' 
                        AND receipt_id LIKE 'S$company_id%'
                        ORDER BY CAST(SUBSTRING(receipt_id, LENGTH('$company_id') + 2) AS UNSIGNED) DESC 
                        LIMIT 1";
                
                $result = $conn->query($sql);
                
                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $last_id = $row['receipt_id'];
                    // Extract the numerical part
                    // Format: S{company_id}{number} -> e.g. S1001 for company 1
                    // Length of prefix 'S' + company_id
                    $prefix_len = strlen($company_id) + 1;
                    $number = intval(substr($last_id, $prefix_len));
                    $next_number = $number + 1;
                } else {
                    $next_number = 1; // Start at 1 if no sales exist
                }
                
                // Pad with zeros to 3 digits (e.g. 001, 002)
                $next_id = 'S' . $company_id . str_pad($next_number, 3, '0', STR_PAD_LEFT);
                
                echo json_encode(['receipt_id' => $next_id]);
                exit;
        }
    }
}

// Get date range from user input (default: all transactions)
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '2020-01-01';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Enhanced statistics SQL query with date range filter - with safe fallbacks
$stats_sql = "
SELECT 
    COALESCE(SUM(received_weight), 0) AS total_weight,
    COALESCE(SUM(CASE WHEN transaction_type = 'Exchange' THEN received_weight ELSE 0 END), 0) AS total_received_weight,
    COALESCE(SUM(CASE WHEN transaction_type = 'Exchange' THEN delivered_weight ELSE 0 END), 0) AS total_issue_gold,
    COALESCE(SUM(CASE WHEN transaction_type = 'Exchange' THEN fine_weight ELSE 0 END), 0) AS total_fine_gold,
    COALESCE(SUM(amount), 0) AS total_amount,
    COUNT(DISTINCT party_id) AS total_parties,
    COUNT(*) AS total_transactions,
    COALESCE(SUM(CASE WHEN payment_type = 'Payment_In' THEN payment_amount ELSE 0 END), 0) AS total_paid_amount,
    COALESCE(SUM(CASE WHEN payment_type = 'Payment_Out' THEN payment_amount ELSE 0 END), 0) AS total_payment_amount,
    COALESCE(SUM(CASE WHEN payment_status IN ('Due', 'Partial') THEN due_amount ELSE 0 END), 0) AS total_due
FROM transactions
WHERE company_id = $company_id AND DATE(date_of_transaction) BETWEEN ? AND ? AND transaction_type = 'Sale'";

$stats_stmt = $conn->prepare($stats_sql);
if (!$stats_stmt) {
    die("SQL Error in stats query: " . $conn->error . "<br><br>Query: " . $stats_sql);
}
$stats_stmt->bind_param("ss", $start_date, $end_date);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();

// Get current stock separately to avoid subquery issues
$stock_sql = "SELECT COALESCE(current_stock, 0) as current_stock FROM gold_stock WHERE company_id = ? AND purity = 100.00 ORDER BY id ASC LIMIT 1";
$stock_stmt = $conn->prepare($stock_sql);
if ($stock_stmt) {
    $stock_stmt->bind_param("i", $company_id);
    $stock_stmt->execute();
    $stock_result = $stock_stmt->get_result();
    if ($stock_row = $stock_result->fetch_assoc()) {
        $stats['current_stock'] = $stock_row['current_stock'];
    } else {
        $stats['current_stock'] = 0;
    }
    $stock_stmt->close();
} else {
    $stats['current_stock'] = 0;
}

// Get cash balance (Cash In Hand) from account_balances
$cash_sql = "SELECT current_balance as cash_balance FROM account_balances WHERE company_id = ? AND account_type = 'Cash'";
$cash_stmt = $conn->prepare($cash_sql);
if ($cash_stmt) {
    $cash_stmt->bind_param("i", $company_id);
    $cash_stmt->execute();
    $cash_result = $cash_stmt->get_result();
    if ($cash_row = $cash_result->fetch_assoc()) {
        $stats['cash_balance'] = $cash_row['cash_balance'];
    } else {
        $stats['cash_balance'] = 0;
    }
    $cash_stmt->close();
} else {
    $stats['cash_balance'] = 0;
}

// Get pagination parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Get search parameter
$search = isset($_GET['search']) ? "%" . $conn->real_escape_string($_GET['search']) . "%" : null;

// Transactions query with search and date filter
$transactions_sql = "SELECT t.*, p.party_name 
    FROM transactions t 
    LEFT JOIN parties p ON t.party_id = p.id 
    WHERE t.company_id = $company_id AND DATE(t.date_of_transaction) BETWEEN ? AND ? AND t.transaction_type = 'Sale'";
if ($search) {
    $transactions_sql .= " AND (p.party_name LIKE ? OR t.receipt_id LIKE ?)";
}
$transactions_sql .= " ORDER BY t.date_of_transaction DESC, t.id DESC LIMIT ?, ?";

$transactions_stmt = $conn->prepare($transactions_sql);
if (!$transactions_stmt) {
    die("SQL Error in transactions query: " . $conn->error . "<br><br>Query: " . $transactions_sql);
}
if ($search) {
    $transactions_stmt->bind_param("ssssii", $start_date, $end_date, $search, $search, $offset, $limit);
} else {
    $transactions_stmt->bind_param("ssii", $start_date, $end_date, $offset, $limit);
}
$transactions_stmt->execute();
$transactions_result = $transactions_stmt->get_result();
$transactions = $transactions_result->fetch_all(MYSQLI_ASSOC);

// Count total transactions for pagination
$total_sql = "SELECT COUNT(*) as count FROM transactions t 
    LEFT JOIN parties p ON t.party_id = p.id 
    WHERE t.company_id = $company_id AND DATE(t.date_of_transaction) BETWEEN ? AND ? AND t.transaction_type = 'Sale'";
if ($search) {
    $total_sql .= " AND (p.party_name LIKE ? OR t.receipt_id LIKE ?)";
}
$total_stmt = $conn->prepare($total_sql);
if (!$total_stmt) {
    die("SQL Error in total count query: " . $conn->error . "<br><br>Query: " . $total_sql);
}
if ($search) {
    $total_stmt->bind_param("ssss", $start_date, $end_date, $search, $search);
} else {
    $total_stmt->bind_param("ss", $start_date, $end_date);
}
$total_stmt->execute();
$total_result = $total_stmt->get_result();
$total_transactions = $total_result->fetch_assoc()['count'];
$total_pages = ceil($total_transactions / $limit);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sale - Mormukut</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
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
        }

        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background-color: #F8F9FA;
            color: var(--secondary);
        }

        .soft-gradient-blue { background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.05)); }
        .soft-gradient-green { background: linear-gradient(135deg, rgba(34, 197, 94, 0.1), rgba(34, 197, 94, 0.05)); }
        .soft-gradient-orange { background: linear-gradient(135deg, rgba(249, 115, 22, 0.1), rgba(249, 115, 22, 0.05)); }
        .soft-gradient-purple { background: linear-gradient(135deg, rgba(168, 85, 247, 0.1), rgba(168, 85, 247, 0.05)); }
        .soft-gradient-teal { background: linear-gradient(135deg, rgba(20, 184, 166, 0.1), rgba(20, 184, 166, 0.05)); }
        .soft-gradient-yellow { background: linear-gradient(135deg, rgba(234, 179, 8, 0.1), rgba(234, 179, 8, 0.05)); }
        
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
    </style>
</head>
<body class="bg-gray-100">
    <?php include 'components/sidebar.php'; ?>
    <?php include 'components/header.php'; ?>
    
    <div class="p-6 ml-16">
        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-7 gap-4 mb-6">
            <div class="soft-gradient-blue rounded-xl p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-blue-700 mb-1">Received Weight</p>
                        <p class="text-lg font-bold text-blue-800"><?= number_format($stats['total_received_weight'] ?? 0, 2) ?>g</p>
                    </div>
                    <div class="w-10 h-10 bg-blue-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-weight text-white text-sm"></i>
                    </div>
                </div>
            </div>

            <div class="soft-gradient-green rounded-xl p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-green-700 mb-1">Fine Weight</p>
                        <p class="text-lg font-bold text-green-800"><?= number_format($stats['total_fine_gold'] ?? 0, 2) ?>g</p>
                    </div>
                    <div class="w-10 h-10 bg-green-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-coins text-white text-sm"></i>
                    </div>
                </div>
            </div>

            <div class="soft-gradient-orange rounded-xl p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-orange-700 mb-1">Issue Weight</p>
                        <p class="text-lg font-bold text-orange-800"><?= number_format($stats['total_issue_gold'] ?? 0, 2) ?>g</p>
                    </div>
                    <div class="w-10 h-10 bg-orange-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-box text-white text-sm"></i>
                    </div>
                </div>
            </div>

            <div class="soft-gradient-purple rounded-xl p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-purple-700 mb-1">Current Stock (100%)</p>
                        <p class="text-lg font-bold text-purple-800"><?= number_format($stats['current_stock'] ?? 0, 2) ?>g</p>
                    </div>
                    <div class="w-10 h-10 bg-purple-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-box-open text-white text-sm"></i>
                    </div>
                </div>
            </div>

            <div class="soft-gradient-teal rounded-xl p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-teal-700 mb-1">Cash In Hand</p>
                        <p class="text-lg font-bold text-teal-800">₹<?= number_format($stats['cash_balance'] ?? 0, 0) ?></p>
                    </div>
                    <div class="w-10 h-10 bg-teal-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-wallet text-white text-sm"></i>
                    </div>
                </div>
            </div>

            <div class="soft-gradient-teal rounded-xl p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-teal-700 mb-1">Amount Received</p>
                        <p class="text-lg font-bold text-teal-800">₹<?= number_format($stats['total_paid_amount'] ?? 0, 0) ?></p>
                    </div>
                    <div class="w-10 h-10 bg-teal-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-arrow-up text-white text-sm"></i>
                    </div>
                </div>
            </div>

            <div class="soft-gradient-yellow rounded-xl p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-yellow-700 mb-1">Amount Paid</p>
                        <p class="text-lg font-bold text-yellow-800">₹<?= number_format($stats['total_payment_amount'] ?? 0, 0) ?></p>
                    </div>
                    <div class="w-10 h-10 bg-yellow-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-arrow-down text-white text-sm"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="flex flex-col lg:flex-row gap-6">
            <!-- Left Side - Form -->
            <div class="bg-white rounded-lg shadow-md border border-gray-200" style="flex: 0 0 55%;">
                <!-- Transaction Form -->
                <form id="exchangeForm" onsubmit="return false;" class="bg-white rounded-lg shadow-md border border-gray-200 overflow-hidden">
                    <input type="hidden" name="action" value="save_transaction">
                    <input type="hidden" name="transaction_id" value="">
                    
                    <!-- Section 1: Transaction Details -->
                    <div class="bg-blue-50 px-4 py-2 border-b border-blue-100">
                        <h3 class="text-sm font-bold text-blue-800 flex items-center">
                            <i class="fas fa-file-invoice mr-2"></i> Transaction Details
                        </h3>
                    </div>
                    <div class="p-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                        <!-- Receipt ID -->
                        <div class="relative">
                            <label class="block text-sm font-bold text-gray-700 mb-1">Sale ID</label>
                        <div class="relative">
                            <i class="fas fa-hashtag absolute left-3 top-3 text-gray-400"></i>
                            <input type="text" name="receipt_id" id="receiptId" 
                                class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 font-bold text-gray-800"
                                placeholder="Search Sale ID..." autocomplete="off">
                            <div id="receiptSuggestions" class="absolute z-50 w-full bg-white border border-gray-200 rounded-lg shadow-lg mt-1 hidden max-h-48 overflow-y-auto"></div>
                        </div>        </div>

                        <!-- Date -->
                        <div class="relative">
                            <label class="block text-xs font-bold text-gray-700 mb-1">Date</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                                    <i class="fas fa-calendar-alt text-sm"></i>
                                </span>
                                <input type="datetime-local" class="block w-full pl-10 pr-3 py-2.5 text-sm font-medium border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 shadow-sm" name="date_of_transaction" required>
                            </div>
                        </div>

                        <!-- Party Name -->
                        <div class="relative">
                            <label class="block text-xs font-bold text-gray-700 mb-1">Party Name</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                                    <i class="fas fa-user text-sm"></i>
                                </span>
                                <input type="text" class="block w-full pl-10 pr-3 py-2.5 text-sm font-medium border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 shadow-sm" name="party_name" id="partyNameInput" required placeholder="Select Party" autocomplete="off">
                                <input type="hidden" name="party_id" id="partyId">
                            </div>
                            <div id="partyList" class="hidden absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-48 overflow-y-auto"></div>
                        </div>
                    </div>

                    <!-- Section 2: Weight & Exchange -->
                    <div class="bg-yellow-50 px-4 py-2 border-t border-b border-yellow-100">
                        <h3 class="text-sm font-bold text-yellow-800 flex items-center">
                            <i class="fas fa-balance-scale mr-2"></i> Weight Information
                        </h3>
                    </div>
                    <div class="p-4 grid grid-cols-2 gap-3">
                        <!-- Weight -->
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Weight (g)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-weight text-green-500 text-sm"></i>
                                </span>
                                <input type="number" step="0.001" class="block w-full pl-10 pr-3 py-2.5 text-sm font-bold text-gray-900 border border-gray-300 rounded-md focus:ring-yellow-500 focus:border-yellow-500 shadow-sm bg-green-100" name="weight" id="weight" required placeholder="0.000">
                            </div>
                        </div>

                        <!-- Purity (Input with Autocomplete) -->
                        <div class="relative">
                            <label class="block text-xs font-bold text-gray-700 mb-1">Purity (%)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-certificate text-yellow-500 text-sm"></i>
                                </span>
                                <input type="text" class="block w-full pl-10 pr-3 py-2.5 text-sm font-bold border border-gray-300 rounded-md focus:ring-yellow-500 focus:border-yellow-500 shadow-sm" name="purity" id="purity" placeholder="Enter purity %" required autocomplete="off">
                            </div>
                            <!-- Purity suggestions dropdown -->
                            <div id="puritySuggestions" class="hidden absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-xl max-h-60 overflow-y-auto"></div>
                        </div>
                    </div>

                    <!-- Section 3: Payment Details -->
                    <div class="bg-green-50 px-4 py-2 border-t border-b border-green-100">
                        <h3 class="text-sm font-bold text-green-800 flex items-center">
                            <i class="fas fa-money-bill-wave mr-2"></i> Payment Details
                        </h3>
                    </div>
                    <div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-4">
                        <!-- Rate -->
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Rate (₹/g)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                                    <i class="fas fa-rupee-sign text-sm"></i>
                                </span>
                                <input type="number" step="0.01" class="block w-full pl-10 pr-3 py-2.5 text-sm font-bold border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500 shadow-sm" name="rate" id="rate" required placeholder="0.00">
                            </div>
                        </div>

                        <!-- Amount -->
                        <div>
                            <label class="block text-xs font-bold text-green-700 mb-1">Amount (₹)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-coins text-green-600 text-sm"></i>
                                </span>
                                <input type="text" class="block w-full pl-10 pr-3 py-2.5 text-sm font-bold text-green-700 bg-green-100 border border-green-200 rounded-md shadow-sm" name="amount" id="amount" readonly>
                            </div>
                        </div>

                        <!-- Paid Amount -->
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1" id="paymentAmountLabel"><strong>Paid Amount (₹)</strong></label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                                    <i class="fas fa-wallet text-sm"></i>
                                </span>
                                <input type="number" step="0.01" class="block w-full pl-10 pr-3 py-2.5 text-sm font-medium border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500 shadow-sm" name="payment_amount" id="paymentAmount" value="0">
                            </div>
                            <div id="amountType" class="text-xs mt-1 font-semibold text-gray-500 h-4"></div>
                        </div>

                        <!-- Payment Method -->
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Pay Mode</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                                    <i class="fas fa-credit-card text-sm"></i>
                                </span>
                                <select class="block w-full pl-10 pr-3 py-2.5 text-sm font-medium border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500 shadow-sm" name="payment_method">
                                    <option value="Cash">Cash</option>
                                    <option value="Bank Transfer">Bank</option>
                                    <option value="Cheque">Cheque</option>
                                    <option value="UPI">UPI</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Footer: Narration & Buttons -->
                    <div class="bg-gray-50 p-4 border-t border-gray-200 grid grid-cols-1 md:grid-cols-4 gap-4 items-center">
                        <div class="md:col-span-2 relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                                <i class="fas fa-comment-alt text-sm"></i>
                            </span>
                            <input type="text" class="block w-full pl-10 pr-3 py-2.5 text-sm border border-gray-300 rounded-md focus:ring-gray-500 focus:border-gray-500 shadow-sm" name="narration" placeholder="Add narration or remarks...">
                        </div>
                        <div class="md:col-span-2 flex space-x-2">
                            <button type="submit" id="submitBtn" class="flex-1 bg-gradient-to-r from-blue-600 to-blue-700 text-white text-sm font-bold py-2.5 px-4 rounded-md shadow hover:from-blue-700 hover:to-blue-800 transition transform hover:scale-[1.02]">
                                <i class="fas fa-save mr-2" id="submitIcon"></i><span id="submitText">Save</span>
                            </button>
                            <button type="button" id="deleteBtn" class="hidden px-4 py-2.5 bg-gradient-to-r from-red-600 to-red-700 text-white text-sm font-bold rounded-md hover:from-red-700 hover:to-red-800 shadow-sm" title="Delete Transaction">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                            <button type="button" id="resetFormBtn" class="px-4 py-2.5 bg-white border border-gray-300 text-gray-700 text-sm font-bold rounded-md hover:bg-gray-50 shadow-sm" title="Reset Form">
                                <i class="fas fa-undo"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Hidden fields to maintain structure logic -->
                    <input type="hidden" name="payment_status" value="Due">
                    <input type="hidden" name="payment_type" id="paymentType" value="Payment_In">
                </form>
            </div>

            <!-- Right Side - Transactions List -->
            <div class="bg-white rounded-lg shadow-md border border-gray-200" style="flex: 0 0 45%;">
                <div class="bg-gradient-to-r from-yellow-500 to-yellow-600 px-4 py-3 rounded-t-lg">
                    <h2 class="text-base font-semibold text-white flex items-center">
                        <i class="fas fa-list mr-2"></i>
                        Recent Transactions
                    </h2>
                </div>
                <div class="p-4">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left text-gray-500">
                            <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                                <tr>
                                    <th class="text-left py-3 px-3 font-bold">ID & Date</th>
                                    <th class="text-left py-3 px-3 font-bold">Party</th>
                                    <th class="text-left py-3 px-3 font-bold">Weight & Type</th>
                                    <th class="text-left py-3 px-3 font-bold">Amount</th>
                                    <th class="text-left py-3 px-3 font-bold">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100" id="recentTransactionList">
                                <?php if (count($transactions) > 0): 
                                foreach ($transactions as $index => $t): 
                                    $serial = $index + 1;
                                    $isPaymentIn = $t['payment_type'] === 'Payment_In';
                                    
                                    // Payment Column Logic (Shows ACTUAL Paid Amount)
                                    $paidAmount = $t['payment_amount'];
                                    if ($paidAmount > 0) {
                                        if ($isPaymentIn) {
                                            $payDisplay = '<span class="text-green-600 font-bold">₹' . number_format($paidAmount, 0) . '</span>';
                                        } else {
                                            $payDisplay = '<span class="text-red-500 font-bold">- ₹' . number_format($paidAmount, 0) . '</span>';
                                        }
                                    } else {
                                        $payDisplay = '<span class="text-gray-400 font-medium">-</span>';
                                    }
                                    
                                    // Determine gold type based on purity
                                    $goldType = 'Gold';
                                    $purityVal = floatval($t['purity']);
                                    if ($purityVal >= 99) {
                                        $goldType = '24K Gold';
                                    } elseif ($purityVal >= 91 && $purityVal < 92) {
                                        $goldType = '22K Gold';
                                    } elseif ($purityVal >= 75 && $purityVal < 76) {
                                        $goldType = '18K Gold';
                                    } elseif ($purityVal > 0) {
                                        $goldType = 'Gold';
                                    } else {
                                        $goldType = 'Mix';
                                    }
                                ?>
                                    <tr class="hover:bg-gray-50 transition border-b border-gray-100">
                                        <!-- Col 1: ID & Date -->
                                        <td class="py-3 px-3 align-top">
                                            <div class="flex items-start gap-2">
                                                <div class="w-2 h-2 rounded-full bg-green-500 mt-1 flex-shrink-0"></div>
                                                <div>
                                                    <div class="font-bold text-gray-900 text-sm"><?= htmlspecialchars($t['receipt_id']) ?></div>
                                                    <div class="text-xs text-gray-500"><?= date('d M Y', strtotime($t['date_of_transaction'])) ?></div>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Col 2: Party -->
                                        <td class="py-3 px-3 align-top">
                                            <div class="font-semibold text-gray-900 text-sm"><?= htmlspecialchars($t['party_name']) ?></div>
                                        </td>

                                        <!-- Col 3: Weight & Type -->
                                        <td class="py-3 px-3 align-top">
                                            <div class="font-bold text-green-600 text-sm"><?= number_format($t['gold_weight'], 2) ?>g</div>
                                            <div class="text-xs text-gray-600"><?= $goldType ?> • ₹<?= number_format($t['rate'], 2) ?>/g</div>
                                        </td>

                                        <!-- Col 4: Amount -->
                                        <td class="py-3 px-3 align-top">
                                            <div class="font-bold text-gray-900 text-base">₹<?= number_format($t['gold_amount'], 2) ?></div>
                                        </td>

                                        <!-- Col 5: Actions -->
                                        <td class="py-3 px-3 align-top">
                                            <div class="flex items-center gap-2">
                                                <button class="text-blue-600 hover:text-blue-800 print-sale-receipt cursor-pointer" 
                                                        data-id="<?= $t['id'] ?>"
                                                        title="Print Receipt">
                                                    <i class="fas fa-print text-sm"></i>
                                                </button>
                                                <button class="text-yellow-600 hover:text-yellow-800" title="Edit">
                                                    <i class="fas fa-edit text-sm"></i>
                                                </button>
                                                <button class="text-red-600 hover:text-red-800" title="Delete">
                                                    <i class="fas fa-trash text-sm"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; 
                                else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-8 text-gray-500">
                                            <i class="fas fa-inbox text-2xl mb-2"></i><br>
                                            No transactions found
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
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Pass company name to JavaScript
        const companyName = '<?php echo $_SESSION['company_name'] ?? 'Gold Trading Company'; ?>';
    </script>
    <script src="js/gold_exchange.js"></script>
    <script src="js/gold_exchange_additions.js"></script>
    <script src="js/sale.js"></script>
</body>
</html>


