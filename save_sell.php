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
        // Accept both 'sell_weight' and 'weight' field names
        $sell_weight = floatval($_POST['sell_weight'] ?? $_POST['weight'] ?? 0);
        $purity = floatval($_POST['purity']);
        $rate = floatval($_POST['rate']);
        // Remove commas and parse amount (handles Indian number format)
        $amount = floatval(str_replace(',', '', $_POST['amount']));
        $additional_cash = floatval($_POST['additional_cash'] ?? 0);
        $additional_bank = floatval($_POST['additional_bank'] ?? 0);
        $bank_payment_type = $conn->real_escape_string($_POST['bank_payment_type'] ?? $_POST['payment_method'] ?? '');
        $narration = $conn->real_escape_string($_POST['narration'] ?? '');
        
        // Get payment_type and convert to proper case for booking_type column
        $payment_type = strtolower($_POST['payment_type'] ?? 'bank');
        $booking_type = ucfirst($payment_type); // Convert 'cash' to 'Cash', 'bank' to 'Bank'
        
        // Validate required fields
        if (empty($receipt_id) || empty($party_id) || $sell_weight <= 0 || $rate < 0) {
            throw new Exception("Please fill all required fields with valid values");
        }
        
        // Check company gold stock availability (but don't block the sale)
        $stock_check = "SELECT id, current_stock FROM gold_stock WHERE purity = $purity AND company_id = $company_id ORDER BY id DESC LIMIT 1";
        $stock_result = $conn->query($stock_check);
        
        if (!$stock_result) {
            // Log the error but don't block the sale
            error_log("Error checking company gold stock for purity $purity, company $company_id");
            $stock_data = null;
            $company_stock = 0;
        } else {
            $stock_data = $stock_result->fetch_assoc();
            $company_stock = $stock_data ? $stock_data['current_stock'] : 0;
        }
        
        // Log stock warning but don't block the sale
        if ($sell_weight > $company_stock) {
            error_log("WARNING: Insufficient company stock. Available: {$company_stock}g, Requested: {$sell_weight}g. Sale proceeding anyway.");
            // Don't throw exception - just log the warning and proceed
        }
        
        // Check party balance by booking_type (Cash or Bank)
        $balance_check = "SELECT 
            COALESCE(SUM(CASE WHEN transaction_type = 'Booking' AND booking_type = '$booking_type' THEN gold_weight ELSE 0 END), 0) as booked_weight_by_type,
            COALESCE(SUM(CASE WHEN transaction_type = 'Sale' AND booking_type = '$booking_type' THEN gold_weight ELSE 0 END), 0) as sold_weight_by_type,
            COALESCE(SUM(CASE WHEN transaction_type = 'Booking' THEN gold_weight ELSE 0 END), 0) as total_booked_weight,
            COALESCE(SUM(CASE WHEN transaction_type = 'Sale' THEN gold_weight ELSE 0 END), 0) as total_sold_weight,
            COALESCE(SUM(CASE WHEN transaction_type = 'Booking' THEN gold_amount ELSE 0 END), 0) as booked_amount,
            COALESCE(SUM(CASE WHEN transaction_type = 'Payment' AND payment_type = 'Payment_In' THEN payment_amount ELSE 0 END), 0) as advance_received
            FROM transactions 
            WHERE party_id = $party_id AND company_id = $company_id";
        
        $balance_result = $conn->query($balance_check);
        if (!$balance_result) {
            throw new Exception("Error checking party balance");
        }
        
        $balance_data = $balance_result->fetch_assoc();
        
        // Calculate available weight for the specific booking_type
        $available_weight_by_type = $balance_data['booked_weight_by_type'] - $balance_data['sold_weight_by_type'];
        $total_available_weight = $balance_data['total_booked_weight'] - $balance_data['total_sold_weight'];
        $advance_received = $balance_data['advance_received'];
        
        // Log warning if selling more than available for this booking type, but allow the sale
        if ($balance_data['booked_weight_by_type'] > 0 && $sell_weight > $available_weight_by_type) {
            error_log("WARNING: Selling more than available $booking_type booking. Available: {$available_weight_by_type}g, Selling: {$sell_weight}g. Sale proceeding anyway.");
        }
        
        // If no booking exists for this type, set available_weight to sell_weight for calculations
        if ($balance_data['booked_weight_by_type'] == 0) {
            // Direct sale without booking - set previous balance to sell_weight
            $available_weight_by_type = $sell_weight;
        }
        
        // Use type-specific available weight for balance tracking
        $available_weight = $available_weight_by_type;
        
        // Insert main sale transaction
        $sale_sql = "INSERT INTO transactions (
            company_id, party_id, receipt_id, transaction_type, date_of_transaction,
            gold_weight, purity, rate, gold_amount, payment_amount, payment_method, payment_type, booking_type,
            party_balance_before, party_balance_after, party_gold_balance_before, party_gold_balance_after,
            narration
        ) VALUES (
            $company_id, $party_id, '$receipt_id', 'Sale', '$date_of_transaction',
            $sell_weight, $purity, $rate, $amount, 0, 'Sale', 'Payment_Out', '$booking_type',
            -$advance_received, -$advance_received - $amount, $available_weight, " . ($available_weight - $sell_weight) . ",
            'Gold sale transaction - $receipt_id" . (!empty($narration) ? " - $narration" : "") . "'
        )";
        
        if (!$conn->query($sale_sql)) {
            throw new Exception("Error creating sale transaction: " . $conn->error);
        }
        
        $sale_transaction_id = $conn->insert_id;
        
        // Calculate total payment received
        // For advance payments, we need to check if party has credit (positive balance)
        // Advance credit means we owe them money, so it reduces the sale amount they need to pay
        $advance_settlement = min($advance_received, $amount);
        $total_payment_received = $advance_settlement + $additional_cash + $additional_bank;
        
        // Log advance settlement for debugging
        error_log("Sale calculation - Sale amount: {$amount}, Advance received: {$advance_received}, Advance settlement: {$advance_settlement}, Additional payments: " . ($additional_cash + $additional_bank));
        
        // Update the main sale transaction with correct payment amount
        $update_payment_sql = "UPDATE transactions SET payment_amount = $total_payment_received WHERE id = $sale_transaction_id";
        $conn->query($update_payment_sql);
        
        // Handle advance settlement (if any)
        if ($advance_settlement > 0) {
            $advance_receipt_id = 'ADV-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $advance_sql = "INSERT INTO transactions (
                company_id, party_id, receipt_id, transaction_type, date_of_transaction,
                gold_weight, purity, rate, gold_amount, payment_amount, payment_method, payment_type, booking_type,
                party_balance_before, party_balance_after, party_gold_balance_before, party_gold_balance_after,
                narration
            ) VALUES (
                $company_id, $party_id, '$advance_receipt_id', 'Advance_Settlement', '$date_of_transaction',
                0.000, 0.00, 0.00, 0.00, $advance_settlement, 'Advance', 'Payment_Out', '$booking_type',
                -$advance_received, -" . ($advance_received - $advance_settlement) . ", $available_weight, $available_weight,
                'Advance settlement for sale $receipt_id'
            )";
            
            if (!$conn->query($advance_sql)) {
                throw new Exception("Error creating advance settlement: " . $conn->error);
            }
        }
        
        // Handle additional cash received (if any)
        if ($additional_cash > 0) {
            $cash_receipt_id = 'CSH-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $cash_sql = "INSERT INTO transactions (
                company_id, party_id, receipt_id, transaction_type, date_of_transaction,
                gold_weight, purity, rate, gold_amount, payment_amount, payment_method, payment_type, booking_type,
                party_balance_before, party_balance_after, party_gold_balance_before, party_gold_balance_after,
                narration
            ) VALUES (
                $company_id, $party_id, '$cash_receipt_id', 'Received', '$date_of_transaction',
                0.000, 0.00, 0.00, 0.00, $additional_cash, 'Cash', 'Payment_In', 'Cash',
                -" . ($advance_received - $advance_settlement) . ", -" . ($advance_received - $advance_settlement + $additional_cash) . ", " . ($available_weight - $sell_weight) . ", " . ($available_weight - $sell_weight) . ",
                'Cash received for sale $receipt_id'
            )";
            
            if (!$conn->query($cash_sql)) {
                throw new Exception("Error creating cash received transaction: " . $conn->error);
            }
            
            // Update company cash balance
            if (!updateAccountBalance($conn, $company_id, 'Cash', $additional_cash)) {
                error_log("Failed to update company cash balance for sale $receipt_id");
            }
        }
        
        // Handle additional bank received (if any)
        if ($additional_bank > 0) {
            $bank_receipt_id = 'BNK-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            // All bank payments now use simplified 'Bank' method
            $bank_narration = 'Bank received for sale ' . $receipt_id;
            if (!empty($bank_payment_type)) {
                $bank_narration .= ' via ' . $bank_payment_type;
            }
            
            $bank_sql = "INSERT INTO transactions (
                company_id, party_id, receipt_id, transaction_type, date_of_transaction,
                gold_weight, purity, rate, gold_amount, payment_amount, payment_method, payment_type, booking_type,
                party_balance_before, party_balance_after, party_gold_balance_before, party_gold_balance_after,
                narration
            ) VALUES (
                $company_id, $party_id, '$bank_receipt_id', 'Received', '$date_of_transaction',
                0.000, 0.00, 0.00, 0.00, $additional_bank, 'Bank', 'Payment_In', 'Bank',
                -" . ($advance_received - $advance_settlement + $additional_cash) . ", -" . ($advance_received - $advance_settlement + $additional_cash + $additional_bank) . ", " . ($available_weight - $sell_weight) . ", " . ($available_weight - $sell_weight) . ",
                '$bank_narration'
            )";
            
            if (!$conn->query($bank_sql)) {
                throw new Exception("Error creating bank received transaction: " . $conn->error);
            }
            
            // Update company bank balance
            if (!updateAccountBalance($conn, $company_id, 'Bank', $additional_bank)) {
                error_log("Failed to update company bank balance for sale $receipt_id");
                // We don't block the transaction for this, but logging is important
            }
        }
        
        // Update gold stock (decrease company stock when selling)
        try {
            if ($stock_data && $stock_data['id']) {
                $stock_id = $stock_data['id'];
                $current_stock = $stock_data['current_stock'];
                $new_stock = $current_stock - $sell_weight;
                
                // Allow negative stock for tracking purposes
                $update_stock_sql = "UPDATE gold_stock SET current_stock = $new_stock, last_updated = NOW() WHERE id = $stock_id";
                if (!$conn->query($update_stock_sql)) {
                    error_log("Error updating gold stock: " . $conn->error);
                    // Don't throw exception - just log the error
                } else {
                    error_log("Stock updated successfully: ID $stock_id, Old: {$current_stock}g, New: {$new_stock}g, Sold: {$sell_weight}g");
                }
            } else {
                // If no stock record exists, create one with negative stock
                $insert_stock_sql = "INSERT INTO gold_stock (company_id, purity, current_stock, last_updated) VALUES ($company_id, $purity, -$sell_weight, NOW())";
                if (!$conn->query($insert_stock_sql)) {
                    error_log("Error creating gold stock record: " . $conn->error);
                    // Don't throw exception - just log the error
                } else {
                    error_log("New stock record created: Company $company_id, Purity $purity, Stock: -{$sell_weight}g");
                }
            }
        } catch (Exception $e) {
            // Log stock update error but don't block the sale
            error_log("Stock update failed but sale proceeding: " . $e->getMessage());
        }
        
        // Update party balances - separate cash and bank tracking
        // Sale means party is selling gold back to us, so we owe them money
        // Additional payments they make reduce their debt to us
        
        if ($booking_type == 'Cash') {
            // Cash booking: party gets cash for their gold (we owe them)
            // But if they make additional payments, it reduces their debt to us
            $cash_balance_change = $amount; // We owe them cash for the gold
            $cash_balance_change -= $advance_settlement; // Less any advance they already gave
            $cash_balance_change -= $additional_cash; // Less additional cash payment they made
            $bank_balance_change = -$additional_bank; // Additional bank payment reduces their debt
        } else {
            // Bank booking: party gets bank payment for their gold (we owe them)
            // But if they make additional payments, it reduces their debt to us
            $bank_balance_change = $amount; // We owe them bank payment for the gold
            $bank_balance_change -= $advance_settlement; // Less any advance they already gave
            $bank_balance_change -= $additional_bank; // Less additional bank payment they made
            $cash_balance_change = -$additional_cash; // Additional cash payment reduces their debt
        }
        
        $update_party_balance = "UPDATE parties SET 
            current_gold_balance = current_gold_balance - $sell_weight,
            cash_balance = cash_balance + ($cash_balance_change),
            bank_balance = bank_balance + ($bank_balance_change)
            WHERE id = $party_id";
        
        if (!$conn->query($update_party_balance)) {
            throw new Exception("Error updating party balance: " . $conn->error);
        }
        
        // Update current_balance as sum of the updated cash and bank balances
        $update_current_balance = "UPDATE parties SET 
            current_balance = cash_balance + bank_balance
            WHERE id = $party_id";
        
        if (!$conn->query($update_current_balance)) {
            throw new Exception("Error updating current balance: " . $conn->error);
        }
        
        // Get party details for response
        $party_sql = "SELECT party_name, contact_no FROM parties WHERE id = $party_id";
        $party_result = $conn->query($party_sql);
        $party_data = $party_result->fetch_assoc();
        
        $conn->commit();
        
        // Get updated stock information for response
        $final_stock_check = "SELECT current_stock FROM gold_stock WHERE purity = $purity AND company_id = $company_id ORDER BY id DESC LIMIT 1";
        $final_stock_result = $conn->query($final_stock_check);
        $final_stock = 0;
        if ($final_stock_result && $final_stock_result->num_rows > 0) {
            $final_stock_data = $final_stock_result->fetch_assoc();
            $final_stock = $final_stock_data['current_stock'];
        }
        
        // Return success response
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'message' => 'Gold sale completed successfully',
            'data' => [
                'receipt_id' => $receipt_id,
                'party_name' => $party_data['party_name'],
                'party_contact' => $party_data['contact_no'],
                'date_of_transaction' => $date_of_transaction,
                'sell_weight' => $sell_weight,
                'purity' => $purity,
                'rate' => $rate,
                'amount' => $amount,
                'advance_settlement' => $advance_settlement,
                'additional_cash' => $additional_cash,
                'additional_bank' => $additional_bank,
                'final_settlement' => $amount - $advance_settlement + $additional_cash + $additional_bank,
                'remaining_gold' => $available_weight - $sell_weight,
                'stock_before' => $company_stock,
                'stock_after' => $final_stock,
                'stock_warning' => $sell_weight > $company_stock ? "Sale completed with insufficient stock. Please add stock soon." : null
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
} else {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request'
    ]);
}
?>
