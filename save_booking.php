<?php
session_start();
require_once 'config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

$company_id = $_SESSION['company_id'];
$user_id = $_SESSION['user_id'];

try {
    $conn->begin_transaction();

    // Get form data
    $party_name = $conn->real_escape_string($_POST['party_name']);
    $booking_weight = floatval($_POST['booking_weight']);
    $purity = floatval($_POST['purity']);
    $rate = floatval($_POST['rate']);
    $amount = floatval($_POST['amount']);
    $narration = $conn->real_escape_string($_POST['narration'] ?? '');
    $booking_type = $conn->real_escape_string($_POST['booking_type']);
    
    // No payment collection during booking - all amounts are due
    $cash_received = 0;
    $bank_received = 0;
    $bank_payment_type = '';
    
    // Generate receipt ID
    $receipt_id = 'BK-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    // Check if party exists, if not create new one
    $party_check = "SELECT id, contact_no FROM parties WHERE company_id = $company_id AND party_name = '$party_name'";
    $party_result = $conn->query($party_check);
    
    if ($party_result->num_rows > 0) {
        $party_row = $party_result->fetch_assoc();
        $party_id = $party_row['id'];
        $party_contact = $party_row['contact_no'];
    } else {
        // Create new party with cash_balance and bank_balance
        $create_party = "INSERT INTO parties (company_id, party_name, current_balance, current_gold_balance, cash_balance, bank_balance) 
                        VALUES ($company_id, '$party_name', 0.00, 0.000, 0.00, 0.00)";
        $conn->query($create_party);
                $party_id = $conn->insert_id;
        $party_contact = null;
    }
    
    // Use the booking_type from the form
    $booking_type = ucfirst($booking_type); // Capitalize first letter
    
    // Insert booking transaction
    $booking_sql = "INSERT INTO transactions (
        company_id, party_id, receipt_id, transaction_type, date_of_transaction,
        gold_weight, purity, rate, gold_amount, payment_amount, payment_method, payment_type, booking_type,
        party_balance_before, party_balance_after, party_gold_balance_before, party_gold_balance_after,
        narration
    ) VALUES (
        $company_id, $party_id, '$receipt_id', 'Booking', NOW(),
        $booking_weight, $purity, $rate, $amount, 0.00, '$booking_type', 'Payment_In', '$booking_type',
        0.00, -$amount, 0.000, $booking_weight,
        '$narration'
    )";
    
    $conn->query($booking_sql);
    $booking_id = $conn->insert_id;
    
    // Update party balances - no payment received during booking
    // Balance interpretation:
    // - Negative balance: We owe party money (advance credit)
    // - Positive balance: Party owes us money (debt)
    // - Zero balance: No outstanding amount
    
    // For booking without payment: Party owes us money (creates positive balance = debt)
    if ($booking_type == 'Cash') {
        // Cash booking: party owes us cash (creates positive balance = debt)
        $cash_change = $amount; // Positive = debt for cash booking
        $bank_change = 0; // No bank debt for cash booking
    } else {
        // Bank booking: party owes us bank payment (creates positive balance = debt)
        $bank_change = $amount; // Positive = debt for bank booking
        $cash_change = 0; // No cash debt for bank booking
    }
    
    // Update all balances in one query to ensure consistency
    $update_party = "UPDATE parties SET 
        current_gold_balance = current_gold_balance + $booking_weight,
        cash_balance = cash_balance + ($cash_change),
        bank_balance = bank_balance + ($bank_change)
        WHERE id = $party_id";
    $conn->query($update_party);
    
    // Then update current_balance as sum of the updated cash and bank balances
    $update_current_balance = "UPDATE parties SET 
        current_balance = cash_balance + bank_balance
        WHERE id = $party_id";
    $conn->query($update_current_balance);
    
        $conn->commit();

        echo json_encode([
            'status' => 'success',
            'message' => 'Booking created successfully',
            'data' => [
                'receipt_id' => $receipt_id,
                'party_name' => $party_name,
                'booking_weight' => $booking_weight,
                'purity' => $purity,
                'rate' => $rate,
                'amount' => $amount,
                'booking_type' => $booking_type,
                'party_contact' => $party_contact
            ]
        ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>