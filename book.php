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
require_once __DIR__ . '/helpers/gold_rate_helper.php';
require_once __DIR__ . '/helpers/receipt_id_helper.php';

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
$gold_rate_unit = gold_rate_get_unit($conn, $company_id);
$gold_rate_label = gold_rate_label($gold_rate_unit);
$gold_rate_suffix = gold_rate_suffix($gold_rate_unit);

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
            $bookingId = next_receipt_id($conn, $company_id, 'B', ['transaction_type' => 'Booking']);
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
                    gold_rate_apply_display_to_row($row, $gold_rate_unit);
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

                gold_rate_apply_display_to_row($booking, $gold_rate_unit);
                
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
                $rate = gold_rate_from_display(floatval($_POST['rate']), $gold_rate_unit);
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
                
                // Ensure booking ID is globally unique ({company_id}B{serial})
                if (receipt_id_exists_globally($conn, $receipt_id)) {
                    error_log("Duplicate booking ID found: {$receipt_id}, generating new one");
                    $receipt_id = next_receipt_id($conn, $company_id, 'B', ['transaction_type' => 'Booking']);
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
                        $received_id = next_receipt_id($conn, $company_id, 'R', ['transaction_type' => 'Received']);
                        
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
                            'rate' => gold_rate_to_display($rate, $gold_rate_unit),
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
                $rate = gold_rate_from_display(floatval($_POST['rate']), $gold_rate_unit);
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
                            'rate' => gold_rate_to_display($rate, $gold_rate_unit),
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
$start_date = isset($_GET['start_date']) && !empty($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) && !empty($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$transactions = [];
$total_transactions = 0;
$total_pages = 0;
$current_page = $page;

try {
    $date_clause = "AND DATE(t.date_of_transaction) BETWEEN ? AND ?";
    
    if ($search) {
        $search_escaped = $conn->real_escape_string($search);
        $where_clause = "AND (p.party_name LIKE ? OR t.receipt_id LIKE ?)";
        $search_pattern = "%{$search_escaped}%";
        
        $transactions_sql = "SELECT t.*, p.party_name, p.contact_no as party_contact 
                            FROM transactions t 
                            LEFT JOIN parties p ON t.party_id = p.id
                            WHERE t.transaction_type = 'Booking' 
                            AND t.company_id = ?
                            $date_clause
                            $where_clause 
                            ORDER BY t.date_of_transaction DESC, t.id DESC 
                            LIMIT ?, ?";
        
        $stmt = $conn->prepare($transactions_sql);
        if ($stmt) {
            $stmt->bind_param("isssii", $company_id, $start_date, $end_date, $search_pattern, $search_pattern, $offset, $limit);
            $stmt->execute();
            $transactions_result = $stmt->get_result();
            if ($transactions_result) {
                while ($row = $transactions_result->fetch_assoc()) {
                    $transactions[] = $row;
                }
            }
        }
        
        $total_sql = "SELECT COUNT(*) as count 
                      FROM transactions t 
                      LEFT JOIN parties p ON t.party_id = p.id
                      WHERE t.transaction_type = 'Booking' 
                      AND t.company_id = ?
                      $date_clause
                      $where_clause";
        $count_stmt = $conn->prepare($total_sql);
        if ($count_stmt) {
            $count_stmt->bind_param("isss", $company_id, $start_date, $end_date, $search_pattern, $search_pattern);
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
                            WHERE t.transaction_type = 'Booking' 
                            AND t.company_id = ?
                            $date_clause
                            ORDER BY t.date_of_transaction DESC, t.id DESC 
                            LIMIT ?, ?";
        
        $stmt = $conn->prepare($transactions_sql);
        if ($stmt) {
            $stmt->bind_param("issii", $company_id, $start_date, $end_date, $offset, $limit);
            $stmt->execute();
            $transactions_result = $stmt->get_result();
            if ($transactions_result) {
                while ($row = $transactions_result->fetch_assoc()) {
                    $transactions[] = $row;
                }
            }
        }
        
        $total_sql = "SELECT COUNT(*) as count 
                      FROM transactions t 
                      LEFT JOIN parties p ON t.party_id = p.id
                      WHERE t.transaction_type = 'Booking' 
                      AND t.company_id = ?
                      $date_clause";
        $count_stmt = $conn->prepare($total_sql);
        if ($count_stmt) {
            $count_stmt->bind_param("iss", $company_id, $start_date, $end_date);
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

$total_booking_weight = ($stats['total_cash_booking_weight'] ?? 0) + ($stats['total_bank_booking_weight'] ?? 0);
$total_booking_amount = ($stats['total_cash_booking_amount'] ?? 0) + ($stats['total_bank_booking_amount'] ?? 0);
$total_received_amount = ($stats['total_cash_received'] ?? 0) + ($stats['total_bank_received'] ?? 0);
?>

<!-- Main Content Container -->
<div class="w-full">
    <!-- Statistics Dashboard -->
    <div class="overflow-x-auto pb-1 mb-4">
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 min-w-[36rem] sm:min-w-0">
            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase">Booking weight</p>
                        <p class="stats-card-value leading-tight">
                            <?= number_format($total_booking_weight, 2) ?><span class="text-[10px] font-medium text-slate-500 ml-0.5">g</span>
                        </p>
                        <p class="stats-metal-split">
                            <span class="metal-seg" title="Cash booking">
                                <i class="fas fa-wallet metal-icon-gold" aria-hidden="true"></i>
                                <span class="metal-num"><?= number_format($stats['total_cash_booking_weight'] ?? 0, 2) ?></span><span class="metal-unit">g</span>
                            </span>
                            <span class="text-slate-300" aria-hidden="true">·</span>
                            <span class="metal-seg" title="Bank booking">
                                <i class="fas fa-university metal-icon-silver" aria-hidden="true"></i>
                                <span class="metal-num"><?= number_format($stats['total_bank_booking_weight'] ?? 0, 2) ?></span><span class="metal-unit">g</span>
                            </span>
                        </p>
                    </div>
                    <div class="stats-icon-wrap bg-sky-100 stats-icon shrink-0">
                        <i class="fas fa-book text-sky-600 text-xs"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase">Sell weight</p>
                        <p class="stats-card-value leading-tight">
                            <?= number_format($stats['total_sale_weight'] ?? 0, 2) ?><span class="text-[10px] font-medium text-slate-500 ml-0.5">g</span>
                        </p>
                        <p class="text-[9px] font-medium text-slate-400 uppercase tracking-tight mt-0.5">Gold sold today</p>
                    </div>
                    <div class="stats-icon-wrap bg-emerald-100 stats-icon shrink-0">
                        <i class="fas fa-shopping-cart text-emerald-600 text-xs"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase">Purchase weight</p>
                        <p class="stats-card-value leading-tight">
                            <?= number_format($stats['total_purchase_weight'] ?? 0, 2) ?><span class="text-[10px] font-medium text-slate-500 ml-0.5">g</span>
                        </p>
                        <p class="text-[9px] font-medium text-slate-400 uppercase tracking-tight mt-0.5">Gold purchased today</p>
                    </div>
                    <div class="stats-icon-wrap bg-amber-100 stats-icon shrink-0">
                        <i class="fas fa-shopping-basket text-amber-700 text-xs"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase">Total amount</p>
                        <p class="stats-card-value leading-tight">₹<?= number_format($total_booking_amount, 0) ?></p>
                        <p class="stats-metal-split">
                            <span class="metal-seg" title="Cash amount">
                                <i class="fas fa-wallet metal-icon-gold" aria-hidden="true"></i>
                                <span class="metal-num">₹<?= number_format($stats['total_cash_booking_amount'] ?? 0, 0) ?></span>
                            </span>
                            <span class="text-slate-300" aria-hidden="true">·</span>
                            <span class="metal-seg" title="Bank amount">
                                <i class="fas fa-university metal-icon-silver" aria-hidden="true"></i>
                                <span class="metal-num">₹<?= number_format($stats['total_bank_booking_amount'] ?? 0, 0) ?></span>
                            </span>
                        </p>
                    </div>
                    <div class="stats-icon-wrap bg-violet-100 stats-icon shrink-0">
                        <i class="fas fa-coins text-violet-600 text-xs"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200/50 stats-card">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="stats-card-label uppercase">Amount received</p>
                        <p class="stats-card-value leading-tight">₹<?= number_format($total_received_amount, 0) ?></p>
                        <p class="stats-metal-split">
                            <span class="metal-seg" title="Cash received">
                                <i class="fas fa-wallet metal-icon-gold" aria-hidden="true"></i>
                                <span class="metal-num">₹<?= number_format($stats['total_cash_received'] ?? 0, 0) ?></span>
                            </span>
                            <span class="text-slate-300" aria-hidden="true">·</span>
                            <span class="metal-seg" title="Bank received">
                                <i class="fas fa-university metal-icon-silver" aria-hidden="true"></i>
                                <span class="metal-num">₹<?= number_format($stats['total_bank_received'] ?? 0, 0) ?></span>
                            </span>
                        </p>
                    </div>
                    <div class="stats-icon-wrap bg-teal-100 stats-icon shrink-0">
                        <i class="fas fa-arrow-up text-teal-600 text-xs"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Form and List Layout -->
    <div class="flex flex-col lg:flex-row gap-6">
        <!-- Left Side - Booking Form -->
        <div style="flex: 0 0 55%;">
            <form id="bookingForm" onsubmit="return false;" class="bg-white rounded-lg shadow-md border border-gray-200 overflow-hidden">
                <input type="hidden" name="action" value="save_booking">
                <input type="hidden" name="party_id" id="partyId">

                <!-- Section 1: Transaction Details -->
                <div class="bg-blue-50 px-3 py-1 border-b border-blue-100">
                    <h3 class="text-xs font-bold text-blue-800 flex items-center">
                        <i class="fas fa-file-invoice mr-1.5 text-xs"></i> Transaction Details
                    </h3>
                </div>
                <div class="p-2 grid grid-cols-12 gap-1.5">
                    <div class="relative col-span-3">
                        <label class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Booking ID</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-gray-500">
                                <i class="fas fa-hashtag text-xs"></i>
                            </span>
                            <input type="text" class="block w-full pl-7 pr-7 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-blue-400 focus:border-blue-400 compact-input cursor-pointer" name="receipt_id" readonly id="bookingIdInput" tabindex="0">
                            <button type="button" class="absolute right-1.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-blue-600" id="showBookingListBtn" title="Recent bookings">
                                <i class="fas fa-history text-xs"></i>
                            </button>
                        </div>
                        <div id="bookingList" class="hidden absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-xl max-h-60 overflow-y-auto"></div>
                    </div>

                    <div class="relative col-span-3">
                        <label class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Date</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-purple-500">
                                <i class="fas fa-calendar-alt text-xs"></i>
                            </span>
                            <input type="datetime-local" class="block w-full pl-7 pr-1 py-1.5 text-[11px] font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-purple-400 focus:border-purple-400 compact-input" name="date_of_transaction" required>
                        </div>
                    </div>

                    <div class="relative col-span-6">
                        <label class="block text-[10px] font-bold text-gray-700 mb-0.5 flex items-center justify-between uppercase tracking-tighter compact-label">
                            <span>Party Name</span>
                            <button type="button" class="text-[10px] font-bold text-blue-600 hover:text-blue-800 normal-case tracking-normal" id="addNewPartyBtn" title="Add New Party (Alt+A)">
                                <i class="fas fa-plus mr-0.5"></i>New
                            </button>
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-blue-500">
                                <i class="fas fa-user text-xs"></i>
                            </span>
                            <input type="text" class="block w-full pl-7 pr-2 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-blue-400 focus:border-blue-400 compact-input" name="party_name" id="partyNameInput" required autocomplete="off" placeholder="Select Party">
                        </div>
                        <div id="partyList" class="hidden absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-48 overflow-y-auto"></div>
                    </div>
                </div>

                <!-- Section 2: Booking Details -->
                <div class="bg-blue-50 px-3 py-1 border-t border-b border-blue-100">
                    <h3 class="text-xs font-bold text-blue-800 flex items-center">
                        <i class="fas fa-weight mr-1.5 text-xs"></i> Booking Details
                    </h3>
                </div>
                <div class="p-2 grid grid-cols-2 gap-1.5">
                    <div>
                        <label class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Weight (g)</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-green-600">
                                <i class="fas fa-weight text-xs"></i>
                            </span>
                            <input type="number" step="0.001" class="block w-full pl-7 pr-2 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-green-400 focus:border-green-400 compact-input" name="booking_weight" required placeholder="0.000">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Purity (%)</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-orange-400">
                                <i class="fas fa-percent text-xs"></i>
                            </span>
                            <select class="block w-full pl-7 pr-1 py-1.5 text-[11px] font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-orange-400 focus:border-orange-400 compact-input" name="purity">
                                <option value="99.90">Gold Coin (99.90%)</option>
                                <option value="99.50">Gold Bar (99.50%)</option>
                                <option value="91.60">Gold Jewelry (91.60%)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Section 3: Rate & Amount -->
                <div class="bg-emerald-50 px-3 py-1 border-t border-b border-emerald-100">
                    <h3 class="text-xs font-bold text-emerald-800 flex items-center">
                        <i class="fas fa-money-bill-wave mr-1.5 text-xs"></i> Rate & Amount
                    </h3>
                </div>
                <div class="p-2 grid grid-cols-2 md:grid-cols-3 gap-1.5">
                    <div>
                        <label class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Rate (<?= htmlspecialchars($gold_rate_label) ?>)</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-orange-500">
                                <i class="fas fa-rupee-sign text-xs"></i>
                            </span>
                            <input type="number" step="0.01" class="block w-full pl-7 pr-2 py-1.5 text-xs font-bold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-orange-400 focus:border-orange-400 compact-input" name="rate" required placeholder="0.00">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-green-700 mb-0.5 uppercase tracking-tighter compact-label">Total (₹)</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-green-600">
                                <i class="fas fa-coins text-xs"></i>
                            </span>
                            <input type="text" class="block w-full pl-7 pr-2 py-1.5 text-xs font-semibold text-gray-700 bg-gray-50 border border-gray-200 rounded cursor-not-allowed compact-input" name="total_amount" readonly placeholder="₹0.00">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-gray-700 mb-0.5 uppercase tracking-tighter compact-label">Booking Type</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-gray-600">
                                <i class="fas fa-credit-card text-xs"></i>
                            </span>
                            <select class="block w-full pl-7 pr-1 py-1.5 text-[11px] font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-gray-400 focus:border-gray-400 compact-input" name="booking_type" id="bookingTypeSelect">
                                <option value="">Select Type</option>
                                <option value="cash">Cash</option>
                                <option value="bank" selected>Bank</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Footer: Narration & Buttons -->
                <div class="bg-gray-50 p-1.5 border-t border-gray-200 grid grid-cols-1 md:grid-cols-4 gap-1.5 items-center">
                    <div class="md:col-span-2 relative">
                        <span class="absolute inset-y-0 left-0 pl-2 flex items-center pointer-events-none text-purple-500">
                            <i class="fas fa-comment-alt text-xs"></i>
                        </span>
                        <input type="text" class="block w-full pl-7 pr-2 py-1.5 text-xs font-semibold text-gray-900 bg-white border border-gray-200 rounded focus:ring-1 focus:ring-purple-400 focus:border-purple-400 compact-input" name="narration" placeholder="Narration...">
                    </div>
                    <div class="md:col-span-2 flex space-x-1">
                        <button type="button" id="submitBtn" class="flex-1 bg-gradient-to-r from-blue-600 to-blue-700 text-white text-[10px] font-bold uppercase py-1.5 px-3 rounded shadow hover:from-blue-700 hover:to-blue-800 transition tracking-tighter">
                            <i class="fas fa-save mr-1"></i>Book Gold
                        </button>
                        <button type="button" id="updateBtn" class="hidden flex-1 bg-gradient-to-r from-emerald-600 to-emerald-700 text-white text-[10px] font-bold uppercase py-1.5 px-3 rounded shadow hover:from-emerald-700 hover:to-emerald-800 transition tracking-tighter">
                            <i class="fas fa-save mr-1"></i>Update
                        </button>
                        <button type="button" id="deleteBtn" class="hidden px-2.5 py-1.5 bg-gradient-to-r from-red-600 to-red-700 text-white text-[10px] font-bold rounded hover:from-red-700 hover:to-red-800 shadow-sm" title="Delete">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                        <button type="button" id="cancelEditBtn" class="hidden px-2.5 py-1.5 bg-white border border-gray-300 text-gray-700 text-[10px] font-bold rounded hover:bg-gray-50 shadow-sm" title="Cancel">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </form>
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
        <div class="bg-white rounded-lg shadow-md border border-gray-200 flex flex-col" style="flex: 0 0 45%; max-height: calc(100vh - 12rem);">
            <div class="bg-blue-50 px-3 py-1.5 border-b border-blue-100 rounded-t-lg shrink-0">
                <div class="flex items-center justify-between gap-2 flex-wrap">
                    <h2 class="text-xs font-bold text-blue-800 flex items-center shrink-0">
                        <i class="fas fa-list mr-1.5 text-xs"></i>
                        Recent Bookings
                    </h2>
                    <form method="GET" action="" id="dateRangeForm" class="flex items-center gap-1.5">
                        <input type="date" name="start_date" id="startDate"
                            value="<?= htmlspecialchars($start_date) ?>"
                            class="px-1.5 py-0.5 border border-gray-200 rounded text-[10px] w-24 focus:ring-1 focus:ring-blue-400 focus:border-blue-400 bg-white font-medium"
                            max="<?= date('Y-m-d') ?>" title="From Date">
                        <span class="text-gray-400 text-[10px] font-bold">to</span>
                        <input type="date" name="end_date" id="endDate"
                            value="<?= htmlspecialchars($end_date) ?>"
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
            <div class="p-2 overflow-y-auto flex-1 min-h-0">
                <div class="overflow-x-auto">
                    <table class="w-full text-left book-txn-table responsive-table">
                        <thead class="bg-slate-50 border-b border-slate-100 sticky top-0 z-10">
                            <tr>
                                <th class="py-2 px-2 text-left text-[9px] font-bold text-slate-500 min-w-[88px]">Id</th>
                                <th class="py-2 px-2 text-left text-[9px] font-bold text-slate-500 min-w-[64px]">Party</th>
                                <th class="py-2 px-2 text-right text-[9px] font-bold text-slate-500">Weight</th>
                                <th class="py-2 px-2 text-right text-[9px] font-bold text-slate-500">Rate</th>
                                <th class="py-2 px-2 text-right text-[9px] font-bold text-slate-500">Amount</th>
                                <th class="py-2 px-2 text-right text-[9px] font-bold text-slate-500 w-14">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100" id="recentTransactionList">
                            <?php if (empty($transactions)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-8 text-gray-500 text-xs">
                                        <i class="fas fa-inbox text-xl mb-2 block"></i>
                                        No bookings found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($transactions as $t): ?>
                                    <tr class="hover:bg-slate-50 transition-colors border-b border-slate-100 last:border-b-0 cursor-pointer selectable-row group"
                                        data-receipt-id="<?= htmlspecialchars($t['receipt_id']) ?>"
                                        data-transaction="<?= base64_encode(json_encode($t)) ?>">
                                        <td class="py-1.5 px-2 align-top min-w-[88px]">
                                            <div class="text-[10px] font-bold text-blue-600 group-hover:underline leading-tight font-mono break-all">
                                                #<?= htmlspecialchars($t['receipt_id']) ?>
                                            </div>
                                            <div class="text-[8px] font-bold text-slate-400 uppercase leading-tight mt-0.5 flex items-center gap-1">
                                                <span class="text-blue-500">B</span>
                                                <span><?= date('d M', strtotime($t['date_of_transaction'])) ?></span>
                                            </div>
                                        </td>
                                        <td class="py-1.5 px-2 align-top">
                                            <div class="text-[10px] font-semibold text-slate-800 truncate max-w-[72px] uppercase" title="<?= htmlspecialchars($t['party_name'] ?? '') ?>">
                                                <?= htmlspecialchars($t['party_name'] ?? '') ?>
                                            </div>
                                        </td>
                                        <td class="py-1.5 px-2 align-top text-right whitespace-nowrap">
                                            <div class="text-[10px] font-bold text-slate-700 leading-none">
                                                <?= number_format($t['gold_weight'], 3) ?><span class="text-[8px] font-normal ml-0.5">g</span>
                                            </div>
                                            <div class="text-[8px] font-bold text-slate-400 uppercase mt-0.5"><?= number_format($t['purity'], 2) ?>%</div>
                                        </td>
                                        <td class="py-1.5 px-2 align-top text-right whitespace-nowrap">
                                            <div class="text-[10px] font-semibold text-amber-600 leading-none">@ ₹<?= number_format(gold_rate_to_display(floatval($t['rate']), $gold_rate_unit), 0) ?><span class="text-[8px] font-normal text-slate-500"><?= htmlspecialchars($gold_rate_suffix) ?></span></div>
                                            <div class="text-[8px] font-medium text-slate-400 uppercase mt-0.5"><?= htmlspecialchars($t['booking_type'] ?? 'Cash') ?></div>
                                        </td>
                                        <td class="py-1.5 px-2 align-top text-right whitespace-nowrap">
                                            <div class="text-[10px] font-bold text-slate-800 leading-none">₹<?= number_format($t['gold_amount'], 0) ?></div>
                                            <div class="mt-0.5">
                                                <span class="text-[7.5px] px-1 py-0.5 rounded bg-blue-100 text-blue-700 font-bold uppercase tracking-tighter"><?= htmlspecialchars($t['booking_type'] ?? 'Book') ?></span>
                                            </div>
                                        </td>
                                        <td class="py-1.5 px-1 align-top">
                                            <div class="flex items-center justify-end gap-0.5">
                                                <button type="button" class="text-blue-500 hover:text-blue-700 p-0.5 print-transaction" title="Print">
                                                    <i class="fas fa-print text-[9px]"></i>
                                                </button>
                                                <button type="button" class="text-red-500 hover:text-red-700 p-0.5 delete-transaction" title="Delete">
                                                    <i class="fas fa-trash-alt text-[9px]"></i>
                                                </button>
                                                <button type="button" class="text-green-600 hover:text-green-800 p-0.5 share-transaction" title="Share">
                                                    <i class="fas fa-share text-[9px]"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination -->
            <?php
            $pagination_query = http_build_query(array_filter([
                'start_date' => $start_date,
                'end_date' => $end_date,
                'search' => $search ?: null,
            ]));
            $pagination_prefix = $pagination_query ? '?' . $pagination_query . '&' : '?';
            ?>
            <?php if ($total_pages > 1): ?>
                <div class="flex justify-center pb-3 px-2 shrink-0 border-t border-slate-100 pt-2">
                    <nav class="flex space-x-1">
                        <?php if ($current_page > 1): ?>
                            <a href="<?= $pagination_prefix ?>page=<?= $current_page - 1 ?>" class="px-2 py-0.5 bg-gray-100 text-gray-700 text-[10px] font-bold rounded hover:bg-gray-200">Prev</a>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++): ?>
                            <a href="<?= $pagination_prefix ?>page=<?= $i ?>" class="px-2 py-0.5 text-[10px] font-bold rounded <?= $i === $current_page ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>"><?= $i ?></a>
                        <?php endfor; ?>
                        
                        <?php if ($current_page < $total_pages): ?>
                            <a href="<?= $pagination_prefix ?>page=<?= $current_page + 1 ?>" class="px-2 py-0.5 bg-gray-100 text-gray-700 text-[10px] font-bold rounded hover:bg-gray-200">Next</a>
                        <?php endif; ?>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'components/footer.php'; ?>

<style>
/* Stats cards (match exchange.php) */
.stats-card-label {
    font-size: 10px;
    font-weight: 500;
    letter-spacing: 0.02em;
    color: rgb(100 116 139);
}

.stats-card-value {
    font-size: 1rem;
    font-weight: 600;
    color: rgb(51 65 85);
    font-variant-numeric: tabular-nums;
}

.stats-metal-split {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.15rem 0.45rem;
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.02em;
    line-height: 1.35;
    margin-top: 0.35rem;
    font-variant-numeric: tabular-nums;
    color: rgb(71 85 105);
}

.stats-metal-split .metal-seg {
    display: inline-flex;
    align-items: center;
    gap: 0.2rem;
}

.stats-metal-split .metal-num {
    font-weight: 700;
    font-size: 0.6875rem;
}

.stats-metal-split .metal-unit {
    font-size: 0.625rem;
    font-weight: 600;
    color: rgb(100 116 139);
}

.stats-metal-split .metal-icon-gold {
    color: #b45309;
    font-size: 0.625rem;
}

.stats-metal-split .metal-icon-silver {
    color: #475569;
    font-size: 0.625rem;
}

.stats-icon-wrap {
    width: 2rem;
    height: 2rem;
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Compact form styles */
#partyList {
    position: absolute !important;
    top: 100% !important;
    left: 0 !important;
    right: 0 !important;
    width: 100% !important;
    z-index: 1000 !important;
}

/* Transaction list — keep compact sizes */
.book-txn-table {
    color: rgb(100 116 139);
    font-size: 10px;
}

.book-txn-table th,
.book-txn-table td {
    vertical-align: top;
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
    window.GOLD_RATE_CONFIG = <?= json_encode(gold_rate_js_config($gold_rate_unit)) ?>;
</script>
<script src="js/gold-rate-utils.js"></script>
<script src="js/keyboard-navigation.js"></script>
<script src="js/book-gold.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const startDate = document.getElementById('startDate');
    const endDate = document.getElementById('endDate');
    if (!startDate || !endDate) return;

    function validateDateRange() {
        const start = new Date(startDate.value);
        const end = new Date(endDate.value);
        if (start > end) {
            if (document.activeElement === startDate) {
                endDate.value = startDate.value;
            } else {
                startDate.value = endDate.value;
            }
        }
    }

    startDate.addEventListener('change', validateDateRange);
    endDate.addEventListener('change', validateDateRange);
});
</script>
</body>

