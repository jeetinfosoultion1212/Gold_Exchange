<?php
/**
 * Book Gold - Clean, modern implementation
 * All JavaScript is in js/book-gold.js
 */

// Start output buffering first
if (ob_get_level() > 0) {
    ob_clean();
}
ob_start();

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Set headers before any output
header("Cache-Control: no-cache, must-revalidate");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
header("Pragma: no-cache");
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_error.log');

// Load database configuration
require_once __DIR__ . '/config/database.php';

// Verify database connection
if (!isset($conn) || $conn->connect_error) {
    error_log("Database connection error in book.php: " . ($conn->connect_error ?? 'Connection object not set'));
    die('Database connection failed. Please check your database configuration.');
}

// Get user and company info with validation
if (!isset($_SESSION['company_id']) || !isset($_SESSION['user_id'])) {
    error_log("Session variables missing: company_id=" . (isset($_SESSION['company_id']) ? $_SESSION['company_id'] : 'not set') . ", user_id=" . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'not set'));
    header('Location: login.php');
    exit;
}

$company_id = intval($_SESSION['company_id']);
$user_id = intval($_SESSION['user_id']);
$company_name = $_SESSION['company_name'] ?? '';
$user_name = $_SESSION['full_name'] ?? '';

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Start output buffering to capture content
ob_start();

// ============================================
// AJAX REQUEST HANDLERS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Ensure clean output for AJAX
    if (ob_get_level()) {
        ob_clean();
    }
    
    switch ($_POST['action']) {
        case 'search_parties':
            try {
                $search = $conn->real_escape_string($_POST['term'] ?? '');
                $sql = "SELECT DISTINCT p.id, p.party_name, p.address, 
                        COALESCE(p.cash_balance, 0) as cash_balance,
                        COALESCE(p.bank_balance, 0) as bank_balance
                        FROM parties p 
                        WHERE p.company_id = ? AND p.party_name LIKE ? 
                        LIMIT 5";
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Failed to prepare statement: " . $conn->error);
                }
                $search_pattern = "%{$search}%";
                $stmt->bind_param("is", $company_id, $search_pattern);
                $stmt->execute();
                $result = $stmt->get_result();
                $parties = [];
                while ($row = $result->fetch_assoc()) {
                    // Calculate outstanding amount as sum of cash_balance and bank_balance
                    $cash_balance = floatval($row['cash_balance'] ?? 0);
                    $bank_balance = floatval($row['bank_balance'] ?? 0);
                    $outstanding_amount = $cash_balance + $bank_balance;
                    
                    $parties[] = [
                        'id' => $row['id'],
                        'party_name' => $row['party_name'],
                        'address' => $row['address'],
                        'outstanding' => max(0, $outstanding_amount) // Ensure non-negative
                    ];
                }
                echo json_encode($parties);
            } catch (Exception $e) {
                error_log("Search parties error: " . $e->getMessage());
                echo json_encode(['error' => 'Failed to search parties']);
            }
            exit;
            
        case 'save_party':
            $party_name = $conn->real_escape_string($_POST['party_name'] ?? '');
            $address = $conn->real_escape_string($_POST['address'] ?? '');
            $contact_no = $conn->real_escape_string($_POST['contact_no'] ?? '');
            $gstin = $conn->real_escape_string($_POST['gstin'] ?? '');
            $state = $conn->real_escape_string($_POST['state'] ?? '');
            $city = $conn->real_escape_string($_POST['city'] ?? '');
            $bank_details = $conn->real_escape_string($_POST['bank_details'] ?? '');
            
            $sql = "INSERT INTO parties (company_id, party_name, address, contact_no, gstin, state, city, bank_details) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("isssssss", $company_id, $party_name, $address, $contact_no, $gstin, $state, $city, $bank_details);
            
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
            
        case 'generate_booking_id':
            $prefix = "B{$company_id}";
            $lastIdSql = "SELECT receipt_id FROM transactions 
                         WHERE company_id = ? 
                         AND receipt_id LIKE '{$prefix}%' 
                         ORDER BY receipt_id DESC 
                         LIMIT 1";
            $lastIdStmt = $conn->prepare($lastIdSql);
            $lastIdStmt->bind_param("i", $company_id);
            $lastIdStmt->execute();
            $lastIdResult = $lastIdStmt->get_result();
            
            $nextSerial = 1;
            if ($lastIdResult->num_rows > 0) {
                $lastId = $lastIdResult->fetch_assoc()['receipt_id'];
                $lastSerial = intval(substr($lastId, strlen($prefix)));
                $nextSerial = $lastSerial + 1;
            }
            
            $bookingId = "{$prefix}{$nextSerial}";
            echo json_encode([
                'status' => 'success',
                'booking_id' => $bookingId
            ]);
            exit;
            
        case 'get_booking_list':
            try {
                $sql = "SELECT t.*, p.party_name, p.contact_no as party_contact 
                       FROM transactions t 
                       LEFT JOIN parties p ON t.party_id = p.id
                       WHERE t.transaction_type = 'Booking' 
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
                $bookings = [];
                while ($row = $result->fetch_assoc()) {
                    $bookings[] = [
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
                        'booking_type' => $row['booking_type'] ?? 'Cash',
                        'narration' => $row['narration'] ?? ''
                    ];
                }
                echo json_encode($bookings);
            } catch (Exception $e) {
                error_log("Get booking list error: " . $e->getMessage());
                echo json_encode(['error' => 'Failed to get booking list']);
            }
            exit;
            
        case 'get_booking_details':
            try {
                $booking_id = intval($_POST['booking_id'] ?? 0);
                $receipt_id = $conn->real_escape_string($_POST['receipt_id'] ?? '');
                
                if ($booking_id <= 0 && empty($receipt_id)) {
                    throw new Exception("Invalid booking ID or receipt ID");
                }
                
                $sql = "SELECT t.*, p.party_name, p.contact_no as party_contact, p.address as party_address
                       FROM transactions t 
                       LEFT JOIN parties p ON t.party_id = p.id
                       WHERE t.company_id = ?";
                
                if ($booking_id > 0) {
                    $sql .= " AND t.id = ?";
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        throw new Exception("Failed to prepare statement: " . $conn->error);
                    }
                    $stmt->bind_param("ii", $company_id, $booking_id);
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
                $booking = $result->fetch_assoc();
                if (!$booking) {
                    throw new Exception("Booking not found");
                }
                
                // Calculate total received for this booking
                $booking_receipt_id = $booking['receipt_id'];
                $received_sql = "SELECT COALESCE(SUM(payment_amount), 0) as total_received
                                FROM transactions 
                                WHERE narration LIKE ? 
                                AND transaction_type = 'Received' 
                                AND payment_type = 'Payment_In'
                                AND company_id = ?";
                $search_pattern = "%booking {$booking_receipt_id}%";
                $received_stmt = $conn->prepare($received_sql);
                if ($received_stmt) {
                    $received_stmt->bind_param("si", $search_pattern, $company_id);
                    $received_stmt->execute();
                    $received_result = $received_stmt->get_result();
                    $received_data = $received_result->fetch_assoc();
                    $booking['total_received'] = floatval($received_data['total_received'] ?? 0);
                    $received_stmt->close();
                } else {
                    $booking['total_received'] = 0;
                }
                
                echo json_encode($booking);
            } catch (Exception $e) {
                error_log("Get booking details error: " . $e->getMessage());
                echo json_encode(['error' => $e->getMessage()]);
            }
            exit;
            
        case 'save_booking':
            error_log("Save booking request received");
            error_log("POST data: " . print_r($_POST, true));
            
            if (ob_get_level()) {
                ob_clean();
            }
            
            // Validate required fields
            $required_fields = ['party_name', 'party_id', 'receipt_id', 'date_of_transaction', 'booking_weight', 'purity', 'rate', 'total_amount', 'booking_type'];
            foreach ($required_fields as $field) {
                if (!isset($_POST[$field]) || empty($_POST[$field])) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => "Missing required field: {$field}"
                    ]);
                    exit;
                }
            }
            
            $conn->begin_transaction();
            try {
                error_log("Starting booking save process");
                $receipt_id = $conn->real_escape_string($_POST['receipt_id']);
                $party_name = $conn->real_escape_string($_POST['party_name']);
                $party_id = intval($_POST['party_id']);
                $date_of_transaction = $conn->real_escape_string($_POST['date_of_transaction']);
                $booking_weight = floatval($_POST['booking_weight']);
                $purity = floatval($_POST['purity']);
                $rate = floatval($_POST['rate']);
                $total_amount = floatval(str_replace(['₹', ','], '', $_POST['total_amount']));
                $booking_type = $conn->real_escape_string($_POST['booking_type']);
                $cash_received = floatval($_POST['cash_received'] ?? 0);
                $bank_received = floatval($_POST['bank_received'] ?? 0);
                $bank_payment_type = $conn->real_escape_string($_POST['bank_payment_type'] ?? '');
                $narration = $conn->real_escape_string($_POST['narration'] ?? '');
                
                // Validate party_id exists
                if ($party_id <= 0) {
                    throw new Exception("Invalid party selected. Please select a valid party.");
                }
                
                // Check if party exists
                $party_check_sql = "SELECT id FROM parties WHERE id = ? AND company_id = ?";
                $party_check_stmt = $conn->prepare($party_check_sql);
                $party_check_stmt->bind_param("ii", $party_id, $company_id);
                $party_check_stmt->execute();
                $party_check_result = $party_check_stmt->get_result();
                
                if ($party_check_result->num_rows === 0) {
                    throw new Exception("Selected party does not exist. Please select a valid party.");
                }
                
                // Check if receipt_id already exists
                $receipt_check_sql = "SELECT receipt_id FROM transactions WHERE receipt_id = ?";
                $receipt_check_stmt = $conn->prepare($receipt_check_sql);
                $receipt_check_stmt->bind_param("s", $receipt_id);
                $receipt_check_stmt->execute();
                $receipt_check_result = $receipt_check_stmt->get_result();
                
                if ($receipt_check_result->num_rows > 0) {
                    // Generate a new booking ID if duplicate found
                    error_log("Duplicate booking ID found: {$receipt_id}, generating new one");
                    $prefix = "B{$company_id}";
                    
                    $lastIdSql = "SELECT receipt_id FROM transactions 
                                 WHERE company_id = ? 
                                 AND receipt_id LIKE '{$prefix}%' 
                                 ORDER BY receipt_id DESC 
                                 LIMIT 1";
                    $lastIdStmt = $conn->prepare($lastIdSql);
                    $lastIdStmt->bind_param("i", $company_id);
                    $lastIdStmt->execute();
                    $lastIdResult = $lastIdStmt->get_result();
                    
                    $nextSerial = 1;
                    if ($lastIdResult->num_rows > 0) {
                        $lastId = $lastIdResult->fetch_assoc()['receipt_id'];
                        $lastSerial = intval(substr($lastId, strlen($prefix)));
                        $nextSerial = $lastSerial + 1;
                    }
                    
                    $receipt_id = "{$prefix}{$nextSerial}";
                    error_log("Generated new booking ID: {$receipt_id}");
                }
                
                // Insert booking transaction
                $sql = "INSERT INTO transactions (
                    company_id, party_id, receipt_id, transaction_type, 
                    date_of_transaction, gold_weight, purity, rate, gold_amount, 
                    booking_type, narration
                ) VALUES (?, ?, ?, 'Booking', ?, ?, ?, ?, ?, ?, ?)";
                
                $booking_type_value = ($booking_type === 'bank') ? 'Bank' : 'Cash';
                
                // Determine payment method and amount for the Received transaction
                if ($booking_type === 'bank') {
                    $valid_bank_methods = ['RTGS' => 'Bank', 'NEFT' => 'Bank', 'UPI' => 'UPI', 'Cheque' => 'Cheque', 'Bank Transfer' => 'Bank', 'Other' => 'Bank'];
                    $payment_method = isset($valid_bank_methods[$bank_payment_type]) ? $valid_bank_methods[$bank_payment_type] : 'Bank';
                    $payment_amount = $bank_received;
                } else {
                    $payment_method = 'Cash';
                    $payment_amount = $cash_received;
                }
                
                error_log("Booking details - Booking Type: {$booking_type_value}, Payment Amount: {$payment_amount}");
                
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Failed to prepare statement: " . $conn->error);
                }
                $stmt->bind_param("iissddddss", 
                    $company_id, $party_id, $receipt_id, $date_of_transaction,
                    $booking_weight, $purity, $rate, $total_amount, 
                    $booking_type_value, $narration
                );
                
                if ($stmt->execute()) {
                    $transaction_id = $stmt->insert_id;
                    
                    // If payment received, create received transaction with R prefix
                    if ($payment_amount > 0) {
                        $received_prefix = "R{$company_id}";
                        
                        $receivedIdSql = "SELECT receipt_id FROM transactions 
                                         WHERE company_id = ? 
                                         AND receipt_id LIKE '{$received_prefix}%' 
                                         ORDER BY receipt_id DESC 
                                         LIMIT 1";
                        $receivedIdStmt = $conn->prepare($receivedIdSql);
                        $receivedIdStmt->bind_param("i", $company_id);
                        $receivedIdStmt->execute();
                        $receivedIdResult = $receivedIdStmt->get_result();
                        
                        $receivedNextSerial = 1;
                        if ($receivedIdResult->num_rows > 0) {
                            $receivedLastId = $receivedIdResult->fetch_assoc()['receipt_id'];
                            $receivedLastSerial = intval(substr($receivedLastId, strlen($received_prefix)));
                            $receivedNextSerial = $receivedLastSerial + 1;
                        }
                        
                        $received_id = "{$received_prefix}{$receivedNextSerial}";
                        
                        $payment_sql = "INSERT INTO transactions (
                            company_id, party_id, receipt_id, transaction_type,
                            date_of_transaction, payment_amount, payment_method, payment_type,
                            narration
                        ) VALUES (?, ?, ?, 'Received', ?, ?, ?, 'Payment_In', ?)";
                        
                        $payment_narration = "Payment received for booking {$receipt_id}";
                        $payment_stmt = $conn->prepare($payment_sql);
                        $payment_stmt->bind_param("iisssss", 
                            $company_id, $party_id, $received_id, 
                            $date_of_transaction, $payment_amount, $payment_method, $payment_narration
                        );
                        
                        if (!$payment_stmt->execute()) {
                            throw new Exception("Failed to create received transaction: " . $payment_stmt->error);
                        }
                        
                        error_log("Received transaction created successfully: {$received_id} for booking {$receipt_id} with amount {$payment_amount}");
                    }
                    
                    $conn->commit();
                    
                    // Get party contact for WhatsApp
                    $party_sql = "SELECT contact_no FROM parties WHERE id = ?";
                    $party_stmt = $conn->prepare($party_sql);
                    if (!$party_stmt) {
                        error_log("Failed to prepare party contact query: " . $conn->error);
                        $party_contact = '';
                    } else {
                        $party_stmt->bind_param("i", $party_id);
                        $party_stmt->execute();
                        $party_result = $party_stmt->get_result();
                        $party_contact = $party_result->fetch_assoc()['contact_no'] ?? '';
                    }
                    
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Booking saved successfully',
                        'data' => [
                            'receipt_id' => $receipt_id,
                            'party_name' => $party_name,
                            'party_contact' => $party_contact,
                            'booking_weight' => $booking_weight,
                            'purity' => $purity,
                            'rate' => $rate,
                            'amount' => $total_amount,
                            'cash_received' => $cash_received,
                            'bank_received' => $bank_received,
                            'bank_payment_type' => $bank_payment_type,
                            'total_received' => $payment_amount,
                            'remaining' => $total_amount - $payment_amount,
                            'date_of_transaction' => $date_of_transaction,
                            'booking_type' => $booking_type_value,
                            'narration' => $narration
                        ]
                    ]);
                } else {
                    throw new Exception("Failed to save booking: " . $stmt->error);
                }
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Booking error: " . $e->getMessage());
                echo json_encode([
                    'status' => 'error',
                    'message' => $e->getMessage()
                ]);
            }
            exit;
            
        case 'update_booking':
            try {
                $conn->begin_transaction();
                
                $original_receipt_id = $conn->real_escape_string($_POST['original_receipt_id'] ?? '');
                
                // Validate required fields
                $required_fields = ['party_name', 'party_id', 'receipt_id', 'date_of_transaction', 'booking_weight', 'purity', 'rate', 'total_amount', 'booking_type'];
                foreach ($required_fields as $field) {
                    if (!isset($_POST[$field]) || empty($_POST[$field])) {
                        throw new Exception("Missing required field: {$field}");
                    }
                }
                
                $party_name = $conn->real_escape_string($_POST['party_name']);
                $party_id = intval($_POST['party_id']);
                $receipt_id = $conn->real_escape_string($_POST['receipt_id']);
                $date_of_transaction = $conn->real_escape_string($_POST['date_of_transaction']);
                $booking_weight = floatval($_POST['booking_weight']);
                $purity = floatval($_POST['purity']);
                $rate = floatval($_POST['rate']);
                $total_amount = floatval($_POST['total_amount']);
                $booking_type = $conn->real_escape_string($_POST['booking_type']);
                $narration = $conn->real_escape_string($_POST['narration'] ?? '');
                
                // Delete original transaction
                $delete_sql = "DELETE FROM transactions WHERE receipt_id = ? AND company_id = ?";
                $delete_stmt = $conn->prepare($delete_sql);
                $delete_stmt->bind_param("si", $original_receipt_id, $company_id);
                
                if (!$delete_stmt->execute()) {
                    throw new Exception("Failed to delete original transaction: " . $delete_stmt->error);
                }
                
                // Insert updated transaction
                $booking_type_value = $booking_type === 'bank' ? 'Bank' : 'Cash';
                
                $sql = "INSERT INTO transactions (
                    company_id, party_id, receipt_id, transaction_type, 
                    date_of_transaction, gold_weight, purity, rate, gold_amount, 
                    booking_type, narration
                ) VALUES (?, ?, ?, 'Booking', ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Failed to prepare statement: " . $conn->error);
                }
                
                $stmt->bind_param("iissddddss", 
                    $company_id, $party_id, $receipt_id, $date_of_transaction,
                    $booking_weight, $purity, $rate, $total_amount, 
                    $booking_type_value, $narration
                );
                
                if ($stmt->execute()) {
                    $conn->commit();
                    
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Booking updated successfully',
                        'data' => [
                            'receipt_id' => $receipt_id,
                            'party_name' => $party_name,
                            'booking_weight' => $booking_weight,
                            'purity' => $purity,
                            'rate' => $rate,
                            'amount' => $total_amount,
                            'date_of_transaction' => $date_of_transaction
                        ]
                    ]);
                } else {
                    throw new Exception("Failed to update booking: " . $stmt->error);
                }
                
            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode([
                    'status' => 'error',
                    'message' => $e->getMessage()
                ]);
            }
            exit;
            
        case 'delete_booking':
            $conn->begin_transaction();
            try {
                $booking_id = intval($_POST['booking_id'] ?? 0);
                
                // Get booking details first
                $booking_sql = "SELECT * FROM transactions WHERE id = ? AND transaction_type = 'Booking' AND company_id = ?";
                $booking_stmt = $conn->prepare($booking_sql);
                $booking_stmt->bind_param("ii", $booking_id, $company_id);
                $booking_stmt->execute();
                $booking_result = $booking_stmt->get_result();
                
                if ($booking_result->num_rows === 0) {
                    throw new Exception("Booking not found");
                }
                
                $booking = $booking_result->fetch_assoc();
                $booking_receipt_id = $booking['receipt_id'];
                
                // Delete all linked payment transactions
                $delete_payments = "DELETE FROM transactions 
                                  WHERE narration LIKE ? 
                                  AND transaction_type IN ('Payment', 'Received') 
                                  AND company_id = ?";
                $search_pattern = "%booking {$booking_receipt_id}%";
                $del_pay_stmt = $conn->prepare($delete_payments);
                $del_pay_stmt->bind_param("si", $search_pattern, $company_id);
                $del_pay_stmt->execute();
                
                // Delete the booking transaction itself
                $delete_booking = "DELETE FROM transactions WHERE id = ? AND company_id = ?";
                $del_book_stmt = $conn->prepare($delete_booking);
                $del_book_stmt->bind_param("ii", $booking_id, $company_id);
                $del_book_stmt->execute();
                
                $conn->commit();
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Booking deleted successfully'
                ]);
            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode([
                    'status' => 'error',
                    'message' => $e->getMessage()
                ]);
            }
            exit;
            
        case 'delete_transaction':
            $receipt_id = $conn->real_escape_string($_POST['receipt_id'] ?? '');
            
            try {
                $conn->begin_transaction();
                
                // Get transaction details before deletion
                $get_transaction_sql = "SELECT * FROM transactions WHERE receipt_id = ? AND company_id = ?";
                $get_stmt = $conn->prepare($get_transaction_sql);
                $get_stmt->bind_param("si", $receipt_id, $company_id);
                $get_stmt->execute();
                $transaction = $get_stmt->get_result()->fetch_assoc();
                
                if (!$transaction) {
                    throw new Exception("Transaction not found");
                }
                
                // Delete the transaction
                $delete_sql = "DELETE FROM transactions WHERE receipt_id = ? AND company_id = ?";
                $delete_stmt = $conn->prepare($delete_sql);
                $delete_stmt->bind_param("si", $receipt_id, $company_id);
                
                if (!$delete_stmt->execute()) {
                    throw new Exception("Failed to delete transaction: " . $delete_stmt->error);
                }
                
                $conn->commit();
                
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Transaction deleted successfully'
                ]);
                
            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode([
                    'status' => 'error',
                    'message' => $e->getMessage()
                ]);
            }
            exit;
    }
}

// ============================================
// STATISTICS QUERY
// ============================================
// Initialize default stats
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
    'total_bank_booking_amount' => 0,
    'purity_99_50_stock' => 0,
    'purity_99_90_stock' => 0,
    'purity_100_00_stock' => 0,
    'purity_91_60_stock' => 0
];

try {
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
        SUM(CASE WHEN transaction_type = 'Received' AND payment_method = 'Cash' THEN payment_amount ELSE 0 END) AS total_cash_received,
        SUM(CASE WHEN transaction_type = 'Received' AND payment_method = 'Bank' THEN payment_amount ELSE 0 END) AS total_bank_received,
        SUM(CASE WHEN transaction_type = 'Received' AND payment_method = 'UPI' THEN payment_amount ELSE 0 END) AS total_upi_received,
        SUM(CASE WHEN transaction_type = 'Received' AND payment_method = 'Cheque' THEN payment_amount ELSE 0 END) AS total_cheque_received,
        SUM(CASE WHEN transaction_type = 'Booking' AND booking_type = 'Cash' THEN gold_weight ELSE 0 END) AS total_cash_booking_weight,
        SUM(CASE WHEN transaction_type = 'Booking' AND booking_type = 'Bank' THEN gold_weight ELSE 0 END) AS total_bank_booking_weight,
        SUM(CASE WHEN transaction_type = 'Booking' AND booking_type = 'Cash' THEN gold_amount ELSE 0 END) AS total_cash_booking_amount,
        SUM(CASE WHEN transaction_type = 'Booking' AND booking_type = 'Bank' THEN gold_amount ELSE 0 END) AS total_bank_booking_amount,
        (SELECT current_stock FROM gold_stock WHERE purity = 99.50 AND company_id = ? ORDER BY id DESC LIMIT 1) AS purity_99_50_stock,
        (SELECT current_stock FROM gold_stock WHERE purity = 99.90 AND company_id = ? ORDER BY id DESC LIMIT 1) AS purity_99_90_stock,
        (SELECT current_stock FROM gold_stock WHERE purity = 100.00 AND company_id = ? ORDER BY id DESC LIMIT 1) AS purity_100_00_stock,
        (SELECT current_stock FROM gold_stock WHERE purity = 91.60 AND company_id = ? ORDER BY id DESC LIMIT 1) AS purity_91_60_stock
    FROM transactions
    WHERE DATE(date_of_transaction) = CURRENT_DATE AND company_id = ?";
    
    $stmt = $conn->prepare($stats_sql);
    if ($stmt) {
        $stmt->bind_param("iiiii", $company_id, $company_id, $company_id, $company_id, $company_id);
        $stmt->execute();
        $stats_result = $stmt->get_result();
        if ($stats_result) {
            $fetched_stats = $stats_result->fetch_assoc();
            if ($fetched_stats) {
                $stats = array_merge($stats, $fetched_stats);
            }
        }
    } else {
        error_log("Failed to prepare stats query: " . $conn->error);
    }
} catch (Exception $e) {
    error_log("Stats query error: " . $e->getMessage());
    // Use default stats array
}

// ============================================
// GET RECENT TRANSACTIONS
// ============================================
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$transactions = [];
$total_transactions = 0;
$total_pages = 0;
$current_page = $page;

try {
    if ($search) {
        $search_escaped = $conn->real_escape_string($search);
        $where_clause = "AND (p.party_name LIKE ? OR t.receipt_id LIKE ?)";
        $search_pattern = "%{$search_escaped}%";
        
        $transactions_sql = "SELECT t.*, p.party_name, p.contact_no as party_contact 
                            FROM transactions t 
                            LEFT JOIN parties p ON t.party_id = p.id
                            WHERE t.transaction_type IN ('Booking', 'Received') 
                            AND t.company_id = ?
                            $where_clause 
                            ORDER BY t.date_of_transaction DESC, t.id DESC 
                            LIMIT ?, ?";
        
        $stmt = $conn->prepare($transactions_sql);
        if ($stmt) {
            $stmt->bind_param("issii", $company_id, $search_pattern, $search_pattern, $offset, $limit);
            $stmt->execute();
            $transactions_result = $stmt->get_result();
            if ($transactions_result) {
                while ($row = $transactions_result->fetch_assoc()) {
                    $transactions[] = $row;
                }
            }
        }
        
        // Count total transactions with search
        $total_sql = "SELECT COUNT(*) as count 
                      FROM transactions t 
                      LEFT JOIN parties p ON t.party_id = p.id
                      WHERE t.transaction_type IN ('Booking', 'Received') 
                      AND t.company_id = ?
                      $where_clause";
        $count_stmt = $conn->prepare($total_sql);
        if ($count_stmt) {
            $count_stmt->bind_param("iss", $company_id, $search_pattern, $search_pattern);
            $count_stmt->execute();
            $total_result = $count_stmt->get_result();
            if ($total_result) {
                $total_row = $total_result->fetch_assoc();
                $total_transactions = $total_row ? intval($total_row['count']) : 0;
            }
        }
    } else {
        $transactions_sql = "SELECT t.*, p.party_name, p.contact_no as party_contact 
                            FROM transactions t 
                            LEFT JOIN parties p ON t.party_id = p.id
                            WHERE t.transaction_type IN ('Booking', 'Received') 
                            AND t.company_id = ?
                            ORDER BY t.date_of_transaction DESC, t.id DESC 
                            LIMIT ?, ?";
        
        $stmt = $conn->prepare($transactions_sql);
        if ($stmt) {
            $stmt->bind_param("iii", $company_id, $offset, $limit);
            $stmt->execute();
            $transactions_result = $stmt->get_result();
            if ($transactions_result) {
                while ($row = $transactions_result->fetch_assoc()) {
                    $transactions[] = $row;
                }
            }
        }
        
        // Count total transactions without search
        $total_sql = "SELECT COUNT(*) as count 
                      FROM transactions t 
                      LEFT JOIN parties p ON t.party_id = p.id
                      WHERE t.transaction_type IN ('Booking', 'Received') 
                      AND t.company_id = ?";
        $count_stmt = $conn->prepare($total_sql);
        if ($count_stmt) {
            $count_stmt->bind_param("i", $company_id);
            $count_stmt->execute();
            $total_result = $count_stmt->get_result();
            if ($total_result) {
                $total_row = $total_result->fetch_assoc();
                $total_transactions = $total_row ? intval($total_row['count']) : 0;
            }
        }
    }
    
    $total_pages = $total_transactions > 0 ? ceil($total_transactions / $limit) : 0;
} catch (Exception $e) {
    error_log("Transactions query error: " . $e->getMessage());
    // Use empty arrays/defaults already set above
}
?>

<!-- Main Content Container -->
<div class="w-full">
    <!-- Statistics Dashboard -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
        <!-- Booking Weight -->
        <div class="soft-gradient-blue rounded-xl p-4 shadow-sm h-full">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-blue-700 mb-1">Booking Weight</p>
                    <p class="text-lg font-bold text-blue-800 mb-0"><?= number_format(($stats['total_cash_booking_weight'] ?? 0) + ($stats['total_bank_booking_weight'] ?? 0), 1) ?>g</p>
                    <p class="text-xs text-blue-600 mb-0">Cash: <?= number_format($stats['total_cash_booking_weight'] ?? 0, 1) ?>g | Bank: <?= number_format($stats['total_bank_booking_weight'] ?? 0, 1) ?>g</p>
                </div>
                <div class="w-10 h-10 bg-blue-500 rounded-lg flex items-center justify-center">
                    <i class="fas fa-book text-white text-sm"></i>
                </div>
            </div>
        </div>
        
        <!-- Sell Weight -->
        <div class="soft-gradient-green rounded-xl p-4 shadow-sm h-full">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-green-700 mb-1">Sell Weight</p>
                    <p class="text-lg font-bold text-green-800 mb-0"><?= number_format($stats['total_sale_weight'] ?? 0, 1) ?>g</p>
                    <p class="text-xs text-green-600 mb-0">Gold Sold Today</p>
                </div>
                <div class="w-10 h-10 bg-green-500 rounded-lg flex items-center justify-center">
                    <i class="fas fa-shopping-cart text-white text-sm"></i>
                </div>
            </div>
        </div>
        
        <!-- Purchase Weight -->
        <div class="soft-gradient-orange rounded-xl p-4 shadow-sm h-full">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-orange-700 mb-1">Purchase Weight</p>
                    <p class="text-lg font-bold text-orange-800 mb-0"><?= number_format($stats['total_purchase_weight'] ?? 0, 1) ?>g</p>
                    <p class="text-xs text-orange-600 mb-0">Gold Purchased Today</p>
                </div>
                <div class="w-10 h-10 bg-orange-500 rounded-lg flex items-center justify-center">
                    <i class="fas fa-shopping-basket text-white text-sm"></i>
                </div>
            </div>
        </div>
        
        <!-- Total Amount -->
        <div class="soft-gradient-purple rounded-xl p-4 shadow-sm h-full">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-purple-700 mb-1">Total Amount</p>
                    <p class="text-lg font-bold text-purple-800 mb-0">₹<?= number_format(($stats['total_cash_booking_amount'] ?? 0) + ($stats['total_bank_booking_amount'] ?? 0), 0) ?></p>
                    <p class="text-xs text-purple-600 mb-0">Cash: ₹<?= number_format($stats['total_cash_booking_amount'] ?? 0, 0) ?> | Bank: ₹<?= number_format($stats['total_bank_booking_amount'] ?? 0, 0) ?></p>
                </div>
                <div class="w-10 h-10 bg-purple-500 rounded-lg flex items-center justify-center">
                    <i class="fas fa-coins text-white text-sm"></i>
                </div>
            </div>
        </div>
        
        <!-- Amount Received -->
        <div class="soft-gradient-teal rounded-xl p-4 shadow-sm h-full">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-teal-700 mb-1">Amount Received</p>
                    <p class="text-lg font-bold text-teal-800 mb-0">₹<?= number_format(($stats['total_cash_received'] ?? 0) + ($stats['total_bank_received'] ?? 0), 0) ?></p>
                    <p class="text-xs text-teal-600 mb-0">Cash: ₹<?= number_format($stats['total_cash_received'] ?? 0, 0) ?> | Bank: ₹<?= number_format($stats['total_bank_received'] ?? 0, 0) ?></p>
                </div>
                <div class="w-10 h-10 bg-teal-500 rounded-lg flex items-center justify-center">
                    <i class="fas fa-money-bill-wave text-white text-sm"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Form and List Layout -->
    <div class="flex flex-col lg:flex-row gap-6">
        <!-- Left Side - Booking Form -->
        <div class="bg-white rounded-lg shadow-md border border-gray-200" style="flex: 0 0 55%;">
            <div class="bg-gradient-to-r from-blue-500 to-blue-600 px-4 py-2 rounded-t-lg">
                <h2 class="text-base font-semibold text-white flex items-center">
                    <i class="fas fa-book mr-2"></i>
                    Book Gold Transaction
                </h2>
            </div>
            <div class="p-3">
                <form id="bookingForm" onsubmit="return false;" class="space-y-3">
                    <input type="hidden" name="action" value="save_booking">
                    <input type="hidden" name="party_id" id="partyId">
                    
                    <!-- Row 1: Booking ID & Date -->
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Booking ID</label>
                            <div class="relative">
                                <input type="text" class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer" name="receipt_id" readonly id="bookingIdInput" tabindex="0">
                                <button type="button" class="absolute right-2 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600" id="showBookingListBtn">
                                    <i class="fas fa-history"></i>
                                </button>
                            </div>
                            <div id="bookingList" class="absolute z-10 mt-1 bg-white border border-gray-200 rounded-lg shadow-xl max-h-64 overflow-y-auto hidden" style="width: 400px; max-width: 90vw;"></div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                            <input type="datetime-local" class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" name="date_of_transaction" required>
                        </div>
                    </div>
                    
                    <!-- Row 2: Party Name -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Party Name</label>
                        <div class="relative">
                            <input type="text" class="block w-full pl-3 pr-20 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" name="party_name" id="partyNameInput" required autocomplete="off" placeholder="Enter party name...">
                            <div class="absolute inset-y-0 right-0 flex items-center pr-2">
                                <button type="button" class="px-3 py-1 text-sm bg-blue-500 text-white rounded-lg hover:bg-blue-600" id="addNewPartyBtn" title="Add New Party (Alt+A)">
                                    <i class="fas fa-plus mr-1"></i>New <span class="text-xs opacity-75">(Alt+A)</span>
                                </button>
                            </div>
                        </div>
                        <div id="partyList" class="mt-2 hidden bg-white border border-gray-200 rounded-lg shadow-xl max-h-64 overflow-y-auto"></div>
                    </div>
                    
                    <!-- Row 3: Weight & Purity -->
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Weight (g)</label>
                            <input type="number" step="0.001" class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" name="booking_weight" required placeholder="0.000">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Purity (%)</label>
                            <select class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" name="purity">
                                <option value="99.90">Gold Coin (99.90%)</option>
                                <option value="99.50">Gold Bar (99.50%)</option>
                                <option value="91.60">Gold Jewelry (91.60%)</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Row 4: Rate, Total & Booking Type -->
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Rate (₹/g)</label>
                            <input type="number" step="0.01" class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" name="rate" required placeholder="0.00">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Total (₹)</label>
                            <input type="text" class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg bg-gray-50" name="total_amount" readonly placeholder="₹0.00">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Booking Type</label>
                            <select class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" name="booking_type" id="bookingTypeSelect">
                                <option value="">Select Type</option>
                                <option value="cash">Cash</option>
                                <option value="bank" selected>Bank</option>
                            </select>
                        </div>
                    </div>

                    <!-- Narration -->
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Narration (Optional)</label>
                        <textarea class="block w-full px-2 py-1 text-xs border border-gray-300 rounded focus:ring-1 focus:ring-blue-500" name="narration" rows="2" placeholder="Additional notes..."></textarea>
                    </div>
                    
                    <!-- Submit Buttons -->
                    <div class="flex justify-end space-x-2">
                        <button type="button" id="submitBtn" class="px-4 py-2 bg-yellow-500 hover:bg-yellow-600 text-white font-semibold text-sm rounded transition-colors duration-200">
                            <i class="fas fa-book mr-1"></i>Book Gold
                        </button>
                        <button type="button" id="updateBtn" class="hidden px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white font-semibold text-sm rounded transition-colors duration-200">
                            <i class="fas fa-save mr-1"></i>Update Booking
                        </button>
                        <button type="button" id="deleteBtn" class="hidden px-4 py-2 bg-red-500 hover:bg-red-600 text-white font-semibold text-sm rounded transition-colors duration-200">
                            <i class="fas fa-trash mr-1"></i>Delete Booking
                        </button>
                        <button type="button" id="cancelEditBtn" class="hidden px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white font-semibold text-sm rounded transition-colors duration-200">
                            <i class="fas fa-times mr-1"></i>Cancel Edit
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Immediate form submission prevention script -->
        <script>
        (function() {
            // Prevent form submission at document level FIRST
            document.addEventListener('submit', function(e) {
                const form = e.target;
                if (form && form.id === 'bookingForm') {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    console.log('[Document Level] Form submit prevented');
                    return false;
                }
            }, true); // Capture phase - highest priority
            
            // Wait for DOM to be ready
            function initFormPrevention() {
                const form = document.getElementById('bookingForm');
                if (!form) {
                    setTimeout(initFormPrevention, 50);
                    return;
                }
                
                console.log('[Inline Script] Form found, setting up prevention');
                
                // Remove any action or method that might cause submission
                form.removeAttribute('action');
                form.removeAttribute('method');
                
                // Prevent form submission immediately - use capture phase
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    console.log('[Inline Script] Form submit prevented');
                    return false;
                }, true); // Capture phase - catches before bubbling
                
                // Also prevent on form element directly
                form.onsubmit = function(e) {
                    if (e) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                    console.log('[Inline Script] onsubmit prevented');
                    return false;
                };
                
                // Handle Enter key at document level with highest priority
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        const target = e.target;
                        const form = target.closest('#bookingForm');
                        
                        // Only handle if inside our form
                        if (form && form.id === 'bookingForm') {
                            // Allow Enter in textarea
                            if (target.tagName === 'TEXTAREA') {
                                return true;
                            }
                            
                            // IMPORTANT: Check if party dropdown is open FIRST - if so, let PartySearch handle it
                            if (target.id === 'partyNameInput' || target.name === 'party_name') {
                                const partyList = document.getElementById('partyList');
                                if (partyList && !partyList.classList.contains('hidden')) {
                                    // Dropdown is open, let PartySearch handle Enter key
                                    // Don't prevent, don't stop - just exit and let event reach input handler
                                    console.log('[Document Level] Party dropdown open, allowing PartySearch to handle Enter');
                                    return; // Exit early, don't interfere - event will reach input handler
                                }
                            }
                            
                            // Prevent default for all other inputs (only if dropdown is not open)
                            if (target.tagName === 'INPUT' || target.tagName === 'SELECT') {
                                e.preventDefault();
                                e.stopImmediatePropagation();
                                console.log('[Document Level] Enter key prevented in', target.name || target.id);
                                
                                // Trigger the submit button click
                                setTimeout(function() {
                                    const submitBtn = document.getElementById('submitBtn');
                                    if (submitBtn && !submitBtn.classList.contains('hidden')) {
                                        console.log('[Document Level] Triggering submitBtn click');
                                        submitBtn.click();
                                    }
                                }, 10);
                                
                                return false;
                            }
                        }
                    }
                }, true); // Capture phase - highest priority
            }
            
            // Start immediately
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initFormPrevention);
            } else {
                initFormPrevention();
            }
        })();
        </script>

        <!-- Right Side - Transactions List -->
        <div class="bg-white rounded-lg shadow-md border border-gray-200" style="flex: 0 0 45%; max-width: 100%; overflow: hidden;">
            <div class="bg-gradient-to-r from-green-500 to-green-600 px-4 py-2 rounded-t-lg">
                <h2 class="text-base font-semibold text-white flex items-center">
                    <i class="fas fa-list mr-2"></i>
                    Recent Transactions
                </h2>
            </div>
            <div class="p-4">
                <div class="overflow-x-auto" style="max-width: 100%;">
                    <table class="w-full text-sm responsive-table" style="table-layout: fixed; width: 100%;">
                        <thead>
                            <tr class="bg-gray-50 border-b">
                                <th class="text-left py-2 px-1 font-medium text-gray-700 text-sm" style="width: 25%;">ID & Date</th>
                                <th class="text-left py-2 px-1 font-medium text-gray-700 text-sm" style="width: 25%;">Party</th>
                                <th class="text-left py-2 px-1 font-medium text-gray-700 text-sm" style="width: 25%;">Weights (g)</th>
                                <th class="text-left py-2 px-1 font-medium text-gray-700 text-sm" style="width: 15%;">Amount</th>
                                <th class="text-left py-2 px-1 font-medium text-gray-700 text-sm" style="width: 10%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($transactions)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-8 text-gray-500">
                                        <i class="fas fa-inbox text-2xl mb-2"></i><br>
                                        No transactions found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($transactions as $t): ?>
                                    <tr class="border-b hover:bg-gray-50 cursor-pointer selectable-row" data-receipt-id="<?= $t['receipt_id'] ?>" data-transaction="<?= base64_encode(json_encode($t)) ?>">
                                        <td class="py-2 px-1">
                                            <div class="flex items-center">
                                                <input type="radio" name="selected_transaction" value="<?= $t['receipt_id'] ?>" class="mr-1 transaction-radio">
                                                <?php if ($t['transaction_type'] === 'Received'): ?>
                                                    <span class="bg-green-500 text-white text-xs px-1.5 py-0.5 rounded-full mr-1 font-bold">R</span>
                                                <?php else: ?>
                                                    <span class="bg-blue-500 text-white text-xs px-1.5 py-0.5 rounded-full mr-1 font-bold">B</span>
                                                <?php endif; ?>
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
                                            <?php if ($t['transaction_type'] === 'Received'): ?>
                                                <div class="text-left">
                                                    <div class="font-bold text-green-600 text-sm">₹<?= number_format($t['payment_amount'], 2) ?></div>
                                                    <div class="text-xs text-gray-600"><?= $t['payment_method'] ?? 'Cash' ?></div>
                                                </div>
                                            <?php else: ?>
                                                <div class="text-left">
                                                    <div class="font-bold text-blue-600 text-sm"><?= number_format($t['gold_weight'], 2) ?>g</div>
                                                    <div class="text-xs text-gray-600">₹<?= number_format($t['rate'], 2) ?>/g</div>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-2 px-1">
                                            <div class="text-left">
                                                <?php if ($t['transaction_type'] === 'Received'): ?>
                                                    <span class="font-bold text-green-600 text-sm">₹<?= number_format($t['payment_amount'], 2) ?></span>
                                                <?php else: ?>
                                                    <span class="font-bold text-green-600 text-sm">₹<?= number_format($t['gold_amount'], 2) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="py-2 px-1">
                                            <div class="flex items-center space-x-1">
                                                <button class="text-blue-600 hover:text-blue-800 text-sm print-transaction" title="Print Receipt">
                                                    <i class="fas fa-print"></i>
                                                </button>
                                                <button class="text-red-600 hover:text-red-800 text-sm delete-transaction" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <button class="text-green-600 hover:text-green-800 text-sm share-transaction" title="Share">
                                                    <i class="fas fa-share"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Selected Transaction Actions -->
                <div id="selectedTransactionActions" class="hidden mt-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-medium text-blue-900">Selected Transaction</h3>
                            <p id="selectedTransactionInfo" class="text-xs text-blue-700"></p>
                        </div>
                        <div class="flex space-x-2">
                            <button id="printTransactionBtn" class="px-3 py-1 bg-blue-500 hover:bg-blue-600 text-white text-xs rounded transition-colors">
                                <i class="fas fa-print mr-1"></i>Print
                            </button>
                            <button id="deleteTransactionBtn" class="px-3 py-1 bg-red-500 hover:bg-red-600 text-white text-xs rounded transition-colors">
                                <i class="fas fa-trash mr-1"></i>Delete
                            </button>
                            <button id="clearSelectionBtn" class="px-3 py-1 bg-gray-500 hover:bg-gray-600 text-white text-xs rounded transition-colors">
                                <i class="fas fa-times mr-1"></i>Cancel
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="flex justify-center mt-4 pb-4">
                    <nav class="flex space-x-2">
                        <?php if ($current_page > 1): ?>
                            <a href="?page=<?= $current_page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?>" class="px-3 py-1 bg-gray-200 text-gray-700 rounded hover:bg-gray-300">Previous</a>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++): ?>
                            <a href="?page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?>" class="px-3 py-1 <?= $i === $current_page ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?> rounded"><?= $i ?></a>
                        <?php endfor; ?>
                        
                        <?php if ($current_page < $total_pages): ?>
                            <a href="?page=<?= $current_page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?>" class="px-3 py-1 bg-gray-200 text-gray-700 rounded hover:bg-gray-300">Next</a>
                        <?php endif; ?>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'components/footer.php'; ?>

<style>
/* Responsive font sizing for table */
@media (max-width: 768px) {
    .responsive-table th,
    .responsive-table td {
        font-size: 0.75rem !important;
        padding: 0.25rem 0.125rem !important;
    }
    .responsive-table .font-bold {
        font-size: 0.75rem !important;
    }
    .responsive-table .font-semibold {
        font-size: 0.75rem !important;
    }
}

@media (min-width: 769px) and (max-width: 1024px) {
    .responsive-table th,
    .responsive-table td {
        font-size: 0.875rem !important;
        padding: 0.375rem 0.25rem !important;
    }
    .responsive-table .font-bold {
        font-size: 0.875rem !important;
    }
    .responsive-table .font-semibold {
        font-size: 0.875rem !important;
    }
}

@media (min-width: 1025px) {
    .responsive-table th,
    .responsive-table td {
        font-size: 0.875rem !important;
        padding: 0.5rem 0.25rem !important;
    }
    .responsive-table .font-bold {
        font-size: 0.875rem !important;
    }
    .responsive-table .font-semibold {
        font-size: 0.875rem !important;
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

<?php
// Capture the content
$content = ob_get_clean();

// Set page title and include layout
$page_title = "Book Gold";
include 'components/layout.php';
?>

<!-- Pass company_id and company_name to JavaScript -->
<script>
    window.companyId = <?= $company_id ?>;
    window.companyName = <?= json_encode($company_name ?: 'Gold Trading Company') ?>;
</script>
<script src="js/keyboard-navigation.js"></script>
<script src="js/book-gold.js"></script>
</body>

