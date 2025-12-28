<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

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
            case 'get_party_ledger':
                $party_id = intval($_POST['party_id']);
                
                // Get party basic info
                $party_sql = "SELECT * FROM parties WHERE id = $party_id AND company_id = $company_id";
                $party_result = $conn->query($party_sql);
                $party_data = $party_result->fetch_assoc();
                
                if (!$party_data) {
                    echo json_encode(['status' => 'error', 'message' => 'Party not found']);
                    exit;
                }
                
                // Get all transactions for this party
                $transactions_sql = "SELECT * FROM transactions 
                                   WHERE party_id = $party_id AND company_id = $company_id 
                                   ORDER BY date_of_transaction DESC, id DESC";
                $transactions_result = $conn->query($transactions_sql);
                $transactions = [];
                
                while ($row = $transactions_result->fetch_assoc()) {
                    $transactions[] = $row;
                }
                
                // Calculate summary with booking type breakdown
                $booked_weight = 0;
                $sold_weight = 0;
                $booked_weight_cash = 0;
                $sold_weight_cash = 0;
                $booked_weight_bank = 0;
                $sold_weight_bank = 0;
                $booked_amount = 0;
                $cash_received = 0;
                $bank_received = 0;
                $total_paid_out = 0;
                $gold_received_weight = 0;
                
                foreach ($transactions as $trans) {
                    switch ($trans['transaction_type']) {
                        case 'Booking':
                            $booked_weight += $trans['gold_weight'];
                            $booked_amount += $trans['gold_amount'];
                            // Booking type breakdown
                            if (isset($trans['booking_type']) && $trans['booking_type'] == 'Cash') {
                                $booked_weight_cash += $trans['gold_weight'];
                            } elseif (isset($trans['booking_type']) && $trans['booking_type'] == 'Bank') {
                                $booked_weight_bank += $trans['gold_weight'];
                            }
                            break;
                        case 'Sale':
                            $sold_weight += $trans['gold_weight'];
                            // Sale type breakdown
                            if (isset($trans['booking_type']) && $trans['booking_type'] == 'Cash') {
                                $sold_weight_cash += $trans['gold_weight'];
                            } elseif (isset($trans['booking_type']) && $trans['booking_type'] == 'Bank') {
                                $sold_weight_bank += $trans['gold_weight'];
                            }
                            break;
                        case 'Payment':
                            if ($trans['payment_type'] == 'Payment_In') {
                                // Only count positive amounts (filter out negative)
                                if ($trans['payment_amount'] > 0) {
                                if ($trans['payment_method'] == 'Cash' || empty($trans['payment_method'])) {
                                    $cash_received += $trans['payment_amount'];
                                } else {
                                    $bank_received += $trans['payment_amount'];
                                    }
                                }
                            } else {
                                $total_paid_out += $trans['payment_amount'];
                            }
                            break;
                        case 'Received':
                            // Received transactions are payments received from party
                            if ($trans['payment_amount'] > 0) {
                                if ($trans['payment_method'] == 'Cash' || empty($trans['payment_method'])) {
                                    $cash_received += $trans['payment_amount'];
                                } else {
                                    $bank_received += $trans['payment_amount'];
                                }
                            }
                            break;
                        case 'Exchange':
                            $gold_received_weight += floatval($trans['received_weight'] ?? 0);
                            break;
                    }
                }
                
                $remaining_weight = $booked_weight - $sold_weight;
                $remaining_weight_cash = $booked_weight_cash - $sold_weight_cash;
                $remaining_weight_bank = $booked_weight_bank - $sold_weight_bank;
                $total_received = $cash_received + $bank_received;
                $due_amount = $booked_amount - $total_received;
                
                echo json_encode([
                    'status' => 'success',
                    'party' => $party_data,
                    'transactions' => $transactions,
                    'summary' => [
                        'booked_weight' => $booked_weight,
                        'sold_weight' => $sold_weight,
                        'remaining_weight' => $remaining_weight,
                        'booked_weight_cash' => $booked_weight_cash,
                        'sold_weight_cash' => $sold_weight_cash,
                        'remaining_weight_cash' => $remaining_weight_cash,
                        'booked_weight_bank' => $booked_weight_bank,
                        'sold_weight_bank' => $sold_weight_bank,
                        'remaining_weight_bank' => $remaining_weight_bank,
                        'booked_amount' => $booked_amount,
                        'cash_received' => $cash_received,
                        'bank_received' => $bank_received,
                        'total_received' => $total_received,
                        'due_amount' => $due_amount,
                        'gold_received_weight' => $gold_received_weight,
                        // Add balance information from parties table
                        'current_balance' => floatval($party_data['current_balance'] ?? 0),
                        'cash_balance' => floatval($party_data['cash_balance'] ?? 0),
                        'bank_balance' => floatval($party_data['bank_balance'] ?? 0),
                        'current_gold_balance' => floatval($party_data['current_gold_balance'] ?? 0),
                        'cash_gold_balance' => floatval($party_data['cash_gold_balance'] ?? 0),
                        'bank_gold_balance' => floatval($party_data['bank_gold_balance'] ?? 0),
                        'total_paid_out' => $total_paid_out
                    ]
                ]);
                exit;
                
            case 'save_party':
                $party_name = $conn->real_escape_string($_POST['party_name'] ?? '');
                $address = $conn->real_escape_string($_POST['address'] ?? '');
                $contact_no = $conn->real_escape_string($_POST['contact_no'] ?? '');
                
                // Get balance values
                $cash_balance = floatval($_POST['cash_balance'] ?? 0);
                $bank_balance = floatval($_POST['bank_balance'] ?? 0);
                $current_gold_balance = floatval($_POST['current_gold_balance'] ?? 0);
                
                // Calculate total balance
                $current_balance = $cash_balance + $bank_balance;
                
                if (empty($party_name)) {
                    echo json_encode(['status' => 'error', 'message' => 'Party name is required']);
                    exit;
                }
                
                $sql = "INSERT INTO parties (company_id, party_name, address, contact_no, cash_balance, bank_balance, current_balance, current_gold_balance, cash_gold_balance, bank_gold_balance) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                // For new parties, split gold balance equally between cash and bank (or you can set to 0)
                $cash_gold_balance = $current_gold_balance / 2;
                $bank_gold_balance = $current_gold_balance / 2;
                $stmt->bind_param("isssdddddd", $company_id, $party_name, $address, $contact_no, $cash_balance, $bank_balance, $current_balance, $current_gold_balance, $cash_gold_balance, $bank_gold_balance);
                
                if ($stmt->execute()) {
                    $party_id = $stmt->insert_id;
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Party added successfully',
                        'party_id' => $party_id,
                        'party_name' => $party_name
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Error adding party: ' . $stmt->error
                    ]);
                }
                exit;
                
            case 'cut_vow':
                $sale_transaction_id = intval($_POST['sale_transaction_id'] ?? 0);
                $rate = floatval($_POST['rate'] ?? 0);
                
                if ($sale_transaction_id <= 0 || $rate <= 0) {
                    echo json_encode(['status' => 'error', 'message' => 'Invalid transaction ID or rate']);
                    exit;
                }
                
                $conn->begin_transaction();
                try {
                    // Get sale transaction
                    $sale_sql = "SELECT * FROM transactions WHERE id = $sale_transaction_id AND company_id = $company_id AND transaction_type = 'Sale'";
                    $sale_result = $conn->query($sale_sql);
                    $sale_trans = $sale_result->fetch_assoc();
                    
                    if (!$sale_trans) {
                        throw new Exception('Sale transaction not found');
                    }
                    
                    $party_id = $sale_trans['party_id'];
                    $gold_weight = floatval($sale_trans['gold_weight']);
                    $purity = floatval($sale_trans['purity']);
                    $sale_receipt_id = $sale_trans['receipt_id'];
                    $sale_date = $sale_trans['date_of_transaction'];
                    
                    // Calculate total amount
                    $total_amount = $gold_weight * $rate;
                    
                    // Get current party balances
                    $party_sql = "SELECT cash_balance, bank_balance, current_balance FROM parties WHERE id = $party_id AND company_id = $company_id";
                    $party_result = $conn->query($party_sql);
                    $party_data = $party_result->fetch_assoc();
                    
                    if (!$party_data) {
                        throw new Exception('Party not found');
                    }
                    
                    // Find associated payment/receipt transaction (same receipt_id or same date)
                    $payment_sql = "SELECT * FROM transactions 
                                   WHERE party_id = $party_id 
                                   AND company_id = $company_id 
                                   AND transaction_type IN ('Received', 'Payment')
                                   AND payment_type = 'Payment_In'
                                   AND payment_amount > 0
                                   AND DATE(date_of_transaction) = DATE('$sale_date')
                                   ORDER BY id DESC LIMIT 1";
                    $payment_result = $conn->query($payment_sql);
                    $payment_trans = $payment_result->fetch_assoc();
                    
                    $payment_amount = 0;
                    $payment_method = 'Cash';
                    if ($payment_trans) {
                        $payment_amount = floatval($payment_trans['payment_amount']);
                        $payment_method = $payment_trans['payment_method'] ?? 'Cash';
                    }
                    
                    // Determine booking type for sale (based on payment method or default to Cash)
                    $booking_type = $sale_trans['booking_type'] ?? $payment_method;
                    if (empty($booking_type)) {
                        $booking_type = 'Cash';
                    }
                    
                    // Logic explanation:
                    // When payment is received: balance = balance - payment_amount (reduces what party owes)
                    // When sale is made: balance = balance + sale_amount (increases what party owes)
                    // 
                    // Example: Party paid ₹5,00,000 advance, sale is ₹57,36,000
                    // - Initial balance: 0
                    // - After payment: 0 - 5,00,000 = -5,00,000 (we owe party ₹5,00,000 credit)
                    // - After sale: -5,00,000 + 57,36,000 = 52,36,000 (party owes us ₹52,36,000)
                    // - Net: ₹57,36,000 - ₹5,00,000 = ₹52,36,000 (remaining amount party owes)
                    //
                    // So we just need to ADD the sale amount to balance
                    // The payment was already correctly deducted, so we don't reverse it
                    
                    // Apply sale amount - ADD to balance (increases what party owes us)
                    if ($booking_type == 'Cash') {
                        $cash_adjustment = $total_amount; // Add to cash balance
                        $bank_adjustment = 0;
                    } else {
                        $cash_adjustment = 0;
                        $bank_adjustment = $total_amount; // Add to bank balance
                    }
                    
                    // Update sale transaction with rate and amount
                    $update_sale_sql = "UPDATE transactions SET 
                                       rate = $rate,
                                       gold_amount = $total_amount,
                                       booking_type = '$booking_type',
                                       updated_at = NOW()
                                       WHERE id = $sale_transaction_id";
                    if (!$conn->query($update_sale_sql)) {
                        throw new Exception('Error updating sale transaction: ' . $conn->error);
                    }
                    
                    // Update party balances
                    $update_party_sql = "UPDATE parties SET 
                                        cash_balance = cash_balance + $cash_adjustment,
                                        bank_balance = bank_balance + $bank_adjustment,
                                        current_balance = current_balance + ($cash_adjustment + $bank_adjustment)
                                        WHERE id = $party_id AND company_id = $company_id";
                    if (!$conn->query($update_party_sql)) {
                        throw new Exception('Error updating party balances: ' . $conn->error);
                    }
                    
                    $conn->commit();
                    
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Vow cut successfully. Rate applied and balances updated.',
                        'total_amount' => $total_amount,
                        'payment_reversed' => $payment_amount,
                        'net_adjustment' => ($cash_adjustment + $bank_adjustment)
                    ]);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Error cutting vow: ' . $e->getMessage()
                    ]);
                }
                exit;
                
            case 'search_parties':
                $search = $conn->real_escape_string($_POST['term'] ?? '');
                $where_clause = "WHERE p.company_id = $company_id";
                if (!empty($search)) {
                    $where_clause .= " AND p.party_name LIKE '%$search%'";
                }
                $sql = "SELECT p.*, 
                        p.cash_balance,
                        p.bank_balance,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' THEN t.gold_weight ELSE 0 END), 0) as booked_weight,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Sale' THEN t.gold_weight ELSE 0 END), 0) as sold_weight,
                        COALESCE(SUM(CASE WHEN t.transaction_type = 'Booking' THEN t.gold_amount ELSE 0 END), 0) as booked_amount,
                        COALESCE(SUM(CASE WHEN (t.transaction_type = 'Payment' OR t.transaction_type = 'Received') AND t.payment_type = 'Payment_In' AND t.payment_amount > 0 AND (t.payment_method = 'Cash' OR t.payment_method IS NULL OR t.payment_method = '') THEN t.payment_amount ELSE 0 END), 0) as cash_received,
                        COALESCE(SUM(CASE WHEN (t.transaction_type = 'Payment' OR t.transaction_type = 'Received') AND t.payment_type = 'Payment_In' AND t.payment_amount > 0 AND t.payment_method = 'Bank' THEN t.payment_amount ELSE 0 END), 0) as bank_received
                        FROM parties p 
                        LEFT JOIN transactions t ON p.id = t.party_id AND t.company_id = $company_id
                        $where_clause
                        GROUP BY p.id
                        ORDER BY p.party_name
                        LIMIT 50";
                $result = $conn->query($sql);
                $parties = [];
                while ($row = $result->fetch_assoc()) {
                    $remaining_weight = $row['booked_weight'] - $row['sold_weight'];
                    $total_received = $row['cash_received'] + $row['bank_received'];
                    $due_amount = $row['booked_amount'] - $total_received;
                    
                    // Use current_balance from parties table as outstanding balance
                    $current_balance = floatval($row['current_balance'] ?? 0);
                    
                    $parties[] = [
                        'id' => $row['id'],
                        'party_name' => $row['party_name'],
                        'address' => $row['address'],
                        'contact_no' => $row['contact_no'],
                        'booked_weight' => floatval($row['booked_weight']),
                        'sold_weight' => floatval($row['sold_weight']),
                        'remaining_weight' => floatval($remaining_weight),
                        'booked_amount' => floatval($row['booked_amount']),
                        'cash_received' => floatval($row['cash_received']),
                        'bank_received' => floatval($row['bank_received']),
                        'total_received' => floatval($total_received),
                        'due_amount' => floatval($due_amount),
                        'current_balance' => $current_balance,
                        'cash_balance' => floatval($row['cash_balance'] ?? 0),
                        'bank_balance' => floatval($row['bank_balance'] ?? 0)
                    ];
                }
                echo json_encode($parties);
                exit;
                
            case 'clear_party_balance':
                $party_id = intval($_POST['party_id'] ?? 0);
                
                if ($party_id <= 0) {
                    echo json_encode(['status' => 'error', 'message' => 'Invalid party ID']);
                    exit;
                }
                
                // Verify party belongs to this company
                $verify_sql = "SELECT party_name FROM parties WHERE id = $party_id AND company_id = $company_id";
                $verify_result = $conn->query($verify_sql);
                
                if (!$verify_result || $verify_result->num_rows === 0) {
                    echo json_encode(['status' => 'error', 'message' => 'Party not found']);
                    exit;
                }
                
                $party_data = $verify_result->fetch_assoc();
                $party_name = $party_data['party_name'];
                
                $conn->begin_transaction();
                try {
                    // Reset all balances to zero
                    $update_sql = "UPDATE parties SET 
                                  current_balance = 0,
                                  cash_balance = 0,
                                  bank_balance = 0,
                                  current_gold_balance = 0,
                                  cash_gold_balance = 0,
                                  bank_gold_balance = 0
                                  WHERE id = $party_id AND company_id = $company_id";
                    
                    if (!$conn->query($update_sql)) {
                        throw new Exception('Failed to clear balances: ' . $conn->error);
                    }
                    
                    // Create an adjustment transaction record for audit trail
                    $receipt_id = 'CLEAR_' . $party_id . '_' . time();
                    $narration = "All balances cleared by $user_name on " . date('Y-m-d H:i:s');
                    
                    $adjustment_sql = "INSERT INTO transactions (
                                      receipt_id,
                                      date_of_transaction,
                                      party_id,
                                      company_id,
                                      user_id,
                                      transaction_type,
                                      narration
                                  ) VALUES (
                                      '$receipt_id',
                                      NOW(),
                                      $party_id,
                                      $company_id,
                                      $user_id,
                                      'Balance_Clear',
                                      '$narration'
                                  )";
                    
                    if (!$conn->query($adjustment_sql)) {
                        throw new Exception('Failed to create adjustment record: ' . $conn->error);
                    }
                    
                    $conn->commit();
                    
                    echo json_encode([
                        'status' => 'success',
                        'message' => "All balances cleared for party: $party_name",
                        'party_name' => $party_name
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

// Start output buffering to capture content
ob_start();
?>


    <!-- Main Content Container -->
<div class="w-full">
        <!-- Party Search Container -->
        <div class="party-list-container" id="partyListContainer">
            <div class="gradient-card rounded-lg shadow-sm">
                <div class="px-4 py-4">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-poppins font-semibold text-gray-800 flex items-center">
                            <i class="fas fa-search text-blue-500 mr-2"></i>
                            Search Party Ledger
                        </h2>
                        <button id="addNewPartyBtn" class="px-4 py-2 bg-green-500 hover:bg-green-600 text-white rounded-lg transition-all duration-200 shadow-lg flex items-center space-x-2">
                            <i class="fas fa-plus"></i>
                            <span>New Party</span>
                            <kbd class="ml-2 px-1.5 py-0.5 text-xs bg-green-600 border border-green-400 rounded font-mono">Alt+A</kbd>
                        </button>
                    </div>
                    <div class="relative">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Search Party</label>
                        <div class="relative">
                            <input type="text" 
                                   id="partySearchInput"
                                   class="block w-full pl-10 pr-4 py-3 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                                   placeholder="Press / to focus, or click to see all parties, then type to filter..."
                                   autocomplete="off">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-search text-gray-400"></i>
                </div>
                    </div>
                        <div id="partyList" class="hidden absolute top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-xl max-h-96 overflow-y-auto z-50"></div>
                    </div>
                    <div class="mt-4">
                        <div class="text-sm text-gray-500">
                            <i class="fas fa-info-circle mr-1"></i>
                            <span>Focus to see all parties. Type to filter. Use <kbd class="px-1.5 py-0.5 bg-gray-100 border border-gray-300 rounded text-xs font-mono">↑↓</kbd> to navigate, <kbd class="px-1.5 py-0.5 bg-gray-100 border border-gray-300 rounded text-xs font-mono">Enter</kbd> to select, <kbd class="px-1.5 py-0.5 bg-gray-100 border border-gray-300 rounded text-xs font-mono">Esc</kbd> to toggle list, <kbd class="px-1.5 py-0.5 bg-gray-100 border border-gray-300 rounded text-xs font-mono">/</kbd> to focus search.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- New Party Modal -->
        <div id="newPartyModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4" tabindex="-1">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full max-h-[90vh] overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
                <div class="sticky top-0 bg-white border-b border-gray-200 px-4 py-3 flex items-center justify-between rounded-t-lg">
                    <h3 id="modalTitle" class="text-lg font-semibold text-gray-900">Add New Party</h3>
                    <button id="closeNewPartyModal" class="text-gray-400 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 rounded" aria-label="Close modal">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <form id="newPartyForm" class="px-4 py-4">
                    <!-- Party Name -->
                    <div class="mb-3">
                        <label for="newPartyName" class="block text-sm font-medium text-gray-700 mb-1">Party Name <span class="text-red-500">*</span></label>
                        <input type="text" 
                               id="newPartyName" 
                               name="party_name"
                               class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                               placeholder="Enter party name"
                               required
                               autocomplete="off">
                    </div>
                    
                    <!-- Address -->
                    <div class="mb-3">
                        <label for="newPartyAddress" class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                        <textarea id="newPartyAddress" 
                                  name="address"
                                  rows="2"
                                  class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                                  placeholder="Enter party address (optional)"></textarea>
                    </div>
                    
                    <!-- Contact Number -->
                    <div class="mb-3">
                        <label for="newPartyContact" class="block text-sm font-medium text-gray-700 mb-1">Contact Number</label>
                        <input type="tel" 
                               id="newPartyContact" 
                               name="contact_no"
                               class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                               placeholder="Enter contact number (optional)"
                               autocomplete="off">
                    </div>
                    
                    <!-- Previous Balance Information -->
                    <div class="mb-4 p-3 bg-gray-50 border border-gray-200 rounded-lg">
                        <label class="block text-xs font-medium text-gray-600 mb-2">Previous Balance Information (Optional)</label>
                        <div class="grid grid-cols-2 gap-3 text-xs">
                            <div>
                                <label for="prevCashBalance" class="block text-gray-500 mb-1">Cash Balance (₹)</label>
                                <input type="number" 
                                       id="prevCashBalance" 
                                       name="cash_balance"
                                       step="0.01"
                                       min="0"
                                       value="0.00"
                                       class="block w-full px-2 py-1.5 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                                       placeholder="0.00">
                            </div>
                            <div>
                                <label for="prevBankBalance" class="block text-gray-500 mb-1">Bank Balance (₹)</label>
                                <input type="number" 
                                       id="prevBankBalance" 
                                       name="bank_balance"
                                       step="0.01"
                                       min="0"
                                       value="0.00"
                                       class="block w-full px-2 py-1.5 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                                       placeholder="0.00">
                            </div>
                            <div>
                                <label for="prevGoldBalance" class="block text-gray-500 mb-1">Gold Balance (g)</label>
                                <input type="number" 
                                       id="prevGoldBalance" 
                                       name="current_gold_balance"
                                       step="0.001"
                                       min="0"
                                       value="0.000"
                                       class="block w-full px-2 py-1.5 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                                       placeholder="0.000">
                            </div>
                            <div>
                                <label class="block text-gray-500 mb-1">Total Balance (₹)</label>
                                <div id="prevTotalBalance" class="px-2 py-1.5 text-sm font-semibold text-gray-700 bg-gray-100 border border-gray-300 rounded-lg">₹0.00</div>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mt-2 italic">Note: Enter previous balances if this party has existing balances. Leave as 0.00 for new parties.</p>
                    </div>
                    
                    <!-- Form Actions -->
                    <div class="flex justify-end space-x-2 pt-3 border-t border-gray-200">
                        <button type="button" 
                                id="cancelNewPartyBtn" 
                                class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-gray-500 transition-colors">
                            Cancel
                        </button>
                        <button type="submit" 
                                id="saveNewPartyBtn" 
                                class="px-4 py-2 text-sm font-medium text-white bg-green-500 hover:bg-green-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 transition-colors">
                            <i class="fas fa-save mr-1"></i>Save Party
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Cut Vow Modal -->
        <div id="cutVowModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4" tabindex="-1">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full" role="dialog" aria-modal="true" aria-labelledby="cutVowModalTitle">
                <div class="sticky top-0 bg-white border-b border-gray-200 px-4 py-3 flex items-center justify-between rounded-t-lg">
                    <h3 id="cutVowModalTitle" class="text-lg font-semibold text-gray-900">Cut Vow - Enter Rate</h3>
                    <button id="closeCutVowModal" class="text-gray-400 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 rounded" aria-label="Close modal">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <form id="cutVowForm" class="px-4 py-4">
                    <input type="hidden" id="cutVowTransactionId" name="transaction_id">
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Gold Weight</label>
                        <div class="px-3 py-2 bg-gray-50 border border-gray-300 rounded-lg text-sm font-semibold text-gray-700" id="cutVowWeight">0.000g</div>
                    </div>
                    
                    <div class="mb-4">
                        <label for="cutVowRate" class="block text-sm font-medium text-gray-700 mb-2">Rate (₹ per gram) <span class="text-red-500">*</span></label>
                        <input type="number" 
                               id="cutVowRate" 
                               name="rate"
                               step="0.01"
                               min="0.01"
                               required
                               class="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                               placeholder="Enter rate per gram"
                               autocomplete="off">
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Total Amount</label>
                        <div class="px-3 py-2 bg-green-50 border border-green-300 rounded-lg text-sm font-bold text-green-700" id="cutVowTotalAmount">₹0.00</div>
                    </div>
                    
                    <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                        <p class="text-xs text-blue-700">
                            <i class="fas fa-info-circle mr-1"></i>
                            <strong>Note:</strong> This will reverse the initial payment and apply the full sale amount based on the rate you enter.
                        </p>
                    </div>
                    
                    <div class="flex justify-end space-x-2 pt-3 border-t border-gray-200">
                        <button type="button" 
                                id="cancelCutVowBtn" 
                                class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-gray-500 transition-colors">
                            Cancel
                        </button>
                        <button type="submit" 
                                id="saveCutVowBtn" 
                                class="px-4 py-2 text-sm font-medium text-white bg-orange-500 hover:bg-orange-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 transition-colors">
                            <i class="fas fa-cut mr-1"></i>Cut Vow
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Individual Party Ledger Container -->
        <div class="ledger-container hidden" id="ledgerContainer">
            <!-- Summary Cards -->
            <div id="summaryCards"></div>
            
            <!-- Party Details Card -->
            <div class="gradient-card rounded-xl shadow-sm mb-4">
                <div class="px-4 py-3 border-b border-gray-100">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                        <div class="flex-1">
                            <h2 class="text-lg font-bold text-gray-800 mb-2" id="partyName"></h2>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                                <div>
                                    <p class="text-sm text-gray-600">
                                        <i class="fas fa-phone mr-2"></i>
                                        <span id="partyContact">No contact</span>
                                    </p>
                    </div>
                                <div>
                                    <p class="text-sm text-gray-600">
                                        <i class="fas fa-map-marker-alt mr-2"></i>
                                        <span id="partyAddress">No address</span>
                                    </p>
                    </div>
                </div>
            </div>
                        <div class="mt-3 md:mt-0 md:text-right">
                            <p class="text-sm text-gray-500 mb-1">Party ID: <span id="partyId" class="font-medium"></span></p>
                            <span id="accountStatus" class="inline-flex px-3 py-1 rounded-full text-sm font-medium"></span>
                    </div>
                    </div>
                </div>
            </div>

            <!-- Transaction History -->
            <div class="gradient-card rounded-xl shadow-sm">
                <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="text-base font-semibold text-gray-800">Transaction History</h3>
                    <button class="px-3 py-1.5 text-sm font-medium text-white bg-green-500 hover:bg-green-600 rounded-lg transition-all duration-200 shadow-sm flex items-center space-x-1" id="exportPdfBtn">
                        <i class="fas fa-file-pdf"></i>
                        <span>Export PDF</span>
                    </button>
                </div>
                <div class="p-4">
                    <!-- Professional Tab Menu -->
                    <div class="flex flex-wrap gap-1 mb-4 bg-gray-100 p-1 rounded-lg">
                        <button class="tab-btn flex-1 px-3 py-2 text-sm font-medium rounded-md bg-blue-600 text-white transition-colors duration-200" data-tab="all">
                            <i class="fas fa-list mr-1"></i>All
                        </button>
                        <button class="tab-btn flex-1 px-3 py-2 text-sm font-medium rounded-md bg-white text-blue-600 hover:bg-blue-50 transition-colors duration-200" data-tab="bookings">
                            <i class="fas fa-book mr-1"></i>Bookings
                        </button>
                        <button class="tab-btn flex-1 px-3 py-2 text-sm font-medium rounded-md bg-white text-blue-600 hover:bg-blue-50 transition-colors duration-200" data-tab="sales">
                            <i class="fas fa-shopping-cart mr-1"></i>Sales
                        </button>
                        <button class="tab-btn flex-1 px-3 py-2 text-sm font-medium rounded-md bg-white text-blue-600 hover:bg-blue-50 transition-colors duration-200" data-tab="payments">
                            <i class="fas fa-money-bill-wave mr-1"></i>Payments
                        </button>
                        <button class="tab-btn flex-1 px-3 py-2 text-sm font-medium rounded-md bg-white text-blue-600 hover:bg-blue-50 transition-colors duration-200" data-tab="gold-received">
                            <i class="fas fa-coins mr-1"></i>Gold Received
                        </button>
                    </div>
                    
                    <!-- Transaction Tables -->
                    <div class="overflow-hidden">
                        <div class="overflow-x-auto hide-scrollbar-x">
                            <!-- All Transactions Table -->
                            <div id="allTransactionsTable" class="tab-content">
                                <table class="w-full text-xs">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Booking Type</th>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Receipt</th>
                                            <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase">Weight</th>
                                            <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase">Rate</th>
                                            <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                                            <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase">Payment</th>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Narration</th>
                                            <th class="px-2 py-2 text-center text-xs font-medium text-gray-500 uppercase">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-100">
                                        <!-- All transactions will be loaded here -->
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Bookings Table -->
                            <div id="bookingsTable" class="tab-content hidden">
                                <table class="w-full text-xs">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Booking Type</th>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Receipt</th>
                                            <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase">Weight</th>
                                            <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase">Purity</th>
                                            <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase">Rate</th>
                                            <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Narration</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-100">
                                        <!-- Booking transactions will be loaded here -->
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Sales Table -->
                            <div id="salesTable" class="tab-content hidden">
                                <table class="w-full text-xs">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Booking Type</th>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Receipt</th>
                                            <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase">Weight</th>
                                            <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase">Purity</th>
                                            <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase">Rate</th>
                                            <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Narration</th>
                                            <th class="px-2 py-2 text-center text-xs font-medium text-gray-500 uppercase">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-100">
                                        <!-- Sale transactions will be loaded here -->
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Payments Table -->
                            <div id="paymentsTable" class="tab-content hidden">
                                <table class="w-full text-xs">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Receipt</th>
                                            <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Narration</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-100">
                                        <!-- Payment transactions will be loaded here -->
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Gold Received Table -->
                            <div id="goldReceivedTable" class="tab-content hidden">
                                <table class="w-full text-xs">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Receipt</th>
                                            <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase">Weight</th>
                                            <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase">Rate</th>
                                            <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase">Value</th>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase">Narration</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-100">
                                        <!-- Gold received transactions will be loaded here -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Styles -->
    <style>
        /* Modal Styles */
        #newPartyModal {
            backdrop-filter: blur(2px);
        }
        
        #newPartyModal .bg-white {
            animation: modalSlideIn 0.2s ease-out;
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Focus styles for modal */
        #newPartyModal input:focus,
        #newPartyModal textarea:focus,
        #newPartyModal button:focus {
            outline: 2px solid #3b82f6;
            outline-offset: 2px;
        }
        
        /* Party list scrollbar */
        #partyList::-webkit-scrollbar {
            width: 6px;
        }
        
        #partyList::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        
        #partyList::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 3px;
        }
        
        #partyList::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        
        /* Keyboard shortcut styling */
        kbd {
            font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, "Liberation Mono", monospace;
            font-size: 0.75rem;
            line-height: 1.5;
        }
        
        /* Party item hover effect */
        .party-item:hover {
            transform: translateX(2px);
        }
        
        .party-item {
            transition: all 0.15s ease;
        }
        
        /* Hide horizontal scrollbar but keep scrolling functionality */
        .hide-scrollbar-x {
            scrollbar-width: none; /* Firefox */
            -ms-overflow-style: none; /* IE and Edge */
        }
        
        .hide-scrollbar-x::-webkit-scrollbar {
            display: none; /* Chrome, Safari, Opera */
            height: 0;
        }
        
        .hide-scrollbar-x::-webkit-scrollbar-track {
            display: none;
        }
        
        .hide-scrollbar-x::-webkit-scrollbar-thumb {
            display: none;
        }
    </style>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="js/keyboard-navigation-generic.js"></script>
    <script>
        $(document).ready(function() {
            // New Party Modal Functions
            function showNewPartyModal() {
                // Reset balance fields (new party has zero balance)
                $('#prevCashBalance').val('0.00');
                $('#prevBankBalance').val('0.00');
                $('#prevGoldBalance').val('0.000');
                updateTotalBalance();
                
                $('#newPartyModal').removeClass('hidden').addClass('flex');
                // Focus on party name field after a short delay to ensure modal is visible
                setTimeout(() => {
                    $('#newPartyName').focus();
                }, 100);
            }
            
            // Calculate and update total balance
            function updateTotalBalance() {
                const cashBalance = parseFloat($('#prevCashBalance').val()) || 0;
                const bankBalance = parseFloat($('#prevBankBalance').val()) || 0;
                const totalBalance = cashBalance + bankBalance;
                $('#prevTotalBalance').text('₹' + formatIndianCurrency(totalBalance));
            }
            
            // Update total balance when cash or bank balance changes
            $('#prevCashBalance, #prevBankBalance').on('input', function() {
                updateTotalBalance();
            });
            
            function hideNewPartyModal() {
                $('#newPartyModal').removeClass('flex').addClass('hidden');
                $('#newPartyForm')[0].reset();
            }
            
            // Keyboard navigation for modal
            $('#newPartyModal').on('keydown', function(e) {
                // Close on Escape
                if (e.key === 'Escape') {
                    hideNewPartyModal();
                    return;
                }
                
                // Tab navigation within modal
                if (e.key === 'Tab') {
                    const focusableElements = $('#newPartyModal').find('input, textarea, button, [tabindex]:not([tabindex="-1"])').filter(':visible');
                    const firstElement = focusableElements.first();
                    const lastElement = focusableElements.last();
                    
                    if (e.shiftKey) {
                        // Shift + Tab
                        if (document.activeElement === firstElement[0]) {
                            e.preventDefault();
                            lastElement.focus();
                        }
                    } else {
                        // Tab
                        if (document.activeElement === lastElement[0]) {
                            e.preventDefault();
                            firstElement.focus();
                        }
                    }
                }
            });
            
            // Open new party modal
            $('#addNewPartyBtn').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                showNewPartyModal();
            });
            
            // Auto-load all parties when search input is focused
            $('#partySearchInput').on('focus', function() {
                const currentValue = $(this).val().trim();
                // Always load parties when focused (if list is hidden or empty)
                if (!partyListVisible || $('#partyList').hasClass('hidden') || $('#partyList .party-item').length === 0) {
                    // Load all parties when first focused
                    searchParties(currentValue || '');
                }
            });
            
            // Close modal buttons
            $('#closeNewPartyModal, #cancelNewPartyBtn').on('click', function() {
                hideNewPartyModal();
            });
            
            // Close on backdrop click
            $('#newPartyModal').on('click', function(e) {
                if (e.target === this) {
                    hideNewPartyModal();
                }
            });
            
            // Save new party - handle form submission
            $('#newPartyForm').on('submit', function(e) {
                e.preventDefault();
                saveNewParty();
            });
            
            $('#saveNewPartyBtn').on('click', function(e) {
                e.preventDefault();
                saveNewParty();
            });
            
            function saveNewParty() {
                const partyName = $('#newPartyName').val().trim();
                const address = $('#newPartyAddress').val().trim();
                const contactNo = $('#newPartyContact').val().trim();
                const cashBalance = parseFloat($('#prevCashBalance').val()) || 0;
                const bankBalance = parseFloat($('#prevBankBalance').val()) || 0;
                const goldBalance = parseFloat($('#prevGoldBalance').val()) || 0;
                
                if (!partyName) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Validation Error',
                        text: 'Party name is required!'
                    });
                    $('#newPartyName').focus();
                    return;
                }
                
                // Show loading
                Swal.fire({
                    title: 'Saving...',
                    text: 'Please wait while we create the party',
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                $.post('', {
                    action: 'save_party',
                    party_name: partyName,
                    address: address,
                    contact_no: contactNo,
                    cash_balance: cashBalance,
                    bank_balance: bankBalance,
                    current_gold_balance: goldBalance
                }, function(response) {
                    Swal.close();
                    if (response.status === 'success') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: 'Party created successfully!',
                            timer: 2000,
                            showConfirmButton: false
                        }).then(() => {
                            hideNewPartyModal();
                            // Refresh party list and select the new party
                            $('#partySearchInput').val(partyName);
                            $('#partySearchInput').trigger('input');
                            setTimeout(() => {
                                // Try to find and select the new party
                                const partyItems = document.querySelectorAll('#partyList .party-item');
                                for (let item of partyItems) {
                                    if (item.getAttribute('data-name') === partyName) {
                                        const partyId = item.getAttribute('data-id');
                                        if (partyId) {
                                            selectParty(partyId);
                                            break;
                                        }
                                    }
                                }
                            }, 500);
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to create party'
                        });
                    }
                }, 'json').fail(function(xhr, status, error) {
                    Swal.close();
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'An error occurred while creating the party. Please try again.'
                    });
                });
            }
            
            // Allow Enter key to submit form (but not in textarea)
            $('#newPartyForm').on('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey && e.target.tagName !== 'TEXTAREA') {
                    e.preventDefault();
                    $('#saveNewPartyBtn').click();
                }
            });
            
            // Prevent form submission on Enter in textarea (allow new lines)
            $('#newPartyAddress').on('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    // Allow default behavior (new line)
                    return true;
                }
            });
            let currentPartyId = null;
            let partyListVisible = false;
            let currentIndex = -1;
            
            // Check if party_id is provided in URL
            const urlParams = new URLSearchParams(window.location.search);
            const partyIdFromUrl = urlParams.get('party_id');
            
            if (partyIdFromUrl) {
                // Load specific party ledger directly
                loadPartyLedger(parseInt(partyIdFromUrl));
            }
            
            // Party search functionality
            let searchTimer;
            $('#partySearchInput').on('input', function() {
                const term = $(this).val().trim();
                
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => {
                    // Search even with empty term (shows all parties)
                    searchParties(term);
                }, 150); // Reduced delay for better responsiveness
            });
            
            // Search parties
            function searchParties(term) {
                $.post('', {action: 'search_parties', term: term}, function(parties) {
                    renderPartiesDropdown(parties);
                }, 'json');
            }
            
            // Focus search field on page load (only if party list container is visible)
            setTimeout(function() {
                if ($('#partyListContainer').is(':visible') && !$('#ledgerContainer').is(':visible')) {
                    $('#partySearchInput').focus();
                    // Auto-load all parties on initial focus
                    searchParties('');
                }
            }, 100);
            
            // Keyboard shortcut to open new party modal (Alt+A)
            $(document).on('keydown', function(e) {
                // Only when party list container is visible and not in input/textarea
                if ($('#partyListContainer').is(':visible') && !$('#ledgerContainer').is(':visible')) {
                    if (e.altKey && e.key.toLowerCase() === 'a' && e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA') {
                        e.preventDefault();
                        showNewPartyModal();
                    }
                }
            });
            
            // Render parties dropdown
            function renderPartiesDropdown(parties) {
                const partyList = $('#partyList');
                partyList.empty();
                currentIndex = -1;
                
                if (parties.length === 0) {
                    partyList.html(`
                        <div class="px-4 py-3 text-center text-sm text-gray-500">
                            <i class="fas fa-search text-gray-300 mb-2"></i>
                            <p>No parties found</p>
                            <p class="text-xs mt-1">Try a different search term or create a new party</p>
                        </div>
                    `).removeClass('hidden');
                    partyListVisible = true;
                    return;
                }
                
                // Show header with count and shortcuts
                const headerText = parties.length >= 50 
                    ? `Showing first 50 parties. Type to filter.`
                    : `Showing ${parties.length} ${parties.length === 1 ? 'party' : 'parties'}. Type to filter.`;
                
                partyList.append(`
                    <div class="px-3 py-2 bg-blue-50 border-b border-blue-200 text-xs text-blue-700 sticky top-0 z-10">
                        <div class="flex items-center justify-between">
                            <div>
                                <i class="fas fa-info-circle mr-1"></i>
                                ${headerText}
                            </div>
                            <div class="flex items-center space-x-2 text-xs">
                                <span class="hidden sm:inline"><kbd class="px-1.5 py-0.5 bg-white border border-blue-300 rounded font-mono">↑↓</kbd> Navigate</span>
                                <span class="hidden sm:inline"><kbd class="px-1.5 py-0.5 bg-white border border-blue-300 rounded font-mono">Enter</kbd> Select</span>
                                <span><kbd class="px-1.5 py-0.5 bg-white border border-blue-300 rounded font-mono">Esc</kbd> Close</span>
                            </div>
                        </div>
                    </div>
                `);
                
                parties.forEach((party, index) => {
                    // Use current_balance instead of due_amount for outstanding balance
                    const outstandingBalance = parseFloat(party.current_balance) || 0;
                    const statusClass = outstandingBalance > 0 ? 'text-red-600' : outstandingBalance < 0 ? 'text-green-600' : 'text-gray-600';
                    const statusBadge = outstandingBalance > 0 ? 'bg-red-100 text-red-800' : outstandingBalance < 0 ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800';
                    const statusText = outstandingBalance > 0 ? 'Due' : outstandingBalance < 0 ? 'Credit' : 'Clear';
                    
                    const partyItem = document.createElement('div');
                    partyItem.className = 'px-3 py-2.5 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-b-0 transition-colors duration-150 party-item group';
                    partyItem.setAttribute('data-index', index);
                    partyItem.setAttribute('data-id', party.id || '');
                    partyItem.setAttribute('data-name', party.party_name || '');
                    
                    partyItem.innerHTML = `
                        <div class="flex items-center justify-between">
                            <div class="flex items-center flex-1 min-w-0">
                                <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center text-white text-xs font-semibold mr-3 shadow-sm flex-shrink-0">
                                    ${(party.party_name || 'U').charAt(0).toUpperCase()}
                                    </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center space-x-2">
                                        <div class="text-sm font-semibold text-gray-900 truncate">${party.party_name || 'Unknown Party'}</div>
                                        <span class="text-xs text-gray-500 font-mono bg-gray-50 px-1.5 py-0.5 rounded">ID: ${party.id}</span>
                                    </div>
                                    <div class="text-xs text-gray-500 truncate mt-0.5">
                                        ${party.contact_no ? `<i class="fas fa-phone mr-1"></i>${party.contact_no}` : ''}
                                        ${party.address ? `<span class="ml-2"><i class="fas fa-map-marker-alt mr-1"></i>${party.address}</span>` : ''}
                                </div>
                                </div>
                            </div>
                            <div class="ml-4 text-right flex-shrink-0">
                                <div class="text-sm font-bold ${statusClass}">₹${formatIndianCurrency(Math.abs(outstandingBalance))}</div>
                                <div class="text-xs ${statusBadge} px-2 py-0.5 rounded-full inline-block mt-1">${statusText}</div>
                            </div>
                            <div class="ml-2 flex items-center space-x-2">
                                <span class="text-xs text-gray-400 hidden group-hover:inline">Press <kbd class="px-1 py-0.5 bg-gray-100 border border-gray-300 rounded text-xs font-mono">Enter</kbd></span>
                                <i class="fas fa-chevron-right text-gray-400"></i>
                            </div>
                        </div>
                    `;
                    
                    // Add click handler
                    partyItem.addEventListener('click', (e) => {
                        e.stopPropagation();
                        const partyId = partyItem.getAttribute('data-id');
                        if (partyId) {
                            selectParty(partyId);
                        }
                    });
                    
                    partyList[0].appendChild(partyItem);
                });
                
                partyList.removeClass('hidden');
                partyListVisible = true;
            }
            
            // Select party and load ledger
            function selectParty(partyId) {
                $('#partyList').addClass('hidden');
                partyListVisible = false;
                currentIndex = -1;
                loadPartyLedger(parseInt(partyId));
            }
            
            // Keyboard navigation for party list
            $('#partySearchInput').on('keydown', function(e) {
                const partyItems = document.querySelectorAll('#partyList .party-item');
                
                // If dropdown is visible, handle arrow keys and Enter
                if (partyListVisible && partyItems.length > 0) {
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        e.stopPropagation();
                        if (currentIndex < 0) {
                            currentIndex = 0;
                        } else {
                            currentIndex = Math.min(currentIndex + 1, partyItems.length - 1);
                        }
                        updatePartyHighlight();
                        return;
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        e.stopPropagation();
                        if (currentIndex <= 0) {
                            currentIndex = -1;
                        } else {
                            currentIndex = Math.max(currentIndex - 1, 0);
                        }
                        updatePartyHighlight();
                        return;
                    } else if (e.key === 'Enter') {
                        e.preventDefault();
                        e.stopPropagation();
                        // If no item is selected, select the first one
                        if (currentIndex < 0 && partyItems.length > 0) {
                            currentIndex = 0;
                            updatePartyHighlight();
                        }
                        // Select the highlighted item
                        if (currentIndex >= 0 && currentIndex < partyItems.length) {
                            const selectedItem = partyItems[currentIndex];
                            if (selectedItem) {
                                const partyId = selectedItem.getAttribute('data-id');
                                if (partyId) {
                                    selectParty(partyId);
                                }
                            }
                        }
                        return;
                    } else if (e.key === 'Escape') {
                        e.preventDefault();
                        e.stopPropagation();
                        // If list is visible, hide it
                        if (partyListVisible) {
                            $('#partyList').addClass('hidden');
                            partyListVisible = false;
                            currentIndex = -1;
                        } else {
                            // If list is hidden, show it again
                            const searchTerm = $('#partySearchInput').val().trim();
                            searchParties(searchTerm || '');
                        }
                        return;
                    }
                }
            });
            
            // Function to update party selection highlighting
            function updatePartyHighlight() {
                const partyItems = document.querySelectorAll('#partyList .party-item');
                
                partyItems.forEach((item, index) => {
                    if (index === currentIndex && currentIndex >= 0) {
                        item.classList.add('bg-blue-100', 'border-l-4', 'border-blue-500', 'ring-2', 'ring-blue-300');
                        item.classList.remove('hover:bg-blue-50');
                    } else {
                        item.classList.remove('bg-blue-100', 'border-l-4', 'border-blue-500', 'ring-2', 'ring-blue-300');
                        item.classList.add('hover:bg-blue-50');
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
                if (!$(e.target).closest('#partySearchInput, #partyList').length && partyListVisible) {
                    $('#partyList').addClass('hidden');
                    partyListVisible = false;
                    currentIndex = -1;
                }
            });
            
            // Load party ledger
            function loadPartyLedger(partyId) {
                console.log('Loading party ledger for ID:', partyId);
                currentPartyId = partyId;
                
                $.post('', {action: 'get_party_ledger', party_id: partyId}, function(response) {
                    console.log('Response received:', response);
                    if (response.status === 'success') {
                        showPartyLedger(response);
                    } else {
                        console.error('Error loading party ledger:', response.message);
                        alert('Error loading party ledger: ' + response.message);
                    }
                }, 'json').fail(function(xhr, status, error) {
                    console.error('AJAX error:', error);
                    alert('Error loading party ledger: ' + error);
                });
            }
            
            // Show party ledger
            function showPartyLedger(data) {
                const party = data.party;
                const transactions = data.transactions;
                const summary = data.summary;
                
                // Update party info
                $('#partyName').text(party.party_name);
                $('#partyContact').text(party.contact_no || 'No contact');
                $('#partyAddress').text(party.address || 'No address');
                $('#partyId').text(party.id);
                
                // Update account status based on current balance
                const currentBalance = summary.current_balance || 0;
                const statusClass = currentBalance > 0 ? 'status-due' : currentBalance < 0 ? 'status-clear' : 'status-clear';
                const statusText = currentBalance > 0 ? 'Amount Due' : currentBalance < 0 ? 'Credit Balance' : 'Account Clear';
                $('#accountStatus').removeClass('status-due status-clear').addClass(statusClass).text(statusText);
                
                // Update summary cards
                updateSummaryCards(summary);
                
                // Update transaction tables
                updateTransactionTables(transactions, summary);
                
                // Show ledger container
                $('#partyListContainer').addClass('hidden');
                $('#ledgerContainer').removeClass('hidden');
                
                // Store data for PDF export
                window.currentLedgerData = data;
            }
            
            // Update summary cards
            function updateSummaryCards(summary) {
                const cardsHtml = `
                    <div class="grid grid-cols-2 md:grid-cols-6 gap-4 mb-6">
                        <div class="soft-gradient-blue rounded-xl p-3 shadow-sm h-full">
                            <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-xs font-medium text-blue-700 mb-1">Booked</p>
                                    <p class="text-lg font-bold text-blue-800 mb-0">${summary.booked_weight.toFixed(2)}g</p>
                                        <p class="text-xs text-blue-600 mb-0">Cash: ${summary.booked_weight_cash.toFixed(2)}g | Bank: ${summary.booked_weight_bank.toFixed(2)}g</p>
                                    </div>
                                <div class="w-8 h-8 bg-blue-500 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-book text-white text-xs"></i>
                                </div>
                            </div>
                        </div>
                        
                        <div class="soft-gradient-green rounded-xl p-3 shadow-sm h-full">
                            <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-xs font-medium text-green-700 mb-1">Sold</p>
                                    <p class="text-lg font-bold text-green-800 mb-0">${summary.sold_weight.toFixed(2)}g</p>
                                        <p class="text-xs text-green-600 mb-0">Cash: ${summary.sold_weight_cash.toFixed(2)}g | Bank: ${summary.sold_weight_bank.toFixed(2)}g</p>
                                    </div>
                                <div class="w-8 h-8 bg-green-500 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-shopping-cart text-white text-xs"></i>
                                </div>
                            </div>
                        </div>
                        


                        <div class="soft-gradient-teal rounded-xl p-3 shadow-sm h-full">
                            <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-xs font-medium text-teal-700 mb-1">Total Received</p>
                                    <p class="text-lg font-bold text-teal-800 mb-0">₹${formatIndianCurrency(summary.total_received || 0)}</p>
                                        <p class="text-xs text-teal-600 mb-0">Cash: ₹${formatIndianCurrency(summary.cash_received || 0)} | Bank: ₹${formatIndianCurrency(summary.bank_received || 0)}</p>
                                    </div>
                                <div class="w-8 h-8 bg-teal-500 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-money-bill-wave text-white text-xs"></i>
                                </div>
                            </div>
                        </div>

                        <div class="soft-gradient-orange rounded-xl p-3 shadow-sm h-full">
                            <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-xs font-medium text-orange-700 mb-1">Total Balance</p>
                                    <p class="text-lg font-bold text-orange-800 mb-0">₹${formatIndianCurrency(summary.current_balance || 0)}</p>
                                        <p class="text-xs text-orange-600 mb-0">Cash: ₹${formatIndianCurrency(summary.cash_balance || 0)} | Bank: ₹${formatIndianCurrency(summary.bank_balance || 0)}</p>
                                    </div>
                                <div class="w-8 h-8 bg-orange-500 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-wallet text-white text-xs"></i>
                                </div>
                            </div>
                        </div>
                        
                        <div class="soft-gradient-yellow rounded-xl p-3 shadow-sm h-full">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs font-medium text-yellow-700 mb-1">Gold Received</p>
                                    <p class="text-lg font-bold text-yellow-800 mb-0">${(summary.gold_received_weight || 0).toFixed(3)}g</p>
                                    <p class="text-xs text-yellow-600 mb-0">Total gold received from exchanges</p>
                                </div>
                                <div class="w-8 h-8 bg-yellow-500 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-coins text-white text-xs"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                $('#summaryCards').html(cardsHtml);
            }
            
            // Update transaction tables
            function updateTransactionTables(transactions, summary) {
                // All transactions table
                const allTbody = $('#allTransactionsTable tbody');
                allTbody.empty();
                
                // Separate transactions by type
                const bookings = [];
                const sales = [];
                const payments = [];
                const goldReceived = [];
                
                transactions.forEach(trans => {
                    const rowHtml = createTransactionRow(trans);
                    allTbody.append(rowHtml);
                    
                    switch(trans.transaction_type) {
                        case 'Booking':
                            bookings.push(trans);
                            break;
                        case 'Sale':
                            sales.push(trans);
                            break;
                        case 'Payment':
                            // Only include payments with positive amounts
                            if (trans.payment_amount && trans.payment_amount > 0) {
                                payments.push(trans);
                            }
                            break;
                        case 'Gold_Received':
                            goldReceived.push(trans);
                            break;
                    }
                });
                
                // Add summary row at the bottom showing total outstanding balance
                if (summary) {
                    const cashBalance = parseFloat(summary.cash_balance || 0);
                    const bankBalance = parseFloat(summary.bank_balance || 0);
                    const totalBalance = parseFloat(summary.current_balance || 0);
                    
                    // Format amount helper function
                    const formatAmount = (amount) => {
                        const num = parseFloat(amount);
                        if (isNaN(num)) return '₹0.00';
                        // Use formatIndianCurrency if available, otherwise use simple formatting
                        if (typeof formatIndianCurrency === 'function') {
                            return '₹' + formatIndianCurrency(num);
                        } else {
                            return '₹' + num.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        }
                    };
                    
                    const balanceClass = totalBalance > 0 ? 'text-red-600 font-bold' : totalBalance < 0 ? 'text-green-600 font-bold' : 'text-gray-600 font-bold';
                    
                    const summaryRow = `
                        <tr class="bg-yellow-50 border-t-2 border-yellow-300 font-semibold">
                            <td colspan="4" class="px-2 py-3 text-xs font-bold text-gray-700 uppercase">
                                <i class="fas fa-calculator mr-1"></i>Total Outstanding Balance
                            </td>
                            <td class="px-2 py-3 text-xs text-gray-600 text-right" colspan="3">
                                <div class="flex flex-col items-end">
                                    <div class="text-xs text-gray-500 mb-1">Cash: <span class="font-semibold ${cashBalance > 0 ? 'text-red-600' : cashBalance < 0 ? 'text-green-600' : 'text-gray-600'}">${formatAmount(cashBalance)}</span></div>
                                    <div class="text-xs text-gray-500">Bank: <span class="font-semibold ${bankBalance > 0 ? 'text-red-600' : bankBalance < 0 ? 'text-green-600' : 'text-gray-600'}">${formatAmount(bankBalance)}</span></div>
                                </div>
                            </td>
                            <td class="px-2 py-3 text-xs ${balanceClass} text-right" colspan="4">
                                <div class="flex flex-col items-end">
                                    <div class="text-sm font-bold">Total: ${formatAmount(totalBalance)}</div>
                                    <div class="text-xs ${totalBalance > 0 ? 'text-red-500' : totalBalance < 0 ? 'text-green-500' : 'text-gray-500'}">
                                        ${totalBalance > 0 ? '(Due)' : totalBalance < 0 ? '(Credit)' : '(Clear)'}
                                    </div>
                                </div>
                            </td>
                        </tr>
                    `;
                    allTbody.append(summaryRow);
                }
                
                // Update individual tables
                updateTable('#bookingsTable tbody', bookings, 'booking');
                updateTable('#salesTable tbody', sales, 'sale');
                updateTable('#paymentsTable tbody', payments, 'payment');
                updateTable('#goldReceivedTable tbody', goldReceived, 'gold-received');
            }
            
            // Create transaction row
            function createTransactionRow(trans) {
                const typeClass = getTransactionTypeClass(trans.transaction_type);
                const typeIcon = getTransactionTypeIcon(trans.transaction_type);
                
                // Safe number formatting functions
                const formatWeight = (weight) => {
                    if (weight === null || weight === undefined || weight === '') return '-';
                    const num = parseFloat(weight);
                    return isNaN(num) ? '-' : num.toFixed(3);
                };
                
                const formatRate = (rate) => {
                    if (rate === null || rate === undefined || rate === '') return '-';
                    const num = parseFloat(rate);
                    return isNaN(num) ? '-' : '₹' + num.toFixed(2);
                };
                
                const formatAmount = (amount) => {
                    if (amount === null || amount === undefined || amount === '') return '-';
                    const num = parseFloat(amount);
                    return isNaN(num) ? '-' : '₹' + formatIndianCurrency(num);
                };
                
                // Format booking type with badge
                const formatBookingType = (bookingType, transType) => {
                    if (!bookingType || (transType !== 'Booking' && transType !== 'Sale')) return '-';
                    const badgeClass = bookingType === 'Cash' ? 'bg-purple-100 text-purple-800' : 'bg-indigo-100 text-indigo-800';
                    return `<span class="inline-flex px-2 py-0.5 text-xs font-medium rounded ${badgeClass}">${bookingType}</span>`;
                };
                
                // Check if sale transaction needs "Cut Vow" (no rate or rate = 0)
                const needsCutVow = trans.transaction_type === 'Sale' && (!trans.rate || parseFloat(trans.rate) <= 0);
                const actionButton = needsCutVow 
                    ? `<button class="cut-vow-btn px-2 py-1 text-xs bg-orange-500 hover:bg-orange-600 text-white rounded transition-colors" 
                               data-trans-id="${trans.id}" 
                               data-weight="${trans.gold_weight || 0}"
                               title="Cut Vow - Enter Rate">
                            <i class="fas fa-cut mr-1"></i>Cut Vow
                        </button>`
                    : '-';
                
                return `
                    <tr class="table-row-hover">
                        <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900">
                            <div>${formatDate(trans.date_of_transaction)}</div>
                            <div class="text-xs text-gray-500">${formatTime(trans.date_of_transaction)}</div>
                        </td>
                        <td class="px-2 py-2 whitespace-nowrap">
                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full ${typeClass}">
                                <i class="${typeIcon} mr-1"></i>${trans.transaction_type}
                            </span>
                        </td>
                        <td class="px-2 py-2 whitespace-nowrap text-xs">${formatBookingType(trans.booking_type, trans.transaction_type)}</td>
                        <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900">${trans.receipt_id || '-'}</td>
                        <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 text-right">${formatWeight(trans.gold_weight)}</td>
                        <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 text-right">${formatRate(trans.rate)}</td>
                        <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 text-right">${formatAmount(trans.gold_amount)}</td>
                        <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 text-right">${trans.payment_amount && trans.payment_amount > 0 ? formatAmount(trans.payment_amount) : '-'}</td>
                        <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-500">
                            ${trans.transaction_type === 'Sale' && trans.payment_amount && trans.payment_amount > 0 
                                ? '<span class="text-green-600 font-semibold">Received</span>' 
                                : (trans.payment_method || '-')}
                        </td>
                        <td class="px-2 py-2 text-xs text-gray-500">${trans.narration || '-'}</td>
                        <td class="px-2 py-2 whitespace-nowrap text-center">${actionButton}</td>
                    </tr>
                `;
            }
            
            // Update individual table
            function updateTable(selector, transactions, type) {
                const tbody = $(selector);
                tbody.empty();
                
                if (transactions.length === 0) {
                    const colspan = type === 'sale' ? '8' : '7';
                    tbody.append(`
                        <tr>
                            <td colspan="${colspan}" class="text-center py-4">
                                <i class="fas fa-inbox fa-lg text-gray-400 mb-2"></i>
                                <p class="text-gray-500 text-xs">No ${type} transactions found</p>
                            </td>
                        </tr>
                    `);
                    return;
                }
                
                transactions.forEach(trans => {
                    let rowHtml = '';
                    
                    // Safe formatting functions
                    const formatWeight = (weight) => {
                        if (weight === null || weight === undefined || weight === '') return '-';
                        const num = parseFloat(weight);
                        return isNaN(num) ? '-' : num.toFixed(3);
                    };
                    
                    const formatRate = (rate) => {
                        if (rate === null || rate === undefined || rate === '') return '-';
                        const num = parseFloat(rate);
                        return isNaN(num) ? '-' : '₹' + num.toFixed(2);
                    };
                    
                    const formatAmount = (amount) => {
                        if (amount === null || amount === undefined || amount === '') return '-';
                        const num = parseFloat(amount);
                        return isNaN(num) ? '-' : '₹' + formatIndianCurrency(num);
                    };
                    
                    const formatPurity = (purity) => {
                        if (purity === null || purity === undefined || purity === '') return '-';
                        const num = parseFloat(purity);
                        return isNaN(num) ? '-' : num + '%';
                    };
                    
                    // Format booking type with badge
                    const formatBookingTypeBadge = (bookingType) => {
                        if (!bookingType) return '-';
                        const badgeClass = bookingType === 'Cash' ? 'bg-purple-100 text-purple-800' : 'bg-indigo-100 text-indigo-800';
                        return `<span class="inline-flex px-2 py-0.5 text-xs font-medium rounded ${badgeClass}">${bookingType}</span>`;
                    };
                    
                    switch(type) {
                        case 'booking':
                        case 'sale':
                            // Check if sale transaction needs "Cut Vow" (no rate or rate = 0)
                            const needsCutVow = type === 'sale' && (!trans.rate || parseFloat(trans.rate) <= 0);
                            const actionButton = needsCutVow 
                                ? `<button class="cut-vow-btn px-2 py-1 text-xs bg-orange-500 hover:bg-orange-600 text-white rounded transition-colors" 
                                           data-trans-id="${trans.id}" 
                                           data-weight="${trans.gold_weight || 0}"
                                           title="Cut Vow - Enter Rate">
                                        <i class="fas fa-cut mr-1"></i>Cut Vow
                                    </button>`
                                : '-';
                            
                            rowHtml = `
                                <tr class="table-row-hover">
                                    <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900">
                                        <div>${formatDate(trans.date_of_transaction)}</div>
                                        <div class="text-xs text-gray-500">${formatTime(trans.date_of_transaction)}</div>
                                    </td>
                                    <td class="px-2 py-2 whitespace-nowrap text-xs">${formatBookingTypeBadge(trans.booking_type)}</td>
                                    <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900">${trans.receipt_id || '-'}</td>
                                    <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 text-right">${formatWeight(trans.gold_weight)}</td>
                                    <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 text-right">${formatPurity(trans.purity)}</td>
                                    <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 text-right">${formatRate(trans.rate)}</td>
                                    <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 text-right">${formatAmount(trans.gold_amount)}</td>
                                    <td class="px-2 py-2 text-xs text-gray-500">
                                        ${trans.payment_amount && trans.payment_amount > 0 
                                            ? '<span class="text-green-600 font-semibold">Received</span>' 
                                            : '-'}
                                    </td>
                                    <td class="px-2 py-2 text-xs text-gray-500">${trans.narration || '-'}</td>
                                    <td class="px-2 py-2 whitespace-nowrap text-center">${actionButton}</td>
                                </tr>
                            `;
                            break;
                        case 'payment':
                            // Only show positive payment amounts
                            if (trans.payment_amount && trans.payment_amount > 0) {
                                rowHtml = `
                                    <tr class="table-row-hover">
                                        <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900">
                                            <div>${formatDate(trans.date_of_transaction)}</div>
                                            <div class="text-xs text-gray-500">${formatTime(trans.date_of_transaction)}</div>
                                        </td>
                                        <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900">${trans.receipt_id || '-'}</td>
                                        <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 text-right">${formatAmount(trans.payment_amount)}</td>
                                        <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-500">${trans.payment_method || '-'}</td>
                                        <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-500">
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full ${trans.payment_type === 'Payment_In' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}">
                                                ${trans.payment_type === 'Payment_In' ? 'Received' : 'Paid Out'}
                                            </span>
                                        </td>
                                        <td class="px-2 py-2 text-xs text-gray-500">${trans.narration || '-'}</td>
                                    </tr>
                                `;
                            }
                            break;
                        case 'gold-received':
                            rowHtml = `
                                <tr class="table-row-hover">
                                    <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900">
                                        <div>${formatDate(trans.date_of_transaction)}</div>
                                        <div class="text-xs text-gray-500">${formatTime(trans.date_of_transaction)}</div>
                                    </td>
                                    <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900">${trans.receipt_id || '-'}</td>
                                    <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 text-right">${formatWeight(trans.gold_weight)}</td>
                                    <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 text-right">${formatRate(trans.rate)}</td>
                                    <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 text-right">${formatAmount(trans.gold_amount)}</td>
                                    <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-500">${formatBookingTypeBadge(trans.payment_method)}</td>
                                    <td class="px-2 py-2 text-xs text-gray-500">${trans.narration || '-'}</td>
                                </tr>
                            `;
                            break;
                    }
                    
                    tbody.append(rowHtml);
                });
            }
            
            // Export PDF functionality
            $('#exportPdfBtn').on('click', function() {
                if (!window.currentLedgerData) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'No ledger data available to export'
                    });
                    return;
                }
                
                exportToPDF(window.currentLedgerData);
            });
            
            // Export to PDF function using TCPDF
            function exportToPDF(data) {
                if (!data || !data.party || !data.party.id) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'No party data available to export'
                    });
                    return;
                }
                
                // Show loading
                Swal.fire({
                    title: 'Generating PDF...',
                    text: 'Please wait while we prepare your document',
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                // Open PDF in new window/tab (will trigger download)
                const partyId = data.party.id;
                const pdfUrl = `export_party_ledger_pdf.php?party_id=${partyId}`;
                
                // Use fetch to check if PDF generation is successful
                fetch(pdfUrl)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('PDF generation failed');
                        }
                        return response.blob();
                    })
                    .then(blob => {
                        // Create download link
                        const url = window.URL.createObjectURL(blob);
                        const link = document.createElement('a');
                        link.href = url;
                        link.download = `Ledger_${data.party.party_name.replace(/[^A-Za-z0-9_]/g, '_')}_${partyId}_${new Date().toISOString().split('T')[0]}.pdf`;
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                        window.URL.revokeObjectURL(url);
                        
                        // Close loading and show success
                        Swal.close();
                        Swal.fire({
                            icon: 'success',
                            title: 'PDF Exported!',
                            text: 'Your ledger report has been downloaded successfully.',
                            timer: 2000,
                            showConfirmButton: false
                        });
                    })
                    .catch(error => {
                        console.error('PDF export error:', error);
                        Swal.close();
                        Swal.fire({
                            icon: 'error',
                            title: 'Export Failed',
                            html: 'Failed to generate PDF. Please check:<br><br>' +
                                  '1. TCPDF library is installed<br>' +
                                  '2. Server has write permissions<br>' +
                                  '3. Party data is valid',
                            confirmButtonText: 'OK'
                        });
                    });
            }
            
            // Helper functions
            function getTransactionTypeClass(type) {
                switch(type) {
                    case 'Booking': return 'bg-blue-100 text-blue-800';
                    case 'Sale': return 'bg-green-100 text-green-800';
                    case 'Payment': return 'bg-yellow-100 text-yellow-800';
                    case 'Gold_Received': return 'bg-yellow-100 text-yellow-800';
                    default: return 'bg-gray-100 text-gray-800';
                }
            }
            
            function getTransactionTypeIcon(type) {
                switch(type) {
                    case 'Booking': return 'fas fa-book';
                    case 'Sale': return 'fas fa-shopping-cart';
                    case 'Payment': return 'fas fa-credit-card';
                    case 'Gold_Received': return 'fas fa-coins';
                    default: return 'fas fa-exchange-alt';
                }
            }
            
            function formatDate(dateString) {
                const date = new Date(dateString);
                return date.toLocaleDateString('en-GB');
            }
            
            function formatTime(dateString) {
                const date = new Date(dateString);
                return date.toLocaleTimeString('en-US', {hour: '2-digit', minute: '2-digit'});
            }
            
            function formatIndianCurrency(amount) {
                if (amount === undefined || amount === null) return '0.00';
                
                let num = parseFloat(String(amount).replace(/,/g, ''));
                if (isNaN(num)) return '0.00';
                
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
            
            // Tab functionality
            $(document).on('click', '.tab-btn', function() {
                const tab = $(this).data('tab');
                switchTab(tab);
            });
            
            function switchTab(tab) {
                // Update tab buttons
                $('.tab-btn').removeClass('bg-blue-600 text-white').addClass('bg-white text-blue-600 hover:bg-blue-50');
                $(`.tab-btn[data-tab="${tab}"]`).removeClass('bg-white text-blue-600 hover:bg-blue-50').addClass('bg-blue-600 text-white');
                
                // Show/hide table bodies
                $('#allTransactionsTable, #bookingsTable, #salesTable, #paymentsTable').addClass('hidden');
                
                if (tab === 'all') {
                    $('#allTransactionsTable').removeClass('hidden');
                } else {
                    $('#' + tab + 'Table').removeClass('hidden');
                }
            }
            
            // Keyboard shortcuts for party ledger
            $(document).on('keydown', function(e) {
                // Only work when ledger is visible
                if ($('#ledgerContainer').is(':visible')) {
                    // Tab shortcuts
                    if (e.ctrlKey || e.metaKey) {
                        switch(e.key) {
                            case '1':
                                e.preventDefault();
                                switchTab('all');
                                break;
                            case '2':
                                e.preventDefault();
                                switchTab('bookings');
                                break;
                            case '3':
                                e.preventDefault();
                                switchTab('sales');
                                break;
                            case '4':
                                e.preventDefault();
                                switchTab('payments');
                                break;
                        }
                    }
                    
                    // Arrow keys for tab navigation
                    if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                        e.preventDefault();
                        const currentTab = $('.tab-btn.bg-blue-600').data('tab');
                        const tabs = ['all', 'bookings', 'sales', 'payments'];
                        const currentIndex = tabs.indexOf(currentTab);
                        let newIndex;
                        
                        if (e.key === 'ArrowLeft') {
                            newIndex = currentIndex > 0 ? currentIndex - 1 : tabs.length - 1;
                        } else {
                            newIndex = currentIndex < tabs.length - 1 ? currentIndex + 1 : 0;
                        }
                        
                        switchTab(tabs[newIndex]);
                    }
                }
                
                // Search focus shortcut
                if (e.key === '/' && e.target.tagName !== 'INPUT' && !$('#ledgerContainer').is(':visible')) {
                    e.preventDefault();
                    $('#partySearchInput').focus();
                    // Also show party list if hidden
                    if (!partyListVisible || $('#partyList').hasClass('hidden')) {
                        const searchTerm = $('#partySearchInput').val().trim();
                        searchParties(searchTerm || '');
                    }
                }
                
                // Back to party list shortcut
                if (e.key === 'Escape' && $('#ledgerContainer').is(':visible')) {
                    $('#ledgerContainer').addClass('hidden');
                    $('#partyListContainer').removeClass('hidden');
                    currentPartyId = null;
                    // Clear search and reset
                    $('#partySearchInput').val('');
                    $('#partyList').addClass('hidden');
                    partyListVisible = false;
                    currentIndex = -1;
                    $('#partySearchInput').focus();
                }
            
                // PDF export shortcut
                if ((e.ctrlKey || e.metaKey) && e.key === 'p' && $('#ledgerContainer').is(':visible')) {
                    e.preventDefault();
                    $('#exportPdfBtn').click();
                }
            });
            
            // Cut Vow Modal Functions
            function showCutVowModal(transId, weight) {
                $('#cutVowTransactionId').val(transId);
                $('#cutVowWeight').text(parseFloat(weight).toFixed(3) + 'g');
                $('#cutVowRate').val('');
                $('#cutVowTotalAmount').text('₹0.00');
                $('#cutVowModal').removeClass('hidden').addClass('flex');
                setTimeout(() => {
                    $('#cutVowRate').focus();
                }, 100);
            }
            
            // Calculate and update total amount when rate changes
            $('#cutVowRate').on('input', function() {
                const rate = parseFloat($(this).val()) || 0;
                const weight = parseFloat($('#cutVowWeight').text().replace('g', '')) || 0;
                const totalAmount = rate * weight;
                $('#cutVowTotalAmount').text('₹' + formatIndianCurrency(totalAmount));
            });
            
            function hideCutVowModal() {
                $('#cutVowModal').removeClass('flex').addClass('hidden');
                $('#cutVowForm')[0].reset();
            }
            
            // Open cut vow modal when button is clicked
            $(document).on('click', '.cut-vow-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const transId = $(this).data('trans-id');
                const weight = $(this).data('weight');
                showCutVowModal(transId, weight);
            });
            
            // Close cut vow modal
            $('#closeCutVowModal, #cancelCutVowBtn').on('click', function() {
                hideCutVowModal();
            });
            
            // Close on backdrop click
            $('#cutVowModal').on('click', function(e) {
                if (e.target === this) {
                    hideCutVowModal();
                }
            });
            
            // Handle cut vow form submission
            $('#cutVowForm').on('submit', function(e) {
                e.preventDefault();
                const transId = $('#cutVowTransactionId').val();
                const rate = parseFloat($('#cutVowRate').val());
                
                if (!transId || !rate || rate <= 0) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Validation Error',
                        text: 'Please enter a valid rate!'
                    });
                    return;
                }
                
                // Show loading
                Swal.fire({
                    title: 'Processing...',
                    text: 'Please wait while we cut the vow',
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                $.post('', {
                    action: 'cut_vow',
                    sale_transaction_id: transId,
                    rate: rate
                }, function(response) {
                    Swal.close();
                    if (response.status === 'success') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message,
                            timer: 2000,
                            showConfirmButton: false
                        }).then(() => {
                            hideCutVowModal();
                            // Reload party ledger to show updated data
                            if (currentPartyId) {
                                loadPartyLedger(currentPartyId);
                            }
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to cut vow'
                        });
                    }
                }, 'json').fail(function(xhr, status, error) {
                    Swal.close();
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'An error occurred while cutting the vow. Please try again.'
                    });
                });
            });
            
            // Keyboard navigation for cut vow modal
            $('#cutVowModal').on('keydown', function(e) {
                if (e.key === 'Escape') {
                    hideCutVowModal();
                }
            });
            
});
    </script>

<?php
// Capture the content
$content = ob_get_clean();

// Set page title and include layout
$page_title = "Party Ledger";
include 'components/layout.php';
?>