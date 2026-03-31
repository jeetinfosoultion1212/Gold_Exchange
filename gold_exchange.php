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
                        id, 
                        party_name, 
                        address,
                        (cash_balance + bank_balance) AS total_due_amount,
                        gold_balance AS total_due_gold,
                        silver_balance AS total_due_silver
                    FROM parties
                    WHERE company_id = $company_id AND party_name LIKE '%$search%'
                    LIMIT 10";

                $result = $conn->query($sql);
                $parties = [];
                while ($row = $result->fetch_assoc()) {
                    $parties[] = [
                        'id' => $row['id'],
                        'party_name' => $row['party_name'],
                        'address' => $row['address'],
                        'total_due_amount' => $row['total_due_amount'],
                        'total_due_gold' => $row['total_due_gold'],
                        'total_due_silver' => $row['total_due_silver']
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
                $silver_balance = floatval($_POST['silver_balance'] ?? 0);

                $conn->begin_transaction();
                try {
                    $sql = "INSERT INTO parties (company_id, party_name, address, contact_no, gstin, state, city, bank_name, account_no, ifsc_code, cash_balance, bank_balance, gold_balance, silver_balance) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("isssssssssdddd", $company_id, $party_name, $address, $contact_no, $gstin, $state, $city, $bank_name, $account_no, $ifsc_code, $cash_balance, $bank_balance, $gold_balance, $silver_balance);
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

            case 'get_exchange_by_receipt_id':
                $receipt_id = $conn->real_escape_string($_POST['receipt_id'] ?? '');

                $sql = "SELECT t.*, p.party_name 
                        FROM transactions t 
                        LEFT JOIN parties p ON t.party_id = p.id
                        WHERE t.receipt_id = ? AND t.company_id = ? AND t.transaction_type = 'Exchange'";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("si", $receipt_id, $company_id);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $transaction = $result->fetch_assoc();

                    // Fetch received items only (no issued items needed)
                    $items_sql = "SELECT * FROM exchange_items WHERE transaction_id = ? AND item_type = 'received' ORDER BY id";
                    $items_stmt = $conn->prepare($items_sql);
                    $items_stmt->bind_param("i", $transaction['id']);
                    $items_stmt->execute();
                    $items_result = $items_stmt->get_result();

                    $received_items = [];

                    while ($item = $items_result->fetch_assoc()) {
                        $received_items[] = [
                            'weight' => $item['weight'],
                            'purity' => $item['purity'],
                            'fine' => $item['fine_weight']
                        ];
                    }

                    $transaction['received_items'] = $received_items;

                    echo json_encode([
                        'status' => 'success',
                        'data' => $transaction
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Receipt not found'
                    ]);
                }
                exit;

            case 'save_transaction':
                $conn->begin_transaction();
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
                    $payment_method = $conn->real_escape_string($data['payment_method'] ?? 'Cash');
                    $payment_amount = floatval($data['payment_amount'] ?? 0);
                    $due_amount = $amount - $payment_amount;

                    // Calculate payment status based on payment amount and total amount
                    // If payment_amount >= amount: Paid
                    // If payment_amount > 0 but < amount: Partial
                    // If payment_amount = 0: Due
                    if ($amount > 0 && $payment_amount >= $amount) {
                        $payment_status = 'Paid';
                    } else if ($payment_amount > 0) {
                        $payment_status = 'Partial';
                    } else {
                        $payment_status = 'Due';
                    }

                    // Override with user-provided status if explicitly set (for edits)
                    if (isset($data['payment_status']) && !empty($data['payment_status'])) {
                        $payment_status = $conn->real_escape_string($data['payment_status']);
                    }

                    $narration = $conn->real_escape_string($data['narration'] ?? '');

                    $transaction_id = isset($data['transaction_id']) && !empty($data['transaction_id']) ? intval($data['transaction_id']) : null;

                    // Get current stock for 100% pure gold (fine gold)
                    // Try multiple stock name variations and purity formats
                    $stock_query = "SELECT id, current_stock, stock_name FROM gold_stock 
                                    WHERE company_id = $company_id 
                                    AND (purity = 100.00 OR purity = 100.0 OR purity = 100)
                                    AND mode = 'Cash'
                                    ORDER BY 
                                        CASE 
                                            WHEN stock_name LIKE '%Fine%' OR stock_name LIKE '%fine%' THEN 1
                                            ELSE 2
                                        END,
                                        id ASC 
                                    LIMIT 1 FOR UPDATE";
                    $stock_result = $conn->query($stock_query);

                    if ($stock_result->num_rows === 0) {
                        // Try without purity restriction as fallback
                        $stock_query_fallback = "SELECT id, current_stock, stock_name FROM gold_stock 
                                                  WHERE company_id = $company_id 
                                                  AND mode = 'Cash'
                                                  ORDER BY id ASC LIMIT 1 FOR UPDATE";
                        $stock_result_fallback = $conn->query($stock_query_fallback);

                        if ($stock_result_fallback->num_rows === 0) {
                            throw new Exception("Stock record not found. Please add a gold stock entry first.");
                        }

                        $stock_data = $stock_result_fallback->fetch_assoc();
                    } else {
                        $stock_data = $stock_result->fetch_assoc();
                    }

                    $stock_id = $stock_data['id'];
                    $current_current_stock = $stock_data['current_stock'];

                    // If this is an edit, get the original transaction details
                    $original_fine_weight = 0;
                    $original_due_amount = 0;
                    $original_difference_weight = 0;
                    $original_payment_amount = 0;
                    if ($transaction_id) {
                        $original_sql = "SELECT fine_weight, party_id, received_weight, due_amount, difference_weight, payment_amount FROM transactions WHERE id = ? FOR UPDATE";
                        $original_stmt = $conn->prepare($original_sql);
                        $original_stmt->bind_param("i", $transaction_id);
                        $original_stmt->execute();
                        $original_result = $original_stmt->get_result();
                        $original_transaction = $original_result->fetch_assoc();

                        if (!$original_transaction) {
                            throw new Exception("Original transaction not found");
                        }

                        $original_fine_weight = $original_transaction['fine_weight'];
                        $original_due_amount = floatval($original_transaction['due_amount'] ?? 0);
                        $original_difference_weight = floatval($original_transaction['difference_weight'] ?? 0);
                        $original_payment_amount = floatval($original_transaction['payment_amount'] ?? 0);
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
                            // Find existing stock (MIX Stock is always considered Cash/Kachha by default)
                            $find_stock_sql = "SELECT id, current_stock FROM gold_stock WHERE company_id = ? AND stock_name = ? AND purity = ? AND mode = 'Cash'";
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

                        $find_stock_sql = "SELECT id, current_stock FROM gold_stock WHERE company_id = ? AND stock_name = ? AND purity = ? AND mode = 'Cash'";
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
                            // Insert (Default to Cash mode)
                            $ins_rs_sql = "INSERT INTO gold_stock (company_id, stock_name, purity, mode, current_stock, last_updated) VALUES (?, ?, ?, 'Cash', ?, NOW())";
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
                            $receipt_id,
                            $company_id,
                            $user_id,
                            $party_id,
                            $date_of_transaction,
                            $received_weight,
                            $purity,
                            $fine_weight,
                            $issue_weight,
                            $difference_weight,
                            $rate,
                            $amount,
                            $payment_method,
                            $payment_status,
                            $due_amount,
                            $narration,
                            $payment_type,
                            $type,
                            $payment_amount,
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
                            $company_id,
                            $user_id,
                            $receipt_id,
                            $party_id,
                            $date_of_transaction,
                            $received_weight,
                            $purity,
                            $fine_weight,
                            $issue_weight,
                            $difference_weight,
                            $rate,
                            $amount,
                            $payment_method,
                            $payment_status,
                            $due_amount,
                            $narration,
                            $payment_type,
                            $type,
                            $payment_amount,
                            $gold_weight,
                            $gold_amount
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
                            $company_id,
                            $user_id,
                            $linked_receipt_id,
                            $party_id,
                            $date_of_transaction,
                            $linked_type,
                            $payment_type,
                            $payment_method,
                            $payment_amount,
                            $linked_narration,
                            $payment_amount
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

                    // Update Party Balance
                    // Logic:
                    // 1. If editing: Revert old balance changes first
                    // 2. Apply new balance changes
                    // 3. due_amount = amount - payment_amount (what party still owes)
                    //    - If positive: party owes money → add to current_balance
                    //    - If negative: party has credit → subtract from current_balance
                    // 4. difference_weight = issue_weight - fine_weight (gold difference)
                    //    - If positive: party received more gold than they gave → owes gold → add to current_gold_balance
                    //    - If negative: party gave more gold than they received → has gold credit → subtract from current_gold_balance

                    if ($transaction_id) {
                        // Revert old balance changes
                        // Reverse the old due_amount and difference_weight
                        // Note: To be perfectly accurate, we'd need to know which mode the old transaction used.
                        // For now we assume the mode hasn't changed or we revert the specific columns based on old payment_method.

                        $old_method = $original_transaction['payment_method'] ?? 'Cash';
                        $old_is_cash = !in_array($old_method, ['Bank', 'UPI', 'Cheque', 'NEFT', 'RTGS']);

                        $revert_amt = -$original_due_amount;
                        $revert_gold = -$original_difference_weight;

                        if ($old_is_cash) {
                            $revert_sql = "UPDATE parties SET cash_balance = cash_balance + ?, gold_balance = gold_balance + ? WHERE id = ?";
                            $revert_stmt = $conn->prepare($revert_sql);
                            $revert_stmt->bind_param("ddi", $revert_amt, $revert_gold, $party_id);
                        } else {
                            $revert_sql = "UPDATE parties SET bank_balance = bank_balance + ?, gold_balance = gold_balance + ? WHERE id = ?";
                            $revert_stmt = $conn->prepare($revert_sql);
                            $revert_stmt->bind_param("ddi", $revert_amt, $revert_gold, $party_id);
                        }
                        $revert_stmt->execute();
                        $revert_stmt->close();
                    }

                    // Apply new balance changes (Cash/Bank specific)
                    $is_current_cash = !in_array($payment_method, ['Bank', 'UPI', 'Cheque', 'NEFT', 'RTGS']);

                    if ($is_current_cash) {
                        $update_party_sql = "UPDATE parties SET 
                            cash_balance = cash_balance + ?,
                            gold_balance = gold_balance + ?
                            WHERE id = ?";
                    } else {
                        $update_party_sql = "UPDATE parties SET 
                            bank_balance = bank_balance + ?,
                            gold_balance = gold_balance + ?
                            WHERE id = ?";
                    }

                    $update_party_stmt = $conn->prepare($update_party_sql);
                    $update_party_stmt->bind_param("ddi", $due_amount, $difference_weight, $party_id);

                    if (!$update_party_stmt->execute()) {
                        throw new Exception("Failed to update party balance: " . $update_party_stmt->error);
                    }
                    $update_party_stmt->close();

                    // === MULTI-ITEM STORAGE: Save received items to exchange_items table ===
                    // Delete existing items for this transaction (if editing)
                    if ($transaction_id) {
                        $delete_items_sql = "DELETE FROM exchange_items WHERE transaction_id = ?";
                        $delete_items_stmt = $conn->prepare($delete_items_sql);
                        $delete_items_stmt->bind_param("i", $transaction_id);
                        $delete_items_stmt->execute();
                        $delete_items_stmt->close();
                    }

                    //Save received items (decode JSON from frontend)
                    if (isset($_POST['received_items']) && !empty($_POST['received_items'])) {
                        $received_items = json_decode($_POST['received_items'], true);

                        if (is_array($received_items) && count($received_items) > 0) {
                            $insert_item_sql = "INSERT INTO exchange_items (transaction_id, company_id, item_type, weight, purity, fine_weight) VALUES (?, ?, ?, ?, ?, ?)";
                            $insert_item_stmt = $conn->prepare($insert_item_sql);

                            foreach ($received_items as $item) {
                                $item_type = 'received';
                                $item_weight = floatval($item['weight']);
                                $item_purity = floatval($item['purity']);
                                $item_fine = floatval($item['fine']);

                                $insert_item_stmt->bind_param(
                                    "iisddd",
                                    $transaction_id,
                                    $company_id,
                                    $item_type,
                                    $item_weight,
                                    $item_purity,
                                    $item_fine
                                );

                                if (!$insert_item_stmt->execute()) {
                                    throw new Exception("Failed to save received item: " . $insert_item_stmt->error);
                                }
                            }

                            $insert_item_stmt->close();
                        }
                    }
                    // === END MULTI-ITEM STORAGE ===

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
                // Get the last receipt ID for this company
                $sql = "SELECT receipt_id FROM transactions 
                        WHERE company_id = ? AND transaction_type = 'Exchange' 
                        ORDER BY id DESC LIMIT 1";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('i', $company_id);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $lastReceiptId = $row['receipt_id'];
                    // Extract number from receipt ID (e.g., "EX123" -> 123)
                    preg_match('/\d+$/', $lastReceiptId, $matches);
                    $lastNumber = isset($matches[0]) ? intval($matches[0]) : 0;
                    $nextNumber = $lastNumber + 1;
                } else {
                    $nextNumber = 1;
                }

                $nextReceiptId = 'EX' . $nextNumber;
                echo json_encode(['receipt_id' => $nextReceiptId]);
                exit;
        }
    }
}

// Get date range from user input (default: current month)
$start_date = isset($_GET['start_date']) && !empty($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01'); // First day of current month
$end_date = isset($_GET['end_date']) && !empty($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d'); // Today

// Enhanced statistics SQL query with date range filter - Exchange specific
$stats_sql = "
SELECT 
    COALESCE(SUM(received_weight), 0) AS total_weight,
    COALESCE(SUM(received_weight), 0) AS total_received_weight,
    COALESCE(SUM(delivered_weight), 0) AS total_issue_gold,
    COALESCE(SUM(fine_weight), 0) AS total_fine_gold,
    COALESCE(SUM(amount), 0) AS total_amount,
    COUNT(DISTINCT party_id) AS total_parties,
    COUNT(*) AS total_transactions,
    COALESCE(SUM(CASE WHEN payment_type = 'Payment_In' THEN payment_amount ELSE 0 END), 0) AS total_paid_amount,
    COALESCE(SUM(CASE WHEN payment_type = 'Payment_Out' THEN payment_amount ELSE 0 END), 0) AS total_payment_amount,
    COALESCE(SUM(CASE WHEN payment_status IN ('Due', 'Partial') THEN due_amount ELSE 0 END), 0) AS total_due
FROM transactions
WHERE company_id = ? AND DATE(date_of_transaction) BETWEEN ? AND ? AND transaction_type = 'Exchange'";

$stats_stmt = $conn->prepare($stats_sql);
if (!$stats_stmt) {
    die("SQL Error in stats query: " . $conn->error . "<br><br>Query: " . $stats_sql);
}
$stats_stmt->bind_param("iss", $company_id, $start_date, $end_date);
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
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Get search parameter
$search = isset($_GET['search']) ? "%" . $conn->real_escape_string($_GET['search']) . "%" : null;

// Transactions query with search and date filter
$transactions_sql = "SELECT t.*, p.party_name 
    FROM transactions t 
    LEFT JOIN parties p ON t.party_id = p.id 
    WHERE t.company_id = ? AND DATE(t.date_of_transaction) BETWEEN ? AND ? AND t.transaction_type = 'Exchange'";
if ($search) {
    $transactions_sql .= " AND (p.party_name LIKE ? OR t.receipt_id LIKE ?)";
}
$transactions_sql .= " ORDER BY t.date_of_transaction DESC, t.id DESC LIMIT ?, ?";

$transactions_stmt = $conn->prepare($transactions_sql);
if (!$transactions_stmt) {
    die("SQL Error in transactions query: " . $conn->error . "<br><br>Query: " . $transactions_sql);
}
if ($search) {
    $transactions_stmt->bind_param("isssii", $company_id, $start_date, $end_date, $search, $search, $offset, $limit);
} else {
    $transactions_stmt->bind_param("issii", $company_id, $start_date, $end_date, $offset, $limit);
}
$transactions_stmt->execute();
$transactions_result = $transactions_stmt->get_result();
$transactions = $transactions_result->fetch_all(MYSQLI_ASSOC);

// Count total transactions for pagination
$total_sql = "SELECT COUNT(*) as count FROM transactions t 
    LEFT JOIN parties p ON t.party_id = p.id 
    WHERE t.company_id = ? AND DATE(t.date_of_transaction) BETWEEN ? AND ? AND t.transaction_type = 'Exchange'";
if ($search) {
    $total_sql .= " AND (p.party_name LIKE ? OR t.receipt_id LIKE ?)";
}
$total_stmt = $conn->prepare($total_sql);
if (!$total_stmt) {
    die("SQL Error in total count query: " . $conn->error . "<br><br>Query: " . $total_sql);
}
if ($search) {
    $total_stmt->bind_param("isss", $company_id, $start_date, $end_date, $search, $search);
} else {
    $total_stmt->bind_param("iss", $company_id, $start_date, $end_date);
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
    <title>Gold Exchange - Mormukut</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet">
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

        /* Compact UI Adjustments */
        @media (max-width: 1600px) {
            .compact-text {
                font-size: 0.7rem !important;
            }

            .compact-label {
                font-size: 0.65rem !important;
                margin-bottom: 0.1rem !important;
            }

            .compact-input {
                padding-top: 0.4rem !important;
                padding-bottom: 0.4rem !important;
                font-size: 0.75rem !important;
            }

            .grid-cols-7 {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }

            .stats-card {
                padding: 0.6rem !important;
            }

            .stats-icon {
                width: 1.75rem !important;
                height: 1.75rem !important;
            }

            .stats-icon i {
                font-size: 0.8rem !important;
            }
        }

        @media (max-width: 1280px) {
            .grid-cols-7 {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }
    </style>
</head>

<body class="bg-gray-100">
    <?php include 'components/sidebar.php'; ?>
    <?php include 'components/header.php'; ?>

    <div class="px-2 pt-1 pb-4 ml-16">
        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-7 gap-3 mb-4">
            <div class="soft-gradient-blue rounded-xl p-3 shadow-sm stats-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-[10px] font-bold text-blue-700 uppercase tracking-tighter opacity-80">Rcv. Wt</p>
                        <p class="text-base font-bold text-blue-800 leading-tight">
                            <?= number_format($stats['total_received_weight'] ?? 0, 2) ?><span
                                class="text-[10px] ml-0.5">g</span></p>
                    </div>
                    <div class="w-8 h-8 bg-blue-500 rounded-lg flex items-center justify-center stats-icon">
                        <i class="fas fa-weight text-white text-xs"></i>
                    </div>
                </div>
            </div>

            <div class="soft-gradient-green rounded-xl p-3 shadow-sm stats-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-[10px] font-bold text-green-700 uppercase tracking-tighter opacity-80">Fine
                            Weight</p>
                        <p class="text-base font-bold text-green-800 leading-tight">
                            <?= number_format($stats['total_fine_gold'] ?? 0, 2) ?><span
                                class="text-[10px] ml-0.5">g</span></p>
                    </div>
                    <div class="w-8 h-8 bg-green-500 rounded-lg flex items-center justify-center stats-icon">
                        <i class="fas fa-coins text-white text-xs"></i>
                    </div>
                </div>
            </div>

            <div class="soft-gradient-orange rounded-xl p-3 shadow-sm stats-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-[10px] font-bold text-orange-700 uppercase tracking-tighter opacity-80">Issue
                            Weight</p>
                        <p class="text-base font-bold text-orange-800 leading-tight">
                            <?= number_format($stats['total_issue_gold'] ?? 0, 2) ?><span
                                class="text-[10px] ml-0.5">g</span></p>
                    </div>
                    <div class="w-8 h-8 bg-orange-500 rounded-lg flex items-center justify-center stats-icon">
                        <i class="fas fa-box text-white text-xs"></i>
                    </div>
                </div>
            </div>

            <div class="soft-gradient-purple rounded-xl p-3 shadow-sm stats-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-[10px] font-bold text-purple-700 uppercase tracking-tighter opacity-80">Stock</p>
                        <p class="text-base font-bold text-purple-800 leading-tight">
                            <?= number_format($stats['current_stock'] ?? 0, 2) ?><span
                                class="text-[10px] ml-0.5">g</span></p>
                    </div>
                    <div class="w-8 h-8 bg-purple-500 rounded-lg flex items-center justify-center stats-icon">
                        <i class="fas fa-box-open text-white text-xs"></i>
                    </div>
                </div>
            </div>

            <div class="soft-gradient-teal rounded-xl p-3 shadow-sm stats-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-[10px] font-bold text-teal-700 uppercase tracking-tighter opacity-80">Cash</p>
                        <p class="text-base font-bold text-teal-800 leading-tight">
                            ₹<?= number_format($stats['cash_balance'] ?? 0, 0) ?></p>
                    </div>
                    <div class="w-8 h-8 bg-teal-500 rounded-lg flex items-center justify-center stats-icon">
                        <i class="fas fa-wallet text-white text-xs"></i>
                    </div>
                </div>
            </div>

            <div class="soft-gradient-teal rounded-xl p-3 shadow-sm stats-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-[10px] font-bold text-teal-700 uppercase tracking-tighter opacity-80">Received
                        </p>
                        <p class="text-base font-bold text-teal-800 leading-tight">
                            ₹<?= number_format($stats['total_paid_amount'] ?? 0, 0) ?></p>
                    </div>
                    <div class="w-8 h-8 bg-teal-500 rounded-lg flex items-center justify-center stats-icon">
                        <i class="fas fa-arrow-up text-white text-xs"></i>
                    </div>
                </div>
            </div>

            <div class="soft-gradient-yellow rounded-xl p-3 shadow-sm stats-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-[10px] font-bold text-yellow-700 uppercase tracking-tighter opacity-80">Paid</p>
                        <p class="text-base font-bold text-yellow-800 leading-tight">
                            ₹<?= number_format($stats['total_payment_amount'] ?? 0, 0) ?></p>
                    </div>
                    <div class="w-8 h-8 bg-yellow-500 rounded-lg flex items-center justify-center stats-icon">
                        <i class="fas fa-arrow-down text-white text-xs"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="flex flex-col lg:flex-row gap-6">
            <!-- Left Side - Form -->
            <div class="bg-white rounded-lg shadow-md border border-gray-200" style="flex: 0 0 55%;">
                <!-- Transaction Form -->
                <form id="exchangeForm" onsubmit="return false;"
                    class="bg-white rounded-lg shadow-md border border-gray-200 overflow-hidden">

                    <!-- Section 1: Transaction Details -->
                    <div class="bg-blue-50 px-3 py-1 border-b border-blue-100">
                        <h3 class="text-xs font-bold text-blue-800 flex items-center">
                            <i class="fas fa-file-invoice mr-1.5 text-xs"></i> Transaction Details
                        </h3>
                    </div>
                    <div class="p-2 grid grid-cols-12 gap-1.5">
                        <!-- Receipt ID (3 columns) -->
                        <div class="relative col-span-3">
                            <label
                                class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Receipt
                                ID</label>
                            <div class="relative">
                                <span
                                    class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-gray-500">
                                    <i class="fas fa-hashtag text-xs"></i>
                                </span>
                                <input type="text"
                                    class="block w-full pl-7 pr-2 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-blue-400 focus:border-blue-400 compact-input"
                                    name="receipt_id" id="receiptId" placeholder="Search..." autocomplete="off">
                            </div>
                            <div id="receiptList"
                                class="hidden absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-xl max-h-60 overflow-y-auto">
                            </div>
                        </div>

                        <!-- Date (3 columns) -->
                        <div class="relative col-span-3">
                            <label
                                class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Date</label>
                            <div class="relative">
                                <span
                                    class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-purple-500">
                                    <i class="fas fa-calendar-alt text-xs"></i>
                                </span>
                                <input type="datetime-local"
                                    class="block w-full pl-7 pr-1 py-1.5 text-[11px] font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-purple-400 focus:border-purple-400 compact-input"
                                    name="date_of_transaction" required>
                            </div>
                        </div>

                        <!-- Party Name (6 columns) -->
                        <div class="relative col-span-6">
                            <label
                                class="block text-[10px] font-bold text-gray-700 mb-0.5 flex items-center justify-between uppercase tracking-tighter compact-label">
                                <span>Party Name</span>
                                <!-- Outstanding Balance Inline -->
                                <span id="partyDueInfoInline" class="hidden text-[10px] font-bold tracking-tighter">
                                    <span class="text-orange-600">Bal:</span>
                                    <span class="text-red-600 ml-0.5" id="dueAmountValueInline">₹0</span>
                                    <span class="text-yellow-700 ml-0.5" id="dueGoldValueInline">0g</span>
                                </span>
                            </label>
                            <div class="relative">
                                <span
                                    class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-blue-500">
                                    <i class="fas fa-user text-xs"></i>
                                </span>
                                <input type="text"
                                    class="block w-full pl-7 pr-2 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-blue-400 focus:border-blue-400 compact-input"
                                    name="party_name" id="partyNameInput" required placeholder="Select Party"
                                    autocomplete="off">
                                <input type="hidden" name="party_id" id="partyId">
                            </div>
                            <div id="partyList"
                                class="hidden absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                            </div>
                        </div>
                    </div>

                    <!-- Section 2: Received Items (Old Gold) -->
                    <div class="bg-blue-50 px-3 py-1 border-t border-b border-blue-100">
                        <h3 class="text-xs font-bold text-blue-800 flex items-center justify-between">
                            <span><i class="fas fa-arrow-down mr-1.5 text-xs"></i> Received Items (Old Gold)</span>
                            <button type="button" onclick="addReceivedItem()"
                                class="bg-blue-600 hover:bg-blue-700 text-white px-2 py-0.5 rounded text-xs font-bold shadow-sm transition-all hover:scale-105 active:scale-95">
                                <i class="fas fa-plus mr-1"></i> Add
                            </button>
                        </h3>
                    </div>
                    <div class="p-2">
                        <div class="border border-gray-200 rounded overflow-hidden">
                            <table class="w-full text-xs">
                                <thead class="bg-slate-100">
                                    <tr class="text-[10px] uppercase font-bold text-slate-600 tracking-tighter">
                                        <th class="px-2 py-1.5 text-left border-b w-8">#</th>
                                        <th class="px-2 py-1.5 text-left border-b"><i
                                                class="fas fa-weight text-blue-500 mr-1"></i>Weight (g)</th>
                                        <th class="px-2 py-1.5 text-left border-b"><i
                                                class="fas fa-percent text-orange-400 mr-1"></i>Purity (%)</th>
                                        <th class="px-2 py-1.5 text-left border-b"><i
                                                class="fas fa-gem text-emerald-500 mr-1"></i>Fine (g)</th>
                                        <th class="px-2 py-1.5 text-center border-b w-10">Act</th>
                                    </tr>
                                </thead>
                            </table>
                            <div class="overflow-y-auto" style="max-height: 120px;">
                                <table class="w-full text-xs">
                                    <tbody id="receivedItemsTable">
                                        <tr class="received-item-row group">
                                            <td class="px-2 py-1 border-b text-gray-500 font-bold item-number w-8">1
                                            </td>
                                            <td class="px-2 py-1 border-b">
                                                <input type="number" step="0.001"
                                                    class="w-full px-2 py-1 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-green-400 focus:border-green-400 received-weight"
                                                    placeholder="0.000" required>
                                            </td>
                                            <td class="px-2 py-1 border-b">
                                                <input type="number" step="0.01"
                                                    class="w-full px-2 py-1 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-yellow-400 focus:border-yellow-400 received-purity"
                                                    placeholder="0.00" required>
                                            </td>
                                            <td class="px-2 py-1 border-b">
                                                <input type="number" step="0.001"
                                                    class="w-full px-2 py-1 text-xs font-bold text-gray-600 bg-gray-50 border border-gray-200 rounded received-fine cursor-not-allowed"
                                                    readonly>
                                            </td>
                                            <td class="px-2 py-1 border-b text-center w-10">
                                                <button type="button" onclick="removeReceivedItem(this)"
                                                    class="text-red-400 hover:text-red-600 text-xs transition-colors">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Section 3: Totals, Issue & Difference -->
                    <div class="bg-orange-50 px-3 py-1 border-t border-b border-orange-100">
                        <h3 class="text-xs font-bold text-orange-800 flex items-center">
                            <i class="fas fa-exchange-alt mr-1.5 text-xs"></i> Totals, Issue & Difference
                        </h3>
                    </div>
                    <div class="p-2 grid grid-cols-4 gap-1.5">
                        <!-- Total Weight -->
                        <div>
                            <label
                                class="block text-[10px] font-bold text-gray-600 mb-0.5 uppercase tracking-tighter compact-label">Total
                                Wt (g)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none">
                                    <i class="fas fa-weight text-green-600 text-xs"></i>
                                </span>
                                <input type="text" id="totalReceivedWeight"
                                    class="block w-full pl-7 pr-2 py-1.5 text-xs font-bold text-gray-600 bg-gray-50 border border-gray-200 rounded cursor-not-allowed compact-input"
                                    readonly value="0.000">
                            </div>
                        </div>

                        <!-- Total Fine -->
                        <div>
                            <label
                                class="block text-[10px] font-bold text-gray-600 mb-0.5 uppercase tracking-tighter compact-label">Fine
                                (g)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none">
                                    <i class="fas fa-gem text-teal-600 text-xs"></i>
                                </span>
                                <input type="text" id="totalReceivedFine"
                                    class="block w-full pl-7 pr-2 py-1.5 text-xs font-bold text-gray-600 bg-gray-50 border border-gray-200 rounded cursor-not-allowed compact-input"
                                    readonly value="0.000">
                            </div>
                        </div>

                        <!-- Issue Weight -->
                        <div>
                            <label
                                class="block text-[10px] font-bold text-rose-700 mb-0.5 uppercase tracking-tighter compact-label">Issue
                                (g)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none">
                                    <i class="fas fa-arrow-up text-rose-500 text-xs"></i>
                                </span>
                                <input type="number" step="0.001"
                                    class="block w-full pl-7 pr-2 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-rose-400 focus:border-rose-400 compact-input"
                                    id="issueWeightInput" placeholder="0.000" required>
                            </div>
                        </div>

                        <!-- Difference -->
                        <div>
                            <label
                                class="block text-[10px] font-bold text-blue-700 mb-0.5 uppercase tracking-tighter compact-label">Diff
                                (g)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none">
                                    <i class="fas fa-balance-scale text-blue-500 text-xs"></i>
                                </span>
                                <input type="text"
                                    class="block w-full pl-7 pr-2 py-1.5 text-xs font-bold text-gray-600 bg-gray-50 border border-gray-200 rounded cursor-not-allowed compact-input"
                                    id="differenceWeight" readonly placeholder="0.000">
                            </div>
                        </div>
                    </div>

                    <!-- Hidden fields for backward compatibility -->
                    <input type="hidden" name="action" value="save_transaction">
                    <input type="hidden" name="transaction_id" value="">
                    <input type="hidden" name="received_weight" id="receivedWeight">
                    <input type="hidden" name="purity" id="purity">
                    <input type="hidden" name="fine_weight" id="fineWeight">
                    <input type="hidden" name="issue_weight" id="issueWeight">

                    <!-- Section 4: Payment Details -->
                    <div class="bg-emerald-50 px-3 py-1 border-t border-b border-emerald-100">
                        <h3 class="text-xs font-bold text-emerald-800 flex items-center">
                            <i class="fas fa-money-bill-wave mr-1.5 text-xs"></i> Payment Details
                        </h3>
                    </div>
                    <div class="p-2 grid grid-cols-2 md:grid-cols-4 gap-1.5">
                        <!-- Rate -->
                        <div>
                            <label
                                class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Rate
                                (₹/g)</label>
                            <div class="relative">
                                <span
                                    class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-orange-500">
                                    <i class="fas fa-rupee-sign text-xs"></i>
                                </span>
                                <input type="number" step="0.01"
                                    class="block w-full pl-7 pr-2 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-orange-400 focus:border-orange-400 compact-input"
                                    name="rate" id="rate" required placeholder="0.00">
                            </div>
                        </div>

                        <!-- Amount -->
                        <div>
                            <label
                                class="block text-[10px] font-bold text-green-700 mb-0.5 uppercase tracking-tighter compact-label">Amount
                                (₹)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none">
                                    <i class="fas fa-coins text-green-600 text-xs"></i>
                                </span>
                                <input type="text"
                                    class="block w-full pl-7 pr-2 py-1.5 text-xs font-semibold text-gray-700 bg-gray-50 border border-gray-200 rounded cursor-not-allowed compact-input"
                                    name="amount" id="amount" readonly>
                            </div>
                        </div>

                        <!-- Paid Amount -->
                        <div>
                            <label
                                class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label"
                                id="paymentAmountLabel">Paid Amt (₹)</label>
                            <div class="relative">
                                <span
                                    class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-indigo-500">
                                    <i class="fas fa-wallet text-xs"></i>
                                </span>
                                <input type="number" step="0.01"
                                    class="block w-full pl-7 pr-2 py-1.5 text-xs font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-indigo-400 focus:border-indigo-400 compact-input"
                                    name="payment_amount" id="paymentAmount" value="0">
                            </div>
                        </div>

                        <!-- Payment Method -->
                        <div>
                            <label
                                class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Mode</label>
                            <div class="relative">
                                <span
                                    class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-gray-600">
                                    <i class="fas fa-credit-card text-xs"></i>
                                </span>
                                <select
                                    class="block w-full pl-7 pr-1 py-1.5 text-[11px] font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-gray-400 focus:border-gray-400 compact-input"
                                    name="payment_method">
                                    <option value="Cash">Cash</option>
                                    <option value="Bank Transfer">Bank</option>
                                    <option value="Cheque">Cheque</option>
                                    <option value="UPI">UPI</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Footer: Narration & Buttons -->
                    <div
                        class="bg-gray-50 p-1.5 border-t border-gray-200 grid grid-cols-1 md:grid-cols-4 gap-1.5 items-center">
                        <div class="md:col-span-2 relative">
                            <span
                                class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-purple-500">
                                <i class="fas fa-comment-alt text-xs"></i>
                            </span>
                            <input type="text"
                                class="block w-full pl-7 pr-2 py-1.5 text-xs font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-purple-400 focus:border-purple-400 compact-input"
                                name="narration" placeholder="Narration...">
                        </div>
                        <div class="md:col-span-2 flex space-x-1">
                            <button type="submit" id="submitBtn"
                                class="flex-1 bg-gradient-to-r from-blue-600 to-blue-700 text-white text-[10px] font-bold uppercase py-1.5 px-3 rounded shadow hover:from-blue-700 hover:to-blue-800 transition tracking-tighter">
                                <i class="fas fa-save mr-1" id="submitIcon"></i><span id="submitText">Save</span>
                            </button>
                            <button type="button" id="deleteBtn"
                                class="hidden px-2.5 py-1.5 bg-gradient-to-r from-red-600 to-red-700 text-white text-[10px] font-bold rounded hover:from-red-700 hover:to-red-800 shadow-sm"
                                title="Delete">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                            <button type="button" id="resetFormBtn"
                                class="px-2.5 py-1.5 bg-white border border-gray-300 text-gray-700 text-[10px] font-bold rounded hover:bg-gray-50 shadow-sm"
                                title="Reset">
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
                <div class="bg-blue-50 px-3 py-1.5 border-b border-blue-100 rounded-t-lg">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-xs font-bold text-blue-800 flex items-center">
                            <i class="fas fa-list mr-1.5 text-xs"></i>
                            Recent Transactions
                        </h2>
                        <!-- Compact Date Range Filter -->
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
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left text-gray-500">
                            <thead class="bg-slate-50 border-b border-slate-100">
                                <tr>
                                    <th class="py-2 px-2 text-left text-[9px] font-bold text-slate-500 w-16">Id</th>
                                    <th class="py-2 px-2 text-left text-[9px] font-bold text-slate-500">Party</th>
                                    <th class="py-2 px-2 text-right text-[9px] font-bold text-slate-500">Rcv.Wt</th>
                                    <th class="py-2 px-2 text-right text-[9px] font-bold text-slate-500">Fine Wt</th>
                                    <th class="py-2 px-2 text-right text-[9px] font-bold text-slate-500">Issue Wt</th>
                                    <th class="py-2 px-2 text-right text-[9px] font-bold text-slate-500">Amount</th>
                                    <th class="py-2 px-2 text-right text-[9px] font-bold text-slate-500">Action</th>
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

                                        // Difference color
                                        $diffColor = $t['difference_weight'] > 0 ? 'text-green-600' : ($t['difference_weight'] < 0 ? 'text-red-600' : 'text-gray-600');
                                        ?>
                                        <tr
                                            class="hover:bg-slate-50 transition-colors border-b border-slate-100 last:border-b-0">
                                            <!-- ID & Date -->
                                            <td class="py-1.5 px-2 align-top group cursor-pointer print-receipt"
                                                data-id="<?= $t['id'] ?>"
                                                data-receipt-id="<?= htmlspecialchars($t['receipt_id']) ?>"
                                                data-party="<?= htmlspecialchars($t['party_name']) ?>"
                                                data-date="<?= $t['date_of_transaction'] ?>"
                                                data-received="<?= $t['received_weight'] ?>" data-purity="<?= $t['purity'] ?>"
                                                data-fine="<?= $t['fine_weight'] ?>" data-issue="<?= $t['delivered_weight'] ?>"
                                                data-diff="<?= $t['difference_weight'] ?>" data-rate="<?= $t['rate'] ?>"
                                                data-amount="<?= $t['amount'] ?>"
                                                data-payment-amount="<?= $t['payment_amount'] ?>"
                                                data-payment-method="<?= $t['payment_method'] ?>"
                                                data-payment-status="<?= $t['payment_status'] ?>"
                                                data-payment-type="<?= $t['payment_type'] ?>"
                                                data-narration="<?= htmlspecialchars($t['narration'] ?? '') ?>"
                                                title="Click to Print Receipt">
                                                <div class="text-[10px] font-bold text-blue-600 group-hover:underline truncate">
                                                    #<?= $t['receipt_id'] ?></div>
                                                <div class="text-[8px] font-bold text-slate-400 uppercase leading-tight">
                                                    <?= date('d M', strtotime($t['date_of_transaction'])) ?></div>
                                            </td>

                                            <!-- Party -->
                                            <td class="py-1.5 px-2 align-top">
                                                <div
                                                    class="text-[10px] font-semibold text-slate-800 truncate max-w-[80px] uppercase">
                                                    <?= htmlspecialchars($t['party_name']) ?></div>
                                            </td>

                                            <!-- Weight & Purity -->
                                            <td class="py-1.5 px-2 align-top text-right">
                                                <div class="text-[10px] font-bold text-slate-700 leading-none">
                                                    <?= number_format($t['received_weight'], 3) ?><span
                                                        class="text-[8px] font-normal ml-0.5">g</span></div>
                                                <div class="text-[8px] font-bold text-slate-400 uppercase mt-0.5">
                                                    <?= number_format($t['purity'], 2) ?>%</div>
                                            </td>

                                            <!-- Fine & Rate -->
                                            <td class="py-1.5 px-2 align-top text-right">
                                                <div class="text-[10px] font-semibold text-amber-600 leading-none">
                                                    <?= number_format($t['fine_weight'], 3) ?><span
                                                        class="text-[8px] font-normal ml-0.5">g</span></div>
                                                <div class="text-[8px] font-medium text-slate-400 uppercase mt-0.5">@
                                                    <?= number_format($t['rate'], 0) ?></div>
                                            </td>

                                            <!-- Issue & Diff -->
                                            <td class="py-1.5 px-2 align-top text-right">
                                                <div class="text-[10px] font-semibold text-slate-600 leading-none">
                                                    <?= number_format($t['delivered_weight'], 3) ?><span
                                                        class="text-[8px] font-normal ml-0.5">g</span></div>
                                                <div class="text-[8px] font-bold <?= $diffColor ?> uppercase mt-0.5">
                                                    <?= $t['difference_weight'] > 0 ? '+' : '' ?>        <?= number_format($t['difference_weight'], 3) ?>
                                                </div>
                                            </td>

                                            <!-- Bill & Status -->
                                            <td class="py-1.5 px-2 align-top text-right">
                                                <div class="text-[10px] font-bold text-slate-800 leading-none">
                                                    ₹<?= number_format($t['amount'], 0) ?></div>
                                                <div class="mt-1">
                                                    <?php if ($t['payment_amount'] >= $t['amount']): ?>
                                                        <span
                                                            class="text-[7.5px] px-1 py-0.5 rounded bg-green-100 text-green-700 font-bold uppercase tracking-tighter">Paid</span>
                                                    <?php else: ?>
                                                        <span
                                                            class="text-[7.5px] px-1 py-0.5 rounded bg-rose-100 text-rose-700 font-bold uppercase tracking-tighter">Due</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>

                                            <!-- Payment Flow -->
                                            <td class="py-1.5 px-2 align-top">
                                                <div class="flex items-center justify-end">
                                                    <button onclick="loadTransaction(<?= $t['id'] ?>)"
                                                        class="ml-1 text-blue-500 hover:text-blue-700 p-0.5">
                                                        <i class="fas fa-edit text-[9px]"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach;
                                else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-8 text-gray-500">
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

        // Date Range Filter Functions
        $(document).ready(function () {
            // Validate date range (end date should be >= start date)
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
                    // Auto-correct: set end date to start date if invalid
                    if ($(this).attr('id') === 'startDate') {
                        $('#endDate').val($('#startDate').val());
                    } else {
                        $('#startDate').val($('#endDate').val());
                    }
                }
            });
        });

        // ========== MULTI-ITEM FUNCTIONALITY ==========

        // Add Received Item Row
        function addReceivedItem() {
            const table = document.getElementById('receivedItemsTable');
            const rowCount = table.querySelectorAll('.received-item-row').length + 1;

            const newRow = `
                <tr class="received-item-row">
                    <td class="px-2 py-1.5 border-b text-gray-700 font-bold item-number" style="width: 40px;">${rowCount}</td>
                    <td class="px-2 py-1.5 border-b">
                        <input type="number" step="0.001" class="w-full px-2 py-2 text-xs font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-green-400 focus:border-green-400 received-weight" placeholder="0.000" required>
                    </td>
                    <td class="px-2 py-1.5 border-b">
                        <input type="number" step="0.01" class="w-full px-2 py-2 text-xs font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-yellow-400 focus:border-yellow-400 received-purity" placeholder="0.00" required>
                    </td>
                    <td class="px-2 py-1.5 border-b">
                        <input type="number" step="0.001" class="w-full px-2 py-2 text-xs font-semibold text-gray-700 bg-white border border-gray-200 rounded received-fine cursor-not-allowed" readonly>
                    </td>
                    <td class="px-2 py-1.5 border-b text-center" style="width: 48px;">
                        <button type="button" onclick="removeReceivedItem(this)" class="text-red-600 hover:text-red-800 text-sm">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </td>
                </tr>
            `;

            table.insertAdjacentHTML('beforeend', newRow);
            updateItemNumbers();
            attachCalculationListeners();
        }

        // Remove Received Item Row
        function removeReceivedItem(btn) {
            const table = document.getElementById('receivedItemsTable');
            if (table.querySelectorAll('.received-item-row').length > 1) {
                btn.closest('tr').remove();
                updateItemNumbers();
                calculateTotals();
            } else {
                Swal.fire({
                    icon: 'warning',
                    title: 'Cannot Remove',
                    text: 'At least one received item is required',
                    timer: 2000,
                    showConfirmButton: false
                });
            }
        }

        // Update Item Numbers
        function updateItemNumbers() {
            const rows = document.querySelectorAll('.received-item-row');
            rows.forEach((row, index) => {
                row.querySelector('.item-number').textContent = index + 1;
            });
        }

        // Calculate Fine Gold for Each Row
        function calculateRowFine(row) {
            const weight = parseFloat(row.querySelector('.received-weight').value) || 0;
            const purity = parseFloat(row.querySelector('.received-purity').value) || 0;
            let fine = (weight * purity / 100);

            // Round to nearest 0.010 (e.g., 19.105 -> 19.110, 19.104 -> 19.100)
            const roundedFine = (Math.round(fine * 100) / 100).toFixed(3);
            row.querySelector('.received-fine').value = roundedFine;
        }

        // Calculate All Totals
        function calculateTotals() {
            // Calculate Received Items
            let totalReceivedFine = 0;
            let totalReceivedWeight = 0;
            const receivedRows = document.querySelectorAll('.received-item-row');
            receivedRows.forEach(row => {
                calculateRowFine(row);
                const weight = parseFloat(row.querySelector('.received-weight').value) || 0;
                const fine = parseFloat(row.querySelector('.received-fine').value) || 0;
                totalReceivedWeight += weight;
                totalReceivedFine += fine;
            });

            // Update Totals
            document.getElementById('totalReceivedWeight').value = totalReceivedWeight.toFixed(3);
            const finalFine = (Math.round(totalReceivedFine * 100) / 100).toFixed(3);
            document.getElementById('totalReceivedFine').value = finalFine;

            // Get Issue Weight (simple, no purity calculation)
            const issueWeight = parseFloat(document.getElementById('issueWeightInput').value) || 0;

            // Calculate Difference (Issue minus Received Fine)
            const difference = issueWeight - parseFloat(finalFine);
            const differenceField = document.getElementById('differenceWeight');
            differenceField.value = difference.toFixed(3);

            // Highlight Difference text color based on value
            if (difference > 0) {
                // Positive - Giving more (Green bold)
                differenceField.classList.remove('text-red-700', 'text-gray-700', 'font-semibold');
                differenceField.classList.add('text-green-700', 'font-bold');
            } else if (difference < 0) {
                // Negative - Giving less (Red bold)
                differenceField.classList.remove('text-green-700', 'text-gray-700', 'font-semibold');
                differenceField.classList.add('text-red-700', 'font-bold');
            } else {
                // Zero - Neutral (Gray normal)
                differenceField.classList.remove('text-green-700', 'text-red-700', 'font-bold');
                differenceField.classList.add('text-gray-700', 'font-semibold');
            }

            // Update hidden fields for backward compatibility
            document.getElementById('receivedWeight').value = totalReceivedWeight.toFixed(3);
            document.getElementById('fineWeight').value = totalReceivedFine.toFixed(3);
            document.getElementById('issueWeight').value = issueWeight.toFixed(3);

            // Calculate weighted average purity (for backward compatibility)
            if (totalReceivedWeight > 0) {
                const avgPurity = (totalReceivedFine / totalReceivedWeight * 100).toFixed(2);
                document.getElementById('purity').value = avgPurity;
            }
        }

        // Attach Calculation Listeners
        function attachCalculationListeners() {
            // Received items
            document.querySelectorAll('.received-weight, .received-purity').forEach(input => {
                input.removeEventListener('input', calculateTotals);
                input.addEventListener('input', calculateTotals);
            });

            // Issue weight
            const issueWeight = document.getElementById('issueWeightInput');
            if (issueWeight) {
                issueWeight.removeEventListener('input', calculateTotals);
                issueWeight.addEventListener('input', calculateTotals);
            }
        }

        // Initialize on Page Load
        document.addEventListener('DOMContentLoaded', function () {
            attachCalculationListeners();
            calculateTotals();
        });
    </script>
    <script src="js/shared-party-handler.js"></script>
    <script src="js/gold_exchange.js"></script>
    <script src="js/gold_exchange_additions.js"></script>
    <script src="js/gold_exchange_multi_item.js"></script>
    <script src="js/clear_party.js"></script>
    <script src="js/keyboard_navigation.js"></script>
</body>

</html>