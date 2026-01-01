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
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' THEN t.gold_weight ELSE 0 END), 0) as booked_weight,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Sale' THEN t.gold_weight ELSE 0 END), 0) as sold_weight,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' THEN t.gold_amount ELSE 0 END), 0) as booked_amount,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Payment' AND t.payment_type = 'Payment_In' AND t.payment_method = 'Cash' THEN t.payment_amount ELSE 0 END), 0) as cash_received,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Payment' AND t.payment_type = 'Payment_In' AND t.payment_method = 'Bank' THEN t.payment_amount ELSE 0 END), 0) as bank_received
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
                        'booked_weight' => number_format($booked_weight, 2),
                        'sold_weight' => number_format($row['sold_weight'], 2),
                        'available_weight' => number_format($available_weight, 2),
                        'avg_rate' => number_format($avg_rate, 2),
                        'cash_received' => number_format($row['cash_received'], 2),
                        'bank_received' => number_format($row['bank_received'], 2)
                    ];
                }
                echo json_encode($parties);
                exit;
                
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
                            'rate' => floatval($booking_row['rate']),
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
                    
                    
                    // Ensure all values are numeric, not null
                    $cash_received = floatval($row['cash_received'] ?? 0);
                    $bank_received = floatval($row['bank_received'] ?? 0);
                    
                    echo json_encode([
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
                        'avg_rate' => floatval($avg_rate),
                        'cash_received' => floatval($cash_received),
                        'bank_received' => floatval($bank_received),
                        'detailed_bookings' => $detailed_bookings,
                        'debug_payments' => $debug_payments
                    ]);
                } else {
                    echo json_encode([
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
                        'bank_received' => '0.00'
                    ]);
                }
                exit;
                
            case 'generate_sale_id':
                // Generate unique sale ID: S + company_id + serial
                
                $prefix = "S{$company_id}";
                
                // Get the last sale ID for this company
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
                    // First sale for this company
                    $serial = 1;
                }
                
                $saleId = $prefix . str_pad($serial, 3, '0', STR_PAD_LEFT);
                
                echo json_encode([
                    'status' => 'success',
                    'sale_id' => $saleId
                ]);
                exit;

            case 'save_sell':
                $conn->begin_transaction();
                try {
                    $receipt_id = $conn->real_escape_string($_POST['receipt_id']);
                    $party_id = intval($_POST['party_id']);
                    $date_of_transaction = $conn->real_escape_string($_POST['date_of_transaction']);
                    $gold_weight = floatval($_POST['sell_weight']);
                    $purity = floatval($_POST['purity']);
                    $rate = floatval($_POST['rate']);
                    $gold_amount = floatval($_POST['amount']);
                    
                    // Get payment amount from either cash or bank field
                    $additional_cash = floatval($_POST['additional_cash'] ?? 0);
                    $additional_bank = floatval($_POST['additional_bank'] ?? 0);
                    $payment_amount = max($additional_cash, $additional_bank); // Use whichever is greater
                    $payment_method = $conn->real_escape_string($_POST['payment_method'] ?? 'Cash');
                    $narration = $conn->real_escape_string($_POST['narration'] ?? '');
                    
                    // DEBUG: Log received values
                    error_log("=== SAVE_SELL BACKEND DEBUG ===");
                    error_log("sell_weight from POST: " . ($_POST['sell_weight'] ?? 'NOT SET'));
                    error_log("gold_weight parsed: " . $gold_weight);
                    error_log("amount from POST: " . ($_POST['amount'] ?? 'NOT SET'));
                    error_log("gold_amount parsed: " . $gold_amount);
                    error_log("additional_cash: " . $additional_cash);
                    error_log("additional_bank: " . $additional_bank);
                    error_log("payment_amount: " . $payment_amount);
                    error_log("================================");

                    // Validation
                    if (empty($receipt_id) || empty($party_id) || $gold_weight <= 0) {
                         throw new Exception("Required fields missing or invalid values");
                    }
                    
                    // Fetch Party Data and Balance
                    $party_sql = "SELECT party_name, current_balance, cash_balance, bank_balance FROM parties WHERE id = $party_id AND company_id = $company_id";
                    $party_result = $conn->query($party_sql);
                    if (!$party_result || $party_result->num_rows === 0) {
                        throw new Exception("Party not found");
                    }
                    $party_data = $party_result->fetch_assoc();
                    $party_name = $party_data['party_name'];
                    $current_balance_before = floatval($party_data['current_balance']);
                    $cash_balance_before = floatval($party_data['cash_balance']);
                    $bank_balance_before = floatval($party_data['bank_balance']);

                    // Calculate Balance Change
                    // When Selling:
                    // Company sells Gold (Value: $gold_amount) -> Party owes Company +$gold_amount (Debit Party)
                    // Company receives Payment ($payment_amount) -> Party owes Company -$payment_amount (Credit Party)
                    // Net Balance Change = $gold_amount - $payment_amount
                    // current_balance represents "Amount Party Owes Company" (Positive = Debit Balance)
                    
                    $balance_change = $gold_amount - $payment_amount;
                    $current_balance_after = $current_balance_before + $balance_change;
                    
                    // Determine payment status for the Sale transaction
                    if ($payment_amount >= $gold_amount) {
                        $payment_status = 'Paid';
                    } elseif ($payment_amount > 0) {
                        $payment_status = 'Partial';
                    } else {
                        $payment_status = 'Due';
                    }
                    
                    // Set payment_method to NULL if no payment was made
                    $sale_payment_method = ($payment_amount > 0) ? $payment_method : null;
                    
                    // Insert Sale Transaction (with payment reference for tracking)
                    $sql = "INSERT INTO transactions (
                        company_id, party_id, receipt_id, transaction_type, date_of_transaction,
                        gold_weight, purity, rate, gold_amount, payment_amount, payment_method, payment_status,
                        party_balance_before, party_balance_after, narration
                    ) VALUES (
                        ?, ?, ?, 'Sale', ?,
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?
                    )";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("iissdddddssddss", 
                        $company_id, $party_id, $receipt_id, $date_of_transaction,
                        $gold_weight, $purity, $rate, $gold_amount, $payment_amount, $sale_payment_method, $payment_status,
                        $current_balance_before, $current_balance_after, $narration
                    );
                    
                    if (!$stmt->execute()) {
                        throw new Exception("Failed to save transaction: " . $stmt->error);
                    }
                    $transaction_id = $stmt->insert_id;

                    // Update Party Balance
                    $new_current = $current_balance_after; // Calculated above
                    
                    if ($payment_method == 'Cash') {
                         $new_cash = $cash_balance_before + $balance_change;
                         $update_sql = "UPDATE parties SET current_balance = ?, cash_balance = ? WHERE id = ?";
                         $upd = $conn->prepare($update_sql);
                         $upd->bind_param("ddi", $new_current, $new_cash, $party_id);
                    } else {
                         $new_bank = $bank_balance_before + $balance_change;
                         $update_sql = "UPDATE parties SET current_balance = ?, bank_balance = ? WHERE id = ?";
                         $upd = $conn->prepare($update_sql);
                         $upd->bind_param("ddi", $new_current, $new_bank, $party_id);
                    }
                    $upd->execute();
                    
                    // Update Gold Stock (Decrease when selling)
                    $stock_sql = "SELECT id, current_stock FROM gold_stock WHERE purity = ? AND company_id = ? ORDER BY id DESC LIMIT 1";
                    $stock_stmt = $conn->prepare($stock_sql);
                    $stock_stmt->bind_param("di", $purity, $company_id);
                    $stock_stmt->execute();
                    $stock_res = $stock_stmt->get_result();
                    if ($stock_res->num_rows > 0) {
                        $stock_row = $stock_res->fetch_assoc();
                        $new_stock = $stock_row['current_stock'] - $gold_weight;
                        $upd_stock = $conn->prepare("UPDATE gold_stock SET current_stock = ?, last_updated = NOW() WHERE id = ?");
                        $upd_stock->bind_param("di", $new_stock, $stock_row['id']);
                        $upd_stock->execute();
                    } else {
                        // Stock not found, maybe create negative? Or insert new.
                        $new_stock = -$gold_weight;
                         $ins_stock = $conn->prepare("INSERT INTO gold_stock (company_id, purity, current_stock, last_updated) VALUES (?, ?, ?, NOW())");
                         $ins_stock->bind_param("idd", $company_id, $purity, $new_stock);
                         $ins_stock->execute();
                    }
                    
                    // Create separate "Received" transaction entry if payment was made
                    // Following the same pattern as gold_exchange.php
                    require_once __DIR__ . '/handlers/account_balance_helper.php';
                    
                    if ($payment_amount > 0) {
                        $user_id = $_SESSION['user_id'];
                        $received_receipt_id = 'RCV-' . $receipt_id . '-' . rand(1000, 9999);
                        $received_narration = "Payment received for Sale " . $receipt_id;
                        
                        $received_sql = "INSERT INTO transactions (
                            company_id, user_id, receipt_id, party_id, date_of_transaction,
                            transaction_type, payment_type, payment_method, payment_amount,
                            narration, payment_status, due_amount, amount
                        ) VALUES (?, ?, ?, ?, ?, 'Received', 'Payment_In', ?, ?, ?, 'Paid', 0, ?)";
                        
                        $received_stmt = $conn->prepare($received_sql);
                        $received_stmt->bind_param(
                            "iisissdsd",
                            $company_id, $user_id, $received_receipt_id, $party_id, $date_of_transaction,
                            $payment_method, $payment_amount, $received_narration, $payment_amount
                        );
                        
                        if (!$received_stmt->execute()) {
                            throw new Exception("Failed to save payment received transaction: " . $received_stmt->error);
                        }
                        
                        // Update Account Balance (Shop's Physical Cash/Bank)
                        // We Received Payment -> Shop Balance Increases (+)
                        if ($payment_method == 'Cash') {
                             updateAccountBalance($conn, $company_id, 'Cash', $payment_amount);
                        } elseif (in_array($payment_method, ['Bank', 'UPI', 'Cheque', 'NEFT', 'RTGS'])) {
                             updateAccountBalance($conn, $company_id, 'Bank', $payment_amount);
                        }
                    }

                    $conn->commit();
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Sale saved successfully',
                        'data' => [
                             'transaction_id' => $transaction_id,
                             'receipt_id' => $receipt_id,
                             'party_name' => $party_name,
                             'date_of_transaction' => $date_of_transaction,
                             'gold_weight' => $gold_weight,
                             'purity' => $purity,
                             'rate' => $rate,
                             'gold_amount' => $gold_amount,
                             'payment_amount' => $payment_amount,
                             'payment_method' => $payment_method,
                             'payment_status' => $payment_status
                        ]
                    ]);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
                exit;
                
            case 'get_sell_list':
                try {
                    $sql = "SELECT t.*, p.party_name, p.contact_no as party_contact 
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
                            'narration' => $row['narration'] ?? ''
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
                    $sell_id = intval($_POST['sell_id'] ?? 0);
                    $receipt_id = $conn->real_escape_string($_POST['receipt_id'] ?? '');
                    
                    if ($sell_id <= 0 && empty($receipt_id)) {
                        throw new Exception("Invalid sell ID or receipt ID");
                    }
                    
                    $sql = "SELECT t.*, p.party_name, p.contact_no as party_contact, p.address as party_address
                           FROM transactions t 
                           LEFT JOIN parties p ON t.party_id = p.id
                           WHERE t.company_id = ? AND t.transaction_type = 'Sale'";
                    
                    if ($sell_id > 0) {
                        $sql .= " AND t.id = ?";
                        $stmt = $conn->prepare($sql);
                        if (!$stmt) {
                            throw new Exception("Failed to prepare statement: " . $conn->error);
                        }
                        $stmt->bind_param("ii", $company_id, $sell_id);
                    } else {
                        $sql .= " AND t.receipt_id = ?";
                        $stmt = $conn->prepare($sql);
                        if (!$stmt) {
                            throw new Exception("Failed to prepare statement: " . $conn->error);
                        }
                        $stmt->bind_param("is", $company_id, $receipt_id);
                    }
                    
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows === 0) {
                        throw new Exception("Sale transaction not found");
                    }
                    
                    $transaction = $result->fetch_assoc();
                    
                    // Convert to array format expected by frontend
                    echo json_encode([
                        'status' => 'success',
                        'receipt_id' => $transaction['receipt_id'],
                        'party_name' => $transaction['party_name'],
                        'date_of_transaction' => $transaction['date_of_transaction'],
                        'gold_weight' => $transaction['gold_weight'],
                        'purity' => $transaction['purity'],
                        'rate' => $transaction['rate'],
                        'gold_amount' => $transaction['gold_amount'],
                        'payment_type' => $transaction['payment_type'] ?? 'Cash',
                        'payment_amount' => $transaction['payment_amount'] ?? 0,
                        'additional_cash' => $transaction['additional_cash'] ?? 0,
                        'additional_bank' => $transaction['additional_bank'] ?? 0,
                        'bank_payment_type' => $transaction['bank_payment_type'] ?? '',
                        'narration' => $transaction['narration'] ?? '',
                        'party_contact' => $transaction['party_contact'] ?? '',
                        'party_address' => $transaction['party_address'] ?? '',
                        'party_contact' => $transaction['party_contact'] ?? '',
                        'party_address' => $transaction['party_address'] ?? '',
                        'transaction_type' => $transaction['transaction_type'],
                        'party_id' => $transaction['party_id'] // Added party_id for edit functionality
                    ]);
                    $stmt->close();
                } catch (Exception $e) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }
                exit;
                
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
                
            case 'update_sale':
                // For update, we'll delete the old transaction and let the form submit create a new one
                // This is simpler and reuses the save_sell logic
                try {
                    $conn->begin_transaction();
                    require_once __DIR__ . '/handlers/account_balance_helper.php';
                    
                    $original_receipt_id = trim($conn->real_escape_string($_POST['original_receipt_id'] ?? ''));
                    
                    if (empty($original_receipt_id)) {
                        throw new Exception("Original receipt ID is required");
                    }
                    
                    // Get original transaction details for rollback
                    $original_sql = "SELECT * FROM transactions WHERE receipt_id = ? AND company_id = ? AND transaction_type = 'Sale'";
                    $original_stmt = $conn->prepare($original_sql);
                    $original_stmt->bind_param("si", $original_receipt_id, $company_id);
                    $original_stmt->execute();
                    $original_result = $original_stmt->get_result();
                    
                    if ($original_result->num_rows === 0) {
                        // Fallback: Try with or without # if not found
                        // Sometimes receipt_id is stored clean but passed decorated, or vice versa
                        // Try removing # and spaces
                        $clean_id = str_replace(['#', ' '], '', $original_receipt_id);
                         $original_stmt->bind_param("si", $clean_id, $company_id);
                         $original_stmt->execute();
                         $original_result = $original_stmt->get_result();
                         
                         if ($original_result->num_rows === 0) {
                             throw new Exception("Original sale transaction not found for ID: " . $original_receipt_id);
                         }
                    }
                    
                    $original_transaction = $original_result->fetch_assoc();
                    $original_party_id = $original_transaction['party_id'];
                    $original_weight = $original_transaction['gold_weight'];
                    $original_amount = $original_transaction['gold_amount'];
                    $original_purity = $original_transaction['purity'];
                    $original_booking_type = $original_transaction['booking_type'] ?? 'Cash';
                    
                    // First, revert account balances for linked "Received" transactions
                    $get_linked_sql = "SELECT payment_amount, payment_method, transaction_type FROM transactions WHERE company_id = ? AND narration LIKE ? AND transaction_type = 'Received'";
                    $linked_pattern = "%Payment received for Sale " . $original_receipt_id . "%";
                    $get_linked_stmt = $conn->prepare($get_linked_sql);
                    $get_linked_stmt->bind_param("is", $company_id, $linked_pattern);
                    $get_linked_stmt->execute();
                    $linked_result = $get_linked_stmt->get_result();
                    
                    while ($old_linked = $linked_result->fetch_assoc()) {
                        $old_amt = floatval($old_linked['payment_amount']);
                        $old_method = $old_linked['payment_method'];
                        
                        // Reversal Logic: If it was Received (we got money), we remove it (Subtract).
                        $reversal_amt = -$old_amt;
                        
                        if ($old_method === 'Cash') {
                           updateAccountBalance($conn, $company_id, 'Cash', $reversal_amt);
                        } elseif (in_array($old_method, ['Bank', 'UPI', 'Cheque', 'NEFT', 'RTGS'])) {
                           updateAccountBalance($conn, $company_id, 'Bank', $reversal_amt);
                        }
                    }
                    
                    // Delete all related transactions (payments, advance settlements, received)
                    $delete_related_sql = "DELETE FROM transactions WHERE narration LIKE ? AND company_id = ?";
                    $search_pattern = "%{$original_receipt_id}%";
                    $delete_stmt = $conn->prepare($delete_related_sql);
                    $delete_stmt->bind_param("si", $search_pattern, $company_id);
                    $delete_stmt->execute();
                    
                    // Delete original sale transaction
                    $delete_sale_sql = "DELETE FROM transactions WHERE receipt_id = ? AND company_id = ? AND transaction_type = 'Sale'";
                    $delete_sale_stmt = $conn->prepare($delete_sale_sql);
                    $delete_sale_stmt->bind_param("si", $original_receipt_id, $company_id);
                    $delete_sale_stmt->execute();
                    
                    // Rollback party balances - reverse the original sale impact
                    if ($original_booking_type == 'Cash') {
                        $rollback_sql = "UPDATE parties SET cash_balance = cash_balance - ? WHERE id = ?";
                    } else {
                        $rollback_sql = "UPDATE parties SET bank_balance = bank_balance - ? WHERE id = ?";
                    }
                    $rollback_stmt = $conn->prepare($rollback_sql);
                    $rollback_stmt->bind_param("di", $original_amount, $original_party_id);
                    $rollback_stmt->execute();
                    
                    // Update current_balance
                    $update_current_sql = "UPDATE parties SET current_balance = cash_balance + bank_balance WHERE id = ?";
                    $update_current_stmt = $conn->prepare($update_current_sql);
                    $update_current_stmt->bind_param("i", $original_party_id);
                    $update_current_stmt->execute();
                    
                    // Rollback gold stock (add back the sold gold)
                    $rollback_stock_sql = "UPDATE gold_stock SET current_stock = current_stock + ? WHERE purity = ? AND company_id = ? ORDER BY id DESC LIMIT 1";
                    $rollback_stock_stmt = $conn->prepare($rollback_stock_sql);
                    $rollback_stock_stmt->bind_param("ddi", $original_weight, $original_purity, $company_id);
                    $rollback_stock_stmt->execute();
                    
                    $conn->commit();
                    
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Original sale transaction rolled back successfully'
                    ]);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }
                exit;
                
            case 'delete_sale':
                $conn->begin_transaction();
                try {
                    require_once __DIR__ . '/handlers/account_balance_helper.php';
                    
                    $sale_id = intval($_POST['sale_id'] ?? 0);
                    $receipt_id = $conn->real_escape_string($_POST['receipt_id'] ?? '');
                    
                    if ($sale_id <= 0 && empty($receipt_id)) {
                        throw new Exception("Invalid sale ID or receipt ID");
                    }
                    
                    // Get sale details first
                    $sale_sql = "SELECT * FROM transactions WHERE (id = ? OR receipt_id = ?) AND transaction_type = 'Sale' AND company_id = ?";
                    $sale_stmt = $conn->prepare($sale_sql);
                    if ($sale_id > 0) {
                        $sale_stmt->bind_param("isi", $sale_id, $receipt_id, $company_id);
                    } else {
                        $sale_stmt->bind_param("ssi", $receipt_id, $receipt_id, $company_id);
                    }
                    $sale_stmt->execute();
                    $sale_result = $sale_stmt->get_result();
                    
                    if ($sale_result->num_rows === 0) {
                        throw new Exception("Sale transaction not found");
                    }
                    
                    $sale = $sale_result->fetch_assoc();
                    $sale_receipt_id = $sale['receipt_id'];
                    $sale_party_id = $sale['party_id'];
                    $sale_weight = $sale['gold_weight'];
                    $sale_amount = $sale['gold_amount'];
                    $sale_purity = $sale['purity'];
                    $sale_booking_type = $sale['booking_type'] ?? 'Cash';
                    
                    // First, revert account balances for linked "Received" transactions
                    $get_linked_sql = "SELECT payment_amount, payment_method, transaction_type FROM transactions WHERE company_id = ? AND narration LIKE ? AND transaction_type = 'Received'";
                    $linked_pattern = "%Payment received for Sale " . $sale_receipt_id . "%";
                    $get_linked_stmt = $conn->prepare($get_linked_sql);
                    $get_linked_stmt->bind_param("is", $company_id, $linked_pattern);
                    $get_linked_stmt->execute();
                    $linked_result = $get_linked_stmt->get_result();
                    
                    while ($old_linked = $linked_result->fetch_assoc()) {
                        $old_amt = floatval($old_linked['payment_amount']);
                        $old_method = $old_linked['payment_method'];
                        
                        // Reversal Logic: If it was Received (we got money), we remove it (Subtract).
                        $reversal_amt = -$old_amt;
                        
                        if ($old_method === 'Cash') {
                           updateAccountBalance($conn, $company_id, 'Cash', $reversal_amt);
                        } elseif (in_array($old_method, ['Bank', 'UPI', 'Cheque', 'NEFT', 'RTGS'])) {
                           updateAccountBalance($conn, $company_id, 'Bank', $reversal_amt);
                        }
                    }
                    
                    // Delete all linked payment transactions (Received, Payment, Advance_Settlement)
                    $delete_payments = "DELETE FROM transactions 
                                      WHERE narration LIKE ? 
                                      AND transaction_type IN ('Payment', 'Advance_Settlement', 'Received') 
                                      AND company_id = ?";
                    $search_pattern = "%{$sale_receipt_id}%";
                    $del_pay_stmt = $conn->prepare($delete_payments);
                    $del_pay_stmt->bind_param("si", $search_pattern, $company_id);
                    $del_pay_stmt->execute();
                    
                    // Rollback party balances
                    if ($sale_booking_type == 'Cash') {
                        $rollback_sql = "UPDATE parties SET cash_balance = cash_balance - ? WHERE id = ?";
                    } else {
                        $rollback_sql = "UPDATE parties SET bank_balance = bank_balance - ? WHERE id = ?";
                    }
                    $rollback_stmt = $conn->prepare($rollback_sql);
                    $rollback_stmt->bind_param("di", $sale_amount, $sale_party_id);
                    $rollback_stmt->execute();
                    
                    // Update current_balance
                    $update_current_sql = "UPDATE parties SET current_balance = cash_balance + bank_balance WHERE id = ?";
                    $update_current_stmt = $conn->prepare($update_current_sql);
                    $update_current_stmt->bind_param("i", $sale_party_id);
                    $update_current_stmt->execute();
                    
                    // Rollback gold stock (add back the sold gold)
                    $rollback_stock_sql = "UPDATE gold_stock SET current_stock = current_stock + ? WHERE purity = ? AND company_id = ? ORDER BY id DESC LIMIT 1";
                    $rollback_stock_stmt = $conn->prepare($rollback_stock_sql);
                    $rollback_stock_stmt->bind_param("ddi", $sale_weight, $sale_purity, $company_id);
                    $rollback_stock_stmt->execute();
                    
                    // Delete the sale transaction itself
                    $delete_sale = "DELETE FROM transactions WHERE receipt_id = ? AND company_id = ?";
                    $del_sale_stmt = $conn->prepare($delete_sale);
                    $del_sale_stmt->bind_param("si", $sale_receipt_id, $company_id);
                    $del_sale_stmt->execute();
                    
                    $conn->commit();
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Sale deleted successfully'
                    ]);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }
                exit;
                
            case 'save_party':
                $party_name = $conn->real_escape_string($_POST['party_name']);
                $address = $conn->real_escape_string($_POST['address']);
                $contact_no = $conn->real_escape_string($_POST['contact_no']);
                
                $sql = "INSERT INTO parties (company_id, party_name, address, contact_no) VALUES (?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("isss", $company_id, $party_name, $address, $contact_no);
                
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
                
        }
    }
}

function formatIndianNumber($num) {
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

// Enhanced statistics SQL query for sell page
$stats_sql = "
SELECT 
    SUM(CASE WHEN transaction_type = 'Booking' THEN gold_weight ELSE 0 END) AS total_booking_weight,
    SUM(CASE WHEN transaction_type = 'Sale' THEN gold_weight ELSE 0 END) AS total_sale_weight,
    SUM(CASE WHEN transaction_type = 'Purchase' THEN gold_weight ELSE 0 END) AS total_purchase_weight,
    SUM(gold_amount) AS total_amount,
    COUNT(DISTINCT party_id) AS total_parties,
    COUNT(*) AS total_transactions,
    SUM(CASE WHEN payment_type = 'Payment_In' THEN payment_amount ELSE 0 END) AS total_paid_amount,
    SUM(CASE WHEN payment_type = 'Payment_Out' THEN payment_amount ELSE 0 END) AS total_due_amount,
    
    -- Payment method breakdown
    SUM(CASE WHEN transaction_type = 'Received' AND payment_method = 'Cash' THEN payment_amount ELSE 0 END) AS total_cash_received,
    SUM(CASE WHEN transaction_type = 'Received' AND payment_method = 'Bank' THEN payment_amount ELSE 0 END) AS total_bank_received,
    SUM(CASE WHEN transaction_type = 'Received' AND payment_method = 'UPI' THEN payment_amount ELSE 0 END) AS total_upi_received,
    SUM(CASE WHEN transaction_type = 'Received' AND payment_method = 'Cheque' THEN payment_amount ELSE 0 END) AS total_cheque_received,
    
    -- Booking type breakdown
    SUM(CASE WHEN transaction_type = 'Booking' AND booking_type = 'Cash' THEN gold_weight ELSE 0 END) AS total_cash_booking_weight,
    SUM(CASE WHEN transaction_type = 'Booking' AND booking_type = 'Bank' THEN gold_weight ELSE 0 END) AS total_bank_booking_weight,
    SUM(CASE WHEN transaction_type = 'Booking' AND booking_type = 'Cash' THEN gold_amount ELSE 0 END) AS total_cash_booking_amount,
    SUM(CASE WHEN transaction_type = 'Booking' AND booking_type = 'Bank' THEN gold_amount ELSE 0 END) AS total_bank_booking_amount
FROM transactions
WHERE DATE(date_of_transaction) = CURRENT_DATE AND company_id = $company_id";

$stats_result = $conn->query($stats_sql);
if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
} else {
    $stats = [
        'total_booking_weight' => 0,
        'total_sale_weight' => 0,
        'total_purchase_weight' => 0,
        'total_amount' => 0,
        'total_parties' => 0,
        'total_transactions' => 0,
        'total_paid_amount' => 0,
        'total_due_amount' => 0,
        'total_cash_received' => 0,
        'total_bank_received' => 0,
        'total_upi_received' => 0,
        'total_cheque_received' => 0,
        'total_cash_booking_weight' => 0,
        'total_bank_booking_weight' => 0,
        'total_cash_booking_amount' => 0,
        'total_bank_booking_amount' => 0
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




// Get recent sell transactions
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$where_clause = $search ? "AND (p.party_name LIKE '%$search%' OR t.receipt_id LIKE '%$search%')" : '';

$transactions_sql = "SELECT t.*, p.party_name, p.contact_no as party_contact 
                    FROM transactions t 
                    LEFT JOIN parties p ON t.party_id = p.id
                    WHERE t.transaction_type = 'Sale' 
                    AND t.company_id = $company_id
                    $where_clause 
                    ORDER BY t.date_of_transaction DESC, t.id DESC 
                    LIMIT $offset, $limit";

$transactions = $conn->query($transactions_sql);

// Count the total number of Sale transactions
$total_sql = "SELECT COUNT(*) as count 
              FROM transactions t 
              LEFT JOIN parties p ON t.party_id = p.id
              WHERE t.transaction_type = 'Sale' 
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

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gold Selling Management</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&family=Open+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.0.18/dist/sweetalert2.min.css">
    <style>
        /* Copy all CSS from book_gold.php for consistency */
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

        /* Ensure Poppins font is used everywhere */
        input, select, textarea, button, label, .form-control, .form-select {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
        }

        table, th, td {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
        }

        h1, h2, h3, h4, h5, h6, p, span, div {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
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

        .card-header {
            background-color: white;
            border-bottom: 1px solid #eee;
            padding: 0.5rem 0.75rem;
            font-weight: 600;
            border-radius: 12px 12px 0 0 !important;
            display: flex;
            align-items: center;
            gap: 0.5rem;
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
        
        .form-control.border-success {
            border-color: var(--success) !important;
            border-width: 2px;
        }
        
        .form-control.border-success:focus {
            box-shadow: 0 0 0 0.2rem rgba(0, 184, 148, 0.25);
        }
        
        .bg-secondary-soft {
            background-color: rgba(108, 117, 125, 0.1);
        }
        
        .text-secondary {
            color: #6c757d !important;
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

        .stats-container {
            padding: 0.2rem;
            border-radius: 2px;
            margin-bottom: 1rem;
            overflow-x: auto;
        }

        .stats-row {
            display: flex;
            gap: 0.5rem;
            min-width: min-content;
        }

        .stat-card {
            background: #fff;
            border-radius: 6px;
            padding: 0.375rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            transition: all 0.2s ease;
            border: 1px solid rgba(0,0,0,0.04);
            flex: 0 0 auto;
            width: 180px;
        }

        .stat-icon {
            width: 24px;
            height: 24px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .stat-icon i {
            font-size: 0.7rem;
        }

        .stat-content {
            flex-grow: 1;
            min-width: 0;
        }

        .stat-label {
            font-size: 0.7rem;
            color: #6c757d;
            margin-bottom: 0.1rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }

        .stat-value {
            font-size: 0.9rem;
            font-weight: 600;
            color: #2d3436;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.1;
        }

        .stat-value small {
            font-size: 0.6875rem;
            font-weight: 400;
            margin-left: 0.125rem;
            color: #6c757d;
        }

        /* Color schemes */
        .bg-warning-soft { background: linear-gradient(45deg, rgba(255, 193, 7, 0.08), rgba(255, 193, 7, 0.12)); }
        .bg-primary-soft { background: linear-gradient(45deg, rgba(13, 110, 253, 0.08), rgba(13, 110, 253, 0.12)); }
        .bg-info-soft { background: linear-gradient(45deg, rgba(13, 202, 240, 0.08), rgba(13, 202, 240, 0.12)); }
        .bg-success-soft { background: linear-gradient(45deg, rgba(25, 135, 84, 0.08), rgba(25, 135, 84, 0.12)); }
        .bg-danger-soft { background: linear-gradient(45deg, rgba(220, 53, 69, 0.08), rgba(220, 53, 69, 0.12)); }
        
        /* Soft gradient backgrounds */
        .soft-gradient-green { background: linear-gradient(135deg, rgba(34, 197, 94, 0.1), rgba(34, 197, 94, 0.05)); }
        .soft-gradient-orange { background: linear-gradient(135deg, rgba(249, 115, 22, 0.1), rgba(249, 115, 22, 0.05)); }
        .soft-gradient-yellow { background: linear-gradient(135deg, rgba(234, 179, 8, 0.1), rgba(234, 179, 8, 0.05)); }
        .soft-gradient-purple { background: linear-gradient(135deg, rgba(168, 85, 247, 0.1), rgba(168, 85, 247, 0.05)); }
        .soft-gradient-blue { background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.05)); }
        .soft-gradient-teal { background: linear-gradient(135deg, rgba(20, 184, 166, 0.1), rgba(20, 184, 166, 0.05)); }

        .text-primary { color: #0284c7; }
        .text-warning { color: #d97706; }
        .text-success { color: #16a34a; }
        .text-danger { color: #dc2626; }
        .text-info { color: #0284c7; }

        .navbar {
            background: white !important;
            border-bottom: 1px solid #dee2e6;
            padding: 0.5rem 1rem;
            margin: 0;
            width: 100%;
        }

        .logo-img {
            width: 170px;
            height: 50px;
            border-radius: 8px;
        }

        .nav-link {
            padding: 0.5rem 0.75rem;
            color: #333;
            border-radius: 0.25rem;
            transition: all 0.2s ease-in-out;
        }

        .nav-link.active {
            background-color: #fcb91c;
            color: #000;
        }

        .search-box {
            position: relative;
            max-width: 250px;
        }

        .search-box .form-control {
            padding-left: 2rem;
        }

        .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            font-size: 0.875rem;
        }


        #partyList a {
            padding: 0.5rem 0.75rem;
            display: block;
            color: var(--secondary);
            text-decoration: none;
            border-bottom: 1px solid rgba(0,0,0,0.04);
            transition: all 0.2s ease;
        }
        
        #partyList a:hover {
            background-color: #F8F9FA;
        }

        #partyList a.active {
            background-color: rgba(255, 215, 0, 0.15);
        }

        /* Soft gradient backgrounds for stats cards */
        .soft-gradient-blue { background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.05)); }
        .soft-gradient-green { background: linear-gradient(135deg, rgba(34, 197, 94, 0.1), rgba(34, 197, 94, 0.05)); }
        .soft-gradient-orange { background: linear-gradient(135deg, rgba(249, 115, 22, 0.1), rgba(249, 115, 22, 0.05)); }
        .soft-gradient-purple { background: linear-gradient(135deg, rgba(168, 85, 247, 0.1), rgba(168, 85, 247, 0.05)); }
        .soft-gradient-teal { background: linear-gradient(135deg, rgba(20, 184, 166, 0.1), rgba(20, 184, 166, 0.05)); }
        .soft-gradient-yellow { background: linear-gradient(135deg, rgba(234, 179, 8, 0.1), rgba(234, 179, 8, 0.05)); }

            border-left: 3px solid var(--primary);
        }

        .readonly-field {
            background-color: #F8F9FA;
            cursor: not-allowed;
        }

        .avatar-circle {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
        }

        .form-label {
            font-weight: 500;
            margin-bottom: 0.25rem;
            font-size: 0.8rem;
        }

        .input-group-text {
            border-radius: 8px;
            border: 1px solid #DFE6E9;
            background-color: #F8F9FA;
            padding: 0.375rem 0.5rem;
            font-size: 0.8rem;
        }

        /* Responsive table styles */
        .responsive-table {
            font-size: 0.75rem;
        }
        
        .responsive-table th,
        .responsive-table td {
            padding: 0.25rem 0.125rem;
        }
        
        @media (max-width: 768px) {
            .responsive-table {
                font-size: 0.65rem;
            }
            
            .responsive-table th,
            .responsive-table td {
                padding: 0.125rem 0.0625rem;
            }
            
            .app-container {
                padding: 0;
            }
            
            .stats-container {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                padding: 1rem;
            }
            
            .stat-card {
                padding: 1rem;
            }
            
            .stat-icon {
                width: 40px;
                height: 40px;
            }
            
            .stat-value {
                font-size: 1.125rem;
            }

            .btn-group {
                flex-wrap: wrap;
            }
            
            .navbar-toggler {
                border: none;
                outline: none;
                box-shadow: none;
            }
        }
        
        @media (min-width: 769px) and (max-width: 1024px) {
            .responsive-table {
                font-size: 0.7rem;
            }
            
            .responsive-table th,
            .responsive-table td {
                padding: 0.1875rem 0.09375rem;
            }
        }
        
        @media (min-width: 1025px) {
            .responsive-table {
                font-size: 0.75rem;
            }
            
            .responsive-table th,
            .responsive-table td {
                padding: 0.25rem 0.125rem;
            }
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

        /* Ensure proper responsive behavior */
        @media (max-width: 1024px) {
            .flex.flex-col.lg\\:flex-row {
                flex-direction: column !important;
            }
            
            .flex.flex-col.lg\\:flex-row > div {
                flex: none !important;
                width: 100% !important;
                max-width: 100% !important;
            }
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

        .party-item {
            white-space: normal;
            overflow: hidden;
            word-wrap: break-word;
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
        
        /* Professional party list styling */
        .party-item {
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
        
        /* Professional input styling */
        input, select, textarea {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-weight: 400;
            letter-spacing: -0.01em;
        }
        
        /* Professional button styling */
        button {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-weight: 600;
            letter-spacing: -0.01em;
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
    
    /* Receipt Modal Styling */
    .receipt-modal .swal2-popup {
        max-width: 550px;
        padding: 0;
    }

    .receipt-html-container {
        padding: 0 !important;
        margin: 0 !important;
    }

    .receipt-container {
        background: white;
        padding: 20px;
        border-radius: 8px;
    }

    .receipt-header {
        text-align: center;
        border-bottom: 2px dashed #333;
        padding-bottom: 15px;
        margin-bottom: 15px;
    }

    .receipt-body {
        font-size: 13px;
        color: #333;
    }

    .receipt-footer {
        text-align: center;
        border-top: 2px dashed #333;
        padding-top: 15px;
        margin-top: 15px;
        font-size: 11px;
        color: #666;
    }
    </style>
</head>
<body>
        <!-- Main Content Container -->
    <div class="w-full">
        <!-- Colorful Statistics with Icons -->
        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-7 gap-2 mb-6">
            <!-- Total Booking Weight -->
            <div class="soft-gradient-blue rounded-xl p-2 shadow-sm h-full">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-blue-700 mb-0.5">Booking Weight</p>
                        <p class="text-lg font-bold text-blue-800 mb-0"><?= number_format(($stats['total_cash_booking_weight'] ?? 0) + ($stats['total_bank_booking_weight'] ?? 0), 1) ?>g</p>
                        <p class="text-xs text-blue-600 mb-0">Cash: <?= number_format($stats['total_cash_booking_weight'] ?? 0, 1) ?>g | Bank: <?= number_format($stats['total_bank_booking_weight'] ?? 0, 1) ?>g</p>
                        </div>
                    <div class="w-8 h-8 bg-blue-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-book text-white text-xs"></i>
                        </div>
                        </div>
                    </div>
                    
            <!-- Sell Weight -->
            <div class="soft-gradient-green rounded-xl p-2 shadow-sm h-full">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-green-700 mb-0.5">Sell Weight</p>
                        <p class="text-lg font-bold text-green-800 mb-0"><?= number_format($stats['total_sale_weight'] ?? 0, 1) ?>g</p>
                        <p class="text-xs text-green-600 mb-0">Gold Sold Today</p>
                        </div>
                    <div class="w-8 h-8 bg-green-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-shopping-cart text-white text-xs"></i>
                        </div>
                        </div>
                    </div>
                    
            <!-- Total Amount -->
            <div class="soft-gradient-purple rounded-xl p-2 shadow-sm h-full">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-purple-700 mb-0.5">Total Amount</p>
                        <p class="text-lg font-bold text-purple-800 mb-0">₹<?= number_format(($stats['total_cash_booking_amount'] ?? 0) + ($stats['total_bank_booking_amount'] ?? 0), 0) ?></p>
                        <p class="text-xs text-purple-600 mb-0">Cash: ₹<?= number_format($stats['total_cash_booking_amount'] ?? 0, 0) ?> | Bank: ₹<?= number_format($stats['total_bank_booking_amount'] ?? 0, 0) ?></p>
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
            <!-- Left Side - Enhanced Sell Gold Form -->
            <div class="bg-white rounded-lg shadow-md border border-gray-200" style="flex: 0 0 55%;">
                <form id="sellForm" method="POST" class="bg-white rounded-lg shadow-md border border-gray-200 overflow-hidden">
                    <input type="hidden" name="action" value="save_sell">
                    <input type="hidden" name="party_id" id="partyId">
                    
                    <!-- Section 1: Transaction Details -->
                    <div class="bg-blue-50 px-4 py-2 border-b border-blue-100">
                        <h3 class="text-sm font-bold text-blue-800 flex items-center">
                            <i class="fas fa-file-invoice mr-2"></i> Transaction Details
                        </h3>
                    </div>
                    <div class="p-3 grid grid-cols-1 md:grid-cols-3 gap-3">
                        <!-- Sale ID -->
                        <div class="relative">
                            <label class="block text-xs font-bold text-gray-700 mb-1">Sale ID</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                                    <i class="fas fa-hashtag text-sm"></i>
                                </span>
                                <input type="text" class="block w-full pl-10 pr-10 py-2.5 text-sm font-medium border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 shadow-sm" name="receipt_id" id="saleIdInput" placeholder="Search..." autocomplete="off">
                                <button type="button" class="absolute right-2 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600" id="showSaleListBtn">
                                    <i class="fas fa-history"></i>
                                </button>
                            </div>
                            <div id="saleList" class="hidden absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-xl max-h-60 overflow-y-auto"></div>
                        </div>

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
                            </div>
                            <div id="partyList" class="hidden absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-48 overflow-y-auto"></div>
                        </div>
                    </div>



                    <!-- Section 2: Weight Information -->
                    <div class="bg-yellow-50 px-4 py-2 border-t border-b border-yellow-100">
                        <h3 class="text-sm font-bold text-yellow-800 flex items-center">
                            <i class="fas fa-balance-scale mr-2"></i> Weight Information
                        </h3>
                    </div>
                    <div class="p-3 grid grid-cols-3 gap-3">
                        <!-- Weight -->
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Weight (g)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-weight text-yellow-500 text-sm"></i>
                                </span>
                                <input type="number" step="0.001" class="block w-full pl-10 pr-3 py-2.5 text-sm font-bold text-gray-900 border border-gray-300 rounded-md focus:ring-yellow-500 focus:border-yellow-500 shadow-sm bg-yellow-50" name="sell_weight" id="sellWeight" required placeholder="0.000">
                            </div>
                        </div>

                        <!-- Purity -->
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Purity (%)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-certificate text-yellow-500 text-sm"></i>
                                </span>
                                <input type="number" step="0.01" class="block w-full pl-10 pr-3 py-2.5 text-sm font-medium border border-gray-300 rounded-md focus:ring-yellow-500 focus:border-yellow-500 shadow-sm" name="purity" id="purityInput" required placeholder="Enter purity %" autocomplete="off">
                                <div id="purityList" class="hidden absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-xl max-h-48 overflow-y-auto"></div>
                            </div>
                        </div>

                        <!-- Rate -->
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Rate (₹/g)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-rupee-sign text-yellow-500 text-sm"></i>
                                </span>
                                <input type="number" step="0.01" class="block w-full pl-10 pr-3 py-2.5 text-sm font-medium border border-gray-300 rounded-md focus:ring-yellow-500 focus:border-yellow-500 shadow-sm" name="rate" id="rateInput" required placeholder="0.00">
                            </div>
                        </div>
                    </div>

                    <!-- Section 3: Payment Details -->
                    <div class="bg-green-50 px-4 py-2 border-t border-b border-green-100">
                        <h3 class="text-sm font-bold text-green-800 flex items-center">
                            <i class="fas fa-money-bill-wave mr-2"></i> Payment Details
                        </h3>
                    </div>
                    <div class="p-3 grid grid-cols-3 gap-3">
                        <!-- Amount -->
                        <div>
                            <label class="block text-xs font-bold text-green-700 mb-1">Amount (₹)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-coins text-green-600 text-sm"></i>
                                </span>
                                <input type="text" class="block w-full pl-10 pr-3 py-2.5 text-sm font-bold text-green-700 bg-green-50 border border-green-200 rounded-md shadow-sm" name="amount" id="totalAmountInput" readonly>
                            </div>
                        </div>

                        <!-- Paid Amount -->
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Paid Amount (₹)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                                    <i class="fas fa-money-bill-wave text-sm"></i>
                                </span>
                                <input type="number" step="0.01" class="block w-full pl-10 pr-3 py-2.5 text-sm font-medium border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500 shadow-sm" name="payment_amount" id="paidAmountInput" placeholder="0.00">
                            </div>
                        </div>

                        <!-- Pay Mode -->
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Pay Mode</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                                    <i class="fas fa-credit-card text-sm"></i>
                                </span>
                                <select class="block w-full pl-10 pr-8 py-2.5 text-sm font-medium border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500 shadow-sm" name="payment_method" id="payModeSelect">
                                    <option value="Cash">Cash</option>
                                    <option value="Bank Transfer">Bank</option>
                                    <option value="Cheque">Cheque</option>
                                    <option value="UPI">UPI</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Footer: Narration & Buttons -->
                    <div class="bg-gray-50 p-3 border-t border-gray-200 grid grid-cols-1 md:grid-cols-4 gap-3 items-center">
                        <div class="md:col-span-3 relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                                <i class="fas fa-comment-alt text-sm"></i>
                            </span>
                            <input type="text" class="block w-full pl-10 pr-3 py-2.5 text-sm border border-gray-300 rounded-md focus:ring-gray-500 focus:border-gray-500 shadow-sm" name="narration" placeholder="Add narration or remarks...">
                        </div>
                        <div class="flex space-x-2">
                            <button type="button" id="sellGoldBtn" class="flex-1 bg-gradient-to-r from-green-600 to-green-700 text-white text-sm font-bold py-2.5 px-4 rounded-md shadow hover:from-green-700 hover:to-green-800 transition transform hover:scale-[1.02]">
                                <i class="fas fa-save mr-2"></i>Save
                            </button>
                            <button type="button" id="updateSaleBtn" class="hidden flex-1 bg-gradient-to-r from-blue-600 to-blue-700 text-white text-sm font-bold py-2.5 px-4 rounded-md shadow hover:from-blue-700 hover:to-blue-800 transition transform hover:scale-[1.02]">
                                <i class="fas fa-edit mr-2"></i>Update
                            </button>
                            <button type="button" id="deleteSaleBtn" class="hidden px-4 py-2.5 bg-red-600 text-white text-sm font-bold rounded-md hover:bg-red-700 shadow-sm">
                                <i class="fas fa-trash"></i>
                            </button>
                            <button type="button" id="cancelEditBtn" class="hidden px-4 py-2.5 bg-gray-500 text-white text-sm font-bold rounded-md hover:bg-gray-600 shadow-sm">
                                <i class="fas fa-times"></i>
                            </button>
                            <button type="button" id="resetFormBtn" class="px-4 py-2.5 bg-white border border-gray-300 text-gray-700 text-sm font-bold rounded-md hover:bg-gray-50 shadow-sm">
                                <i class="fas fa-undo"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Debug: Make hidden fields visible -->
                    <div class="bg-red-50 p-3 border-t border-red-200">
                        <h4 class="text-xs font-bold text-red-700 mb-2">DEBUG: Hidden Field Values</h4>
                        <div class="grid grid-cols-3 gap-2">
                            <div>
                                <label class="block text-xs text-red-600">payment_status</label>
                                <input type="text" name="payment_status" value="Due" class="w-full px-2 py-1 text-xs border rounded">
                            </div>
                            <div>
                                <label class="block text-xs text-red-600">payment_type</label>
                                <input type="text" name="payment_type" id="paymentType" value="Payment_Out" class="w-full px-2 py-1 text-xs border rounded">
                            </div>
                            <div>
                                <label class="block text-xs text-red-600">additional_cash</label>
                                <input type="text" name="additional_cash" id="additionalCash" value="0" class="w-full px-2 py-1 text-xs border rounded bg-yellow-100">
                            </div>
                            <div>
                                <label class="block text-xs text-red-600">additional_bank</label>
                                <input type="text" name="additional_bank" id="additionalBank" value="0" class="w-full px-2 py-1 text-xs border rounded bg-yellow-100">
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Right Side - Enhanced Transactions List -->
            <div class="bg-white rounded-lg shadow-md border border-gray-200" style="flex: 0 0 45%;">
                <div class="bg-gradient-to-r from-green-500 to-green-600 px-4 py-2 rounded-t-lg">
                    <h2 class="text-base font-semibold text-white flex items-center">
                        <i class="fas fa-list mr-2"></i>
                        Recent Transactions
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
                                        <tr class="border-b hover:bg-gray-50 cursor-pointer selectable-row" data-receipt-id="<?= $t['receipt_id'] ?>" data-transaction="<?= base64_encode(json_encode($t)) ?>">
                                            <td class="py-2 px-1">
                                                <div class="flex items-center">
                                                    <span class="bg-green-500 text-white text-xs px-1.5 py-0.5 rounded-full mr-1 font-bold">S</span>
                                                    <div>
                                                        <div class="font-mono text-sm font-bold text-gray-900"><?= htmlspecialchars($t['receipt_id']) ?></div>
                                                        <div class="text-xs text-gray-500 border-b border-gray-300 pb-0.5"><?= date('d M Y', strtotime($t['date_of_transaction'])) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="py-2 px-1">
                                                <div class="font-semibold text-gray-900 text-sm"><?= htmlspecialchars($t['party_name']) ?></div>
                                                <?php if ($t['party_contact']): ?>
                                                    <div class="text-xs text-gray-500"><?= htmlspecialchars($t['party_contact']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-2 px-1">
                                                <div class="flex items-center">
                                                    <span class="bg-green-500 text-white text-xs px-1.5 py-0.5 rounded-full mr-1 font-bold">S</span>
                                                    <div>
                                                        <div class="text-sm font-bold text-green-600"><?= number_format($t['gold_weight'], 2) ?>g</div>
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
                                                <div class="text-sm font-bold text-green-600">₹<?= number_format($t['gold_amount'], 2) ?></div>
                                                <?php if ($t['payment_amount'] > 0): ?>
                                                    <div class="text-xs text-blue-600">Paid: ₹<?= number_format($t['payment_amount'], 2) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-2 px-1">
                                                <div class="flex items-center space-x-1">
                                                    <button type="button" class="text-blue-600 hover:text-blue-800 text-sm print-transaction" title="Print Receipt">
                                                        <i class="fas fa-print"></i>
                                                    </button>
                                                    <button type="button" class="text-green-600 hover:text-green-800" title="WhatsApp" data-id="<?= $t['id'] ?>" data-party-contact="<?= htmlspecialchars($t['party_contact']) ?>">
                                                        <i class="fab fa-whatsapp text-xs"></i>
                                                    </button>
                                                    <button type="button" class="text-blue-600 hover:text-blue-800 edit-btn" title="Edit" data-id="<?= $t['id'] ?>" data-receipt-id="<?= htmlspecialchars($t['receipt_id']) ?>">
                                                        <i class="fas fa-pencil-alt text-xs"></i>
                                                    </button>
                                                    <button type="button" class="text-red-600 hover:text-red-800 delete-btn" title="Delete" data-id="<?= $t['id'] ?>" data-receipt-id="<?= htmlspecialchars($t['receipt_id']) ?>" data-party-name="<?= htmlspecialchars($t['party_name']) ?>" data-weight="<?= $t['gold_weight'] ?>" data-amount="<?= $t['gold_amount'] ?>">
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
                                            No sales found
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
                                   class="px-3 py-2 text-sm border border-gray-300 rounded-lg <?= $i == $page ? 'bg-green-500 text-white' : 'text-gray-700 hover:bg-gray-50' ?>">
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

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="js/keyboard-navigation-generic.js"></script>
    <script src="js/createPartyQuick.js"></script>
    <script>
        // Global function to show sale success modal with receipt
        function showSaleSuccess(msg, saleData) {
            if (!window.Swal) {
                alert(msg);
                return Promise.resolve();
            }
            
            // Format date
            const saleDate = saleData?.date_of_transaction 
                ? new Date(saleData.date_of_transaction).toLocaleString('en-IN', {
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                })
                : new Date().toLocaleString('en-IN');
            
            // Calculate amounts
            const totalAmount = parseFloat(saleData?.amount || 0);
            const advanceSettlement = parseFloat(saleData?.advance_settlement || 0);
            const additionalCash = parseFloat(saleData?.additional_cash || 0);
            const additionalBank = parseFloat(saleData?.additional_bank || 0);
            const totalReceived = advanceSettlement + additionalCash + additionalBank;
            const remaining = totalAmount - totalReceived;
            
            // Get company name
            const companyName = '<?= htmlspecialchars($company_name) ?>' || 'Gold Trading Company';
            
            // Create receipt HTML
            const receiptHTML = `
                <div id="sale-receipt" class="receipt-container" style="max-width: 400px; margin: 0 auto; font-family: Arial, sans-serif;">
                    <div class="receipt-header" style="text-align: center; border-bottom: 2px dashed #333; padding-bottom: 15px; margin-bottom: 15px;">
                        <div style="font-size: 20px; font-weight: bold; margin-bottom: 5px;">${companyName}</div>
                        <div style="font-size: 12px; color: #666;">Sale Receipt</div>
                    </div>
                    
                    <div class="receipt-body" style="font-size: 13px;">
                        <div style="margin-bottom: 12px;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                <span style="color: #666;">Receipt ID:</span>
                                <span style="font-weight: bold;">${saleData?.receipt_id || 'N/A'}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                <span style="color: #666;">Date:</span>
                                <span>${saleDate}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between;">
                                <span style="color: #666;">Party:</span>
                                <span style="font-weight: bold;">${saleData?.party_name || 'N/A'}</span>
                            </div>
                        </div>
                        
                        <div style="border-top: 1px dashed #ccc; border-bottom: 1px dashed #ccc; padding: 12px 0; margin: 12px 0;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span style="color: #666;">Weight:</span>
                                <span style="font-weight: bold;">${parseFloat(saleData?.sell_weight || 0).toFixed(3)} g</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span style="color: #666;">Purity:</span>
                                <span>${parseFloat(saleData?.purity || 0).toFixed(2)}%</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span style="color: #666;">Rate:</span>
                                <span>₹${parseFloat(saleData?.rate || 0).toLocaleString('en-IN', {minimumFractionDigits: 2})}/g</span>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 12px; border-radius: 5px; margin: 12px 0;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span style="color: #666;">Total Amount:</span>
                                <span style="font-weight: bold; font-size: 16px;">₹${totalAmount.toLocaleString('en-IN', {minimumFractionDigits: 2})}</span>
                            </div>
                            ${advanceSettlement > 0 ? `
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span style="color: #666;">Advance Settled:</span>
                                <span style="color: #28a745; font-weight: bold;">₹${advanceSettlement.toLocaleString('en-IN', {minimumFractionDigits: 2})}</span>
                            </div>
                            ` : ''}
                            ${additionalCash > 0 ? `
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span style="color: #666;">Cash Received:</span>
                                <span style="color: #28a745; font-weight: bold;">₹${additionalCash.toLocaleString('en-IN', {minimumFractionDigits: 2})}</span>
                            </div>
                            ` : ''}
                            ${additionalBank > 0 ? `
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span style="color: #666;">Bank Received:</span>
                                <span style="color: #28a745; font-weight: bold;">₹${additionalBank.toLocaleString('en-IN', {minimumFractionDigits: 2})}</span>
                            </div>
                            ` : ''}
                            ${totalReceived > 0 ? `
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px; border-top: 1px solid #ddd; padding-top: 8px; margin-top: 8px;">
                                <span style="color: #666;">Total Received:</span>
                                <span style="color: #28a745; font-weight: bold;">₹${totalReceived.toLocaleString('en-IN', {minimumFractionDigits: 2})}</span>
                            </div>
                            ` : ''}
                            ${remaining > 0 ? `
                            <div style="display: flex; justify-content: space-between; border-top: 1px solid #ddd; padding-top: 8px; margin-top: 8px;">
                                <span style="color: #666;">Remaining:</span>
                                <span style="color: #dc3545; font-weight: bold; font-size: 15px;">₹${remaining.toLocaleString('en-IN', {minimumFractionDigits: 2})}</span>
                            </div>
                            ` : ''}
                        </div>
                        
                        ${saleData?.remaining_gold !== undefined ? `
                        <div style="margin-top: 12px; padding-top: 12px; border-top: 1px dashed #ccc;">
                            <div style="display: flex; justify-content: space-between;">
                                <span style="color: #666;">Remaining Gold:</span>
                                <span style="font-weight: bold;">${parseFloat(saleData.remaining_gold).toFixed(3)} g</span>
                            </div>
                        </div>
                        ` : ''}
                    </div>
                    
                    <div class="receipt-footer" style="text-align: center; border-top: 2px dashed #333; padding-top: 15px; margin-top: 15px; font-size: 11px; color: #666;">
                        <div>Thank you for your business!</div>
                    </div>
                </div>
            `;
            
            // Store the promise from Swal.fire() so we can return it
            const swalPromise = Swal.fire({
                title: 'Sale Completed Successfully!',
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
                    sendSaleWhatsApp(saleData, companyName);
                } else if (result.isDenied) {
                    // Print receipt
                    printSaleReceipt(saleData, companyName);
                }
                return result;
            });
            
            return swalPromise;
        }
        
        // Send sale receipt via WhatsApp
        function sendSaleWhatsApp(saleData, companyName) {
            if (!saleData?.party_contact) {
                Swal.fire('Error', 'Party contact number not available', 'error');
                return;
            }
            
            const totalAmount = parseFloat(saleData.amount || 0);
            const advanceSettlement = parseFloat(saleData.advance_settlement || 0);
            const additionalCash = parseFloat(saleData.additional_cash || 0);
            const additionalBank = parseFloat(saleData.additional_bank || 0);
            const totalReceived = advanceSettlement + additionalCash + additionalBank;
            const remaining = totalAmount - totalReceived;
            
            const message = `*${companyName}*\n` +
                `*Sale Receipt*\n\n` +
                `Receipt ID: *${saleData.receipt_id}*\n` +
                `Date: ${new Date(saleData.date_of_transaction || new Date()).toLocaleString('en-IN')}\n` +
                `Party: *${saleData.party_name}*\n\n` +
                `Weight: ${parseFloat(saleData.sell_weight).toFixed(3)} g\n` +
                `Purity: ${parseFloat(saleData.purity).toFixed(2)}%\n` +
                `Rate: ₹${parseFloat(saleData.rate).toLocaleString('en-IN', {minimumFractionDigits: 2})}/g\n\n` +
                `Total Amount: *₹${totalAmount.toLocaleString('en-IN', {minimumFractionDigits: 2})}*\n` +
                (totalReceived > 0 ? `Total Received: ₹${totalReceived.toLocaleString('en-IN', {minimumFractionDigits: 2})}\n` : '') +
                (remaining > 0 ? `Remaining: *₹${remaining.toLocaleString('en-IN', {minimumFractionDigits: 2})}*\n` : '') +
                `\nThank you for your business!`;
            
            const phoneNumber = saleData.party_contact.replace(/[\s\-\(\)]/g, '');
            const whatsappUrl = `https://wa.me/${phoneNumber}?text=${encodeURIComponent(message)}`;
            
            window.open(whatsappUrl, '_blank');
        }
        
        // Print sale receipt (thermal printer compatible)
        function printSaleReceipt(saleData, companyName) {
            // Open the thermal receipt PDF in a new tab
            if (saleData && saleData.id) {
                window.open(`print_sell_receipt.php?id=${saleData.id}`, '_blank');
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Transaction ID not available for printing'
                });
            }
        }
    </script>
    <script>
        // Global error handler
        window.addEventListener('error', function(e) {
            console.error('Global JavaScript error:', e.error);
            console.error('Error in file:', e.filename);
            console.error('Line number:', e.lineno);
        });
        
        $(document).ready(function() {
            try {
            // Generate sale ID
            function generateSaleId() {
                return new Promise((resolve, reject) => {
                    fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=generate_sale_id'
                    })
                    .then(response => response.json())
                    .then(result => {
                        if (result.status === 'success') {
                            resolve(result.sale_id);
                        } else {
                            // Fallback to client-side generation
                            const companyId = <?= $company_id ?>;
                            const serial = Math.floor(Math.random() * 999) + 1;
                            resolve(`S${companyId}${serial.toString().padStart(3, '0')}`);
                        }
                    })
                    .catch(error => {
                        // Fallback to client-side generation
                        const companyId = <?= $company_id ?>;
                        const serial = Math.floor(Math.random() * 999) + 1;
                        resolve(`S${companyId}${serial.toString().padStart(3, '0')}`);
                    });
                });
            }
            
            // Set initial values
            async function initializeForm() {
                try {
                    const saleId = await generateSaleId();
                    $('#saleIdInput').val(saleId);
                } catch (error) {
                    console.error('Error generating sale ID:', error);
                    // Fallback to client-side generation
                    const companyId = <?= $company_id ?>;
                    const serial = Math.floor(Math.random() * 999) + 1;
                    $('#saleIdInput').val(`S${companyId}${serial.toString().padStart(3, '0')}`);
                }
                
            const now = new Date();
            now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            $('[name="date_of_transaction"]').val(now.toISOString().slice(0, 16));
            }
            
            initializeForm();
            
            // Format initial values for amount fields
            setTimeout(function() {
                $('[name="additional_cash"]').trigger('blur');
                $('[name="additional_bank"]').trigger('blur');
                $('#totalAmountInput').trigger('blur');
            }, 100);

            // Initialize keyboard navigation for sell form
            if (typeof KeyboardNavigationGeneric !== 'undefined') {
                KeyboardNavigationGeneric.init({
                    formId: 'sellForm',
                    fieldOrder: [
                        'saleIdInput',           // 1. Sale ID (readonly)
                        'date_of_transaction',   // 2. Date
                        'party_name',            // 3. Party Name
                        'sell_weight',           // 4. Weight
                        'purity',                // 5. Purity
                        'rate',                  // 6. Rate
                        'amount',                // 7. Total Amount
                        'payment_type',          // 8. Payment Type
                        'additional_cash',       // 9. Cash Received (conditional)
                        'additional_bank',       // 10. Bank Received (conditional)
                        'bank_payment_type',     // 11. Bank Payment Type
                        'narration'              // 12. Narration
                    ],
                    skipFields: [],
                    submitButtonId: 'sellGoldBtn',
                    formName: 'sell'
                });
                window.KeyboardNavigation = KeyboardNavigationGeneric; // Make globally available
            }

            // Party search functionality
            let partyListVisible = false;
            let currentIndex = -1;
            let selectedPartyName = '';
            
            // Function to update party selection status
            function updatePartySelectionStatus(isSelected) {
                if (isSelected) {
                    $('#partyNameInput').addClass('border-green-500');
                } else {
                    $('#partyNameInput').removeClass('border-green-500');
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
                            const hasBooking = parseFloat(party.available_weight) > 0;
                            const statusBadge = hasBooking 
                                ? `<div class="flex items-center space-x-1">
                                    <span class="bg-blue-500 text-white text-xs px-1.5 py-0.5 rounded-full font-bold">B</span>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">${party.available_weight}g</span>
                                   </div>` 
                                : `<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">No Booking</span>`;
                            
                            const partyItem = document.createElement('div');
                            partyItem.className = 'px-2 py-1.5 hover:bg-green-50 cursor-pointer border-b border-gray-100 last:border-b-0 transition-colors duration-150 party-item';
                            partyItem.setAttribute('data-index', index);
                            partyItem.setAttribute('data-id', party.id || '');
                            partyItem.setAttribute('data-name', party.party_name || '');
                            partyItem.setAttribute('data-address', party.address || '');
                            partyItem.setAttribute('data-booked', party.booked_weight || '0');
                            partyItem.setAttribute('data-sold', party.sold_weight || '0');
                            partyItem.setAttribute('data-id', party.id);
                            partyItem.setAttribute('data-name', party.party_name);
                            partyItem.setAttribute('data-address', party.address || ''); // Ensure address is set
                            
                            // Store data attributes for selection
                            partyItem.setAttribute('data-booked', party.booked_weight || 0);
                            partyItem.setAttribute('data-sold', party.sold_weight || 0);
                            partyItem.setAttribute('data-available', party.available_weight || 0);
                            
                            partyItem.innerHTML = `
                                <div class="font-semibold text-sm text-gray-800">${party.party_name}</div>
                                <div class="text-xs text-gray-600">${party.address || 'No address'}</div>
                            `;
                            
                            // Add click handler
                            partyItem.addEventListener('click', (e) => {
                                e.stopPropagation();
                                const partyData = {
                                    id: party.id,
                                    party_name: party.party_name,
                                    address: party.address,
                                    booked_weight: party.booked_weight,
                                    sold_weight: party.sold_weight,
                                    available_weight: party.available_weight
                                };
                                selectParty(partyData);
                            });
                            
                            partyList.append(partyItem);
                        });
                        
                        // Always append "Create New Party" at the bottom for quick access
                        if (term) {
                            const createItem = document.createElement('div');
                            createItem.className = 'px-3 py-2 hover:bg-green-50 cursor-pointer border-t border-green-200 transition-colors party-item bg-green-50';
                            createItem.innerHTML = `
                                <div class="flex items-center">
                                    <i class="fas fa-plus-circle text-green-600 mr-2"></i>
                                    <div>
                                        <div class="font-semibold text-sm text-green-700">Create New Party "${term}"</div>
                                    </div>
                                </div>
                            `;
                            createItem.onclick = function(e) {
                                e.stopPropagation();
                                createNewPartyQuick(term);
                            };
                            partyList.append(createItem);
                        }

                        if (parties.length > 0 || term) {
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
            
            // Function to show add party modal
            function showAddPartyModal(partyName, form) {
                Swal.fire({
                    title: '<div style="font-size: 18px; font-weight: 600; color: #1f2937; font-family: \'Poppins\', sans-serif;">Add New Party</div>',
                    html: `
                        <div style="padding: 8px;">
                            <div style="margin-bottom: 12px;">
                                <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 4px;">Party Name *</label>
                                <input type="text" id="newPartyName" value="${partyName}" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px; outline: none; transition: border-color 0.2s;" placeholder="Enter party name">
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 4px;">Address</label>
                                <textarea id="newPartyAddress" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px; outline: none; transition: border-color 0.2s; resize: vertical; min-height: 50px;" placeholder="Enter party address (optional)"></textarea>
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 4px;">Contact Number</label>
                                <input type="tel" id="newPartyContact" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px; outline: none; transition: border-color 0.2s;" placeholder="Enter contact number (optional)">
                            </div>
                            ${partyName ? `
                            <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 4px; padding: 8px; margin-bottom: 12px;">
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <div style="width: 16px; height: 16px; background: #3b82f6; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 10px;">ℹ</div>
                                    <div style="font-size: 12px; color: #1e40af;">Party "${partyName}" not found. Please fill in the details to create a new party.</div>
                                </div>
                            </div>
                            ` : ''}
                        </div>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Create Party',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#059669',
                    cancelButtonColor: '#dc2626',
                    width: '400px',
                    customClass: {
                        popup: 'swal-popup-enhanced',
                        title: 'swal-title-enhanced',
                        htmlContainer: 'swal-html-enhanced',
                        confirmButton: 'swal-btn-confirm',
                        cancelButton: 'swal-btn-cancel'
                    },
                    preConfirm: () => {
                        const name = document.getElementById('newPartyName').value.trim();
                        const address = document.getElementById('newPartyAddress').value.trim();
                        const contact = document.getElementById('newPartyContact').value.trim();
                        
                        if (!name) {
                            Swal.showValidationMessage('Party name is required');
                            return false;
                        }
                        
                        return { name, address, contact };
                    },
                    didOpen: () => {
                        // Focus on party name field
                        document.getElementById('newPartyName').focus();
                        
                        // Add input event listeners for better UX
                        const nameInput = document.getElementById('newPartyName');
                        const addressInput = document.getElementById('newPartyAddress');
                        const contactInput = document.getElementById('newPartyContact');
                        
                        // Add focus/blur effects
                        [nameInput, addressInput, contactInput].forEach(input => {
                            input.addEventListener('focus', function() {
                                this.style.borderColor = '#3b82f6';
                            });
                            input.addEventListener('blur', function() {
                                this.style.borderColor = '#d1d5db';
                            });
                        });
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        const { name, address, contact } = result.value;
                        
                        // Show loading
                        Swal.fire({
                            title: 'Creating Party...',
                            text: 'Please wait while we create the new party',
                            allowOutsideClick: false,
                            showConfirmButton: false,
                            didOpen: () => {
                                Swal.showLoading();
                            }
                        });
                        
                        // Create new party
                        fetch('', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `action=save_party&party_name=${encodeURIComponent(name)}&address=${encodeURIComponent(address)}&contact_no=${encodeURIComponent(contact)}`
                        })
                        .then(response => response.json())
                        .then(result => {
                            if (result.status === 'success') {
                                // Automatically select the newly created party
                                const newParty = {
                                    id: result.party_id,
                                    party_name: name,
                                    available_weight: 0,
                                    booked_weight: 0,
                                    sold_weight: 0
                                };
                                
                                // Ensure party_id is set before selecting
                                if (newParty.id) {
                                    selectParty(newParty);
                                    
                                    // Show success message
                                    Swal.fire({
                                        icon: 'success',
                                        title: 'Party Created!',
                                        text: `Party "${name}" has been created successfully.`,
                                        confirmButtonColor: '#059669',
                                        timer: 2000,
                                        showConfirmButton: false
                                    });
                                    
                                    // If form is provided, retry form submission; otherwise just close modal
                                    if (form) {
                                        setTimeout(() => {
                                            // Retry form submission
                                            $('#sellForm').submit();
                                        }, 1000);
                                    }
                                } else {
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Error',
                                        text: 'Party created but ID not returned. Please refresh and try again.',
                                        confirmButtonColor: '#dc2626'
                                    });
                                }
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: result.message || 'Failed to create party',
                                    confirmButtonColor: '#dc2626'
                                });
                            }
                        })
                        .catch(error => {
                            console.error('Error creating party:', error);
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'Failed to create party. Please try again.',
                                confirmButtonColor: '#dc2626'
                            });
                        });
                    }
                });
            }
            
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
                
                // Get party details and show booking information
                $.post('', {
                    action: 'get_party_gold_balance',
                    party_id: party.id
                }, function(response) {
                    // Store party data for calculations
                    window.selectedPartyData = response;
                    
                    let avgRateRounded = 0;
                    
                    if (response.booked_weight > 0) {
                        // Customer has bookings - show booking summary
                        $('#bookedWeightDisplay').text(response.booked_weight.toFixed(2) + 'g');
                        $('#advanceReceivedDisplay').text('₹' + formatIndianCurrency(response.advance_received));
                        $('#remainingWeightDisplay').text(Math.max(0, response.available_weight).toFixed(2) + 'g');
                        
                        const avgRateValue = parseFloat(response.avg_rate) || 0;
                        const formattedAvgRate = avgRateValue > 0 ? avgRateValue.toFixed(2) : '0.00';
                        avgRateRounded = avgRateValue > 0 ? parseFloat(formattedAvgRate) : 0;
                        
                        // Show payment settlement section
                        $('#paymentSettlementSection').removeClass('hidden');
                        
                        // Auto-fill rate with average booking rate
                        $('#rateInput').val(formattedAvgRate);
                        recalculateTotalAmount();
                        $('#rateInfo').text('(From booking)').removeClass('hidden text-red-500 text-orange-500').addClass('text-green-500');
                        
                        // Show booking info with breakdown by type and average rate
                        let infoHTML = `
                            <div class="grid grid-cols-6 gap-2 text-center text-xs">
                                <div>
                                    <div class="text-gray-600">Total Booked</div>
                                    <div class="font-bold text-blue-600">${response.booked_weight.toFixed(2)}g</div>
                                </div>
                                <div class="border-l border-gray-300 pl-2">
                                    <div class="text-gray-600">Cash Booked</div>
                                    <div class="font-bold text-purple-600">${response.booked_weight_cash.toFixed(2)}g</div>
                                </div>
                                <div class="border-l border-gray-300 pl-2">
                                    <div class="text-gray-600">Bank Booked</div>
                                    <div class="font-bold text-indigo-600">${response.booked_weight_bank.toFixed(2)}g</div>
                                </div>
                                <div class="border-l border-gray-300 pl-2">
                                    <div class="text-gray-600">Cash Available</div>
                                    <div class="font-bold text-orange-600">${Math.max(0, response.available_weight_cash).toFixed(2)}g</div>
                                </div>
                                <div class="border-l border-gray-300 pl-2">
                                    <div class="text-gray-600">Bank Available</div>
                                    <div class="font-bold text-orange-600">${Math.max(0, response.available_weight_bank).toFixed(2)}g</div>
                                </div>
                                <div class="border-l border-gray-300 pl-2">
                                    <div class="text-gray-600">Avg Rate</div>
                                    <div class="font-bold text-green-600">₹${formattedAvgRate}</div>
                                </div>
                            </div>
                        `;
                        
                        // Add detailed booking breakdown if there are multiple bookings with different rates
                        // This shows individual booking rates when customer has multiple bookings at different rates
                        if (response.detailed_bookings && response.detailed_bookings.length > 1) {
                            let hasDifferentRates = response.detailed_bookings.some(booking => booking.rate !== response.detailed_bookings[0].rate);
                            if (hasDifferentRates) {
                                infoHTML += `
                                    <div class="mt-3 pt-2 border-t border-gray-200">
                                        <div class="text-xs text-gray-600 mb-2">Booking Details:</div>
                                        <div class="space-y-1 max-h-20 overflow-y-auto">
                                `;
                                
                                response.detailed_bookings.forEach(booking => {
                                    if (booking.remaining_weight > 0) {
                                        infoHTML += `
                                            <div class="flex justify-between text-xs">
                                                <span class="text-gray-600">${booking.receipt_id}</span>
                                                <span class="font-medium">${booking.remaining_weight.toFixed(2)}g @ ₹${booking.rate.toFixed(2)}</span>
                                            </div>
                                        `;
                                    }
                                });
                                
                                infoHTML += `
                                        </div>
                                    </div>
                                `;
                            }
                        }
                        
                        $('#infoAlert').html(infoHTML);
                        $('#quickInfoSection').removeClass('hidden');
                        
                    } else {
                        // Customer has NO bookings - hide payment settlement and booking info
                        $('#paymentSettlementSection').addClass('hidden');
                        $('#quickInfoSection').addClass('hidden');
                        $('#rateInput').off('input.manualRateCheck');
                        
                        // Clear rate - user must enter manually
                        $('#rateInput').val('');
                        recalculateTotalAmount();
                        $('#rateInfo').text('(Enter manually)').removeClass('hidden text-green-500 text-red-500').addClass('text-orange-500');
                    }
                    
                    // Add listener to detect manual rate changes
                    $('#rateInput').off('input.manualRateCheck').on('input.manualRateCheck', function() {
                        const currentRate = parseFloat($(this).val());
                        
                        if (currentRate && avgRateRounded && Math.abs(currentRate - avgRateRounded) > 0.009) {
                            $('#rateInfo').text('(Manual rate)').removeClass('text-green-500 text-orange-500').addClass('text-red-500').removeClass('hidden');
                        } else if (avgRateRounded > 0 && Math.abs(currentRate - avgRateRounded) <= 0.009) {
                            $('#rateInfo').text('(From booking)').removeClass('text-red-500 text-orange-500').addClass('text-green-500').removeClass('hidden');
                        } else if (!currentRate) {
                            $('#rateInfo').text('(Enter manually)').removeClass('text-green-500 text-red-500').addClass('text-orange-500').removeClass('hidden');
                        }
                    });
                    
                    updatePaymentSummary();
                }, 'json');
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
                        item.classList.add('bg-green-100', 'border-l-4', 'border-green-500');
                        item.classList.remove('hover:bg-green-50');
                    } else {
                        item.classList.remove('bg-green-100', 'border-l-4', 'border-green-500');
                        item.classList.add('hover:bg-green-50');
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
                if (!$(e.target).closest('#partyNameInput, #partyList').length && partyListVisible) {
                    $('#partyList').addClass('hidden');
                    partyListVisible = false;
                    currentIndex = -1;
                }
            });

            // Add New Party button click handler
            $('#addNewPartyBtn').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                showAddPartyModal('', null);
            });

            // Quick create party (auto-create from typed name)
            function createNewPartyQuick(partyName) {
                if (!partyName || partyName.trim() === '') {
                    return;
                }

                // Show loading
                const toastLoading = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timerProgressBar: true,
                });
                toastLoading.fire({
                    icon: 'info',
                    title: 'Creating party...'
                });

                // Automatically create the party with just the name
                $.ajax({
                    url: '',
                    method: 'POST',
                    data: {
                        action: 'save_party',
                        party_name: partyName.trim(),
                        address: '',
                        contact_no: ''
                    },
                    dataType: 'json',
                    success: function (response) {
                        if (response.status === 'success') {
                            // Show brief success notification
                            const Toast = Swal.mixin({
                                toast: true,
                                position: 'top-end',
                                showConfirmButton: false,
                                timer: 2000,
                                timerProgressBar: true
                            });

                            Toast.fire({
                                icon: 'success',
                                title: `Party "${partyName}" created!`
                            });

                            // Set the party name directly
                            const newParty = {
                                id: response.party_id,
                                party_name: partyName.trim(),
                                address: '',
                                booked_weight: 0,
                                sold_weight: 0,
                                available_weight: 0
                            };
                            
                            // Select it
                            selectParty(newParty);
                            
                            // Focus next field (Weight)
                            $('#sellWeight').focus();
                            
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: response.message,
                                confirmButtonColor: '#dc2626'
                            });
                        }
                    },
                    error: function () {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: 'Failed to create party',
                            confirmButtonColor: '#dc2626'
                        });
                    }
                });
            }

            // Payment type switching
            $('#paymentTypeSelect').on('change', function() {
                const paymentType = $(this).val();
                
                if (paymentType === 'cash') {
                    $('#cashPaymentField').removeClass('hidden');
                    $('#bankPaymentField').addClass('hidden');
                    $('#bankMethodField').addClass('hidden');
                } else if (paymentType === 'bank') {
                    $('#cashPaymentField').addClass('hidden');
                    $('#bankPaymentField').removeClass('hidden');
                    $('#bankMethodField').removeClass('hidden');
                    // Auto-focus bank payment field when switching to bank
                    setTimeout(() => {
                        $('[name="additional_bank"]').focus();
                    }, 100);
                } else {
                    $('#cashPaymentField').addClass('hidden');
                    $('#bankPaymentField').addClass('hidden');
                    $('#bankMethodField').addClass('hidden');
                }
            });

            function recalculateTotalAmount() {
                const weight = parseFloat($('#sellWeight').val()) || 0;
                const rate = parseFloat($('#rateInput').val()) || 0;
                const amount = weight * rate;
                // Format immediately in Indian format when calculated
                $('#totalAmountInput').val(formatIndianCurrency(amount.toFixed(2)));
                updatePaymentSummary();
            }
            
            // Calculate total amount whenever weight or rate changes
            $('#sellWeight').on('input.recalculateAmount', recalculateTotalAmount);
            $('#rateInput').on('input.recalculateAmount', recalculateTotalAmount);

            // Handle Enter key to move to next field instead of submitting form
            $('#sellWeight').on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    $('[name="purity"]').focus();
                }
            });
            
            // ===== PURITY AUTOCOMPLETE FUNCTIONALITY =====
            let purityStocks = [];

            // Fetch purity stocks from server
            function fetchPurityStocks() {
                $.ajax({
                    url: 'sell_gold.php',
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
                    const stockColor = stock.current_stock > 0 ? 'text-green-600' : 'text-red-600';
                    const stockIcon = stock.current_stock > 0 ? 'fa-check-circle' : 'fa-exclamation-circle';
                    
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
            
            // Enhanced keyboard navigation for purity input
            $('#purityInput').on('keydown', function(e) {
                const purityList = $('#purityList');
                const items = $('.purity-item');
                const activeItem = $('.purity-item.bg-gray-200');
                
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
                                // Scroll into view
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
                            // Scroll into view
                            prev[0].scrollIntoView({ block: 'nearest' });
                        }
                    }
                } else if (e.key === 'Enter') {
                    if (!purityList.hasClass('hidden') && activeItem.length > 0) {
                        e.preventDefault();
                        activeItem.click();
                    } else {
                        e.preventDefault();
                        purityList.addClass('hidden');
                        $('#rateInput').focus();
                    }
                } else if (e.key === 'Escape') {
                    purityList.addClass('hidden');
                }
            });
            // ===== END PURITY AUTOCOMPLETE =====
            
            
            $('#rateInput').on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    $('#totalAmountInput').focus();
                }
            });
            
            // Total amount field - move to payment type on Enter
            $('#totalAmountInput').on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    $('#paymentTypeSelect').focus();
                }
            });
            
            // Format Total Amount field in Indian number format
            $('#totalAmountInput').on('focus', function() {
                // Remove formatting on focus for easier editing (if user wants to manually edit)
                let value = $(this).val().replace(/,/g, '');
                if (value === '' || value === '0.00' || value === '0') {
                    $(this).val('');
                } else {
                    $(this).val(value);
                }
            }).on('blur', function() {
                // Format on blur
                let value = $(this).val().replace(/,/g, '');
                if (value === '' || value === '0' || isNaN(parseFloat(value))) {
                    $(this).val('0.00');
                } else {
                    $(this).val(formatIndianCurrency(value));
                }
            }).on('input', function() {
                // Allow only numbers and decimal point (if user manually edits)
                let value = $(this).val().replace(/[^0-9.]/g, '');
                // Ensure only one decimal point
                let parts = value.split('.');
                if (parts.length > 2) {
                    value = parts[0] + '.' + parts.slice(1).join('');
                }
                // Limit to 2 decimal places
                if (parts.length === 2 && parts[1].length > 2) {
                    value = parts[0] + '.' + parts[1].substring(0, 2);
                }
                $(this).val(value);
            });
            
            // Payment type select - move to next payment field on Enter
            $('#paymentTypeSelect').on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const paymentType = $(this).val();
                    // Small delay to ensure fields are visible after change event
                    setTimeout(() => {
                        if (paymentType === 'cash') {
                            $('#additionalCash').focus();
                        } else if (paymentType === 'bank') {
                            // Focus on bank payment amount field
                            const bankField = $('[name="additional_bank"]');
                            if (bankField.length && bankField.is(':visible')) {
                                bankField.focus();
                            } else {
                                // If bank field is not visible, trigger change first
                                $(this).trigger('change');
                                setTimeout(() => {
                                    $('[name="additional_bank"]').focus();
                                }, 100);
                            }
                        } else {
                            $('[name="narration"]').focus();
                        }
                    }, 50);
                }
            });
            
            // Bank payment amount field - move to bank payment type on Enter
            $('[name="additional_bank"]').on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    $('[name="bank_payment_type"]').focus();
                }
            });
            
            // Format Bank Received field in Indian number format
            $('[name="additional_bank"]').on('focus', function() {
                // Remove formatting on focus for easier editing
                let value = $(this).val().replace(/,/g, '');
                if (value === '' || value === '0.00' || value === '0') {
                    $(this).val('');
                } else {
                    $(this).val(value);
                }
            }).on('blur', function() {
                // Format on blur
                let value = $(this).val().replace(/,/g, '');
                if (value === '' || value === '0' || isNaN(parseFloat(value))) {
                    $(this).val('0.00');
                } else {
                    $(this).val(formatIndianCurrency(value));
                }
            }).on('input', function() {
                // Allow only numbers and decimal point
                let value = $(this).val().replace(/[^0-9.]/g, '');
                // Ensure only one decimal point
                let parts = value.split('.');
                if (parts.length > 2) {
                    value = parts[0] + '.' + parts.slice(1).join('');
                }
                // Limit to 2 decimal places
                if (parts.length === 2 && parts[1].length > 2) {
                    value = parts[0] + '.' + parts[1].substring(0, 2);
                }
                $(this).val(value);
            });
            
            // Format Cash Received field in Indian number format
            $('[name="additional_cash"]').on('focus', function() {
                // Remove formatting on focus for easier editing
                let value = $(this).val().replace(/,/g, '');
                if (value === '' || value === '0.00' || value === '0') {
                    $(this).val('');
                } else {
                    $(this).val(value);
                }
            }).on('blur', function() {
                // Format on blur
                let value = $(this).val().replace(/,/g, '');
                if (value === '' || value === '0' || isNaN(parseFloat(value))) {
                    $(this).val('0.00');
                } else {
                    $(this).val(formatIndianCurrency(value));
                }
            }).on('input', function() {
                // Allow only numbers and decimal point
                let value = $(this).val().replace(/[^0-9.]/g, '');
                // Ensure only one decimal point
                let parts = value.split('.');
                if (parts.length > 2) {
                    value = parts[0] + '.' + parts.slice(1).join('');
                }
                // Limit to 2 decimal places
                if (parts.length === 2 && parts[1].length > 2) {
                    value = parts[0] + '.' + parts[1].substring(0, 2);
                }
                $(this).val(value);
            });
            
            // Bank payment type select - move to narration on Enter
            $('[name="bank_payment_type"]').on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    $('[name="narration"]').focus();
                }
            });
            
            // Cash payment field - move to narration on Enter
            $('#additionalCash').on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    $('[name="narration"]').focus();
                }
            });
            
            // Narration field - submit form on Enter
            $('[name="narration"]').on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    // Validate party before submitting
                    const partyId = $('#partyId').val();
                    if (!partyId) {
                        const partyName = $('#partyNameInput').val().trim();
                        if (partyName) {
                            showAddPartyModal(partyName, null);
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Party Not Selected',
                                text: 'Please select a party from the dropdown list or add a new party first.'
                            });
                            $('#partyNameInput').focus();
                        }
                    } else {
                        $('#sellForm').submit();
                    }
                }
            });
            
            // Submit button - handle Enter key properly
            $('#sellGoldBtn').on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    $(this).click();
                }
            });

            // Show Sale Success Modal with Print Option
            function showSaleSuccess(message, saleData) {
                return Swal.fire({
                    icon: 'success',
                    title: '<div style="font-size: 24px; font-weight: 700; color: #059669;">Sale Completed!</div>',
                    html: `
                        <div style="font-family: 'Poppins', sans-serif; text-align: left; padding: 10px;">
                            <div style="background: #f0fdf4; border: 2px solid #86efac; border-radius: 12px; padding: 16px; margin-bottom: 16px;">
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 14px;">
                                    <div>
                                        <div style="color: #6b7280; font-size: 12px; margin-bottom: 4px;">Receipt ID</div>
                                        <div style="font-weight: 600; color: #1f2937;">${saleData.receipt_id || 'N/A'}</div>
                                    </div>
                                    <div>
                                        <div style="color: #6b7280; font-size: 12px; margin-bottom: 4px;">Customer</div>
                                        <div style="font-weight: 600; color: #1f2937;">${saleData.party_name || 'N/A'}</div>
                                    </div>
                                    <div>
                                        <div style="color: #6b7280; font-size: 12px; margin-bottom: 4px;">Weight</div>
                                        <div style="font-weight: 600; color: #1f2937;">${parseFloat(saleData.gold_weight || 0).toFixed(3)}g</div>
                                    </div>
                                    <div>
                                        <div style="color: #6b7280; font-size: 12px; margin-bottom: 4px;">Purity</div>
                                        <div style="font-weight: 600; color: #1f2937;">${parseFloat(saleData.purity || 0).toFixed(2)}%</div>
                                    </div>
                                    <div>
                                        <div style="color: #6b7280; font-size: 12px; margin-bottom: 4px;">Rate</div>
                                        <div style="font-weight: 600; color: #1f2937;">₹${parseFloat(saleData.rate || 0).toLocaleString('en-IN')}/g</div>
                                    </div>
                                    <div>
                                        <div style="color: #6b7280; font-size: 12px; margin-bottom: 4px;">Total Amount</div>
                                        <div style="font-weight: 700; color: #059669; font-size: 16px;">₹${parseFloat(saleData.gold_amount || 0).toLocaleString('en-IN')}</div>
                                    </div>
                                </div>
                            </div>
                            
                            ${saleData.payment_amount > 0 ? `
                            <div style="background: #fef3c7; border: 2px solid #fbbf24; border-radius: 12px; padding: 16px;">
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 14px;">
                                    <div>
                                        <div style="color: #92400e; font-size: 12px; margin-bottom: 4px;">Payment Received</div>
                                        <div style="font-weight: 700; color: #b45309; font-size: 16px;">₹${parseFloat(saleData.payment_amount || 0).toLocaleString('en-IN')}</div>
                                    </div>
                                    <div>
                                        <div style="color: #92400e; font-size: 12px; margin-bottom: 4px;">Payment Method</div>
                                        <div style="font-weight: 600; color: #92400e;">${saleData.payment_method || 'N/A'}</div>
                                    </div>
                                    <div style="grid-column: 1 / -1;">
                                        <div style="color: #92400e; font-size: 12px; margin-bottom: 4px;">Status</div>
                                        <div style="font-weight: 600; color: #b45309;">${saleData.payment_status || 'N/A'}</div>
                                    </div>
                                </div>
                            </div>
                            ` : ''}
                        </div>
                    `,
                    showCancelButton: true,
                    confirmButtonText: '<i class="fas fa-print mr-2"></i>Print Receipt',
                    cancelButtonText: 'Close',
                    confirmButtonColor: '#059669',
                    cancelButtonColor: '#6b7280',
                    width: '500px',
                    customClass: {
                        popup: 'swal-popup-enhanced',
                        confirmButton: 'swal-btn-confirm',
                        cancelButton: 'swal-btn-cancel'
                    }
                }).then((result) => {
                    if (result.isConfirmed && saleData.transaction_id) {
                        // Open print receipt in new window
                        window.open('print_sale_receipt.php?id=' + saleData.transaction_id, '_blank');
                    }
                    return Promise.resolve();
                });
            }

            // Update payment summary
            function updatePaymentSummary() {
                // This function is no longer needed since we removed the payment settlement section
                // Keeping it for compatibility but it does nothing
            }

            // Indian number formatting
            function formatIndianNumber(input) {
                let value = input.value.replace(/[^\d]/g, '');
                if (value) {
                    let num = parseInt(value);
                    if (num >= 10000000) { // Crore
                        let crores = Math.floor(num / 10000000);
                        let lakhs = Math.floor((num % 10000000) / 100000);
                        let thousands = Math.floor((num % 100000) / 1000);
                        let hundreds = num % 1000;
                        let result = crores.toString();
                        if (lakhs > 0) result += ',' + lakhs.toString().padStart(2, '0');
                        if (thousands > 0) result += ',' + thousands.toString().padStart(2, '0');
                        if (hundreds > 0) result += ',' + hundreds.toString().padStart(3, '0');
                        input.value = result;
                    } else if (num >= 100000) { // Lakh
                        let lakhs = Math.floor(num / 100000);
                        let thousands = Math.floor((num % 100000) / 1000);
                        let hundreds = num % 1000;
                        let result = lakhs.toString();
                        if (thousands > 0) result += ',' + thousands.toString().padStart(2, '0');
                        if (hundreds > 0) result += ',' + hundreds.toString().padStart(3, '0');
                        input.value = result;
                    } else if (num >= 1000) { // Thousand
                        let thousands = Math.floor(num / 1000);
                        let hundreds = num % 1000;
                        let result = thousands.toString();
                        if (hundreds > 0) result += ',' + hundreds.toString().padStart(3, '0');
                        input.value = result;
                    } else {
                        input.value = num.toString();
                    }
                } else {
                    input.value = '';
                }
            }

            // Indian currency formatting
            function formatIndianCurrency(amount) {
                // Handle undefined, null, or invalid values
                if (amount === undefined || amount === null) {
                    return '0.00';
                }
                
                // Convert string to number and remove any commas
                let num = parseFloat(String(amount).replace(/,/g, ''));
                
                if (isNaN(num)) {
                    return '0.00';
                }
                
                num = Math.round(num * 100) / 100;
                let str = num.toFixed(2);
                let parts = str.split('.');
                let integerPart = parts[0];
                let decimalPart = parts[1];
                
                if (integerPart.length > 3) {
                    let lastThree = integerPart.slice(-3);
                    let remaining = integerPart.slice(0, -3);
                    
                    if (remaining.length > 2) {
                        let lastTwo = remaining.slice(-2);
                        let beforeLastTwo = remaining.slice(0, -2);
                        integerPart = beforeLastTwo + ',' + lastTwo + ',' + lastThree;
                    } else {
                        integerPart = remaining + ',' + lastThree;
                    }
                }
                
                return integerPart + '.' + decimalPart;
            }

            // Function to reset the sell form
            function resetSellForm() {
                // Clear customer selection
                $('#partyNameInput').val('').removeClass('border-green-500');
                $('#partyId').val('');
                $('#quickInfoSection').addClass('hidden');
                selectedPartyName = '';
                window.selectedPartyData = null;
                
                // Reset form fields
                $('#sellWeight').val('');
                $('#rateInput').val('');
                $('#totalAmountInput').val('0.00');
                $('[name="additional_cash"]').val('0.00');
                $('[name="additional_bank"]').val('0.00');
                $('[name="bank_payment_type"]').val('');
                $('[name="payment_type"]').val('bank');
                $('[name="narration"]').val('');
                
                // Format the reset values
                setTimeout(function() {
                    $('[name="additional_cash"]').trigger('blur');
                    $('[name="additional_bank"]').trigger('blur');
                    $('#totalAmountInput').trigger('blur');
                }, 50);
                
                // Reset payment type to bank
                $('#paymentTypeSelect').val('bank').trigger('change');
                
                // Generate new sale ID
                generateSaleId().then(saleId => {
                    $('#saleIdInput').val(saleId);
                }).catch(error => {
                    console.error('Error generating sale ID:', error);
                    // Fallback to client-side generation
                    const companyId = <?= $company_id ?>;
                    const serial = Math.floor(Math.random() * 999) + 1;
                    $('#saleIdInput').val(`S${companyId}${serial.toString().padStart(3, '0')}`);
                });
                
                // Reset date to current time
                const now = new Date();
                now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
                $('[name="date_of_transaction"]').val(now.toISOString().slice(0, 16));
                
                // Clear rate info
                $('#rateInfo').addClass('hidden');
                
                // Don't focus on party field here - wait for modal to close
                // Focus will be set after modal closes in showSaleSuccess
            }

            // Debug: Check if everything is loaded properly
            console.log('Sell Gold page loaded successfully');
            console.log('jQuery version:', $.fn.jquery);
            console.log('SweetAlert2 loaded:', typeof Swal !== 'undefined');
            
            // Test button click handler
            console.log('Setting up form submission handler...');
            
            // Test if the button exists and add a simple click handler
            const sellButton = $('#sellGoldBtn');
            console.log('Sell button found:', sellButton.length > 0);
            
            if (sellButton.length > 0) {
                console.log('Button element:', sellButton[0]);
            }
            
            // Button click handler - trigger form submission
            sellButton.on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                console.log('Button clicked! Triggering form submission...');
                
                // Trigger form submit event which will handle validation and submission
                $('#sellForm').trigger('submit');
            });
            
            // Form submission - prevent double submission
            let isSubmitting = false;
            $('#sellForm').on('submit', function(e) {
                try {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    // Prevent double submission
                    if (isSubmitting) {
                        console.log('Form submission already in progress, ignoring duplicate submit...');
                        return false;
                    }
                    
                    console.log('Form submitted - validation starting...');
                } catch (error) {
                    console.error('Error in form submit handler:', error);
                    isSubmitting = false;
                    return false;
                }
                
                // Capture form reference before entering callbacks
                const form = this;
                
                // Validate party selection
                const partyId = $('#partyId').val();
                const partyName = $('#partyNameInput').val().trim();
                console.log('Party ID:', partyId);
                console.log('Party name input value:', partyName);
                
                if (!partyId || partyId === '' || partyId === '0') {
                    console.log('Party ID is empty - showing add party modal');
                    if (partyName) {
                        showAddPartyModal(partyName, form);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Party Not Selected',
                            text: 'Please select a party from the dropdown list or add a new party first.'
                        });
                        $('#partyNameInput').focus();
                    }
                    return false;
                }
                
                console.log('Party validation passed - proceeding...');
                
                // Get party name for confirmation dialog
                const partyNameForConfirm = partyName || $('[name="party_name"]').val();
                const sellWeight = parseFloat($('[name="sell_weight"]').val()) || 0;
                const purity = parseFloat($('[name="purity"]').val()) || 0;
                const rate = $('[name="rate"]').val();
                // Parse formatted value (remove commas) for calculations
                const amount = ($('#totalAmountInput').val() || '0').replace(/,/g, '');
                
                // Get payment amount and method for confirmation dialog
                const paymentAmount = parseFloat($('#paidAmountInput').val() || 0);
                const paymentMethod = $('#payModeSelect').val();
                
                // Split into cash/bank based on payment method
                let cashReceived = 0;
                let bankReceived = 0;
                
                if (paymentAmount > 0) {
                    if (paymentMethod === 'Cash') {
                        cashReceived = paymentAmount;
                    } else {
                        bankReceived = paymentAmount;
                    }
                }
                
                const paymentType = $('[name="payment_type"]').val();
                
                // Check booking type balance (warning only, don't block)
                let bookingWarning = '';
                if (window.selectedPartyData && window.selectedPartyData.booked_weight > 0) {
                    let availableForType = 0;
                    let bookingTypeLabel = '';
                    
                    if (paymentType === 'cash') {
                        availableForType = Math.max(0, window.selectedPartyData.available_weight_cash || 0);
                        bookingTypeLabel = 'Cash';
                    } else if (paymentType === 'bank') {
                        availableForType = Math.max(0, window.selectedPartyData.available_weight_bank || 0);
                        bookingTypeLabel = 'Bank';
                    }
                    
                    // Show warning if exceeding available booking for this type
                    if (availableForType > 0 && sellWeight > availableForType) {
                        bookingWarning = `
                            <div style="background: #fef3c7; border: 1px solid #fbbf24; border-radius: 6px; padding: 8px; margin-top: 8px;">
                                <div style="color: #92400e; font-size: 12px; font-weight: 600;">
                                    ⚠️ Notice: Selling more than ${bookingTypeLabel} booking balance
                                </div>
                                <div style="color: #78350f; font-size: 11px; margin-top: 4px;">
                                    ${bookingTypeLabel} Available: ${availableForType.toFixed(2)}g | Selling: ${sellWeight.toFixed(2)}g
                                </div>
                            </div>
                        `;
                    } else if (availableForType === 0) {
                        bookingWarning = `
                            <div style="background: #dbeafe; border: 1px solid #60a5fa; border-radius: 6px; padding: 8px; margin-top: 8px;">
                                <div style="color: #1e40af; font-size: 12px; font-weight: 600;">
                                    ℹ️ Direct Sale (No ${bookingTypeLabel} booking found)
                                </div>
                            </div>
                        `;
                    }
                }
                
                // Show confirmation dialog
                console.log('About to show SweetAlert confirmation dialog...');
                Swal.fire({
                    title: '<div style="font-size: 20px; font-weight: 700; color: #1f2937; font-family: \'Poppins\', sans-serif;">Confirm Gold Sale</div>',
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
                                <div style="font-size: 14px; color: #1f2937; font-weight: 500;">${partyNameForConfirm}</div>
                            </div>
                            
                            <!-- Sale Details -->
                            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px; margin-bottom: 8px;">
                                <div style="display: flex; align-items: center; margin-bottom: 6px;">
                                    <div style="width: 20px; height: 20px; background: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 8px;">
                                        <i class="fas fa-shopping-cart" style="color: white; font-size: 10px;"></i>
                                    </div>
                                    <span style="font-size: 13px; font-weight: 600; color: #374151;">Sale Details</span>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 13px;">
                                    <div><span style="color: #6b7280;">Weight:</span> <span style="color: #1f2937; font-weight: 500;">${sellWeight}g</span></div>
                                    <div><span style="color: #6b7280;">Purity:</span> <span style="color: #1f2937; font-weight: 500;">${purity.toFixed(2)}%</span></div>
                                    <div><span style="color: #6b7280;">Rate:</span> <span style="color: #1f2937; font-weight: 500;">₹${parseFloat(rate).toLocaleString('en-IN')}/g</span></div>
                                    <div><span style="color: #6b7280;">Total:</span> <span style="color: #059669; font-weight: 600;">₹${parseFloat(amount).toLocaleString('en-IN')}</span></div>
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
                                    <div><span style="color: #6b7280;">Type:</span> <span style="color: #059669; font-weight: 600;">Payment_In</span></div>
                                    <div><span style="color: #6b7280;">Cash:</span> <span style="color: #1f2937; font-weight: 500;">₹${parseFloat(cashReceived).toLocaleString('en-IN')}</span></div>
                                    <div><span style="color: #6b7280;">Bank:</span> <span style="color: #1f2937; font-weight: 500;">₹${parseFloat(bankReceived).toLocaleString('en-IN')}</span></div>
                                    <div><span style="color: #6b7280;">Total:</span> <span style="color: #f59e0b; font-weight: 600;">₹${(parseFloat(cashReceived) + parseFloat(bankReceived)).toLocaleString('en-IN')}</span></div>
                                </div>
                            </div>
                            
                            <!-- Booking Warning (if any) -->
                            ${bookingWarning}
                        </div>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Complete Sale',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#059669',
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
                        // Capture form reference before entering callbacks
                        const form = document.getElementById('sellForm');
                        
                        // Explicitly read all form values
                        const sellWeight = parseFloat($('#sellWeight').val() || 0);
                        const purity = parseFloat($('#purityInput').val() || 0);
                        const rate = parseFloat($('#rateInput').val() || 0);
                        const partyId = $('#partyId').val();
                        const receiptId = $('[name="receipt_id"]').val();
                        const dateOfTransaction = $('[name="date_of_transaction"]').val();
                        const narration = $('[name="narration"]').val() || '';
                        
                        // Parse formatted values (remove commas) before sending
                        const amountValue = ($('#totalAmountInput').val() || '0').replace(/,/g, '');
                        
                        // Get payment amount and method
                        const paymentAmount = parseFloat($('#paidAmountInput').val() || 0);
                        const paymentMethod = $('#payModeSelect').val();
                        
                        // Set additional_cash or additional_bank based on payment method
                        let cashValue = '0';
                        let bankValue = '0';
                        
                        if (paymentAmount > 0) {
                            if (paymentMethod === 'Cash') {
                                cashValue = paymentAmount.toString();
                            } else {
                                // Bank, UPI, Cheque, Bank Transfer all go to additional_bank
                                bankValue = paymentAmount.toString();
                            }
                        }
                        
                        // UPDATE FORM FIELDS FIRST (before creating FormData)
                        $('#additionalCash').val(cashValue).css('background-color', cashValue !== '0' ? '#86efac' : '#fef08a');
                        $('#additionalBank').val(bankValue).css('background-color', bankValue !== '0' ? '#86efac' : '#fef08a');
                        
                        // NOW create FormData from the updated form
                        const formData = new FormData(form);
                        
                        // Override/ensure all critical values are set correctly in FormData
                        formData.set('action', 'save_sell');
                        formData.set('receipt_id', receiptId);
                        formData.set('party_id', partyId);
                        formData.set('date_of_transaction', dateOfTransaction);
                        formData.set('sell_weight', sellWeight.toString());
                        formData.set('purity', purity.toString());
                        formData.set('rate', rate.toString());
                        formData.set('amount', amountValue);
                        formData.set('additional_cash', cashValue);
                        formData.set('additional_bank', bankValue);
                        formData.set('payment_method', paymentMethod);
                        formData.set('narration', narration);
                        
                        console.log('Sending AJAX request to sell_gold.php...');
                        console.log('Weight:', sellWeight, 'Purity:', purity, 'Rate:', rate);
                        console.log('Amount:', amountValue);
                        console.log('Payment amount:', paymentAmount, 'Method:', paymentMethod);
                        console.log('Cash:', cashValue, 'Bank:', bankValue);
                        
                        // Set submitting flag before AJAX call
                        isSubmitting = true;
                        
                        $.ajax({
                            url: '',
                            method: 'POST',
                            data: formData,
                            processData: false,
                            contentType: false,
                            dataType: 'json',
                            beforeSend: function() {
                                console.log('Sending form data...');
                                // Show loading indicator
                                Swal.fire({
                                    title: 'Processing...',
                                    text: 'Please wait while we process your sale',
                                    allowOutsideClick: false,
                                    allowEscapeKey: false,
                                    didOpen: () => {
                                        Swal.showLoading();
                                    }
                                });
                            },
                            success: function(response) {
                                console.log('Server response:', response);
                                
                                if(response.status === 'success') {
                                    // Clear form first
                                    resetSellForm();
                                    
                                    // Show success modal with receipt
                                    showSaleSuccess('Sale completed successfully!', response.data || {})
                                        .then(() => {
                                            // Reset flag before reload
                                            isSubmitting = false;
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
                                    isSubmitting = false;
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Error',
                                        text: response.message || 'Failed to save sale'
                                    });
                                }
                            },
                            error: function(xhr, status, error) {
                                isSubmitting = false;
                                console.error('AJAX Error:', error);
                                console.error('Status:', status);
                                console.error('Response Text:', xhr.responseText);
                                console.error('Status Code:', xhr.status);
                                
                                let errorMessage = 'An error occurred while processing your request.';
                                
                                // Try to parse error response
                                try {
                                    const errorResponse = JSON.parse(xhr.responseText);
                                    if (errorResponse.message) {
                                        errorMessage = errorResponse.message;
                                    }
                                } catch (e) {
                                    // If response is not JSON, show a generic error with status
                                    if (xhr.status === 404) {
                                        errorMessage = 'Server endpoint not found. Please contact support.';
                                    } else if (xhr.status === 500) {
                                        errorMessage = 'Server error occurred. Please check server logs.';
                                    } else if (xhr.status === 0) {
                                        errorMessage = 'Network error. Please check your connection.';
                                    }
                                }
                                
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    html: `<p>${errorMessage}</p><small>Status: ${xhr.status}</small>`,
                                    footer: '<small>Check browser console for more details</small>'
                                });
                            }
                        });
                    } else {
                        // User cancelled - reset submission flag
                        isSubmitting = false;
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
                    cancelButtonColor: '#28a745'
                }).then((result) => {
                    if (result.isConfirmed) {
                        resetSellForm();
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

            // New party modal

            // Search functionality
            let searchTimer;
            $('#searchInput').on('input', function() {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => {
                    const searchTerm = $(this).val();
                    window.location.href = `?search=${searchTerm}`;
                }, 500);
            });


            
            // Auto-focus party name field on wide screens
            if ($(window).width() >= 992) {
                setTimeout(function() {
                    $('#partyNameInput').focus();
                }, 500);
            }
            
            
            // Handle print transaction button in recent transactions table
            $(document).on('click', '.print-transaction', function(e) {
                e.stopPropagation(); // Prevent row selection
                const tr = $(this).closest('tr');
                printSellTransaction(tr[0]);
            });
            
            function printSellTransaction(tr) {
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
                        // Decode base64 encoded JSON
                        let jsonString;
                        try {
                            // Try base64 decode first (new format)
                            jsonString = atob(transactionData);
                        } catch (e) {
                            // If base64 decode fails, try parsing as-is (old format or HTML entities)
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
                
                // If we have transaction data and it's a sell transaction, use it
                if (transaction && transaction.transaction_type === 'Sale') {
                    printSellFromData(transaction);
                    return;
                }
                
                // Otherwise, fetch sell details from server using receipt_id
                fetchSellByReceiptId(receiptId);
            }
            
            function fetchSellByReceiptId(receiptId) {
                // Show loading indicator
                Swal.fire({
                    title: 'Loading...',
                    text: 'Fetching sale details',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                // Fetch sell details from server
                $.post('', {
                    action: 'get_sell_details',
                    receipt_id: receiptId
                }, function(response) {
                    Swal.close();
                    
                    if (response.status === 'error') {
                        Swal.fire('Error', response.message || 'Failed to fetch sale details', 'error');
                        return;
                    }
                    
                    // Check if it's a sell transaction
                    if (response.transaction_type !== 'Sale') {
                        Swal.fire('Info', 'Print receipt is only available for sale transactions', 'info');
                        return;
                    }
                    
                    // Print the sell receipt
                    printSellFromData(response);
                }, 'json').fail(function(xhr, status, error) {
                    Swal.close();
                    console.error('Error fetching sell details:', error);
                    Swal.fire('Error', 'Failed to fetch sale details. Please try again.', 'error');
                });
            }
            
            function printSellFromData(transaction) {
                const companyName = window.companyName || 'Gold Trading Company';
                
                // Prepare sale data for printing
                const saleData = {
                    receipt_id: transaction.receipt_id,
                    party_name: transaction.party_name,
                    date_of_transaction: transaction.date_of_transaction,
                    gold_weight: transaction.gold_weight,
                    purity: transaction.purity,
                    rate: transaction.rate,
                    gold_amount: transaction.gold_amount,
                    payment_type: transaction.payment_type || 'Cash',
                    payment_amount: transaction.payment_amount || 0,
                    additional_cash: transaction.additional_cash || 0,
                    additional_bank: transaction.additional_bank || 0,
                    bank_payment_type: transaction.bank_payment_type || '',
                    narration: transaction.narration || ''
                };
                
                // Call the existing printSaleReceipt function
                if (typeof printSaleReceipt === 'function') {
                    printSaleReceipt(saleData, companyName);
                } else {
                    Swal.fire('Error', 'Print function not available', 'error');
                }
            }
            
            // Sale History - module for sale list dropdown (similar to BookingHistory)
            const SaleHistory = (() => {
                let listBtn, listPanel, form;
                let sales = [];

                function init() {
                    listBtn = document.getElementById('showSaleListBtn');
                    listPanel = document.getElementById('saleList');
                    form = document.getElementById('sellForm');
                    if (!(listBtn && listPanel && form)) return;
                    listBtn.addEventListener('click', showList);
                    document.getElementById('saleIdInput').addEventListener('click', showList);
                    document.addEventListener('click', (e) => {
                        if (!listPanel.contains(e.target) && e.target !== listBtn && e.target !== document.getElementById('saleIdInput')) hideList();
                    });
                }

                function showList() {
                    $.post('', {
                        action: 'get_sell_list'
                    }, function(list) {
                        sales = Array.isArray(list) ? list : [];
                        listPanel.innerHTML = '';
                        if (!sales.length) {
                            const noDiv = document.createElement('div');
                            noDiv.className = 'p-3 text-center text-gray-500';
                            noDiv.textContent = 'No previous sales.';
                            listPanel.appendChild(noDiv);
                        } else {
                            sales.forEach((s, i) => {
                                const d = document.createElement('div');
                                d.className = 'sale-item p-2 border-b hover:bg-green-100 cursor-pointer';
                                const dateStr = s.date_of_transaction ? (s.date_of_transaction.split('T')[0] || s.date_of_transaction.split(' ')[0]) : '';
                                d.innerHTML = `<b>${s.receipt_id}</b> <span class='text-xs text-gray-500'>${s.party_name || ''} · ${dateStr}</span>`;
                                d.onclick = () => selectSale(i);
                                listPanel.appendChild(d);
                            });
                        }
                        listPanel.classList.remove('hidden');
                    }, 'json').fail(function() {
                        listPanel.innerHTML = '<div class="p-3 text-center text-red-500">Error loading sales.</div>';
                        listPanel.classList.remove('hidden');
                    });
                }

                function hideList() {
                    listPanel.innerHTML = '';
                    listPanel.classList.add('hidden');
                }

                function selectSale(i) {
                    const s = sales[i];
                    if (!s) return;
                    // Fill form fields with selected sale
                    $('#saleIdInput').val(s.receipt_id);
                    $('[name="date_of_transaction"]').val(s.date_of_transaction ? s.date_of_transaction.replace(' ', 'T') : '');
                    $('#partyNameInput').val(s.party_name || '');
                    $('#partyId').val(s.party_id || '');
                    $('[name="sell_weight"]').val(s.gold_weight || '');
                    $('[name="purity"]').val(s.purity || '');
                    $('[name="rate"]').val(s.rate || '');
                    const amount = s.gold_amount || 0;
                    $('#totalAmountInput').val(formatIndianCurrency(amount));
                    
                    // Update party selection status to remove validation error
                    if (s.party_id && s.party_name) {
                        updatePartySelectionStatus(true);
                        $('#partyNameInput').removeClass('border-red-500').addClass('border-green-500');
                        // Clear any validation error messages
                        $('#partyNameInput').next('.validation-error').remove();
                    }
                    
                    // Show update/delete buttons, hide submit button
                    $('#sellGoldBtn').addClass('hidden');
                    $('#updateSaleBtn').removeClass('hidden');
                    $('#deleteSaleBtn').removeClass('hidden');
                    $('#cancelEditBtn').removeClass('hidden');
                    
                    // Store sale ID for update/delete
                    window.currentSaleId = s.id;
                    window.currentSaleReceiptId = s.receipt_id;
                    
                    // Trigger blur to format the amount
                    setTimeout(() => {
                        $('#totalAmountInput').trigger('blur');
                    }, 50);
                    hideList();
                }
                
                function resetForm() {
                    $('#sellGoldBtn').removeClass('hidden');
                    $('#updateSaleBtn').addClass('hidden');
                    $('#deleteSaleBtn').addClass('hidden');
                    $('#cancelEditBtn').addClass('hidden');
                    window.currentSaleId = null;
                    window.currentSaleReceiptId = null;
                    // Reset form will be called separately
                }
                
                // Expose resetForm for external use
                return { init, resetForm };
            })();
            
            // Make resetForm accessible globally
            window.SaleHistory = SaleHistory;

            // Initialize Sale History (already inside document.ready)
            SaleHistory.init();
            
            // Handle update sale button
            $('#updateSaleBtn').on('click', function() {
                if (!window.currentSaleId || !window.currentSaleReceiptId) {
                    Swal.fire('Error', 'No sale selected for update', 'error');
                    return;
                }
                
                // Validate form
                const partyId = $('#partyId').val();
                const partyName = $('#partyNameInput').val().trim();
                if (!partyId || !partyName) {
                    Swal.fire('Error', 'Please select a valid party', 'error');
                    return;
                }
                
                // First rollback the old transaction, then save new one
                Swal.fire({
                    title: 'Update Sale?',
                    text: 'This will update the sale transaction and adjust party balances',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, Update'
                }).then((result) => {
                    if (!result.isConfirmed) return;
                    
                    // Show loading
                    Swal.fire({
                        title: 'Updating...',
                        text: 'Please wait',
                        allowOutsideClick: false,
                        didOpen: () => Swal.showLoading()
                    });
                    
                    // Rollback old transaction
                    $.post('', {
                        action: 'update_sale',
                        original_receipt_id: window.currentSaleReceiptId
                    }, function(rollbackResponse) {
                        if (rollbackResponse.status === 'success') {
                            Swal.close();
                            // Now submit the form as a new sale (this will call save_sell)
                            $('#sellForm').trigger('submit');
                        } else {
                            Swal.fire('Error', rollbackResponse.message || 'Failed to rollback transaction', 'error');
                        }
                    }, 'json').fail(function() {
                        Swal.fire('Error', 'Failed to update sale. Please try again.', 'error');
                    });
                });
            });
            


            // Handle delete sale button (Footer button)
            $('#deleteSaleBtn').on('click', function() {
                if (!window.currentSaleId || !window.currentSaleReceiptId) {
                    Swal.fire('Error', 'No sale selected for deletion', 'error');
                    return;
                }
                
                Swal.fire({
                    title: 'Delete Sale?',
                    html: `
                        <div class="text-start">
                            <p><strong>Receipt ID:</strong> ${window.currentSaleReceiptId}</p>
                            <p>This will permanently delete the sale and reverse all related operations including:</p>
                            <ul class="list-disc list-inside">
                                <li>Main sale transaction</li>
                                <li>All payment transactions</li>
                                <li>Gold stock changes</li>
                                <li>Party balance updates</li>
                            </ul>
                        </div>
                    `,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, Delete',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (!result.isConfirmed) return;
                    
                    $.post('', {
                        action: 'delete_sale',
                        sale_id: window.currentSaleId,
                        receipt_id: window.currentSaleReceiptId
                    }, function(response) {
                        if (response.status === 'success') {
                            Swal.fire('Deleted!', 'Sale transaction deleted successfully', 'success');
                            // Reset form and reload
                            resetSellForm();
                            setTimeout(() => location.reload(), 1000);
                        } else {
                            Swal.fire('Error', response.message || 'Failed to delete sale', 'error');
                        }
                    }, 'json');
                });
            });
            
            // Handle cancel edit button
            $('#cancelEditBtn').on('click', function() {
                if (window.SaleHistory && window.SaleHistory.resetForm) {
                    window.SaleHistory.resetForm();
                }
                resetSellForm();
            });
            

            
            // Handle Edit Button Click in Table
            $(document).on('click', '.edit-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const receiptId = $(this).data('receipt-id');
                // Use the existing SaleHistory logic to switch to edit mode
                // Need to find the sale in the recent list or fetch it?
                // The sale list is separate. We can just leverage fetchSellByReceiptId or verify if we need to set specific global variables for `updateSaleBtn` to work.
                
                // Set globals
                window.currentSaleId = $(this).data('id');
                window.currentSaleReceiptId = receiptId;
                
                // Fetch details
                $.post('', {
                    action: 'get_sell_details',
                    receipt_id: receiptId
                }, function(response) {
                    if (response.status === 'error') {
                        Swal.fire('Error', 'Could not fetch sale details', 'error');
                        return;
                    }
                    // Populate Form
                    const s = response; // get_sell_details returns flattened object or data object?
                    // Previous code for `get_sell_details` returned a flat JSON object directly (lines 317-335).
                    
                    $('#saleIdInput').val(s.receipt_id);
                    $('[name="date_of_transaction"]').val(s.date_of_transaction ? s.date_of_transaction.replace(' ', 'T') : '');
                    $('#partyNameInput').val(s.party_name || '');
                    
                    // Need party ID. The response doesn't seem to verify party ID is returned in `get_sell_details`...
                    // Let's check `get_sell_details` PHP (lines 277-335). It returns `party_name`, `party_contact`... but `party_id` is NOT in the JSON echo! 
                    // I MUST FIX get_sell_details TO RETURN PARTY ID.
                    // For now, I will assume I fix it.
                    $('#partyId').val(s.party_id);
                    
                    $('[name="sell_weight"]').val(s.gold_weight);
                    $('[name="purity"]').val(s.purity);
                    $('[name="rate"]').val(s.rate);
                    $('#totalAmountInput').val(formatIndianCurrency(s.gold_amount));
                    $('[name="additional_bank"]').val(s.payment_amount); // Paid Amount
                    $('[name="payment_method"]').val(s.payment_method || 'Cash'); // Use correct field name
                    $('[name="narration"]').val(s.narration);
                    
                    // Show update buttons
                    $('#sellGoldBtn').addClass('hidden');
                    $('#updateSaleBtn').removeClass('hidden');
                    $('#deleteSaleBtn').removeClass('hidden');
                    $('#cancelEditBtn').removeClass('hidden');
                    
                    // Scroll to top
                    $('html, body').animate({ scrollTop: 0 }, 'fast');

                }, 'json');
            });

            // Handle delete transaction button
            $(document).on('click', '.delete-btn', function() {
                const transactionId = $(this).data('id');
                const receiptId = $(this).data('receipt-id');
                const partyName = $(this).data('party-name');
                const weight = $(this).data('weight');
                const amount = $(this).data('amount');
                
                // Show confirmation dialog
                Swal.fire({
                    title: 'Delete Transaction?',
                    html: `
                        <div class="text-start">
                            <p><strong>Receipt ID:</strong> ${receiptId}</p>
                            <p><strong>Customer:</strong> ${partyName}</p>
                            <p><strong>Weight:</strong> ${weight}g</p>
                            <p><strong>Amount:</strong> ₹${formatIndianCurrency(amount)}</p>
                            <hr>
                            <div class="alert alert-warning">
                                <strong>Warning:</strong> This will permanently delete the transaction and reverse all related operations including:
                                <ul class="mb-0 mt-2">
                                    <li>Main sale transaction</li>
                                    <li>All payment transactions</li>
                                    <li>Gold stock changes</li>
                                    <li>Party balance updates</li>
                                </ul>
                            </div>
                        </div>
                    `,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, Delete Transaction',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                    focusCancel: true
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Proceed with deletion
                        $.ajax({
                            url: '', // Post to self
                            method: 'POST',
                            data: {
                                action: 'delete_sale',
                                sale_id: transactionId,
                                receipt_id: receiptId
                            },
                            success: function(response) {
                                // Parse if string
                                if (typeof response === 'string') {
                                    try { response = JSON.parse(response); } catch(e){}
                                }
                                
                                if(response.status === 'success') {
                                    Swal.fire({
                                        icon: 'success',
                                        title: 'Transaction Deleted!',
                                        text: `Sale transaction ${receiptId} has been deleted successfully.`,
                                        timer: 2000,
                                        showConfirmButton: false
                                    });
                                    
                                    setTimeout(() => {
                                        location.reload();
                                    }, 2000);
                                } else {
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Error',
                                        text: response.message
                                    });
                                }
                            },
                            error: function(xhr, status, error) {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: 'An error occurred while deleting the transaction. Please try again.'
                                });
                            }
                        });
                    }
                });
            });
            } catch (error) {
                console.error('Error in document ready:', error);
                alert('JavaScript error occurred. Please check console for details.');
            }
        });
    </script>

<?php
// Capture the content
$content = ob_get_clean();

// Set page title and include layout
$page_title = "Sell gold";
include 'components/layout.php';
?>
